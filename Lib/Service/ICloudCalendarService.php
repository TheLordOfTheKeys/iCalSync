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
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncAccount;

/**
 * iCloud-specific Calendar Service.
 *
 * Wraps CalDavClient with iCloud connection logic:
 * - Well-known URL discovery via https://caldav.icloud.com/
 * - Principal URL pattern: https://caldav.icloud.com/{dsid}/principal/
 * - Calendar home set discovery via PROPFIND
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ICloudCalendarService
{
    private const ICLOUD_CALDAV_URL = 'https://caldav.icloud.com/';

    /** @var ICalSyncAccount */
    private ICalSyncAccount $account;

    /** @var CalDavClient|null */
    private ?CalDavClient $client = null;

    /** @var string|null */
    private ?string $decryptedPassword = null;

    /**
     * @param ICalSyncAccount $account
     */
    public function __construct(ICalSyncAccount $account)
    {
        $this->account = $account;
    }

    /**
     * Test the iCloud connection by discovering the principal URL.
     *
     * @return array{success: bool, message: string, principal_url?: string}
     */
    public function testConnection(): array
    {
        $password = $this->account->getDecryptedPassword();
        if (null === $password) {
            return [
                'success' => false,
                'message' => 'credential-decrypt-error',
            ];
        }

        $tempClient = new CalDavClient(
            self::ICLOUD_CALDAV_URL,
            $this->account->apple_id,
            $password
        );

        // Try well-known discovery
        $principalUrl = $tempClient->discoverPrincipal(self::ICLOUD_CALDAV_URL);

        if (empty($principalUrl)) {
            // If well-known fails, use stored principal URL as fallback
            $principalUrl = $this->account->principal_url;
        }

        if (empty($principalUrl)) {
            return [
                'success' => false,
                'message' => 'test-connection-failure',
            ];
        }

        // Try to discover calendars
        $calendars = $tempClient->discoverCalendars($principalUrl);

        return [
            'success' => true,
            'message' => 'test-connection-success',
            'principal_url' => $principalUrl,
            'calendars' => $calendars,
        ];
    }

    /**
     * Discover calendars from the iCloud account.
     *
     * @return array
     */
    public function discoverCalendars(): array
    {
        $client = $this->getClient();
        if (null === $client) {
            return [];
        }

        return $client->discoverCalendars();
    }

    /**
     * Get or create the CalDavClient instance.
     *
     * @return CalDavClient|null
     */
    public function getClient(): ?CalDavClient
    {
        if (null !== $this->client) {
            return $this->client;
        }

        $password = $this->account->getDecryptedPassword();
        if (null === $password) {
            Tools::log()->error('credential-decrypt-error');
            return null;
        }

        $principalUrl = $this->account->principal_url;
        if (empty($principalUrl)) {
            $principalUrl = self::ICLOUD_CALDAV_URL;
        }

        $this->client = new CalDavClient(
            $principalUrl,
            $this->account->apple_id,
            $password
        );

        return $this->client;
    }
}
