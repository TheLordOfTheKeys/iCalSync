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

namespace FacturaScripts\Plugins\ICalSync\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ICalSync\Lib\Service\ICloudCalendarService;
use FacturaScripts\Plugins\ICalSync\Lib\Service\SyncEngine;
use FacturaScripts\Plugins\ICalSync\Lib\Util\ConflictResolutionStrategy;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncAccount;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncCalendar;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncItem;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncLog;

/**
 * Panel de configuración de iCalSync.
 *
 * Gestiona cuentas de iCloud y calendarios asociados,
 * permite test de conexión y descubrimiento remoto.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class AdminICalSync extends Controller
{
    /** @var array */
    public $accounts = [];

    /** @var array */
    public $calendars = [];

    /** @var array */
    public $conflicts = [];

    /** @var array */
    public $conflictStrategies = [];

    /** @var array */
    public $recentErrors = [];

    /** @var string */
    public $errorMessage = '';

    /** @var bool|null */
    public $testSuccess = null;

    /** @var string */
    public $testMessage = '';

    /** @var array|null */
    public $syncStats = null;

    /**
     * Returns basic page attributes.
     *
     * @return array
     */
    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'admin';
        $pageData['title'] = 'icalsync-config';
        $pageData['icon'] = 'fa-solid fa-calendar-check';
        $pageData['showonmenu'] = true;
        return $pageData;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $action = $this->request->get('action', '');
        switch ($action) {
            case 'save':
                $this->saveAccountAction();
                break;
            case 'test-connection':
                $this->testConnectionAction();
                break;
            case 'delete-account':
                $this->deleteAccountAction();
                break;
            case 'delete-calendar':
                $this->deleteCalendarAction();
                break;
            case 'toggle-calendar':
                $this->toggleCalendarAction();
                break;
            case 'resolve-conflict-local':
                $this->resolveConflictAction('local');
                break;
            case 'resolve-conflict-remote':
                $this->resolveConflictAction('remote');
                break;
            case 'resolve-all-conflicts':
                $this->resolveAllConflictsAction();
                break;
            case 'force-sync':
                $this->forceSyncAction();
                break;
            case 'force-export-all':
                $this->forceExportAllAction();
                break;
            case 'sync-calendar':
                $this->syncCalendarAction();
                break;
        }

        $this->loadData();
    }

    /**
     * Load accounts and calendars for the view.
     */
    private function loadData(): void
    {
        $accountModel = new ICalSyncAccount();
        $this->accounts = $accountModel->all([], ['account_name' => 'ASC']);

        $calendarModel = new ICalSyncCalendar();
        $this->calendars = $calendarModel->all([], ['calendar_name' => 'ASC']);

        $this->conflicts = SyncEngine::getUnresolvedConflicts(50);
        $this->conflictStrategies = ConflictResolutionStrategy::options();

        $logModel = new ICalSyncLog();
        $where = [new DataBaseWhere('status', 'error')];
        $this->recentErrors = $logModel->all($where, ['created_at' => 'DESC'], 10, 0);
    }

    /**
     * Save a new or existing iCloud account.
     */
    private function saveAccountAction(): void
    {
        try {
            $id = (int)$this->request->get('id', 0);
            if ($id > 0) {
                $account = new ICalSyncAccount();
                if (false === $account->loadFromCode($id)) {
                    $this->errorMessage = 'Registro no encontrado';
                    return;
                }
            } else {
                $account = new ICalSyncAccount();
            }

            $account->account_name = $this->request->get('account_name', '');
            $account->apple_id = $this->request->get('apple_id', '');
            $account->calendar_url = $this->request->get('calendar_url', '');
            $account->principal_url = $this->request->get('principal_url', '');
            $account->enabled = (bool)$this->request->get('enabled', true);
            $account->sync_frequency_minutes = (int)$this->request->get('sync_frequency_minutes', 15);
            $account->log_level = $this->request->get('log_level', 'warning');

            $password = $this->request->get('app_specific_password', '');
            if (!empty($password)) {
                $account->setPlainPassword($password);
            }

            if (false === $account->test()) {
                $this->errorMessage = 'Validación fallida: verifique nombre y Apple ID';
                return;
            }

            if ($account->save()) {
                Tools::log()->info('record-updated');
                $this->redirect($this->url());
                return;
            }

            $this->errorMessage = 'Error al guardar en la base de datos. Revise el log.';
        } catch (\Exception $e) {
            $this->errorMessage = 'Error: ' . $e->getMessage();
            Tools::log()->error('save-account-error', ['%error%' => $e->getMessage()]);
        }
    }

    /**
     * Test connection to an iCloud account.
     */
    private function testConnectionAction(): void
    {
        $accountId = (int)$this->request->get('id', 0);
        if ($accountId <= 0) {
            $this->testSuccess = false;
            $this->testMessage = 'ID de cuenta no válido';
            return;
        }

        $account = new ICalSyncAccount();
        if (false === $account->loadFromCode($accountId)) {
            $this->testSuccess = false;
            $this->testMessage = 'Cuenta no encontrada';
            return;
        }

        try {
            $service = new ICloudCalendarService($account);
            $result = $service->testConnection();

            $this->testSuccess = $result['success'];
            $this->testMessage = $result['message'] ?? '';

            if ($result['success']) {
                if (!empty($result['principal_url'])) {
                    $account->principal_url = $result['principal_url'];
                    $account->save();
                }
                if (!empty($result['calendars'])) {
                    $this->saveDiscoveredCalendars($account, $result['calendars']);
                    $count = count($result['calendars']);
                    $this->testMessage = 'Principal URL: ' . ($result['principal_url'] ?? 'N/A')
                        . ' | Calendarios descubiertos: ' . $count;
                } else {
                    $this->testMessage = 'Principal URL OK pero no se encontraron calendarios.'
                        . ' URL: ' . ($result['principal_url'] ?? 'N/A');
                }
            }
        } catch (\Exception $e) {
            $this->testSuccess = false;
            $this->testMessage = 'Error: ' . $e->getMessage();
            Tools::log()->error('test-connection-error', ['%error%' => $e->getMessage()]);
        }
    }

    /**
     * Save discovered calendars from iCloud.
     *
     * @param ICalSyncAccount $account
     * @param array $calendars
     */
    private function saveDiscoveredCalendars(ICalSyncAccount $account, array $calendars): void
    {
        foreach ($calendars as $calData) {
            $calendarUrl = $calData['url'];

            // Resolve relative URLs against the CalDAV base
            if (!str_contains($calendarUrl, '://')) {
                $baseUrl = 'https://caldav.icloud.com';
                $calendarUrl = $baseUrl . '/' . ltrim($calendarUrl, '/');
            }

            // Check if already exists
            $existing = new ICalSyncCalendar();
            $where = [
                new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('account_id', $account->id),
                new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('calendar_url', $calendarUrl),
            ];

            if (!empty($existing->all($where, [], 1, 0))) {
                continue;
            }

            $calendar = new ICalSyncCalendar();
            $calendar->account_id = $account->id;
            $calendar->calendar_name = $calData['displayname'];
            $calendar->calendar_url = $calendarUrl;
            $calendar->calendar_color = $calData['color'] ?? '#0d6efd';
            $calendar->ctag = $calData['ctag'] ?? '';
            $calendar->enabled = false;

            if ($calendar->save()) {
                Tools::log()->info('calendar-discovered', [
                    '%name%' => $calData['displayname'],
                ]);
            }
        }
    }

    /**
     * Delete an account and its associated calendars.
     */
    private function deleteAccountAction(): void
    {
        $accountId = $this->request->get('id', 0);
        if (empty($accountId)) {
            Tools::log()->error('account-not-found');
            $this->redirect($this->url());
            return;
        }

        $account = new ICalSyncAccount();
        if (false === $account->loadFromCode($accountId)) {
            Tools::log()->error('account-not-found');
            $this->redirect($this->url());
            return;
        }

        // Delete linked calendars first
        $calendarModel = new ICalSyncCalendar();
        $calendars = $calendarModel->all([
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('account_id', $account->id),
        ]);

        foreach ($calendars as $cal) {
            $cal->delete();
        }

        if ($account->delete()) {
            Tools::log()->info('item-deleted');
        }

        $this->redirect($this->url());
    }

    /**
     * Delete a calendar.
     */
    private function deleteCalendarAction(): void
    {
        $calendarId = $this->request->get('id', 0);
        if (empty($calendarId)) {
            Tools::log()->error('record-not-found');
            $this->redirect($this->url());
            return;
        }

        $calendar = new ICalSyncCalendar();
        if (false === $calendar->loadFromCode($calendarId)) {
            Tools::log()->error('record-not-found');
            $this->redirect($this->url());
            return;
        }

        if ($calendar->delete()) {
            Tools::log()->info('item-deleted');
        }

        $this->redirect($this->url());
    }

    /**
     * Toggle enabled/disabled for a calendar.
     */
    private function toggleCalendarAction(): void
    {
        $calendarId = $this->request->get('id', 0);
        if (empty($calendarId)) {
            Tools::log()->error('record-not-found');
            $this->redirect($this->url());
            return;
        }

        $calendar = new ICalSyncCalendar();
        if (false === $calendar->loadFromCode($calendarId)) {
            Tools::log()->error('record-not-found');
            $this->redirect($this->url());
            return;
        }

        $calendar->enabled = !$calendar->enabled;
        $calendar->save();

        $this->redirect($this->url());
    }

    /**
     * Resolve a single conflict by keeping local or remote version.
     *
     * @param string $resolution 'local' or 'remote'
     */
    private function resolveConflictAction(string $resolution): void
    {
        $itemId = $this->request->get('id', 0);
        if (empty($itemId)) {
            Tools::log()->error('record-not-found');
            $this->redirect($this->url());
            return;
        }

        if (SyncEngine::resolveConflictManually($itemId, $resolution)) {
            Tools::log()->info('conflict-resolved', [
                '%id%' => $itemId,
                '%resolution%' => $resolution,
            ]);
        } else {
            Tools::log()->error('record-not-found');
        }

        $this->redirect($this->url());
    }

    /**
     * Resolve all conflicts by marking them as resolved with local version.
     */
    private function resolveAllConflictsAction(): void
    {
        $conflicts = SyncEngine::getUnresolvedConflicts(500);
        $resolved = 0;

        foreach ($conflicts as $item) {
            if (SyncEngine::resolveConflictManually($item->id, 'local')) {
                $resolved++;
            }
        }

        if ($resolved > 0) {
            Tools::log()->info('conflicts-bulk-resolved', [
                '%count%' => $resolved,
            ]);
        } else {
            Tools::log()->info('no-conflicts-found');
        }

        $this->redirect($this->url());
    }

    private function forceExportAllAction(): void
    {
        // Delete all existing sync items to force full re-export
        $db = new \FacturaScripts\Core\Base\DataBase();
        if ($db->tableExists('icalsync_items')) {
            $db->exec('DELETE FROM icalsync_items');
        }
        Tools::log()->info('force-export-all-cleared');

        // Now run normal force sync
        $this->forceSyncAction();
    }

    /**
     * Force a full sync for all active accounts and calendars.
     */
    private function forceSyncAction(): void
    {
        $accountModel = new ICalSyncAccount();
        $accounts = $accountModel->all([new DataBaseWhere('enabled', true)]);

        $totalStats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0, 'conflicts' => 0];

        foreach ($accounts as $account) {
            try {
                $engine = new SyncEngine($account);
                $result = $engine->run();
                foreach (['created', 'updated', 'deleted', 'skipped', 'errors', 'conflicts'] as $key) {
                    $totalStats[$key] += $result[$key] ?? 0;
                }
            } catch (\Exception $e) {
                $totalStats['errors']++;
                Tools::log()->error('force-sync-error', ['%error%' => $e->getMessage()]);
            }
        }

        $this->syncStats = $totalStats;
        Tools::log()->debug('force-sync-completed', ['%stats%' => json_encode($totalStats)]);
    }

    /**
     * Sync a single calendar for an account.
     */
    private function syncCalendarAction(): void
    {
        $calendarId = $this->request->get('id', 0);
        if (empty($calendarId)) {
            Tools::log()->error('record-not-found');
            $this->redirect($this->url());
            return;
        }

        $calendar = new ICalSyncCalendar();
        if (false === $calendar->loadFromCode($calendarId)) {
            Tools::log()->error('record-not-found');
            $this->redirect($this->url());
            return;
        }

        $account = new ICalSyncAccount();
        if (false === $account->loadFromCode($calendar->account_id)) {
            Tools::log()->error('account-not-found');
            $this->redirect($this->url());
            return;
        }

        $engine = new SyncEngine($account);
        $result = $engine->run();

        Tools::log()->debug('calendar-sync-completed', [
            '%calendar%' => $calendar->calendar_name,
            '%stats%' => json_encode($result),
        ]);

        $this->redirect($this->url());
    }
}
