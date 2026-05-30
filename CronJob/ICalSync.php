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

namespace FacturaScripts\Plugins\ICalSync\CronJob;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ICalSync\Lib\Service\SyncEngine;
use FacturaScripts\Plugins\ICalSync\Lib\Util\SyncNotifier;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncAccount;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncUserAccount;

/**
 * Cron job para sincronización automática con iCloud Calendar.
 *
 * Ejecuta SyncEngine para cada cuenta activa (compartida),
 * y luego para cada cuenta de usuario con sync privado habilitado.
 * Incluye límite de tiempo de ejecución, procesamiento por lotes
 * y notificaciones de error (Phase 3 optimizations).
 *
 * Frecuencia configurable via settings (default: 15 minutos).
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ICalSync
{
    /** Máximo de usuarios a procesar por ejecución */
    private const MAX_USERS_PER_RUN = 10;

    /** Límite de tiempo por ejecución (segundos) */
    private const MAX_EXECUTION_TIME = 30;

    /** Tamaño de lote para procesamiento de entidades */
    private const BATCH_SIZE = 25;

    /**
     * @var float Tiempo de inicio de la ejecución actual
     */
    private static float $startTime = 0.0;

    /**
     * Main cron entry point.
     *
     * @return array Statistics for all accounts
     */
    public static function run(): array
    {
        self::$startTime = microtime(true);
        Tools::log()->info('cron-starting');

        if (false === Tools::settings('icalsync', 'enabled', false)) {
            Tools::log()->info('cron-disabled');
            return [];
        }

        $totalStats = [
            'accounts' => 0,
            'user_accounts' => 0,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
            'conflicts' => 0,
        ];

        // Phase 1: Sync shared accounts
        $sharedStats = self::syncSharedAccounts();
        foreach (['created', 'updated', 'deleted', 'skipped', 'errors', 'conflicts'] as $key) {
            $totalStats[$key] += $sharedStats[$key] ?? 0;
        }
        $totalStats['accounts'] = $sharedStats['accounts'] ?? 0;

        // Check time budget before private accounts
        if (self::isTimeBudgetExceeded()) {
            Tools::log()->warning('cron-time-budget-exceeded', [
                '%phase%' => 'private-accounts',
            ]);
            self::logCronResult($totalStats);
            return $totalStats;
        }

        // Phase 2: Sync per-user private accounts
        $userStats = self::syncPrivateUserAccounts();
        foreach (['created', 'updated', 'deleted', 'skipped', 'errors', 'conflicts'] as $key) {
            $totalStats[$key] += $userStats[$key] ?? 0;
        }
        $totalStats['user_accounts'] = $userStats['accounts'] ?? 0;

        self::logCronResult($totalStats);

        Tools::settingsSave('icalsync', 'last_cron_run', date('Y-m-d H:i:s'));

        return $totalStats;
    }

    /**
     * Sync all active shared accounts.
     *
     * @return array
     */
    private static function syncSharedAccounts(): array
    {
        $accounts = self::loadActiveAccounts();
        if (empty($accounts)) {
            Tools::log()->info('cron-no-accounts');
            return [
                'accounts' => 0,
                'created' => 0,
                'updated' => 0,
                'deleted' => 0,
                'skipped' => 0,
                'errors' => 0,
                'conflicts' => 0,
            ];
        }

        $stats = [
            'accounts' => count($accounts),
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
            'conflicts' => 0,
        ];

        foreach ($accounts as $account) {
            // Stop if time budget exceeded
            if (self::isTimeBudgetExceeded()) {
                Tools::log()->warning('cron-time-budget-exceeded', [
                    '%phase%' => 'shared-account-' . $account->account_name,
                ]);
                break;
            }

            Tools::log()->info('cron-processing-account', [
                '%account%' => $account->account_name,
            ]);

            try {
                $engine = new SyncEngine($account);
                $engine->setMaxExecutionTime(self::MAX_EXECUTION_TIME);
                $engine->setBatchSize(self::BATCH_SIZE);

                $result = $engine->run();

                $stats['created'] += $result['created'] ?? 0;
                $stats['updated'] += $result['updated'] ?? 0;
                $stats['deleted'] += $result['deleted'] ?? 0;
                $stats['skipped'] += $result['skipped'] ?? 0;
                $stats['errors'] += $result['errors'] ?? 0;
                $stats['conflicts'] += $result['conflicts'] ?? 0;

                // Notify on critical errors
                if (($result['errors'] ?? 0) > 0) {
                    SyncNotifier::notifyAdmin(
                        'Sync errors for shared account ' . ($account->account_name ?? 'unknown')
                            . ': ' . ($result['errors'] ?? 0) . ' error(s)',
                        SyncNotifier::SEVERITY_ERROR,
                        ['account' => $account->account_name ?? '', 'errors' => (string)($result['errors'] ?? 0)]
                    );
                }
            } catch (\Exception $e) {
                Tools::log()->error('cron-account-error', [
                    '%account%' => $account->account_name ?? '',
                    '%error%' => $e->getMessage(),
                ]);
                $stats['errors']++;

                SyncNotifier::notifyAdmin(
                    'Sync exception for shared account ' . ($account->account_name ?? 'unknown')
                        . ': ' . $e->getMessage(),
                    SyncNotifier::SEVERITY_ERROR,
                    ['account' => $account->account_name ?? '', 'exception' => $e->getMessage()]
                );
            }
        }

        return $stats;
    }

    /**
     * Sync all active per-user private accounts.
     *
     * Processes users in batches to avoid overloading the system.
     * One user failure does not block others.
     * Includes time budget check (T3.4).
     *
     * @return array
     */
    private static function syncPrivateUserAccounts(): array
    {
        // Use a batch approach: process up to MAX_USERS_PER_RUN at a time
        $offset = 0;
        $totalProcessed = 0;

        $stats = [
            'accounts' => 0,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
            'conflicts' => 0,
        ];

        do {
            // Check time budget
            if (self::isTimeBudgetExceeded()) {
                Tools::log()->warning('cron-time-budget-exceeded', [
                    '%phase%' => 'private-users',
                ]);
                break;
            }

            $users = ICalSyncUserAccount::findActive(self::MAX_USERS_PER_RUN, $offset);
            if (empty($users)) {
                break;
            }

            foreach ($users as $userAccount) {
                // Stop if time budget exceeded
                if (self::isTimeBudgetExceeded()) {
                    break 2;
                }

                // Validate user account has required config
                if (empty($userAccount->apple_id) || empty($userAccount->calendar_url)) {
                    Tools::log()->info('cron-skipping-user', [
                        '%user%' => $userAccount->nick,
                        '%reason%' => 'incomplete-config',
                    ]);
                    $stats['skipped']++;
                    continue;
                }

                Tools::log()->info('cron-processing-user', [
                    '%user%' => $userAccount->nick,
                ]);

                try {
                    // Create a shared account for SyncEngine constructor
                    $sharedAccount = new ICalSyncAccount();
                    $sharedAccount->account_name = $userAccount->nick;

                    $engine = new SyncEngine($sharedAccount);
                    $engine->setMaxExecutionTime(self::MAX_EXECUTION_TIME);
                    $engine->setBatchSize(self::BATCH_SIZE);

                    $result = $engine->syncPrivateCalendar($userAccount);

                    $stats['created'] += $result['created'] ?? 0;
                    $stats['updated'] += $result['updated'] ?? 0;
                    $stats['deleted'] += $result['deleted'] ?? 0;
                    $stats['skipped'] += $result['skipped'] ?? 0;
                    $stats['errors'] += $result['errors'] ?? 0;
                    $stats['conflicts'] += $result['conflicts'] ?? 0;
                    $stats['accounts']++;

                    // Notify on errors for this user
                    if (($result['errors'] ?? 0) > 0) {
                        SyncNotifier::notifyAdmin(
                            'Sync errors for user ' . ($userAccount->nick ?? 'unknown')
                                . ': ' . ($result['errors'] ?? 0) . ' error(s)',
                            SyncNotifier::SEVERITY_WARNING,
                            [
                                'user' => $userAccount->nick ?? '',
                                'errors' => (string)($result['errors'] ?? 0),
                            ]
                        );
                    }
                } catch (\Exception $e) {
                    // One user failure should not block others
                    Tools::log()->error('cron-user-error', [
                        '%user%' => $userAccount->nick,
                        '%error%' => $e->getMessage(),
                    ]);
                    $stats['errors']++;

                    SyncNotifier::notifyAdmin(
                        'Sync exception for user ' . ($userAccount->nick ?? 'unknown')
                            . ': ' . $e->getMessage(),
                        SyncNotifier::SEVERITY_ERROR,
                        ['user' => $userAccount->nick ?? '', 'exception' => $e->getMessage()]
                    );
                }

                $totalProcessed++;
            }

            $offset += self::MAX_USERS_PER_RUN;

            // Safety: don't loop indefinitely
            if ($totalProcessed > 100) {
                Tools::log()->warning('cron-max-users-reached');
                break;
            }
        } while (true);

        if ($totalProcessed > 0) {
            Tools::log()->info('cron-private-users-completed', [
                '%users%' => $totalProcessed,
                '%stats%' => json_encode($stats),
            ]);
        }

        return $stats;
    }

    /**
     * Check if the current cron run has exceeded its time budget.
     *
     * @return bool
     */
    private static function isTimeBudgetExceeded(): bool
    {
        if (self::$startTime <= 0) {
            return false;
        }
        return (microtime(true) - self::$startTime) >= self::MAX_EXECUTION_TIME;
    }

    /**
     * Log the final cron result and persist progress state.
     *
     * @param array $stats
     */
    private static function logCronResult(array $stats): void
    {
        // Persist progress state (T3.4 progress tracking)
        $progress = [
            'last_run_at' => date('Y-m-d H:i:s'),
            'stats' => $stats,
        ];
        Tools::settingsSave('icalsync', 'last_cron_result', json_encode($progress));

        Tools::log()->info('cron-completed', [
            '%stats%' => json_encode($stats),
        ]);
    }

    /**
     * Load all active shared accounts.
     *
     * @return ICalSyncAccount[]
     */
    private static function loadActiveAccounts(): array
    {
        $accountModel = new ICalSyncAccount();
        $where = [
            new DataBaseWhere('enabled', true),
        ];
        $accounts = $accountModel->all($where, ['account_name' => 'ASC']);

        // Filter accounts that have at least one enabled calendar
        return array_filter($accounts, function (ICalSyncAccount $account) {
            $calendarModel = new \FacturaScripts\Plugins\ICalSync\Model\ICalSyncCalendar();
            $calendars = $calendarModel->all([
                new DataBaseWhere('account_id', $account->id),
                new DataBaseWhere('enabled', true),
            ], [], 1, 0);

            return !empty($calendars);
        });
    }
}
