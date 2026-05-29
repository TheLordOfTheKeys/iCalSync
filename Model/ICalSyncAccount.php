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
use FacturaScripts\Plugins\ICalSync\Lib\Util\CredentialEncryption;

/**
 * Cuenta compartida de iCloud para sincronización CalDAV.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ICalSyncAccount extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var bool */
    public $enabled;

    /** @var string */
    public $account_name;

    /** @var string */
    public $apple_id;

    /** @var string|null Almacena el cifrado: base64(nonce + ciphertext) */
    public $app_specific_password;

    /** @var string */
    public $calendar_url;

    /** @var string */
    public $principal_url;

    /** @var int */
    public $sync_frequency_minutes;

    /** @var string */
    public $log_level;

    /** @var string */
    public $last_sync_at;

    /** @var string */
    public $created_at;

    /** @var string */
    public $updated_at;

    public static function tableName(): string
    {
        return 'icalsync_accounts';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->enabled = true;
        $this->sync_frequency_minutes = 15;
        $this->log_level = 'warning';
        $this->created_at = Tools::dateTime();
    }

    public function test(): bool
    {
        if (empty($this->account_name)) {
            Tools::log()->error('name-is-required');
            return false;
        }

        if (empty($this->apple_id)) {
            Tools::log()->error('apple-id-is-required');
            return false;
        }

        $this->account_name = Tools::noHtml($this->account_name);
        $this->apple_id = Tools::noHtml($this->apple_id);
        $this->calendar_url = Tools::noHtml($this->calendar_url ?? '');
        $this->principal_url = Tools::noHtml($this->principal_url ?? '');
        $this->log_level = Tools::noHtml($this->log_level ?? 'warning');
        $this->enabled = (bool)$this->enabled;
        $this->sync_frequency_minutes = max(1, (int)$this->sync_frequency_minutes);

        return parent::test();
    }

    public function save(): bool
    {
        // Encrypt password on save if plaintext is present
        if (!empty($this->app_specific_password) && false === $this->isEncrypted($this->app_specific_password)) {
            $this->app_specific_password = CredentialEncryption::encrypt($this->app_specific_password);
        }
        $this->updated_at = Tools::dateTime();
        return parent::save();
    }

    /**
     * Returns the decrypted app-specific password for use at runtime.
     * Never log or expose this value.
     *
     * @return string|null
     */
    public function getDecryptedPassword(): ?string
    {
        if (empty($this->app_specific_password)) {
            return null;
        }
        return CredentialEncryption::decrypt($this->app_specific_password);
    }

    /**
     * Set the app-specific password in plaintext. It will be encrypted on save().
     *
     * @param string $plaintext
     */
    public function setPlainPassword(string $plaintext): void
    {
        $this->app_specific_password = $plaintext;
    }

    /**
     * Check if a string looks like already-encrypted data.
     *
     * @param string $value
     * @return bool
     */
    private function isEncrypted(string $value): bool
    {
        // Encrypted values are base64-encoded (length > 40) and contain non-printable chars
        return strlen($value) > 40 && 1 === preg_match('/^[A-Za-z0-9+\/=]+$/', $value);
    }
}
