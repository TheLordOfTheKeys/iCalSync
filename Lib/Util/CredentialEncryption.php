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
 * Credential encryption/decryption using libsodium.
 *
 * Encrypts sensitive credentials (app-specific passwords) at rest.
 * Key is derived from FS_DB_NAME, scoped to this plugin.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class CredentialEncryption
{
    /**
     * Encrypt a plaintext string.
     *
     * @param string $plaintext
     * @return string base64-encoded nonce + ciphertext
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($plaintext);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a previously encrypted string.
     *
     * @param string $ciphertext base64-encoded nonce + ciphertext
     * @return string|null Decrypted plaintext, or null on failure
     */
    public static function decrypt(string $ciphertext): ?string
    {
        $key = self::getKey();
        $decoded = base64_decode($ciphertext, true);
        if (false === $decoded) {
            return null;
        }

        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $encrypted = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

        if (false === $nonce || false === $encrypted) {
            return null;
        }

        $plaintext = sodium_crypto_secretbox_open($encrypted, $nonce, $key);
        if (false === $plaintext) {
            return null;
        }

        return $plaintext;
    }

    /**
     * Derive a deterministic encryption key from the FS database name.
     *
     * @return string 32-byte key for sodium_crypto_secretbox
     */
    private static function getKey(): string
    {
        $seed = defined('FS_DB_NAME') ? FS_DB_NAME : 'icalsync_default';
        return hash('sha256', $seed . '_icalsync_secret', true);
    }
}
