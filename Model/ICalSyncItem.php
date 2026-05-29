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

namespace FacturaScripts\Plugins\ICalSync\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/**
 * Mapeo entre entidades de FacturaScripts (Evento/Cita) y UIDs de CalDAV.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ICalSyncItem extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string Evento|Cita */
    public $entity_type;

    /** @var int */
    public $entity_id;

    /** @var int|null */
    public $calendar_id;

    /** @var string */
    public $caldav_uid;

    /** @var string */
    public $caldav_etag;

    /** @var string export|import|bidirectional */
    public $direction;

    /** @var string */
    public $last_sync_at;

    /** @var string synced|pending|error|conflict */
    public $sync_status;

    /** @var string|null */
    public $error_message;

    public static function tableName(): string
    {
        return 'icalsync_items';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->direction = 'export';
        $this->sync_status = 'pending';
    }

    /**
     * Find a sync item by entity type and ID.
     *
     * @param string $entityType
     * @param int $entityId
     * @return self|null
     */
    public static function findByEntity(string $entityType, int $entityId): ?self
    {
        $where = [
            new DataBaseWhere('entity_type', $entityType),
            new DataBaseWhere('entity_id', $entityId),
        ];
        $item = new self();
        return $item->loadWhere($where) ? $item : null;
    }

    /**
     * Find a sync item by CalDAV UID.
     *
     * @param string $caldavUid
     * @return self|null
     */
    public static function findByCaldavUid(string $caldavUid): ?self
    {
        $item = new self();
        $where = [new DataBaseWhere('caldav_uid', $caldavUid)];
        return $item->loadWhere($where) ? $item : null;
    }

    /**
     * Find items by sync status.
     *
     * @param string $status
     * @param int $limit
     * @return self[]
     */
    public static function findByStatus(string $status, int $limit = 50): array
    {
        $where = [new DataBaseWhere('sync_status', $status)];
        return self::all($where, ['last_sync_at' => 'ASC'], 0, $limit);
    }

    /**
     * Returns the calendar this item belongs to.
     *
     * @return ICalSyncCalendar|null
     */
    public function getCalendar(): ?ICalSyncCalendar
    {
        if (empty($this->calendar_id)) {
            return null;
        }
        $calendar = new ICalSyncCalendar();
        return $calendar->loadFromCode($this->calendar_id) ? $calendar : null;
    }
}
