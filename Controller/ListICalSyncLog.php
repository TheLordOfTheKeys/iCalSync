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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;

/**
 * Listado del log de sincronización iCalSync.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ListICalSyncLog extends ListController
{
    /**
     * Returns basic page attributes.
     *
     * @return array
     */
    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'admin';
        $pageData['title'] = 'icalsync-logs';
        $pageData['icon'] = 'fa-solid fa-list';
        $pageData['showonmenu'] = true;
        return $pageData;
    }

    /**
     * Load data for the view.
     *
     * @param string $viewName
     */
    protected function createViews(): void
    {
        $this->addView('ListICalSyncLog', 'ICalSyncLog', 'icalsync-logs', 'fa-solid fa-list');
    }

    /**
     * Override to add action for clearing logs.
     */
    public function run(): void
    {
        parent::run();

        $actionName = $this->request->get('action', '');
        if ('clear-log' === $actionName) {
            $this->clearLogAction();
        }
    }

    /**
     * Clear all log entries.
     */
    private function clearLogAction(): void
    {
        // We use a direct DB truncate for efficiency
        $logModel = new \FacturaScripts\Plugins\ICalSync\Model\ICalSyncLog();
        $dataBase = $logModel->dataBase();

        if ($dataBase->tableExists($logModel->tableName())) {
            $sql = 'DELETE FROM ' . $logModel->tableName();
            if ($dataBase->exec($sql)) {
                Tools::log()->info('icalsync-log-cleared');
            }
        }

        $this->redirect($this->url());
    }
}
