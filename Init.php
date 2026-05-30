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

namespace FacturaScripts\Plugins\ICalSync;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;

require_once __DIR__ . '/Vendor/Autoloader.php';
Vendor\Autoloader::register();

final class Init extends InitClass
{
    public function init(): void
    {

        $db = new DataBase();
        if (false === $db->tableExists('eventos')) {
            return;
        }

        $this->addSyncColumns($db);

        // Register model extensions directly
        \FacturaScripts\Dinamic\Model\Evento::addExtension(new Extension\Model\Evento());
        \FacturaScripts\Dinamic\Model\Cita::addExtension(new Extension\Model\Cita());
        \FacturaScripts\Dinamic\Controller\CalendarioEventos::addExtension(new Extension\Controller\CalendarioEventos());
    }

    public function update(): void
    {
        $db = new DataBase();

        new Model\ICalSyncAccount();
        new Model\ICalSyncCalendar();
        new Model\ICalSyncItem();
        new Model\ICalSyncLog();
        new Model\ICalSyncUserAccount();

        $this->addSyncColumns($db);
    }

    public function uninstall(): void
    {
    }

    private function addSyncColumns(DataBase $db): void
    {
        if ($db->tableExists('eventos')) {
            $this->addColumnIfMissing($db, 'eventos', 'idsincronizacion', 'character varying(255)');
            $this->addColumnIfMissing($db, 'eventos', 'ultima_modificacion', 'timestamp');
        }

        if ($db->tableExists('citas')) {
            $this->addColumnIfMissing($db, 'citas', 'idsincronizacion', 'character varying(255)');
            $this->addColumnIfMissing($db, 'citas', 'ultima_modificacion', 'timestamp');
            $this->addColumnIfMissing($db, 'citas', 'sync_status', 'character varying(20)');
        }

        if ($db->tableExists('icalsync_items')) {
            $this->addColumnIfMissing($db, 'icalsync_items', 'origin', 'character varying(10)');
        }

        if ($db->tableExists('crm_oportunidades')) {
            $this->addColumnIfMissing($db, 'crm_oportunidades', 'idsincronizacion', 'character varying(255)');
            $this->addColumnIfMissing($db, 'crm_oportunidades', 'ultima_modificacion', 'timestamp');
        }
    }

    private function addColumnIfMissing(DataBase $db, string $table, string $column, string $type): void
    {
        $columns = $db->getColumns($table);
        foreach ($columns as $col) {
            if ($col['name'] === $column) {
                return;
            }
        }
        $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
    }
}
