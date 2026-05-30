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

/**
 * Cron job registration for ICalSync plugin.
 *
 * Registered via FacturaScripts cron system when available.
 * For standalone usage, use cron-trigger.php directly.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
if (class_exists('\FacturaScripts\Core\Template\CronClass')) {
    class Cron extends \FacturaScripts\Core\Template\CronClass
    {
        public function run(): void
        {
            $frequency = (int) \FacturaScripts\Core\Tools::settings('icalsync', 'sync_frequency_minutes', 15);
            if ($frequency < 1) {
                $frequency = 15;
            }

            $this->job('icalsync-sync')
                ->every($frequency . ' minutes')
                ->run(function () {
                    CronJob\ICalSync::run();
                });
        }
    }
} else {
    // Fallback: empty class for older FS versions without CronClass
    class Cron
    {
    }
}
