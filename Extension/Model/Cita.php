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

namespace FacturaScripts\Plugins\ICalSync\Extension\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * Extension de modelo para Cita de PlanetaEscenario.
 *
 * Marca entidades como pendientes de sincronizar al guardar/eliminar
 * y gestiona la sincronización privada por usuario.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Cita
{
    public function saveInsert(): \Closure
    {
        return function () {
            try {
                \FacturaScripts\Core\Tools::log()->info('icalsync-cita-hook-fired');
                $entityId = $this->id ?? 0;
                if ($entityId > 0) {
                    $this->dataBase()->exec(
                        'UPDATE citas SET ultima_modificacion = NOW() WHERE id = ' . (int)$entityId
                    );
                }
                \FacturaScripts\Plugins\ICalSync\Extension\Model\citaSyncPrivate($this, false);
            } catch (\Exception $e) {
                // Silent
            }
        };
    }

    public function saveUpdate(): \Closure
    {
        return function () {
            try {
                $entityId = $this->id ?? 0;
                if ($entityId > 0) {
                    $this->dataBase()->exec(
                        'UPDATE citas SET ultima_modificacion = NOW() WHERE id = ' . (int)$entityId
                    );
                }
                \FacturaScripts\Plugins\ICalSync\Extension\Model\citaSyncPrivate($this, true);
            } catch (\Exception $e) {
                // Silent
            }
        };
    }

    public function delete(): \Closure
    {
        return function () {
            try {
                $entityId = $this->id ?? 0;
                if ($entityId <= 0) {
                    return;
                }

                // Remove sync items
                $syncItemClass = 'FacturaScripts\\Plugins\\ICalSync\\Model\\ICalSyncItem';
                if (class_exists($syncItemClass)) {
                    $item = new $syncItemClass();
                    $where = [
                        new DataBaseWhere('entity_type', 'Cita'),
                        new DataBaseWhere('entity_id', $entityId),
                    ];
                    foreach ($item->all($where) as $syncItem) {
                        $syncItem->delete();
                    }
                }

                // Delete from private calendar
                \FacturaScripts\Plugins\ICalSync\Extension\Model\citaDeletePrivate($this);
            } catch (\Exception $e) {
                // Silent
            }
        };
    }
}

/**
 * Export Cita to user's private iCloud calendar.
 * Called from model extension hooks. $cita is the PlanetaEscenario Cita model.
 */
function citaSyncPrivate(object $cita, bool $isUpdate): void
{
    try {
        $entityId = $cita->id ?? 0;
        $nick = $cita->idresponsable ?? '';
        if ($entityId <= 0 || empty($nick)) {
            return;
        }

        // Skip if already synced to shared calendar
        $syncItemClass = 'FacturaScripts\\Plugins\\ICalSync\\Model\\ICalSyncItem';
        if (class_exists($syncItemClass)) {
            $existingItem = $syncItemClass::findByEntity('Cita', $entityId);
            if ($existingItem && $existingItem->caldav_uid && !empty($existingItem->calendar_id)) {
                return;
            }
        }

        $userAccountClass = 'FacturaScripts\\Plugins\\ICalSync\\Model\\ICalSyncUserAccount';
        if (!class_exists($userAccountClass)) {
            return;
        }

        $userAccount = $userAccountClass::findByNick($nick);
        if (null === $userAccount || !$userAccount->enabled || !$userAccount->sync_enabled) {
            return;
        }
        if (empty($userAccount->calendar_url) || empty($userAccount->apple_id)) {
            return;
        }

        $accountClass = 'FacturaScripts\\Plugins\\ICalSync\\Model\\ICalSyncAccount';
        $engineClass = 'FacturaScripts\\Plugins\\ICalSync\\Lib\\Service\\SyncEngine';
        if (class_exists($accountClass) && class_exists($engineClass)) {
            $dummyAccount = new $accountClass();
            $dummyAccount->account_name = $nick;
            $engine = new $engineClass($dummyAccount);
            $engine->exportCita($cita, $userAccount);
        }
    } catch (\Exception $e) {
        // Non-blocking
    }
}

/**
 * Delete Cita from user's private iCloud calendar.
 */
function citaDeletePrivate(object $cita): void
{
    try {
        $entityId = $cita->id ?? 0;
        $nick = $cita->idresponsable ?? '';
        if ($entityId <= 0 || empty($nick)) {
            return;
        }

        $syncItemClass = 'FacturaScripts\\Plugins\\ICalSync\\Model\\ICalSyncItem';
        if (!class_exists($syncItemClass)) {
            return;
        }
        $existingItem = $syncItemClass::findByEntity('Cita', $entityId);
        if (null === $existingItem || empty($existingItem->caldav_uid)) {
            return;
        }

        $userAccountClass = 'FacturaScripts\\Plugins\\ICalSync\\Model\\ICalSyncUserAccount';
        if (!class_exists($userAccountClass)) {
            return;
        }
        $userAccount = $userAccountClass::findByNick($nick);
        if (null === $userAccount || !$userAccount->enabled || empty($userAccount->calendar_url)) {
            return;
        }

        $accountClass = 'FacturaScripts\\Plugins\\ICalSync\\Model\\ICalSyncAccount';
        $engineClass = 'FacturaScripts\\Plugins\\ICalSync\\Lib\\Service\\SyncEngine';
        if (class_exists($accountClass) && class_exists($engineClass)) {
            $dummyAccount = new $accountClass();
            $dummyAccount->account_name = $nick;
            $engine = new $engineClass($dummyAccount);
            $engine->deleteCitaFromPrivate($entityId, $userAccount);
        }
    } catch (\Exception $e) {
        // Non-blocking
    }
}
