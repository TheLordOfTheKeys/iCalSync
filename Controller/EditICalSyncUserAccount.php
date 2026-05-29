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
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncUserAccount;

/**
 * Configuración de cuenta iCloud por usuario.
 *
 * Permite a cada usuario configurar su propia cuenta de iCloud
 * para sincronización privada de Citas.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class EditICalSyncUserAccount extends Controller
{
    /** @var ICalSyncUserAccount */
    public $userAccount;

    /** @var bool */
    public $testResult;

    /** @var string */
    public $testMessage;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'profile';
        $pageData['title'] = 'icalsync-user-config';
        $pageData['icon'] = 'fa-solid fa-user-cog';
        $pageData['showonmenu'] = true;
        return $pageData;
    }

    public function run(): void
    {
        parent::run();

        // Ensure user is logged in
        $user = $this->user;
        if (null === $user || empty($user->nick)) {
            Tools::log()->error('user-not-found');
            $this->redirect($this->url());
            return;
        }

        $actionName = $this->request->get('action', '');
        switch ($actionName) {
            case 'test-connection':
                $this->testConnectionAction();
                break;
            case 'save':
                $this->saveAction();
                break;
        }

        // Load or create the user account
        $this->loadUserAccount($user->nick);
    }

    /**
     * Load the current user's account, or create an empty one.
     *
     * @param string $nick
     */
    private function loadUserAccount(string $nick): void
    {
        $account = ICalSyncUserAccount::findByNick($nick);

        if (null === $account) {
            $account = new ICalSyncUserAccount();
            $account->nick = $nick;
        }

        $this->userAccount = $account;
    }

    /**
     * Save the user account configuration.
     */
    private function saveAction(): void
    {
        $nick = $this->user->nick;

        $account = ICalSyncUserAccount::findByNick($nick);
        if (null === $account) {
            $account = new ICalSyncUserAccount();
            $account->nick = $nick;
        }

        $account->apple_id = $this->request->get('apple_id', '');
        $account->calendar_url = $this->request->get('calendar_url', '');
        $account->principal_url = $this->request->get('principal_url', '');
        $account->enabled = (bool)$this->request->get('enabled', false);
        $account->sync_enabled = (bool)$this->request->get('sync_enabled', false);
        $account->show_in_calendar = (bool)$this->request->get('show_in_calendar', true);
        $account->show_in_dashboard = (bool)$this->request->get('show_in_dashboard', true);

        $password = $this->request->get('app_specific_password', '');
        if (!empty($password)) {
            $account->setPlainPassword($password);
        }

        if ($account->save()) {
            Tools::log()->info('settings-saved');
        } else {
            Tools::log()->error('settings-save-error');
        }

        $this->redirect($this->url());
    }

    /**
     * Test the iCloud CalDAV connection for this user.
     */
    private function testConnectionAction(): void
    {
        $nick = $this->user->nick;

        $account = ICalSyncUserAccount::findByNick($nick);
        if (null === $account) {
            $account = new ICalSyncUserAccount();
            $account->nick = $nick;
        }

        // Use form data if available, or stored values
        $account->apple_id = $this->request->get('apple_id', $account->apple_id);
        $password = $this->request->get('app_specific_password', '');
        if (!empty($password)) {
            $account->setPlainPassword($password);
        }

        $result = $account->testConnection();

        if ($result['success']) {
            if (!empty($result['principal_url'])) {
                $account->principal_url = $result['principal_url'];
                // Save discovered principal URL
                $account->save();
            }

            Tools::log()->info('test-connection-success', [
                '%url%' => $result['principal_url'] ?? '',
            ]);

            // Discover and save calendars info
            if (!empty($result['calendars'])) {
                $firstCalendar = $result['calendars'][0];
                if (empty($account->calendar_url)) {
                    $account->calendar_url = $firstCalendar['url'];
                    $account->save();
                }
            }

            $this->testResult = true;
        } else {
            Tools::log()->error('test-connection-failure', [
                '%msg%' => $result['message'] ?? '',
            ]);
            $this->testResult = false;
        }

        $this->testMessage = $result['message'] ?? '';

        // Reload the account to show updated data
        $this->loadUserAccount($nick);
    }
}
