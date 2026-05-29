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
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ICalSync\Lib\Service\CalDavClient;
use FacturaScripts\Plugins\ICalSync\Lib\Util\CredentialEncryption;

/**
 * Cuenta de iCloud por usuario para sincronización CalDAV privada.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ICalSyncUserAccount extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string */
    public $nick;

    /** @var bool */
    public $enabled;

    /** @var string */
    public $apple_id;

    /** @var string|null Almacena el cifrado: base64(nonce + ciphertext) */
    public $app_specific_password;

    /** @var string */
    public $calendar_url;

    /** @var string */
    public $principal_url;

    /** @var bool */
    public $sync_enabled;

    /** @var string */
    public $last_sync_at;

    /** @var bool */
    public $show_in_calendar;

    /** @var bool */
    public $show_in_dashboard;

    /** @var string */
    public $created_at;

    /** @var string */
    public $updated_at;

    public static function tableName(): string
    {
        return 'icalsync_user_accounts';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->enabled = true;
        $this->sync_enabled = false;
        $this->show_in_calendar = true;
        $this->show_in_dashboard = true;
        $this->created_at = Tools::dateTime();
    }

    /**
     * Load the user account for a given nick.
     *
     * @param string $nick
     * @return self|null
     */
    public static function findByNick(string $nick): ?self
    {
        $account = new self();
        $where = [new DataBaseWhere('nick', $nick)];
        return $account->loadWhere($where) ? $account : null;
    }

    /**
     * Load all active user accounts with sync enabled.
     *
     * @param int $limit
     * @param int $offset
     * @return self[]
     */
    public static function findActive(int $limit = 10, int $offset = 0): array
    {
        $where = [
            new DataBaseWhere('enabled', true),
            new DataBaseWhere('sync_enabled', true),
        ];
        return self::all($where, ['last_sync_at' => 'ASC'], $offset, $limit);
    }

    public function test(): bool
    {
        if (empty($this->nick)) {
            Tools::log()->error('user-not-found');
            return false;
        }

        $this->apple_id = Tools::noHtml($this->apple_id ?? '');
        $this->calendar_url = Tools::noHtml($this->calendar_url ?? '');
        $this->principal_url = Tools::noHtml($this->principal_url ?? '');
        $this->enabled = (bool)$this->enabled;
        $this->sync_enabled = (bool)$this->sync_enabled;
        $this->show_in_calendar = (bool)$this->show_in_calendar;
        $this->show_in_dashboard = (bool)$this->show_in_dashboard;

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
     * Test the iCloud CalDAV connection for this user account.
     *
     * @return array{success: bool, message: string, principal_url?: string, calendars?: array}
     */
    public function testConnection(): array
    {
        if (empty($this->apple_id)) {
            return [
                'success' => false,
                'message' => 'apple-id-is-required',
            ];
        }

        $password = $this->getDecryptedPassword();
        if (null === $password) {
            return [
                'success' => false,
                'message' => 'credential-decrypt-error',
            ];
        }

        $caldavUrl = 'https://caldav.icloud.com/';

        $client = new CalDavClient($caldavUrl, $this->apple_id, $password);

        // Try well-known discovery
        $principalUrl = $client->discoverPrincipal($caldavUrl);

        if (empty($principalUrl)) {
            $principalUrl = $this->principal_url;
        }

        if (empty($principalUrl)) {
            return [
                'success' => false,
                'message' => 'test-connection-failure',
            ];
        }

        // Discover calendars
        $calendars = $client->discoverCalendars($principalUrl);

        return [
            'success' => true,
            'message' => 'test-connection-success',
            'principal_url' => $principalUrl,
            'calendars' => $calendars,
        ];
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
        return strlen($value) > 40 && 1 === preg_match('/^[A-Za-z0-9+\/=]+$/', $value);
    }
}
