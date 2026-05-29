<?php
/**
 * This file is part of ICalSync plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\ICalSync\Lib\Service;

use FacturaScripts\Core\Tools;
use Sabre\DAV\Client as DavClient;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader as VObjectReader;

/**
 * Cliente CalDAV para operaciones con iCloud Calendar.
 *
 * Wrapper sobre sabre/dav para operaciones PROPFIND, REPORT, PUT, DELETE.
 * Incluye manejo de errores con exponential backoff.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class CalDavClient
{
    /** @var DavClient */
    private DavClient $client;

    /** @var string */
    private string $principalUrl;

    /** @var int Máximo de reintentos para errores 429 */
    private const MAX_RETRIES = 3;

    /**
     * @param string $principalUrl
     * @param string $username Apple ID
     * @param string $password App-specific password
     */
    public function __construct(string $principalUrl, string $username, string $password)
    {
        $this->principalUrl = rtrim($principalUrl, '/');

        $this->client = new DavClient([
            'baseUri' => $this->principalUrl . '/',
            'userName' => $username,
            'password' => $password,
            'authType' => DavClient::AUTH_BASIC,
            'encoding' => DavClient::ENCODING_DEFLATE,
            'headers' => [
                'User-Agent' => 'ICalSync-FacturaScripts/1.0',
            ],
            'timeout' => 30,
        ]);
    }

    /**
     * Discover the user's principal URL from a well-known CalDAV URL.
     *
     * @param string $wellKnownUrl e.g., https://caldav.icloud.com/
     * @return string|null The principal URL, or null on failure
     */
    public function discoverPrincipal(string $wellKnownUrl): ?string
    {
        $url = rtrim($wellKnownUrl, '/') . '/.well-known/caldav';

        try {
            $response = $this->client->request('GET', $url);
            if (isset($response['headers']['location'])) {
                return $response['headers']['location'];
            }
            // Try PROPFIND on the base URL as fallback
            return $this->probePrincipalFromRoot($wellKnownUrl);
        } catch (\Exception $e) {
            Tools::log()->error('caldav-http-error', ['%error%' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Discover all calendars from the principal's calendar-home-set.
     *
     * @param string|null $principalUrl
     * @return array Array of ['url' => string, 'displayname' => string, 'color' => string, 'ctag' => string]
     */
    public function discoverCalendars(?string $principalUrl = null): array
    {
        $url = $principalUrl ? rtrim($principalUrl, '/') : $this->principalUrl;

        // Step 1: PROPFIND on principal URL to get calendar-home-set
        $calendarHome = $this->discoverCalendarHome($url);
        if (null === $calendarHome) {
            // Fallback: try PROPFIND directly on principal URL
            $calendarHome = $url;
        } elseif (str_starts_with($calendarHome, '/')) {
            // Resolve relative URL against the principal's base
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
            $calendarHome = $scheme . '://' . $host . $calendarHome;
        }

        // Step 2: PROPFIND on calendar-home-set to list calendars
        return $this->fetchCalendars($calendarHome);
    }

    /**
     * Discover the calendar-home-set from a principal URL.
     *
     * @param string $principalUrl
     * @return string|null The calendar-home-set href, or null
     */
    private function discoverCalendarHome(string $principalUrl): ?string
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <c:calendar-home-set/>
    </d:prop>
</d:propfind>';

        try {
            $response = $this->client->request('PROPFIND', $principalUrl, $body, [
                'Depth' => '0',
                'Content-Type' => 'application/xml; charset=utf-8',
            ]);


            $xml = $this->parseXmlBody($response['body'] ?? '');
            if (null === $xml) {
                return null;
            }

            $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
            $xml->registerXPathNamespace('d', 'DAV:');

            $nodes = $xml->xpath('//c:calendar-home-set/d:href');
            if (empty($nodes)) {
                // Try without namespace prefix
                $nodes = $xml->xpath(
                    '//*[local-name()="calendar-home-set"]/*[local-name()="href"]'
                );
            }

            if (!empty($nodes)) {
                $home = (string)$nodes[0];
                return $home;
            }
            Tools::log()->warning('caldav-no-calset', ['%url%' => $principalUrl]);
        } catch (\Exception $e) {
            Tools::log()->error('caldav-calendar-home-error', ['%error%' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fetch calendars from a calendar-home-set URL via PROPFIND.
     *
     * @param string $calendarHomeUrl
     * @return array
     */
    private function fetchCalendars(string $calendarHomeUrl): array
    {

        $body = '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:displayname/>
        <cs:getctag/>
        <c:supported-calendar-component-set/>
        <d:resourcetype/>
    </d:prop>
</d:propfind>';

        try {
            $response = $this->client->request('PROPFIND', $calendarHomeUrl, $body, [
                'Depth' => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ]);


            $calendars = $this->parseCalendarMultiStatus($response['body'] ?? '');
            return $calendars;
        } catch (\Exception $e) {
            Tools::log()->error('caldav-http-error', ['%error%' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * List events from a calendar, optionally filtered by modification date.
     *
     * @param string $calendarUrl
     * @param string|null $since ISO datetime filter (CALDAV:time-range)
     * @return array Array of ['uri' => string, 'etag' => string, 'data' => string|null]
     */
    public function listEvents(string $calendarUrl, ?string $since = null): array
    {
        $url = rtrim($calendarUrl, '/');

        $body = '<?xml version="1.0" encoding="utf-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag/>
        <c:calendar-data/>
    </d:prop>
    <c:filter>
        <c:comp-filter name="VCALENDAR">
            <c:comp-filter name="VEVENT">';

        if ($since) {
            $body .= '<c:time-range start="' . gmdate('Ymd\THis\Z', strtotime($since)) . '"/>';
        }

        $body .= '
            </c:comp-filter>
        </c:comp-filter>
    </c:filter>
</c:calendar-query>';

        try {
            $response = $this->client->request('REPORT', $url, $body, [
                'Depth' => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ]);

            return $this->parseEventMultiStatus($response['body'] ?? '');
        } catch (\Exception $e) {
            Tools::log()->error('caldav-http-error', ['%error%' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get a single event's iCal data by UID.
     *
     * @param string $calendarUrl
     * @param string $uid
     * @return VCalendar|null
     */
    public function getEvent(string $calendarUrl, string $uid): ?VCalendar
    {
        $url = rtrim($calendarUrl, '/') . '/' . rawurlencode($uid) . '.ics';

        try {
            $response = $this->client->request('GET', $url);
            if (200 === ($response['statusCode'] ?? 0)) {
                return VObjectReader::read($response['body']);
            }
        } catch (\Exception $e) {
            Tools::log()->error('caldav-http-error', ['%error%' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Create a new event on the calendar.
     *
     * @param string $calendarUrl
     * @param string $iCalData Raw iCalendar data string
     * @return array{uid: string, etag: string}|null
     */
    public function createEvent(string $calendarUrl, string $iCalData): ?array
    {
        // Extract UID from iCal data
        $vCal = VObjectReader::read($iCalData);
        $uid = (string)$vCal->VEVENT->UID;
        $url = rtrim($calendarUrl, '/') . '/' . rawurlencode($uid) . '.ics';

        try {
            $response = $this->retryRequest(function () use ($url, $iCalData) {
                return $this->client->request('PUT', $url, $iCalData, [
                    'Content-Type' => 'text/calendar; charset=utf-8',
                ]);
            });

            $statusCode = $response['statusCode'] ?? 0;
            if (201 === $statusCode || 204 === $statusCode) {
                $etag = $this->extractEtag($response);
                return [
                    'uid' => $uid,
                    'etag' => $etag,
                ];
            }

            Tools::log()->error('caldav-http-error', [
                '%error%' => 'Unexpected status: ' . $statusCode,
            ]);
        } catch (\Exception $e) {
            Tools::log()->error('caldav-http-error', ['%error%' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Update an existing event on the calendar.
     *
     * @param string $calendarUrl
     * @param string $uid
     * @param string $iCalData
     * @param string|null $etag Current etag for conditional update
     * @return string|null New etag, or null on failure
     */
    public function updateEvent(string $calendarUrl, string $uid, string $iCalData, ?string $etag = null): ?string
    {
        $url = rtrim($calendarUrl, '/') . '/' . rawurlencode($uid) . '.ics';

        $headers = [
            'Content-Type' => 'text/calendar; charset=utf-8',
        ];
        if ($etag) {
            $headers['If-Match'] = '"' . $etag . '"';
        }

        try {
            $response = $this->retryRequest(function () use ($url, $iCalData, $headers) {
                return $this->client->request('PUT', $url, $iCalData, $headers);
            });

            $statusCode = $response['statusCode'] ?? 0;
            if (204 === $statusCode || 201 === $statusCode) {
                return $this->extractEtag($response);
            }

            Tools::log()->error('caldav-http-error', [
                '%error%' => 'Update returned status: ' . $statusCode,
            ]);
        } catch (\Exception $e) {
            Tools::log()->error('caldav-http-error', ['%error%' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Delete an event from the calendar.
     *
     * @param string $calendarUrl
     * @param string $uid
     * @param string|null $etag
     * @return bool
     */
    public function deleteEvent(string $calendarUrl, string $uid, ?string $etag = null): bool
    {
        $url = rtrim($calendarUrl, '/') . '/' . rawurlencode($uid) . '.ics';

        $headers = [];
        if ($etag) {
            $headers['If-Match'] = '"' . $etag . '"';
        }

        try {
            $response = $this->client->request('DELETE', $url, null, $headers);
            $statusCode = $response['statusCode'] ?? 0;
            return 204 === $statusCode || 200 === $statusCode || 404 === $statusCode;
        } catch (\Exception $e) {
            Tools::log()->error('caldav-http-error', ['%error%' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Probe the root URL for a principal URL via PROPFIND.
     *
     * @param string $baseUrl
     * @return string|null
     */
    private function probePrincipalFromRoot(string $baseUrl): ?string
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:">
    <d:prop>
        <d:current-user-principal/>
    </d:prop>
</d:propfind>';

        try {
            $response = $this->client->request('PROPFIND', rtrim($baseUrl, '/'), $body, [
                'Depth' => '0',
                'Content-Type' => 'application/xml; charset=utf-8',
            ]);

            $xml = $this->parseXmlBody($response['body'] ?? '');
            if (null === $xml) {
                return null;
            }

            $nodes = $xml->xpath('//d:current-user-principal/d:href');
            if (!empty($nodes)) {
                return (string)$nodes[0];
            }
        } catch (\Exception $e) {
            Tools::log()->error('caldav-http-error', ['%error%' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Parse PROPFIND multistatus response for calendar data.
     *
     * @param string $body
     * @return array
     */
    private function parseCalendarMultiStatus(string $body): array
    {
        $calendars = [];
        $xml = $this->parseXmlBody($body);
        if (null === $xml) {
            return $calendars;
        }

        $responses = $xml->xpath('//d:response');
        foreach ($responses as $response) {
            $response->registerXPathNamespace('d', 'DAV:');
            $response->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
            $response->registerXPathNamespace('cs', 'http://calendarserver.org/ns/');
            $response->registerXPathNamespace('ical', 'http://apple.com/ns/ical/');

            $hrefNodes = $response->xpath('d:href');
            if (empty($hrefNodes)) {
                continue;
            }
            $href = (string)$hrefNodes[0];

            $resType = $response->xpath('d:propstat/d:prop/d:resourcetype/*');
            $isCalendar = false;
            foreach ($resType as $type) {
                $ns = $type->getNamespaces(true);
                $localName = $type->getName();
                $nsUri = $ns[''] ?? '';
                // Check for calendar resource type in CalDAV namespace
                if ('calendar' === $localName && false !== strpos($nsUri, 'caldav')) {
                    $isCalendar = true;
                    break;
                }
                // Also check the {ns}localName format
                if (str_contains($localName, '}calendar')) {
                    $isCalendar = true;
                    break;
                }
            }

            if (!$isCalendar) {
                // Log what resource types we found for debugging
                $foundTypes = [];
                foreach ($resType as $type) {
                    $foundTypes[] = $type->getName();
                }
                continue;
            }

            $displayNameNodes = $response->xpath('d:propstat/d:prop/d:displayname');
            $displayName = !empty($displayNameNodes) ? (string)$displayNameNodes[0] : basename($href);

            $colorNodes = $response->xpath('d:propstat/d:prop/ical:calendar-color');
            $color = !empty($colorNodes) ? (string)$colorNodes[0] : '#0d6efd';

            $ctagNodes = $response->xpath('d:propstat/d:prop/cs:getctag');
            $ctag = !empty($ctagNodes) ? (string)$ctagNodes[0] : '';

            $calendars[] = [
                'url' => $href,
                'displayname' => $displayName,
                'color' => $color,
                'ctag' => $ctag,
            ];
        }


        return $calendars;
    }

    /**
     * Parse REPORT multistatus response for event data.
     *
     * @param string $body
     * @return array
     */
    private function parseEventMultiStatus(string $body): array
    {
        $events = [];
        $xml = $this->parseXmlBody($body);
        if (null === $xml) {
            return $events;
        }

        $responses = $xml->xpath('//d:response');
        foreach ($responses as $response) {
            $response->registerXPathNamespace('d', 'DAV:');
            $response->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

            $hrefNodes = $response->xpath('d:href');
            if (empty($hrefNodes)) {
                continue;
            }
            $href = (string)$hrefNodes[0];

            $etagNodes = $response->xpath('d:propstat/d:prop/d:getetag');
            $etag = !empty($etagNodes) ? trim((string)$etagNodes[0], '"') : '';

            $dataNodes = $response->xpath('d:propstat/d:prop/c:calendar-data');
            $data = !empty($dataNodes) ? (string)$dataNodes[0] : null;

            $events[] = [
                'uri' => $href,
                'etag' => $etag,
                'data' => $data,
            ];
        }

        return $events;
    }

    /**
     * Parse XML body safely.
     *
     * @param string $body
     * @return \SimpleXMLElement|null
     */
    private function parseXmlBody(string $body): ?\SimpleXMLElement
    {
        if (empty($body)) {
            return null;
        }

        // Register DAV namespace
        $body = str_replace('xmlns:d="DAV:"', 'xmlns:d="DAV:"', $body);

        try {
            $xml = new \SimpleXMLElement($body);
            $xml->registerXPathNamespace('d', 'DAV:');
            $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
            $xml->registerXPathNamespace('cs', 'http://calendarserver.org/ns/');
            $xml->registerXPathNamespace('apple', 'http://apple.com/ns/ical/');
            return $xml;
        } catch (\Exception $e) {
            Tools::log()->error('caldav-http-error', ['%error%' => 'XML parse error: ' . $e->getMessage()]);
            return null;
        }
    }

    /**
     * Extract ETag from response headers.
     *
     * @param array $response
     * @return string|null
     */
    private function extractEtag(array $response): ?string
    {
        $headers = $response['headers'] ?? [];
        $etag = $headers['etag'] ?? $headers['Etag'] ?? $headers['ETag'] ?? '';
        if (is_array($etag)) {
            $etag = reset($etag);
        }
        return trim((string)$etag, '" ');
    }

    /**
     * Check if a VEVENT in iCal data has RRULE (recurring event).
     *
     * @param string $iCalData Raw iCalendar data
     * @return bool
     */
    public static function hasRecurringRule(string $iCalData): bool
    {
        // Fast text search for RRULE before parsing
        if (stripos($iCalData, 'RRULE') === false) {
            return false;
        }

        try {
            $vCal = VObjectReader::read($iCalData);
            if (isset($vCal->VEVENT) && isset($vCal->VEVENT->RRULE)) {
                return true;
            }
        } catch (\Exception $e) {
            // Silent — malformed data
        }

        return false;
    }

    /**
     * Execute a request with exponential backoff on rate limiting (429).
     *
     * @param callable $requestFn
     * @return array
     * @throws \Exception
     */
    private function retryRequest(callable $requestFn): array
    {
        $retries = 0;

        do {
            $response = $requestFn();
            $statusCode = $response['statusCode'] ?? 0;

            if (429 !== $statusCode) {
                return $response;
            }

            $retries++;
            if ($retries >= self::MAX_RETRIES) {
                throw new \Exception('caldav-rate-limited');
            }

            $backoff = pow(2, $retries);
            Tools::log()->warning('caldav-rate-limited', [
                '%retry%' => $retries,
                '%backoff%' => $backoff,
            ]);
            sleep($backoff);
        } while (true);
    }
}
