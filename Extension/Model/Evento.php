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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * Extension de modelo para Evento de PlanetaEscenario.
 *
 * Marca entidades como pendientes de sincronizar al guardar/eliminar.
 * La sincronización real se realiza via cron o acción manual.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Evento
{
    public function saveInsert(): \Closure
    {
        return function () {
            try {
                \FacturaScripts\Core\Tools::log()->info('icalsync-evento-saveInsert-fired');
                $entityId = $this->idevento ?? 0;
                if ($entityId > 0) {
                    $this->dataBase()->exec(
                        'UPDATE eventos SET ultima_modificacion = NOW() WHERE idevento = ' . (int)$entityId
                    );
                }
            } catch (\Exception $e) {
                // Silent — non-blocking
            }
        };
    }

    public function saveUpdate(): \Closure
    {
        return function () {
            try {
                $entityId = $this->idevento ?? 0;
                if ($entityId > 0) {
                    $this->dataBase()->exec(
                        'UPDATE eventos SET ultima_modificacion = NOW() WHERE idevento = ' . (int)$entityId
                    );
                }
            } catch (\Exception $e) {
                // Silent
            }
        };
    }

    public function delete(): \Closure
    {
        return function () {
            try {
                $entityId = $this->idevento ?? 0;
                if ($entityId <= 0) {
                    return;
                }

                $syncItemClass = 'FacturaScripts\\Plugins\\ICalSync\\Model\\ICalSyncItem';
                if (class_exists($syncItemClass)) {
                    $item = new $syncItemClass();
                    $where = [
                        new DataBaseWhere('entity_type', 'Evento'),
                        new DataBaseWhere('entity_id', $entityId),
                    ];
                    foreach ($item->all($where) as $syncItem) {
                        $syncItem->delete();
                    }
                }
            } catch (\Exception $e) {
                // Silent
            }
        };
    }
}
