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

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/**
 * Calendario iCloud descubierto asociado a una cuenta.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ICalSyncCalendar extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $account_id;

    /** @var string */
    public $calendar_name;

    /** @var string */
    public $calendar_url;

    /** @var string */
    public $calendar_color;

    /** @var string */
    public $ctag;

    /** @var string */
    public $sync_token;

    /** @var string shared|private */
    public $calendar_type;

    /** @var bool */
    public $enabled;

    /** @var string */
    public $last_sync_at;

    public static function tableName(): string
    {
        return 'icalsync_calendars';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->enabled = true;
        $this->calendar_type = 'shared';
    }

    /**
     * Returns the account this calendar belongs to.
     *
     * @return ICalSyncAccount
     */
    public function getAccount(): ICalSyncAccount
    {
        $account = new ICalSyncAccount();
        $account->loadFromCode($this->account_id);
        return $account;
    }
}
