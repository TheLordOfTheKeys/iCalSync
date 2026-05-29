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
use FacturaScripts\Core\Tools;

/**
 * Registro de auditoría de operaciones de sincronización.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ICalSyncLog extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int|null */
    public $account_id;

    /** @var string|null Evento|Cita */
    public $entity_type;

    /** @var int|null */
    public $entity_id;

    /** @var string|null */
    public $caldav_uid;

    /** @var string import|export|delete */
    public $operation;

    /** @var string success|error|conflict|skipped */
    public $status;

    /** @var string|null */
    public $message;

    /** @var string|null JSON details */
    public $details;

    /** @var string */
    public $created_at;

    public static function tableName(): string
    {
        return 'icalsync_logs';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->created_at = Tools::dateTime();
    }

    /**
     * Create a log entry with the given parameters.
     *
     * @param array $data
     * @return bool
     */
    public static function log(array $data): bool
    {
        $log = new self();
        $log->account_id = $data['account_id'] ?? null;
        $log->entity_type = $data['entity_type'] ?? null;
        $log->entity_id = $data['entity_id'] ?? null;
        $log->caldav_uid = $data['caldav_uid'] ?? null;
        $log->operation = $data['operation'] ?? 'import';
        $log->status = $data['status'] ?? 'success';
        $log->message = $data['message'] ?? '';
        $log->details = $data['details'] ?? null;
        return $log->save();
    }

    /**
     * Quick helper to log a success.
     *
     * @param string $operation
     * @param string $message
     * @param array $extra
     * @return bool
     */
    public static function logSuccess(string $operation, string $message, array $extra = []): bool
    {
        return self::log(array_merge($extra, [
            'operation' => $operation,
            'status' => 'success',
            'message' => $message,
        ]));
    }

    /**
     * Quick helper to log an error.
     *
     * @param string $operation
     * @param string $message
     * @param array $extra
     * @return bool
     */
    public static function logError(string $operation, string $message, array $extra = []): bool
    {
        return self::log(array_merge($extra, [
            'operation' => $operation,
            'status' => 'error',
            'message' => $message,
        ]));
    }

    /**
     * Quick helper to log a conflict.
     *
     * @param string $operation
     * @param string $message
     * @param array $extra
     * @return bool
     */
    public static function logConflict(string $operation, string $message, array $extra = []): bool
    {
        return self::log(array_merge($extra, [
            'operation' => $operation,
            'status' => 'conflict',
            'message' => $message,
        ]));
    }

    /**
     * Quick helper to log a skipped operation.
     *
     * @param string $operation
     * @param string $message
     * @param array $extra
     * @return bool
     */
    public static function logSkipped(string $operation, string $message, array $extra = []): bool
    {
        return self::log(array_merge($extra, [
            'operation' => $operation,
            'status' => 'skipped',
            'message' => $message,
        ]));
    }
}
