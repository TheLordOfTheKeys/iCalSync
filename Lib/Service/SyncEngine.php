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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ICalSync\Lib\Util\ConflictResolutionStrategy;
use FacturaScripts\Plugins\ICalSync\Lib\Util\SyncNotifier;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncAccount;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncCalendar;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncItem;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncLog;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncUserAccount;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader as VObjectReader;

/**
 * Core synchronization engine.
 *
 * Orchestrates the sync between PlanetaEscenario entities (Evento, Cita)
 * and remote iCloud calendars via CalDAV.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class SyncEngine
{
    /** @var ICalSyncAccount */
    private ICalSyncAccount $account;

    /** @var ICloudCalendarService|null */
    private ?ICloudCalendarService $service = null;

    /** @var ConflictResolutionStrategy */
    private ConflictResolutionStrategy $conflictStrategy;

    /** @var array<int, array> Cache for discovered calendars (keyed by account id) */
    private static array $calendarDiscoveryCache = [];

    /** @var float Unix timestamp when this sync run started */
    private float $runStartedAt = 0.0;

    /** @var int Max execution time in seconds for a single run */
    private int $maxExecutionTime = 30;

    /** @var int Batch/chunk size for processing entities */
    private int $batchSize = 25;

    /** @var array Estadísticas de la ejecución */
    private array $stats = [
        'created' => 0,
        'updated' => 0,
        'deleted' => 0,
        'skipped' => 0,
        'errors' => 0,
        'conflicts' => 0,
    ];

    /**
     * @param ICalSyncAccount $account
     */
    public function __construct(ICalSyncAccount $account)
    {
        $this->account = $account;
        $this->conflictStrategy = $this->resolveDefaultStrategy();
        $this->batchSize = (int)Tools::settings('icalsync', 'batch_size', 25);
        $this->maxExecutionTime = (int)Tools::settings('icalsync', 'max_execution_time', 30);
    }

    /**
     * Set the conflict resolution strategy for this run.
     *
     * @param ConflictResolutionStrategy $strategy
     * @return self
     */
    public function setConflictStrategy(ConflictResolutionStrategy $strategy): self
    {
        $this->conflictStrategy = $strategy;
        return $this;
    }

    /**
     * Get the current conflict resolution strategy.
     *
     * @return ConflictResolutionStrategy
     */
    public function getConflictStrategy(): ConflictResolutionStrategy
    {
        return $this->conflictStrategy;
    }

    /**
     * Set max execution time for this run.
     *
     * @param int $seconds
     * @return self
     */
    public function setMaxExecutionTime(int $seconds): self
    {
        $this->maxExecutionTime = $seconds;
        return $this;
    }

    /**
     * Set batch size for chunked processing.
     *
     * @param int $size
     * @return self
     */
    public function setBatchSize(int $size): self
    {
        $this->batchSize = max(1, $size);
        return $this;
    }

    /**
     * Check if the current run has exceeded its time budget.
     *
     * @return bool
     */
    private function isTimeBudgetExceeded(): bool
    {
        if ($this->runStartedAt <= 0) {
            return false;
        }
        return (microtime(true) - $this->runStartedAt) >= $this->maxExecutionTime;
    }

    /**
     * Resolve the default conflict strategy from settings.
     *
     * @return ConflictResolutionStrategy
     */
    private function resolveDefaultStrategy(): ConflictResolutionStrategy
    {
        $setting = Tools::settings('icalsync', 'conflict_strategy', '');
        if (!empty($setting)) {
            foreach (ConflictResolutionStrategy::cases() as $case) {
                if ($case->value === $setting) {
                    return $case;
                }
            }
        }
        return ConflictResolutionStrategy::default();
    }

    /**
     * Run sync for all active calendars in this account.
     *
     * @return array Statistics
     */
    public function run(): array
    {
        $this->stats = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
            'conflicts' => 0,
        ];
        $this->runStartedAt = microtime(true);

        Tools::log()->debug('sync-starting', [
            '%account%' => $this->account->account_name,
        ]);

        $calendars = $this->loadActiveCalendarsCached();
        if (empty($calendars)) {
            Tools::log()->info('sync-no-calendars');
            return $this->stats;
        }

        $service = $this->getService();
        if (null === $service) {
            ICalSyncLog::logError('sync-starting', 'credential-decrypt-error');
            SyncNotifier::notifyAdmin(
                'Sync failed for account ' . ($this->account->account_name ?? 'unknown')
                    . ': credential decryption error',
                SyncNotifier::SEVERITY_ERROR
            );
            return $this->stats;
        }

        // Wrap calendar sync in a DB transaction for atomicity
        try {
            $this->withTransaction(function () use ($calendars, $service) {
                foreach ($calendars as $calendar) {
                    if ($this->isTimeBudgetExceeded()) {
                        Tools::log()->warning('sync-time-budget-exceeded', [
                            '%account%' => $this->account->account_name,
                        ]);
                        break;
                    }
                    $this->syncCalendar($calendar, $service);
                }
            });
        } catch (\Exception $e) {
            ICalSyncLog::logError('sync-error', $e->getMessage());
            SyncNotifier::notifyAdmin(
                'Sync transaction failed for account ' . ($this->account->account_name ?? 'unknown')
                    . ': ' . $e->getMessage(),
                SyncNotifier::SEVERITY_ERROR
            );
            $this->stats['errors']++;
        }


        Tools::log()->debug('sync-completed', [
            '%account%' => $this->account->account_name,
            '%stats%' => json_encode($this->stats),
        ]);

        return $this->stats;
    }

    /**
     * Run a dry-run sync (report what would happen without making changes).
     *
     * @return array Forecast statistics
     */
    public function preview(): array
    {
        $calendars = $this->loadActiveCalendars();
        $count = 0;

        foreach ($calendars as $calendar) {
            $where = [new DataBaseWhere('calendar_id', $calendar->id)];
            $items = (new ICalSyncItem())->all($where, [], 0, 0);

            $sourceModel = self::getSourceEntityFromCalendar($calendar);
            if ('Evento' === $sourceModel) {
                $count += $this->countUnsyncedEntities('Evento', $calendar);
            } elseif ('Cita' === $sourceModel) {
                $count += $this->countUnsyncedEntities('Cita', $calendar);
            }
        }

        return [
            'pending_sync' => $count,
        ];
    }

    // ──────────────────────────────────────────────
    //  PRIVATE CALENDAR SYNC (PER-USER)
    // ──────────────────────────────────────────────

    /**
     * Sync a user's private calendar (bidirectional Cita sync).
     *
     * @param ICalSyncUserAccount $userAccount
     * @return array Statistics
     */
    public function syncPrivateCalendar(ICalSyncUserAccount $userAccount): array
    {
        $this->stats = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
            'conflicts' => 0,
        ];
        $this->runStartedAt = microtime(true);

        if (empty($userAccount->apple_id) || empty($userAccount->calendar_url)) {
            Tools::log()->info('sync-no-calendars');
            return $this->stats;
        }

        $password = $userAccount->getDecryptedPassword();
        if (null === $password) {
            ICalSyncLog::logError('sync-private-starting', 'credential-decrypt-error');
            return $this->stats;
        }

        // Build CalDAV client directly for user's private calendar
        $principalUrl = !empty($userAccount->principal_url)
            ? $userAccount->principal_url
            : 'https://caldav.icloud.com/';

        $client = new CalDavClient($principalUrl, $userAccount->apple_id, $password);

        Tools::log()->debug('sync-starting', [
            '%account%' => $userAccount->nick,
        ]);

        // Step 1: Fetch remote changes from user's private calendar
        $remoteEvents = $client->listEvents($userAccount->calendar_url, $userAccount->last_sync_at);

        $citaClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';

        // Step 2: Import remote events that are not yet mapped
        foreach ($remoteEvents as $caldavEvent) {
            if (empty($caldavEvent['data'])) {
                continue;
            }

            try {
                $vCal = VObjectReader::read($caldavEvent['data']);
                $uid = (string)$vCal->VEVENT->UID;

                // Skip if already mapped
                $existing = ICalSyncItem::findByCaldavUid($uid);
                if (null !== $existing) {
                    // Check if update needed (etag changed)
                    if ($existing->caldav_etag !== $caldavEvent['etag']) {
                        $this->updateExistingCitaFromRemote($vCal, $existing, $userAccount, $caldavEvent['etag']);
                    }
                    continue;
                }

                // Skip if this event was exported from FS (has our UID prefix)
                if (strpos($uid, '@facturascripts.icalsync') !== false) {
                    continue;
                }

                // Import as new Cita
                $this->importPrivateToCita($vCal, $userAccount, $caldavEvent['etag']);
            } catch (\Exception $e) {
                ICalSyncLog::logError('cita-import-error', $e->getMessage());
                $this->stats['errors']++;
            }
        }

        // Step 3: Export local Citas modified since last sync
        $this->exportUserCitas($userAccount, $client);

        // Step 4: Clean up remote deletions
        $this->handleRemoteDeletions($remoteEvents, $userAccount);

        // Update last sync timestamp
        $userAccount->last_sync_at = date('Y-m-d H:i:s');
        $userAccount->save();


        Tools::log()->debug('sync-completed', [
            '%account%' => $userAccount->nick,
            '%stats%' => json_encode($this->stats),
        ]);

        return $this->stats;
    }

    /**
     * Export a single Cita to the user's private calendar.
     *
     * @param object $cita
     * @param ICalSyncUserAccount $userAccount
     * @return bool
     */
    public function exportCita(object $cita, ICalSyncUserAccount $userAccount): bool
    {
        if (empty($userAccount->calendar_url)) {
            return false;
        }

        $password = $userAccount->getDecryptedPassword();
        if (null === $password) {
            return false;
        }

        $principalUrl = !empty($userAccount->principal_url)
            ? $userAccount->principal_url
            : 'https://caldav.icloud.com/';

        $client = new CalDavClient($principalUrl, $userAccount->apple_id, $password);
        $vCal = $this->mapCitaToICal($cita);

        if (null === $vCal) {
            ICalSyncLog::logError('cita-export-error', 'Failed to build iCal for Cita #' . ($cita->id ?? 0));
            return false;
        }

        $iCalData = $vCal->serialize();
        $modelClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';

        // Check existing sync item
        $syncItem = ICalSyncItem::findByEntity('Cita', $cita->id ?? 0);

        if ($syncItem && $syncItem->caldav_uid) {
            // Update existing event
            $newEtag = $client->updateEvent(
                $userAccount->calendar_url,
                $syncItem->caldav_uid,
                $iCalData,
                $syncItem->caldav_etag
            );

            if ($newEtag) {
                $syncItem->caldav_etag = $newEtag;
                $syncItem->last_sync_at = date('Y-m-d H:i:s');
                $syncItem->sync_status = 'synced';
                $syncItem->save();
                $this->stats['updated']++;

                ICalSyncLog::logSuccess('cita-exported', 'Updated Cita #' . ($cita->id ?? 0) . ' in private calendar');
                return true;
            }

            ICalSyncLog::logError('cita-export-error', 'Failed to update Cita #' . ($cita->id ?? 0));
            return false;
        }

        // Create new event
        $result = $client->createEvent($userAccount->calendar_url, $iCalData);

        if ($result && !empty($result['uid'])) {
            $syncItem = new ICalSyncItem();
            $syncItem->entity_type = 'Cita';
            $syncItem->entity_id = $cita->id ?? 0;
            $syncItem->caldav_uid = $result['uid'];
            $syncItem->caldav_etag = $result['etag'] ?? '';
            $syncItem->direction = 'bidirectional';
            $syncItem->last_sync_at = date('Y-m-d H:i:s');
            $syncItem->sync_status = 'synced';
            $syncItem->save();
            $this->stats['created']++;

            ICalSyncLog::logSuccess('cita-exported', 'Created Cita #' . ($cita->id ?? 0) . ' in private calendar');
            return true;
        }

        ICalSyncLog::logError('cita-export-error', 'Failed to create Cita #' . ($cita->id ?? 0) . ' in private calendar');
        return false;
    }

    /**
     * Delete a Cita from the user's private calendar.
     *
     * @param int $citaId
     * @param ICalSyncUserAccount $userAccount
     * @return bool
     */
    public function deleteCitaFromPrivate(int $citaId, ICalSyncUserAccount $userAccount): bool
    {
        $syncItem = ICalSyncItem::findByEntity('Cita', $citaId);
        if (null === $syncItem || empty($syncItem->caldav_uid)) {
            return true; // Nothing to delete
        }

        $password = $userAccount->getDecryptedPassword();
        if (null === $password) {
            return false;
        }

        $principalUrl = !empty($userAccount->principal_url)
            ? $userAccount->principal_url
            : 'https://caldav.icloud.com/';

        $client = new CalDavClient($principalUrl, $userAccount->apple_id, $password);

        $deleted = $client->deleteEvent(
            $userAccount->calendar_url,
            $syncItem->caldav_uid,
            $syncItem->caldav_etag
        );

        if ($deleted) {
            $syncItem->delete();
            ICalSyncLog::logSuccess('event-deleted-from-icloud', 'Deleted Cita #' . $citaId . ' from private calendar');
            return true;
        }

        ICalSyncLog::logError('event-delete-error', 'Failed to delete Cita #' . $citaId . ' from private calendar');
        return false;
    }

    // ──────────────────────────────────────────────
    //  PRIVATE SYNC INTERNAL METHODS
    // ──────────────────────────────────────────────

    /**
     * Import a remote CalDAV event as a new Cita for the user.
     *
     * @param VCalendar $vCal
     * @param ICalSyncUserAccount $userAccount
     * @param string $etag
     * @return int|null Cita ID
     */
    private function importPrivateToCita(VCalendar $vCal, ICalSyncUserAccount $userAccount, string $etag): ?int
    {
        try {
            $citaClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';
            if (!class_exists($citaClass)) {
                return null;
            }

            $vEvent = $vCal->VEVENT;
            if (null === $vEvent) {
                return null;
            }

            // Skip recurring events (T3.2)
            if (self::isRecurringEvent($vEvent)) {
                ICalSyncLog::logSkipped('sync-recurring-skipped',
                    'Recurring VEVENT from iCloud skipped — recurring events not fully supported');
                $this->stats['skipped']++;
                return null;
            }

            $cita = new $citaClass();
            $cita->titulo = (string)($vEvent->SUMMARY ?? 'iCloud Event');
            $cita->descripcion = (string)($vEvent->DESCRIPTION ?? '');
            $cita->observaciones = (string)($vEvent->DESCRIPTION ?? '');
            $cita->idresponsable = $userAccount->nick;
            $cita->tipo = 'reunion';
            $cita->ubicacion = (string)($vEvent->LOCATION ?? '');

            // Parse dates
            $dtStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : new \DateTime();
            $dtEnd = $vEvent->DTEND ? $vEvent->DTEND->getDateTime() : clone $dtStart;

            $cita->fecha_inicio = $dtStart->format('Y-m-d H:i:s');
            $cita->fecha_fin = $dtEnd->format('Y-m-d H:i:s');

            // Check if all-day event
            $isAllDay = $vEvent->DTSTART && 'DATE' === $vEvent->DTSTART['VALUE'];
            $cita->todo_el_dia = $isAllDay;

            if ($cita->save()) {
                // Create sync item
                $uid = (string)$vEvent->UID;
                $syncItem = new ICalSyncItem();
                $syncItem->entity_type = 'Cita';
                $syncItem->entity_id = $cita->id;
                $syncItem->caldav_uid = $uid;
                $syncItem->caldav_etag = $etag;
                $syncItem->direction = 'bidirectional';
                $syncItem->last_sync_at = date('Y-m-d H:i:s');
                $syncItem->sync_status = 'synced';
                $syncItem->save();

                $this->stats['created']++;
                ICalSyncLog::logSuccess('cita-imported', 'Imported Cita #' . $cita->id . ' from iCloud');

                return $cita->id;
            }

            ICalSyncLog::logError('cita-import-error', 'Failed to save imported Cita');
            $this->stats['errors']++;
        } catch (\Exception $e) {
            ICalSyncLog::logError('cita-import-error', $e->getMessage());
            $this->stats['errors']++;
        }

        return null;
    }

    /**
     * Update an existing Cita from remote changes.
     *
     * @param VCalendar $vCal
     * @param ICalSyncItem $syncItem
     * @param ICalSyncUserAccount $userAccount
     */
    private function updateExistingCitaFromRemote(VCalendar $vCal, ICalSyncItem $syncItem, ICalSyncUserAccount $userAccount, string $newEtag = ''): void
    {
        try {
            $citaClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';
            if (!class_exists($citaClass)) {
                return;
            }

            $cita = new $citaClass();
            if (false === $cita->loadFromCode($syncItem->entity_id)) {
                return;
            }

            $vEvent = $vCal->VEVENT;
            if (null === $vEvent) {
                return;
            }

            // Check for recurring events (T3.2)
            if (self::isRecurringEvent($vEvent)) {
                ICalSyncLog::logSkipped('sync-recurring-skipped',
                    'Recurring VEVENT update skipped — recurring events not fully supported');
                $this->stats['skipped']++;
                return;
            }

            // Use configured conflict strategy (T3.1)
            $remoteLastMod = $vEvent->{'LAST-MODIFIED'};
            $remoteTime = $remoteLastMod ? $remoteLastMod->getDateTime()->getTimestamp() : 0;
            $localTime = strtotime($cita->fechamod ?? 'now');

            // Only check for conflict if both were modified after last sync
            $lastSyncTime = strtotime($syncItem->last_sync_at ?? '1970-01-01');
            if ($localTime > $lastSyncTime && $remoteTime > $lastSyncTime) {
                $resolution = $this->resolveConflict(
                    $localTime,
                    $remoteTime,
                    'Cita',
                    $cita->id ?? 0,
                    [
                        'caldav_uid' => $syncItem->caldav_uid,
                        'user_account' => $userAccount->nick,
                    ]
                );

                if (null === $resolution) {
                    // Manual resolution deferred
                    return;
                }

                if (false === $resolution) {
                    // Local wins — skip update
                    $syncItem->last_sync_at = date('Y-m-d H:i:s');
                    $syncItem->save();
                    return;
                }
                // Source wins — proceed
            } elseif ($remoteTime <= $localTime) {
                return; // Local is newer or equal, skip
            }

            $cita->titulo = (string)($vEvent->SUMMARY ?? $cita->titulo);
            $cita->descripcion = (string)($vEvent->DESCRIPTION ?? $cita->descripcion);
            $cita->ubicacion = (string)($vEvent->LOCATION ?? $cita->ubicacion);

            $dtStart = $vEvent->DTSTART ? $vEvent->DTSTART->getDateTime() : null;
            $dtEnd = $vEvent->DTEND ? $vEvent->DTEND->getDateTime() : null;

            if ($dtStart) {
                $cita->fecha_inicio = $dtStart->format('Y-m-d H:i:s');
            }
            if ($dtEnd) {
                $cita->fecha_fin = $dtEnd->format('Y-m-d H:i:s');
            }

            if ($cita->save()) {
                $syncItem->caldav_etag = $newEtag ?: $syncItem->caldav_etag;
                $syncItem->last_sync_at = date('Y-m-d H:i:s');
                $syncItem->sync_status = 'synced';
                $syncItem->save();
                $this->stats['updated']++;
            }
        } catch (\Exception $e) {
            ICalSyncLog::logError('cita-sync-error', $e->getMessage());
            $this->stats['errors']++;
        }
    }

    /**
     * Export user's Citas modified since last sync to private calendar.
     *
     * @param ICalSyncUserAccount $userAccount
     * @param CalDavClient $client
     */
    private function exportUserCitas(ICalSyncUserAccount $userAccount, CalDavClient $client): void
    {
        try {
            $citaClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';
            if (!class_exists($citaClass)) {
                return;
            }

            $model = new $citaClass();

            // Load Citas owned by this user — use cursor-based pagination (T3.4)
            $where = [
                new DataBaseWhere('idresponsable', $userAccount->nick),
            ];

            $offset = 0;
            do {
                if ($this->isTimeBudgetExceeded()) {
                    Tools::log()->warning('sync-time-budget-exceeded', [
                        '%account%' => $userAccount->nick,
                    ]);
                    break;
                }

                $allCitas = $model->all($where, ['fechamod' => 'ASC'], $this->batchSize, $offset);
                if (empty($allCitas)) {
                    break;
                }

                foreach ($allCitas as $cita) {
                    if ($this->isTimeBudgetExceeded()) {
                        break 2;
                    }

                    // Check for recurring events (T3.2)
                    if (self::entityIsRecurring($cita)) {
                        ICalSyncLog::logSkipped('sync-recurring-skipped',
                            'Recurring Cita #' . ($cita->id ?? 0) . ' skipped — recurring events not fully supported');
                        $this->stats['skipped']++;
                        continue;
                    }

                    $citaId = $cita->id ?? 0;
                    if ($citaId <= 0) {
                        continue;
                    }

                    // Check if already synced
                    $syncItem = ICalSyncItem::findByEntity('Cita', $citaId);

                    if ($syncItem) {
                        // Only update if local is newer than last sync
                        $citaModTime = strtotime($cita->fechamod ?? 'now');
                        $lastSyncTime = strtotime($syncItem->last_sync_at ?? '1970-01-01');

                        if ($citaModTime <= $lastSyncTime) {
                            continue; // Already up to date
                        }
                    }

                    $vCal = $this->mapCitaToICal($cita);
                    if (null === $vCal) {
                        $this->stats['skipped']++;
                        continue;
                    }

                $iCalData = $vCal->serialize();

                if ($syncItem && $syncItem->caldav_uid) {
                    $newEtag = $client->updateEvent(
                        $userAccount->calendar_url,
                        $syncItem->caldav_uid,
                        $iCalData,
                        $syncItem->caldav_etag
                    );

                    if ($newEtag) {
                        $syncItem->caldav_etag = $newEtag;
                        $syncItem->last_sync_at = date('Y-m-d H:i:s');
                        $syncItem->sync_status = 'synced';
                        $syncItem->save();
                        $this->stats['updated']++;
                    } else {
                        $this->stats['errors']++;
                    }
                } else {
                    $result = $client->createEvent($userAccount->calendar_url, $iCalData);

                    if ($result && !empty($result['uid'])) {
                if (!$syncItem) {
                            $syncItem = new ICalSyncItem();
                        }
                        $syncItem->entity_type = 'Cita';
                        $syncItem->entity_id = $citaId;
                        $syncItem->caldav_uid = $result['uid'];
                        $syncItem->caldav_etag = $result['etag'] ?? '';
                        $syncItem->direction = 'bidirectional';
                        $syncItem->last_sync_at = date('Y-m-d H:i:s');
                        $syncItem->sync_status = 'synced';
                        $syncItem->save();
                        $this->stats['created']++;
                    } else {
                        $this->stats['errors']++;
                    }
                }
            }

            $offset += $this->batchSize;
        } while (true);
        } catch (\Exception $e) {
            Tools::log()->error('sync-load-error', ['%error%' => $e->getMessage()]);
        }
    }

    /**
     * Handle remote deletions by checking which remote events are no longer present.
     *
     * @param array $remoteEvents
     * @param ICalSyncUserAccount $userAccount
     */
    private function handleRemoteDeletions(array $remoteEvents, ICalSyncUserAccount $userAccount): void
    {
        // Collect all remote UIDs from the current calendar query
        $remoteUids = [];
        foreach ($remoteEvents as $event) {
            if (!empty($event['data'])) {
                try {
                    $vCal = VObjectReader::read($event['data']);
                    $uid = (string)$vCal->VEVENT->UID;
                    $remoteUids[$uid] = true;
                } catch (\Exception $e) {
                    // Skip malformed events
                }
            }
        }

        // Find local sync items for this user's Citas that might have been deleted remotely
        try {
            $citaClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';
            if (!class_exists($citaClass)) {
                return;
            }

            // Get all sync items for Citas owned by this user
            $syncItemModel = new ICalSyncItem();
            $allUserCitaItems = $syncItemModel->all([
                new DataBaseWhere('entity_type', 'Cita'),
                new DataBaseWhere('direction', 'bidirectional'),
            ]);

            foreach ($allUserCitaItems as $item) {
                if (!empty($item->caldav_uid) && !isset($remoteUids[$item->caldav_uid])) {
                    // Remote event was deleted — optionally delete local Cita
                    // For safety, we just remove the sync item, keeping the local Cita
                    $item->delete();
                    $this->stats['deleted']++;
                }
            }
        } catch (\Exception $e) {
            Tools::log()->error('sync-load-error', ['%error%' => $e->getMessage()]);
        }
    }

    /**
     * Convert a Cita entity to a VCalendar object.
     *
     * @param object $cita
     * @return VCalendar|null
     */
    public function mapCitaToICal(object $cita): ?VCalendar
    {
        try {
            $vCal = new VCalendar();
            $vCal->VERSION = '2.0';
            $vCal->PRODID = '-//FacturaScripts//ICalSync 1.0//EN';

            $vEvent = $vCal->createComponent('VEVENT');
            $uid = 'Cita-' . ($cita->id ?? 0) . '@facturascripts.icalsync';
            $vEvent->UID = $uid;
            $vEvent->SUMMARY = 'CITA | ' . ($cita->titulo ?? 'Cita');

            // Build description
            $descParts = [];
            if (!empty($cita->descripcion)) {
                $descParts[] = $cita->descripcion;
            }
            if (!empty($cita->ubicacion)) {
                $descParts[] = Tools::lang()->trans('location') . ': ' . $cita->ubicacion;
            }
            if (!empty($cita->enlace_online)) {
                $descParts[] = Tools::lang()->trans('online-link') . ': ' . $cita->enlace_online;
            }
            if (!empty($cita->observaciones)) {
                $descParts[] = Tools::lang()->trans('observations') . ': ' . $cita->observaciones;
            }
            if (!empty($cita->idresponsable)) {
                $descParts[] = 'Responsable: ' . $cita->idresponsable;
            }

            if (!empty($descParts)) {
                $vEvent->DESCRIPTION = implode("\n", $descParts);
            }

            // Location
            if (!empty($cita->ubicacion)) {
                $vEvent->LOCATION = $cita->ubicacion;
            }

            // Dates
            try {
                if (!empty($cita->todo_el_dia) && $cita->todo_el_dia) {
                    $dtStart = new \DateTime($cita->fecha_inicio);
                    $vEvent->DTSTART = $dtStart;
                    $vEvent->DTSTART['VALUE'] = 'DATE';
                    $dtEnd = new \DateTime($cita->fecha_fin ?? $cita->fecha_inicio);
                    $dtEnd->modify('+1 day');
                    $vEvent->DTEND = $dtEnd;
                    $vEvent->DTEND['VALUE'] = 'DATE';
                } else {
                    $dtStart = new \DateTime($cita->fecha_inicio);
                    $dtEnd = new \DateTime($cita->fecha_fin ?? $cita->fecha_inicio);

                    if ($dtEnd <= $dtStart) {
                        $dtEnd = clone $dtStart;
                        $dtEnd->modify('+1 hour');
                    }

                    $vEvent->DTSTART = $dtStart;
                    $vEvent->DTSTART['VALUE'] = 'DATE-TIME';
                    $vEvent->DTEND = $dtEnd;
                    $vEvent->DTEND['VALUE'] = 'DATE-TIME';
                }
            } catch (\Exception $e) {
                Tools::log()->error('sync-cita-date-error', [
                    '%error%' => $e->getMessage(),
                    '%fecha_inicio%' => $cita->fecha_inicio ?? 'null',
                    '%fecha_fin%' => $cita->fecha_fin ?? 'null',
                ]);
                return null;
            }

            $vEvent->STATUS = 'CONFIRMED';

            // Add last-modified for conflict resolution
            if (!empty($cita->fechamod)) {
                $vEvent->{'LAST-MODIFIED'} = $cita->fechamod;
            }

            $vCal->add($vEvent);
            return $vCal;
        } catch (\Exception $e) {
            Tools::log()->error('sync-build-failed', ['%error%' => $e->getMessage()]);
            return null;
        }
    }

    // ──────────────────────────────────────────────
    //  SHARED CALENDAR SYNC (ORIGINAL METHODS)
    // ──────────────────────────────────────────────

    /**
     * Load all active calendars for this account.
     *
     * @return ICalSyncCalendar[]
     */
    private function loadActiveCalendars(): array
    {
        $calendarModel = new ICalSyncCalendar();
        $where = [
            new DataBaseWhere('account_id', $this->account->id),
        ];
        $calendars = $calendarModel->all($where, ['id' => 'ASC']);

        return array_filter($calendars, function (ICalSyncCalendar $cal) {
            return $cal->enabled;
        });
    }

    /**
     * Sync a single calendar.
     *
     * @param ICalSyncCalendar $calendar
     * @param ICloudCalendarService $service
     */
    private function syncCalendar(ICalSyncCalendar $calendar, ICloudCalendarService $service): void
    {
        Tools::log()->debug('syncing-calendar', [
            '%calendar%' => $calendar->calendar_name,
        ]);

        $sourceEntity = self::getSourceEntityFromCalendar($calendar);
        if ('Evento' === $sourceEntity) {
            // Import FIRST so iCloud changes are in FS before export checks fechamod
            static $importDone = false;
            if (!$importDone) {
                $this->importFromSharedCalendar($calendar, $service);
                $importDone = true;
            }

            // Clean up orphan sync items (entities deleted from FS)
            static $cleanupDone = false;
            if (!$cleanupDone) {
                $this->cleanupOrphanSyncItems($calendar, $service);
                $cleanupDone = true;
            }

            // Export orphan Citas to shared calendar
            static $citasExportDone = false;
            if (!$citasExportDone) {
                $this->syncOrphanCitas($calendar, $service);
                $citasExportDone = true;
            }

            // Export Eventos LAST
            static $eventosSynced = false;
            if (!$eventosSynced) {
                $this->syncEventos($calendar, $service);
                $this->syncOportunidades($calendar, $service);
                $eventosSynced = true;
            }
        } elseif ('Cita' === $sourceEntity) {
            $this->syncCitas($calendar, $service);
        } else {
            Tools::log()->warning('sync-unknown-entity', [
                '%entity%' => $sourceEntity,
            ]);
        }
    }

    /**
     * Sync Evento entities to iCloud calendar.
     *
     * @param ICalSyncCalendar $calendar
     * @param ICloudCalendarService $service
     */
    private function syncEventos(ICalSyncCalendar $calendar, ICloudCalendarService $service): void
    {
        $eventos = $this->loadPlanetaEventos(self::getSourceEntityFromCalendar($calendar));

        foreach ($eventos as $evento) {
            $this->syncEntity(
                $calendar,
                $service,
                $evento,
                'Evento',
                $evento->idevento ?? $evento->id ?? 0
            );
        }
    }

    /**
     * Sync Cita entities to iCloud calendar.
     *
     * @param ICalSyncCalendar $calendar
     * @param ICloudCalendarService $service
     */
    private function syncCitas(ICalSyncCalendar $calendar, ICloudCalendarService $service): void
    {
        $citas = $this->loadPlanetaCitas($calendar);

        foreach ($citas as $cita) {
            $this->syncEntity(
                $calendar,
                $service,
                $cita,
                'Cita',
                $cita->id ?? 0
            );
        }
    }

    /**
     * Delete CalDAV events for sync items whose FS entity no longer exists.
     */
    private function cleanupOrphanSyncItems(ICalSyncCalendar $calendar, ICloudCalendarService $service): void
    {
        $client = $service->getClient();
        if (null === $client) {
            return;
        }

        $tables = [
            'Evento' => ['table' => 'eventos', 'pk' => 'idevento', 'class' => 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Evento'],
            'Cita' => ['table' => 'citas', 'pk' => 'id', 'class' => 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita'],
            'Oportunidad' => ['table' => 'crm_oportunidades', 'pk' => 'id', 'class' => 'FacturaScripts\\Plugins\\CRM\\Model\\CrmOportunidad'],
        ];

        foreach ($tables as $entityType => $info) {
            if (!class_exists($info['class'])) {
                continue;
            }

            $items = (new ICalSyncItem())->all([
                new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('entity_type', $entityType),
                new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('direction', 'export'),
            ], [], 0, 0);

            foreach ($items as $item) {
                if (empty($item->caldav_uid)) {
                    continue;
                }

                // Check if entity still exists
                $model = new $info['class']();
                if ($model->loadFromCode($item->entity_id)) {
                    continue; // Still exists
                }

                // Entity deleted — remove from iCloud
                $deleted = $client->deleteEvent($calendar->calendar_url, $item->caldav_uid, $item->caldav_etag);
                if ($deleted || null === $deleted) {
                    // null means 404 (already deleted)
                    $item->delete();
                    $this->stats['deleted']++;
                }
            }
        }
    }

    /**
     * Sync Citas without owner (idresponsable IS NULL) to the shared calendar.
     *
     * @param ICalSyncCalendar $calendar
     * @param ICloudCalendarService $service
     */
    private function syncOrphanCitas(ICalSyncCalendar $calendar, ICloudCalendarService $service): void
    {
        try {
            $citaClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';
            if (!class_exists($citaClass)) {
                return;
            }

            $model = new $citaClass();
            $citas = $model->all([], ['id' => 'ASC'], 0, 0);

            $client = $service->getClient();
            if (null === $client) {
                return;
            }

            $synced = 0;
            foreach ($citas as $cita) {
                // Only sync citas WITHOUT an owner to the shared calendar
                if (!empty($cita->idresponsable)) {
                    continue;
                }

                if ($this->isTimeBudgetExceeded() || $synced >= $this->batchSize) {
                    break;
                }

                $syncItem = ICalSyncItem::findByEntity('Cita', $cita->id);
                if ($syncItem && 'synced' === $syncItem->sync_status) {
                    // Already synced — skip unless modified
                    $localMod = strtotime($cita->fechamod ?? '1970-01-01');
                    $lastSync = strtotime($syncItem->last_sync_at ?? '1970-01-01');
                    if ($localMod <= $lastSync) {
                        continue;
                    }
                }

                // Build iCal data from Cita
                $vCal = $this->mapCitaToICal($cita);
                if (null === $vCal) {
                    $this->stats['skipped']++;
                    continue;
                }

                $iCalData = $vCal->serialize();

                if ($syncItem && $syncItem->caldav_uid) {
                    // Update existing event in iCloud
                    $client->updateEvent($calendar->calendar_url, $syncItem->caldav_uid, $iCalData, $syncItem->caldav_etag ?? '*');
                    $syncItem->last_sync_at = date('Y-m-d H:i:s');
                    $syncItem->sync_status = 'synced';
                    $syncItem->save();
                    $this->stats['updated']++;
                } else {
                    // Create new event in iCloud
                    $result = $client->createEvent($calendar->calendar_url, $iCalData);
                    if (null !== $result && !empty($result['uid'])) {
                        if (!$syncItem) {
                            $syncItem = new ICalSyncItem();
                            $syncItem->entity_type = 'Cita';
                            $syncItem->entity_id = $cita->id;
                        }
                        $syncItem->caldav_uid = $result['uid'] ?? '';
                        $syncItem->caldav_etag = $result['etag'] ?? '';
                        $syncItem->direction = 'export';
                        $syncItem->last_sync_at = date('Y-m-d H:i:s');
                        $syncItem->sync_status = 'synced';
                        $syncItem->calendar_id = $calendar->id;
                        $syncItem->save();
                        $this->stats['created']++;
                    } else {
                        ICalSyncLog::logError('sync-create-error', 'Cita #' . $cita->id);
                        $this->stats['errors']++;
                    }
                }
                $synced++;
            }

            if ($synced > 0) {
            }
        } catch (\Exception $e) {
            Tools::log()->error('sync-orphan-citas-error', ['%error%' => $e->getMessage()]);
        }
    }

    /**
     * Import events from shared iCloud calendar as Citas in FS.
     *
     * @param ICalSyncCalendar $calendar
     * @param ICloudCalendarService $service
     */
    private function importFromSharedCalendar(ICalSyncCalendar $calendar, ICloudCalendarService $service): void
    {
        try {
            $client = $service->getClient();
            if (null === $client) {
                return;
            }

            $remoteEvents = $client->listEvents($calendar->calendar_url);
            $imported = 0;

            foreach ($remoteEvents as $caldavEvent) {
                if ($this->isTimeBudgetExceeded() || $imported >= $this->batchSize) {
                    break;
                }

                if (empty($caldavEvent['data'])) {
                    continue;
                }

                try {
                    $vCal = VObjectReader::read($caldavEvent['data']);
                    if (!isset($vCal->VEVENT)) {
                        continue;
                    }

                    $vevent = $vCal->VEVENT;

                    // Extract the REAL UID from the iCal data
                    $uid = (string)$vevent->UID;
                    if (empty($uid)) {
                        continue;
                    }

                    // Check if already imported
                    $syncItem = ICalSyncItem::findByCaldavUid($uid);
                    if ($syncItem) {
                        $newEtag = $caldavEvent['etag'] ?? '';
                        $oldEtag = $syncItem->caldav_etag ?? '';
                        if ($newEtag && $newEtag === $oldEtag) {
                            continue;
                        }

                        // Branch by entity type
                        if ('Evento' === $syncItem->entity_type) {
                            $this->updateEventoFromRemote($vevent, $syncItem->entity_id);
                        } elseif ('Oportunidad' === $syncItem->entity_type) {
                            $this->updateOportunidadFromRemote($vevent, $syncItem->entity_id);
                        } else {
                            // Don't update Citas that have been assigned to a user
                            $citaClass2 = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';
                            if (class_exists($citaClass2)) {
                                $existingCita = new $citaClass2();
                                if ($existingCita->loadFromCode($syncItem->entity_id) && !empty($existingCita->idresponsable)) {
                                    continue;
                                }
                            }
                            $this->updateCitaFromRemote($vevent, $syncItem->entity_id);
                        }
                        $syncItem->caldav_etag = $newEtag;
                        $syncItem->last_sync_at = date('Y-m-d H:i:s');
                        $syncItem->save();
                        $this->stats['updated']++;
                        $imported++;
                        continue;
                    }


                    // Create new Cita from iCal event — skip our own exports
                    if (str_contains($uid, '@facturascripts.icalsync')) {
                        continue;
                    }

                    $citaData = $this->mapVEventToCitaData($vevent);
                    if (null === $citaData) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    $citaClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';
                    if (!class_exists($citaClass)) {
                        continue;
                    }

                    $cita = new $citaClass();
                    foreach ($citaData as $field => $value) {
                        $cita->{$field} = $value;
                    }

                    if ($cita->save()) {
                        $item = new ICalSyncItem();
                        $item->entity_type = 'Cita';
                        $item->entity_id = $cita->id;
                        $item->calendar_id = $calendar->id;
                        $item->caldav_uid = $uid;
                        $item->caldav_etag = $caldavEvent['etag'] ?? '';
                        $item->direction = 'import';
                        $item->last_sync_at = date('Y-m-d H:i:s');
                        $item->sync_status = 'synced';
                        $item->save();

                        $this->stats['created']++;
                        $imported++;
                    } else {
                        $this->stats['errors']++;
                    }
                } catch (\Exception $e) {
                    ICalSyncLog::logError('shared-import-error', $e->getMessage());
                    $this->stats['errors']++;
                }
            }

            if ($imported > 0) {
            }
        } catch (\Exception $e) {
            Tools::log()->error('sync-import-error', ['%error%' => $e->getMessage()]);
        }
    }

    /**
     * Map a VEVENT from iCal to Cita model data array.
     */
    private function mapVEventToCitaData(\Sabre\VObject\Component\VEvent $vevent): ?array
    {
        try {
            $dtStart = $vevent->DTSTART ? $vevent->DTSTART->getDateTime() : new \DateTime();
            $dtEnd = $vevent->DTEND ? $vevent->DTEND->getDateTime() : (clone $dtStart)->modify('+1 hour');

            return [
                'titulo' => (string)($vevent->SUMMARY ?? 'Sin título'),
                'descripcion' => (string)($vevent->DESCRIPTION ?? ''),
                'fecha_inicio' => $dtStart->format('Y-m-d H:i:s'),
                'fecha_fin' => $dtEnd->format('Y-m-d H:i:s'),
                'ubicacion' => (string)($vevent->LOCATION ?? ''),
                'todo_el_dia' => $vevent->DTSTART && 'DATE' === ($vevent->DTSTART['VALUE'] ?? '') ? 1 : 0,
                'prioridad' => 'normal',
                'fecha_creacion' => date('Y-m-d'),
            ];
        } catch (\Exception $e) {
            Tools::log()->error('sync-vevent-map-error', ['%error%' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Update an existing Oportunidad from a remote VEVENT.
     */
    private function updateOportunidadFromRemote(\Sabre\VObject\Component\VEvent $vevent, int $id): void
    {
        try {
            $class = 'FacturaScripts\\Plugins\\CRM\\Model\\CrmOportunidad';
            if (!class_exists($class)) {
                return;
            }
            $op = new $class();
            if (!$op->loadFromCode($id)) {
                return;
            }
            $op->descripcion = (string)($vevent->SUMMARY ?? $op->descripcion);
            $op->observaciones = (string)($vevent->DESCRIPTION ?? $op->observaciones);
            if ($vevent->DTSTART) {
                $dt = $vevent->DTSTART->getDateTime();
                $op->fecha = $dt->format('Y-m-d');
                $op->hora = $dt->format('H:i');
            }
            $op->save();
        } catch (\Exception $e) {
            // Silent
        }
    }

    /**
     * Update an existing Evento from a remote VEVENT.
     */
    private function updateEventoFromRemote(\Sabre\VObject\Component\VEvent $vevent, int $eventoId): void
    {
        try {
            $eventoClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Evento';
            if (!class_exists($eventoClass)) {
                return;
            }

            $evento = new $eventoClass();
            if (!$evento->loadFromCode($eventoId)) {
                return;
            }

            $evento->nombre = (string)($vevent->SUMMARY ?? $evento->nombre);
            $evento->observaciones = (string)($vevent->DESCRIPTION ?? $evento->observaciones);
            $evento->ciudad = (string)($vevent->LOCATION ?? $evento->ciudad);

            if ($vevent->DTSTART) {
                $dt = $vevent->DTSTART->getDateTime();
                $evento->fecha = $dt->format('d-m-Y');
                $evento->hora = $dt->format('H:i');
            }

            $evento->save();
        } catch (\Exception $e) {
            // Silent
        }
    }

    /**
     * Update an existing Cita from a remote VEVENT.
     */
    private function updateCitaFromRemote(\Sabre\VObject\Component\VEvent $vevent, int $citaId): void
    {
        try {
            $citaClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';
            if (!class_exists($citaClass)) {
                return;
            }

            $cita = new $citaClass();
            if (!$cita->loadFromCode($citaId)) {
                return;
            }

            $dtStart = $vevent->DTSTART ? $vevent->DTSTART->getDateTime() : null;
            $dtEnd = $vevent->DTEND ? $vevent->DTEND->getDateTime() : null;

            if ($dtStart) {
                $cita->fecha_inicio = $dtStart->format('Y-m-d H:i:s');
            }
            if ($dtEnd) {
                $cita->fecha_fin = $dtEnd->format('Y-m-d H:i:s');
            }

            $cita->titulo = (string)($vevent->SUMMARY ?? $cita->titulo);
            $cita->descripcion = (string)($vevent->DESCRIPTION ?? $cita->descripcion);
            $cita->ubicacion = (string)($vevent->LOCATION ?? $cita->ubicacion);

            if ($vevent->DTSTART) {
                $cita->todo_el_dia = ('DATE' === ($vevent->DTSTART['VALUE'] ?? '')) ? 1 : 0;
            }

            $cita->save();
        } catch (\Exception $e) {
            // Silent
        }
    }

    /**
     * Sync Oportunidad entities to iCloud calendar (same pattern as Eventos).
     */
    private function syncOportunidades(ICalSyncCalendar $calendar, ICloudCalendarService $service): void
    {
        $class = 'FacturaScripts\\Plugins\\CRM\\Model\\CrmOportunidad';
        if (!class_exists($class)) {
            return;
        }

        $model = new $class();
        $oportunidades = $model->all([], ['fecha' => 'ASC'], 0, 0);

        foreach ($oportunidades as $op) {
            $entityId = $op->id ?? 0;
            if ($entityId <= 0) {
                continue;
            }

            // Skip if this Oportunidad is already linked to an Evento
            $db = new \FacturaScripts\Core\Base\DataBase();
            $check = $db->select('SELECT idevento FROM eventos WHERE idoportunidad = ' . (int)$entityId . ' LIMIT 1');
            if (!empty($check)) {
                // If previously synced, delete from iCloud
                $expectedUid2 = 'Oportunidad-' . $entityId . '@facturascripts.icalsync';
                $oldItem = ICalSyncItem::findByEntity('Oportunidad', $entityId)
                    ?? ICalSyncItem::findByCaldavUid($expectedUid2);
                if ($oldItem && $oldItem->caldav_uid) {
                    $client = $service->getClient();
                    if ($client) {
                        $client->deleteEvent($calendar->calendar_url, $oldItem->caldav_uid, $oldItem->caldav_etag);
                    }
                    $oldItem->delete();
                    $this->stats['deleted']++;
                }
                continue;
            }

            $expectedUid = 'Oportunidad-' . $entityId . '@facturascripts.icalsync';
            $syncItem = ICalSyncItem::findByEntity('Oportunidad', $entityId)
                ?? ICalSyncItem::findByCaldavUid($expectedUid);
            if ($syncItem && 'synced' === $syncItem->sync_status) {
                $entityMod = strtotime($op->fechamod ?? '1970-01-01');
                $lastSync = strtotime($syncItem->last_sync_at ?? '1970-01-01');
                if ($entityMod <= $lastSync) {
                    continue;
                }
            }

            $vCal = $this->buildOportunidadVCalendar($op);
            if (null === $vCal) {
                $this->stats['skipped']++;
                continue;
            }

            $client = $service->getClient();
            if (null === $client) {
                $this->stats['errors']++;
                continue;
            }

            $iCalData = $vCal->serialize();

            if ($syncItem && $syncItem->caldav_uid) {
                $newEtag = $client->updateEvent($calendar->calendar_url, $syncItem->caldav_uid, $iCalData, $syncItem->caldav_etag);
                if ($newEtag) {
                    $syncItem->caldav_etag = $newEtag;
                    $syncItem->last_sync_at = date('Y-m-d H:i:s');
                    $syncItem->save();
                    $this->stats['updated']++;
                }
                continue;
            }

            $result = $client->createEvent($calendar->calendar_url, $iCalData);
            if ($result && !empty($result['uid'])) {
                if (!$syncItem) {
                    $syncItem = new ICalSyncItem();
                    $syncItem->entity_type = 'Oportunidad';
                    $syncItem->entity_id = $entityId;
                }
                $syncItem->calendar_id = $calendar->id;
                $syncItem->caldav_uid = $result['uid'];
                $syncItem->caldav_etag = $result['etag'] ?? '';
                $syncItem->direction = 'export';
                $syncItem->last_sync_at = date('Y-m-d H:i:s');
                $syncItem->sync_status = 'synced';
                try {
                    $syncItem->save();
                    $this->stats['created']++;
                } catch (\Exception $e) {
                    $existing = ICalSyncItem::findByEntity('Oportunidad', $entityId)
                        ?? ICalSyncItem::findByCaldavUid($result['uid']);
                    if ($existing) {
                        $existing->entity_type = 'Oportunidad';
                        $existing->entity_id = $entityId;
                        $existing->caldav_uid = $result['uid'];
                        $existing->caldav_etag = $result['etag'] ?? '';
                        $existing->last_sync_at = date('Y-m-d H:i:s');
                        $existing->sync_status = 'synced';
                        $existing->save();
                    }
                }
            }
        }
    }

    /**
     * Build VCalendar from an Oportunidad entity.
     */
    private function buildOportunidadVCalendar(object $op): ?VCalendar
    {
        try {
            $vCal = new VCalendar();
            $vCal->add('VEVENT', [
                'SUMMARY' => 'LEAD | ' . ($op->descripcion ?? 'Oportunidad'),
                'DESCRIPTION' => $op->observaciones ?? '',
                'UID' => 'Oportunidad-' . $op->id . '@facturascripts.icalsync',
            ]);

            $fecha = $op->fecha_evento ?? $op->fecha ?? date('Y-m-d');
            $hora = $op->hora ?? '00:00';
            if (strlen($hora) > 8 && str_contains($hora, ' ')) {
                $hora = substr($hora, strrpos($hora, ' ') + 1);
            }

            $dtStart = new \DateTime($fecha . ' ' . $hora);
            $vCal->VEVENT->DTSTART = $dtStart;
            $dtEnd = clone $dtStart;
            $dtEnd->modify('+1 hour');
            $vCal->VEVENT->DTEND = $dtEnd;

            if (!empty($op->fechamod)) {
                $vCal->VEVENT->{'LAST-MODIFIED'} = $op->fechamod;
            }

            return $vCal;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sync a single entity (Evento or Cita) to the remote calendar.
     *
     * @param ICalSyncCalendar $calendar
     * @param ICloudCalendarService $service
     * @param object $entity
     * @param string $modelClass
     * @param int $entityId
     */
    private function syncEntity(
        ICalSyncCalendar $calendar,
        ICloudCalendarService $service,
        object $entity,
        string $modelClass,
        int $entityId
    ): void {

        if ($entityId <= 0) {
            return;
        }

        // Check for recurring events (T3.2)
        if (self::entityIsRecurring($entity)) {
            ICalSyncLog::logSkipped('sync-recurring-skipped',
                'Recurring ' . self::getSourceEntityFromCalendar($calendar) . ' #' . $entityId
                    . ' — recurring events not fully supported');
            $this->stats['skipped']++;
            return;
        }

        $syncItem = ICalSyncItem::findByEntity($modelClass, $entityId);
        $vCal = $this->buildVCalendar($entity, $calendar);

        if (null === $vCal) {
            ICalSyncLog::logSkipped('sync-build-failed', 'Entity #' . $entityId);
            $this->stats['skipped']++;
            return;
        }

        $client = $service->getClient();
        if (null === $client) {
            $this->stats['errors']++;
            return;
        }

        $iCalData = $vCal->serialize();

        if ($syncItem && $syncItem->caldav_uid) {
            // Skip if entity hasn't changed since last sync
            $entityMod = strtotime($entity->fechamod ?? '1970-01-01');
            $lastSync = strtotime($syncItem->last_sync_at ?? '1970-01-01');
            if ($entityMod <= $lastSync) {
                return; // No changes
            }

            // Already synced — update via CalDAV with ETag
            $newEtag = $client->updateEvent(
                $calendar->calendar_url,
                $syncItem->caldav_uid,
                $iCalData,
                $syncItem->caldav_etag
            );

            if ($newEtag) {
                $syncItem->caldav_etag = $newEtag;
                $syncItem->last_sync_at = date('Y-m-d H:i:s');
                $syncItem->sync_status = 'synced';
                $syncItem->save();
                $this->stats['updated']++;
            } else {
                // ETag conflict — skip this round, cron will retry
                ICalSyncLog::logSkipped('sync-etag-conflict', 'Entity #' . $entityId);
                $this->stats['skipped']++;
            }
        } else {
            $result = $client->createEvent($calendar->calendar_url, $iCalData);

            if ($result && !empty($result['uid'])) {
                if (!$syncItem) {
                    $syncItem = new ICalSyncItem();
                }
                $syncItem->calendar_id = $calendar->id;
                $syncItem->entity_type = self::getSourceEntityFromCalendar($calendar);
                $syncItem->entity_id = $entityId;
                $syncItem->caldav_uid = $result['uid'];
                $syncItem->caldav_etag = $result['etag'] ?? '';
                $syncItem->last_sync_at = date('Y-m-d H:i:s');
                $syncItem->sync_status = 'synced';
                $syncItem->direction = 'export';
                try {
                    $syncItem->save();
                    $this->stats['created']++;
                } catch (\Exception $e) {
                    // Duplicate — reload existing and update instead
                    $existing = ICalSyncItem::findByEntity(self::getSourceEntityFromCalendar($calendar), $entityId);
                    if ($existing) {
                        $existing->caldav_uid = $result['uid'];
                        $existing->caldav_etag = $result['etag'] ?? '';
                        $existing->last_sync_at = date('Y-m-d H:i:s');
                        $existing->sync_status = 'synced';
                        $existing->save();
                        $this->stats['updated']++;
                    }
                }
            } else {
                ICalSyncLog::logError('sync-create-error', 'Entity #' . $entityId);
                $this->stats['errors']++;
            }
        }
    }

    /**
     * Find the sync item for a given entity, or create a new one.
     *
     * @param int $calendarId
     * @param string $modelClass
     * @param int $entityId
     * @return ICalSyncItem|false
     */
    private function findSyncItem(int $calendarId, string $modelClass, int $entityId)
    {
        // Find by entity type and ID (calendar_id may differ across runs)
        $where = [
            new DataBaseWhere('entity_type', $modelClass),
            new DataBaseWhere('entity_id', $entityId),
        ];

        $items = (new ICalSyncItem())->all($where, ['id' => 'DESC'], 1, 0);
        if (!empty($items)) {
            return $items[0];
        }

        return false;
    }

    /**
     * Build a VCalendar from a PlanetaEscenario entity.
     *
     * @param object $entity
     * @param ICalSyncCalendar $calendar
     * @return VCalendar|null
     */
    private function buildVCalendar(object $entity, ICalSyncCalendar $calendar): ?VCalendar
    {
        try {
            $vCal = new VCalendar();
            $vCal->VERSION = '2.0';
            $vCal->PRODID = '-//FacturaScripts//ICalSync 1.0//EN';

            $vEvent = $vCal->createComponent('VEVENT');
            $uid = self::getSourceEntityFromCalendar($calendar) . '-' . ($entity->idevento ?? $entity->id ?? 0) . '@facturascripts.icalsync';
            $vEvent->UID = $uid;

            $title = $entity->nombre ?? $entity->titulo ?? 'Evento';
            $prefix = match (self::getSourceEntityFromCalendar($calendar)) {
                'Cita' => 'CITA | ',
                default => 'EVENTO | ',
            };
            $vEvent->SUMMARY = $prefix . $title;

            $desc = $this->buildDescription($entity, self::getSourceEntityFromCalendar($calendar));
            if (!empty($desc)) {
                $vEvent->DESCRIPTION = $desc;
            }

            if ('Evento' === self::getSourceEntityFromCalendar($calendar)) {
                $this->setEventoDates($vEvent, $entity);
            } else {
                $this->setCitaDates($vEvent, $entity);
            }

            $vEvent->STATUS = 'CONFIRMED';

            $vCal->add($vEvent);
            return $vCal;
        } catch (\Exception $e) {
            Tools::log()->error('sync-build-failed', ['%error%' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build description text for the entity.
     *
     * @param object $entity
     * @param string $sourceEntity
     * @return string
     */
    private function buildDescription(object $entity, string $sourceEntity): string
    {
        $parts = [];

        if ('Evento' === $sourceEntity) {
            if (!empty($entity->tipo)) {
                $parts[] = Tools::lang()->trans('type') . ': ' . $entity->tipo;
            }
            if (!empty($entity->ciudad)) {
                $parts[] = Tools::lang()->trans('city') . ': ' . $entity->ciudad;
            }
            if (!empty($entity->idpais)) {
                $parts[] = Tools::lang()->trans('country') . ': ' . $entity->idpais;
            }
        } elseif ('Cita' === $sourceEntity) {
            if (!empty($entity->ubicacion)) {
                $parts[] = Tools::lang()->trans('location') . ': ' . $entity->ubicacion;
            }
            if (!empty($entity->enlace_online)) {
                $parts[] = Tools::lang()->trans('online-link') . ': ' . $entity->enlace_online;
            }
        }

        if (!empty($entity->observaciones)) {
            $parts[] = Tools::lang()->trans('observations') . ': ' . $entity->observaciones;
        }

        return implode("\n", $parts);
    }

    /**
     * Set date/time properties for Evento.
     *
     * @param \Sabre\VObject\Component\VEvent $vEvent
     * @param object $entity
     */
    private function setEventoDates(\Sabre\VObject\Component\VEvent $vEvent, object $entity): void
    {
        $fecha = $entity->fecha ?? date('Y-m-d');
        $horaInicio = $entity->inicio_show ?? $entity->hora ?? '00:00';
        $horaFin = $entity->fin_show ?? $entity->hora_referencia ?? '23:59';

        // If hora contains a full datetime, extract just the time portion
        if (strlen($horaInicio) > 8 && str_contains($horaInicio, ' ')) {
            $horaInicio = substr($horaInicio, strrpos($horaInicio, ' ') + 1);
        }
        if (strlen($horaFin) > 8 && str_contains($horaFin, ' ')) {
            $horaFin = substr($horaFin, strrpos($horaFin, ' ') + 1);
        }

        try {
            $dtStart = new \DateTime($fecha . ' ' . $horaInicio);
            $dtEnd = new \DateTime($fecha . ' ' . $horaFin);
        } catch (\Exception $e) {
            Tools::log()->error('sync-date-parse-error', ['%error%' => $e->getMessage()]);
            $dtStart = new \DateTime();
            $dtEnd = (clone $dtStart)->modify('+2 hours');
        }

        if ($dtEnd <= $dtStart) {
            $dtEnd = clone $dtStart;
            $dtEnd->modify('+2 hours');
        }

        $vEvent->DTSTART = $dtStart;
        $vEvent->DTSTART['VALUE'] = 'DATE-TIME';
        $vEvent->DTEND = $dtEnd;
        $vEvent->DTEND['VALUE'] = 'DATE-TIME';
    }

    /**
     * Set date/time properties for Cita.
     *
     * @param \Sabre\VObject\Component\VEvent $vEvent
     * @param object $entity
     */
    private function setCitaDates(\Sabre\VObject\Component\VEvent $vEvent, object $entity): void
    {
        if (!empty($entity->todo_el_dia) && $entity->todo_el_dia) {
            $dtStart = new \DateTime($entity->fecha_inicio);
            $vEvent->DTSTART = $dtStart;
            $vEvent->DTSTART['VALUE'] = 'DATE';
            $dtEnd = new \DateTime($entity->fecha_fin ?? $entity->fecha_inicio);
            $dtEnd->modify('+1 day');
            $vEvent->DTEND = $dtEnd;
            $vEvent->DTEND['VALUE'] = 'DATE';
            return;
        }

        $dtStart = new \DateTime($entity->fecha_inicio);
        $dtEnd = new \DateTime($entity->fecha_fin ?? $entity->fecha_inicio);

        if ($dtEnd <= $dtStart) {
            $dtEnd = clone $dtStart;
            $dtEnd->modify('+1 hour');
        }

        $vEvent->DTSTART = $dtStart;
        $vEvent->DTSTART['VALUE'] = 'DATE-TIME';
        $vEvent->DTEND = $dtEnd;
        $vEvent->DTEND['VALUE'] = 'DATE-TIME';
    }

    /**
     * Load Evento entities that need syncing (all active ones).
     *
     * @param string $sourceEntity
     * @return array
     */
    private function loadPlanetaEventos(string $sourceEntity): array
    {
        try {
            $eventoClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Evento';
            if (!class_exists($eventoClass)) {
                return [];
            }
            $model = new $eventoClass();
            return $model->all([], ['fecha' => 'ASC'], 0, 0);
        } catch (\Exception $e) {
            Tools::log()->error('sync-load-error', ['%error%' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Load Cita entities for a given calendar.
     *
     * @param ICalSyncCalendar $calendar
     * @return array
     */
    private function loadPlanetaCitas(ICalSyncCalendar $calendar): array
    {
        try {
            $citaClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\Cita';
            if (!class_exists($citaClass)) {
                return [];
            }
            $model = new $citaClass();
            return $model->all([], ['fecha_inicio' => 'ASC'], 0, 0);
        } catch (\Exception $e) {
            Tools::log()->error('sync-load-error', ['%error%' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Count entities that don't have a sync item yet.
     *
     * @param string $modelName 'Evento' or 'Cita'
     * @param ICalSyncCalendar $calendar
     * @return int
     */
    private function countUnsyncedEntities(string $modelName, ICalSyncCalendar $calendar): int
    {
        try {
            $entityClass = 'FacturaScripts\\Plugins\\PlanetaEscenario\\Model\\' . $modelName;
            if (!class_exists($entityClass)) {
                return 0;
            }
            $model = new $entityClass();
            $all = $model->all([], [], 0, 0);
            $synced = 0;

            foreach ($all as $entity) {
                $entityId = $entity->idevento ?? $entity->id ?? 0;
                if ($entityId > 0 && false !== $this->findSyncItem($calendar->id, $entityClass, $entityId)) {
                    $synced++;
                }
            }

            return count($all) - $synced;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get or create the ICloudCalendarService.
     *
     * @return ICloudCalendarService|null
     */
    private function getService(): ?ICloudCalendarService
    {
        if (null !== $this->service) {
            return $this->service;
        }

        $this->service = new ICloudCalendarService($this->account);
        return $this->service;
    }

    // ──────────────────────────────────────────────
    //  ADVANCED CONFLICT RESOLUTION (T3.1)
    // ──────────────────────────────────────────────

    /**
     * Resolve a conflict between local and remote versions of an event.
     *
     * Returns true if the remote should be applied (source wins),
     * false if local should be kept (destination wins),
     * or null if the conflict is deferred for manual resolution.
     *
     * @param int $localTimestamp Unix timestamp of local last-modified
     * @param int $remoteTimestamp Unix timestamp of remote last-modified
     * @param string $entityType Entity type (Evento, Cita)
     * @param int $entityId Entity ID
     * @param array $context Additional context for conflict logging
     *
     * @return bool|null true = source (remote) wins, false = destination (local) wins, null = manual
     */
    public function resolveConflict(
        int $localTimestamp,
        int $remoteTimestamp,
        string $entityType,
        int $entityId,
        array $context = []
    ): ?bool {
        switch ($this->conflictStrategy) {
            case ConflictResolutionStrategy::LAST_WRITE_WINS:
                // Compare timestamps; keep the newest
                return $remoteTimestamp > $localTimestamp;

            case ConflictResolutionStrategy::SOURCE_WINS:
                return true; // Remote always wins

            case ConflictResolutionStrategy::DESTINATION_WINS:
                return false; // Local always wins

            case ConflictResolutionStrategy::MANUAL:
                // Log the conflict with full details, do NOT overwrite either side
                $details = array_merge([
                    'strategy' => $this->conflictStrategy->value,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'local_last_modified' => date('Y-m-d H:i:s', $localTimestamp),
                    'remote_last_modified' => date('Y-m-d H:i:s', $remoteTimestamp),
                    'resolution' => 'manual',
                    'resolved_at' => null,
                    'resolved_by' => null,
                ], $context);

                ICalSyncLog::log([
                    'account_id' => $this->account->id ?? null,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'operation' => 'conflict-detected',
                    'status' => 'conflict',
                    'message' => 'Conflict detected for ' . $entityType . ' #' . $entityId
                        . ' — manual resolution required',
                    'details' => json_encode($details),
                ]);

                $this->stats['conflicts']++;

                // Mark the sync item as conflict so admin UI can find it
                $existing = ICalSyncItem::findByEntity($entityType, $entityId);
                if (null !== $existing) {
                    $existing->sync_status = 'conflict';
                    $existing->error_message = 'Manual resolution required — conflict detected';
                    $existing->save();
                }

                return null; // Defer — no automatic resolution
        }

        // Fallback: source wins
        return true;
    }

    /**
     * Get all unresolved conflicts for display in admin UI.
     *
     * @param int $limit
     * @return ICalSyncItem[]
     */
    public static function getUnresolvedConflicts(int $limit = 50): array
    {
        return ICalSyncItem::findByStatus('conflict', $limit);
    }

    /**
     * Resolve a specific conflict manually.
     *
     * @param int $syncItemId
     * @param string $resolution 'local' or 'remote'
     * @return bool
     */
    public static function resolveConflictManually(int $syncItemId, string $resolution): bool
    {
        $item = new ICalSyncItem();
        if (false === $item->loadFromCode($syncItemId)) {
            return false;
        }

        if ('conflict' !== $item->sync_status) {
            return false;
        }

        $item->sync_status = 'synced';
        $item->error_message = 'Manually resolved: ' . $resolution;
        $item->save();

        ICalSyncLog::logSuccess('conflict-resolved', 'Conflict #' . $syncItemId
            . ' resolved manually (' . $resolution . ')');

        return true;
    }

    // ──────────────────────────────────────────────
    //  SOURCE ENTITY RESOLUTION
    // ──────────────────────────────────────────────

    /**
     * Get the source entity type from a calendar's type field.
     * Maps calendar_type to entity name.
     *
     * @param ICalSyncCalendar $calendar
     * @return string 'Evento', 'Cita', or empty string
     */
    private static function getSourceEntityFromCalendar(ICalSyncCalendar $calendar): string
    {
        return match ($calendar->calendar_type) {
            'private' => 'Cita',
            default => 'Evento',
        };
    }

    // ──────────────────────────────────────────────
    //  RECURRING EVENT DETECTION (T3.2)
    // ──────────────────────────────────────────────

    /**
     * Check if a VEVENT contains RRULE (recurring event).
     *
     * @param \Sabre\VObject\Component\VEvent|null $vEvent
     * @return bool
     */
    public static function isRecurringEvent(?\Sabre\VObject\Component\VEvent $vEvent): bool
    {
        if (null === $vEvent) {
            return false;
        }
        return isset($vEvent->RRULE);
    }

    /**
     * Check if an entity is a recurring event based on its data.
     * For Eventos/Citas: check if has a 'recurring' or 'repetir' field set.
     *
     * @param object $entity
     * @return bool
     */
    public static function entityIsRecurring(object $entity): bool
    {
        // Check common recurring fields
        if (isset($entity->recurring) && !empty($entity->recurring)) {
            return true;
        }
        if (isset($entity->repetir) && !empty($entity->repetir)) {
            return true;
        }
        if (isset($entity->es_recurrente) && !empty($entity->es_recurrente)) {
            return true;
        }
        if (isset($entity->frecuencia) && !empty($entity->frecuencia)) {
            return true;
        }
        return false;
    }

    // ──────────────────────────────────────────────
    //  CALENDAR DISCOVERY CACHE (T3.4)
    // ──────────────────────────────────────────────

    /**
     * Load active calendars with caching to avoid re-discovery every run.
     *
     * @return ICalSyncCalendar[]
     */
    private function loadActiveCalendarsCached(): array
    {
        $accountId = $this->account->id;
        if (isset(self::$calendarDiscoveryCache[$accountId])) {
            return self::$calendarDiscoveryCache[$accountId];
        }

        $calendars = $this->loadActiveCalendars();
        self::$calendarDiscoveryCache[$accountId] = $calendars;
        return $calendars;
    }

    /**
     * Clear the calendar discovery cache.
     */
    public static function clearCalendarCache(): void
    {
        self::$calendarDiscoveryCache = [];
    }

    // ──────────────────────────────────────────────
    //  DB TRANSACTION WRAPPING (T3.4)
    // ──────────────────────────────────────────────

    /**
     * Execute a callback within a DB transaction.
     * Rolls back on failure and logs the error.
     *
     * @param callable $callback
     * @return mixed
     * @throws \Exception
     */
    private function withTransaction(callable $callback): mixed
    {
        $dataBase = new \FacturaScripts\Core\Base\DataBase();
        $dataBase->beginTransaction();

        try {
            $result = $callback();
            $dataBase->commit();
            return $result;
        } catch (\Exception $e) {
            $dataBase->rollback();
            Tools::log()->error('sync-transaction-error', ['%error%' => $e->getMessage()]);
            throw $e;
        }
    }
}
