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

namespace FacturaScripts\Plugins\ICalSync\Extension\Controller;

use FacturaScripts\Core\Tools;

/**
 * Extension de controlador para CalendarioEventos de PlanetaEscenario.
 *
 * Añade filtro de origen (iCloud/Interno/iCloud Privado),
 * indicadores visuales de sincronización y lógica de visibilidad por usuario.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class CalendarioEventos
{
    public function execPreviousAction(): \Closure
    {
        return function ($action) {
            calSyncProcessOriginFilter();
        };
    }

    public function execAfterAction(): \Closure
    {
        return function ($action) {
            calSyncApplyVisibility();
        };
    }

    public function loadData(): \Closure
    {
        return function ($viewName, $view) {
            calSyncAddBadgeData();
        };
    }
}

function calSyncProcessOriginFilter(): void
{
    try {
        $origen = filter_input(INPUT_GET, 'origen');
        if (null === $origen || false === $origen) {
            $origen = filter_input(INPUT_POST, 'origen');
        }

        if (null !== $origen && false !== $origen) {
            $validValues = ['todos', 'icloud', 'interno', 'icloud-privado'];
            $origen = in_array($origen, $validValues, true) ? $origen : 'todos';
        } else {
            $origen = 'todos';
        }

        Tools::settingsSave('icalsync', 'calendar_origin_filter', $origen);
    } catch (\Exception $e) {
        // Silent
    }
}

function calSyncApplyVisibility(): void
{
    try {
        $currentUser = Tools::user() ?? null;
        $currentNick = $currentUser ? $currentUser->nick : '';
        $origen = Tools::settings('icalsync', 'calendar_origin_filter', 'todos');

        $hasPrivateSync = false;
        if (!empty($currentNick)) {
            $userAccountClass = 'FacturaScripts\\Plugins\\ICalSync\\Model\\ICalSyncUserAccount';
            if (class_exists($userAccountClass)) {
                $account = $userAccountClass::findByNick($currentNick);
                $hasPrivateSync = $account && $account->enabled && $account->sync_enabled
                    && $account->show_in_calendar;
            }
        }

        Tools::settingsSave('icalsync', 'current_user_nick', $currentNick);
        Tools::settingsSave('icalsync', 'current_user_has_private', $hasPrivateSync ? '1' : '0');
        Tools::settingsSave('icalsync', 'filter_origen', $origen);

        $db = new \FacturaScripts\Core\Base\DataBase();
        if (false === $db->tableExists('icalsync_items')) {
            return;
        }

        $sql = 'SELECT COUNT(*) AS total FROM icalsync_items WHERE direction = ' . $db->var2str('bidirectional');
        $privateCount = $db->select($sql);
        Tools::settingsSave('icalsync', 'private_sync_count', (string)(!empty($privateCount) ? $privateCount[0]['total'] : 0));

        $sql2 = "SELECT COUNT(*) AS total FROM icalsync_items WHERE direction IN ('export','import')";
        $sharedCount = $db->select($sql2);
        Tools::settingsSave('icalsync', 'shared_sync_count', (string)(!empty($sharedCount) ? $sharedCount[0]['total'] : 0));
    } catch (\Exception $e) {
        // Silent
    }
}

function calSyncAddBadgeData(): void
{
    try {
        $db = new \FacturaScripts\Core\Base\DataBase();
        if (false === $db->tableExists('eventos') || false === $db->tableExists('icalsync_items')) {
            return;
        }

        $sql = 'SELECT COUNT(*) AS total FROM eventos e'
            . ' WHERE NOT EXISTS ('
            . 'SELECT 1 FROM icalsync_items si'
            . " WHERE si.entity_type = 'Evento'"
            . ' AND si.entity_id = e.idevento'
            . ')';
        $unsyncedEventos = $db->select($sql);
        Tools::settingsSave('icalsync', 'unsynced_eventos', (string)(!empty($unsyncedEventos) ? $unsyncedEventos[0]['total'] : 0));

        $sql2 = 'SELECT COUNT(*) AS total FROM citas c'
            . ' WHERE NOT EXISTS ('
            . 'SELECT 1 FROM icalsync_items si'
            . " WHERE si.entity_type = 'Cita'"
            . ' AND si.entity_id = c.id'
            . ')';
        $unsyncedCitas = $db->select($sql2);
        Tools::settingsSave('icalsync', 'unsynced_citas', (string)(!empty($unsyncedCitas) ? $unsyncedCitas[0]['total'] : 0));
    } catch (\Exception $e) {
        // Silent
    }
}
