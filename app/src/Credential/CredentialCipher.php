<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Credential;

/**
 * Symetrické šifrování přístupových údajů uložených v DB (libsodium secretbox).
 * Master klíč žije jen v env (APP_CREDENTIALS_KEY, base64 32 B) — DB drží pouze
 * šifrované hodnoty. Bez klíče (nenakonfigurováno) cipher mlčky neaktivní:
 * encrypt/decrypt nelze, volající spadne na env fallback.
 */
final class CredentialCipher
{
    private readonly ?string $key;

    public function __construct(
        #[\SensitiveParameter]
        string $appCredentialsKey,
    ) {
        $decoded = $appCredentialsKey !== '' ? base64_decode($appCredentialsKey, true) : false;
        $this->key = ($decoded !== false && \strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
            ? $decoded
            : null;
    }

    public function isReady(): bool
    {
        return $this->key !== null;
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        if ($this->key === null) {
            throw new \LogicException('APP_CREDENTIALS_KEY není nastaven nebo není 32 B (base64) — nelze šifrovat.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    /**
     * Dešifruje; vrací null, pokud klíč chybí nebo data nejdou ověřit
     * (poškozená/cizí hodnota) — volající pak použije fallback.
     */
    public function decrypt(string $stored): ?string
    {
        if ($this->key === null) {
            return null;
        }
        $raw = base64_decode($stored, true);
        if ($raw === false || \strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        return $plain === false ? null : $plain;
    }
}
