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

namespace FacturaScripts\Plugins\ICalSync\Lib\Util;

/**
 * Conflict resolution strategy enum for sync conflicts.
 *
 * Determines how the sync engine behaves when both local and remote
 * copies of an event have been modified since the last sync.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
enum ConflictResolutionStrategy: string
{
    /**
     * Compare last-modified timestamps; keep the newest version.
     * Default behavior, safest for automated sync.
     */
    case LAST_WRITE_WINS = 'last_write_wins';

    /**
     * Do NOT overwrite either side. Create a conflict record
     * with full details and mark the item for manual review.
     */
    case MANUAL = 'manual';

    /**
     * Remote (iCloud/CalDAV) version always overwrites local.
     */
    case SOURCE_WINS = 'source_wins';

    /**
     * Local (FacturaScripts) version always overwrites remote.
     */
    case DESTINATION_WINS = 'destination_wins';

    /**
     * Get the human-readable label for a strategy.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::LAST_WRITE_WINS => 'Ultima modificación gana',
            self::MANUAL => 'Resolución manual',
            self::SOURCE_WINS => 'Gana versión remota (iCloud)',
            self::DESTINATION_WINS => 'Gana versión local (FacturaScripts)',
        };
    }

    /**
     * Get all strategies as key-value pairs for select widgets.
     *
     * @return array
     */
    public static function options(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            $result[$case->value] = $case->label();
        }
        return $result;
    }

    /**
     * Get the default strategy.
     *
     * @return self
     */
    public static function default(): self
    {
        return self::LAST_WRITE_WINS;
    }
}
