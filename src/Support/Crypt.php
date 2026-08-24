<?php

namespace EvolutionCMS\aIMage\Support;

use RuntimeException;

/**
 * Authenticated encryption for the stored API keys.
 *
 * Evolution CMS registers no Laravel encrypter and has no `app.key`, so this
 * package brings its own rather than storing keys in the clear. A database
 * dump is a routine artefact — handed to a host, copied to staging — and an
 * API key that travels with one is an API key someone else can spend.
 *
 * The secret comes from `AIMAGE_SECRET` when the operator sets one, otherwise
 * from a generated file outside the database. Keeping it out of the database
 * is the entire point: a secret stored beside the ciphertext protects nothing.
 */
final class Crypt
{
    private const PREFIX = 'aimg1:';
    private const CIPHER = 'aes-256-gcm';

    private static ?string $secret = null;

    /** Returns a self-describing string safe to put in a settings row. */
    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::secret(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AIMage could not encrypt the API key.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value written by encrypt().
     *
     * A value without the prefix is returned as-is: keys entered before this
     * package encrypted them, or written by hand into the settings table, must
     * keep working rather than being read back as gibberish.
     *
     * Returns null when the value is ours but will not decrypt — a rotated
     * secret, or a truncated column — because a corrupt key must present as
     * "no key configured" and prompt for a new one, not as a mysterious 401.
     */
    public static function decrypt(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (!str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plaintext = openssl_decrypt(
            substr($raw, 28),
            self::CIPHER,
            self::secret(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );

        return $plaintext === false ? null : $plaintext;
    }

    /** Whether a stored value is one of ours, for migration and diagnostics. */
    public static function isEncrypted(?string $value): bool
    {
        return str_starts_with(trim((string) $value), self::PREFIX);
    }

    // ------------------------------------------------------------------

    private static function secret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        $configured = trim((string) (getenv('AIMAGE_SECRET') ?: ''));

        if ($configured !== '') {
            return self::$secret = hash('sha256', $configured, true);
        }

        return self::$secret = hash('sha256', self::fileSecret(), true);
    }

    /**
     * Read, or create once, the generated secret.
     *
     * `core/custom/` is the sanctioned place for site-local state and is not
     * web-accessible. If it cannot be written — a read-only deployment — the
     * failure is loud, because falling back to a predictable secret would mean
     * "encrypted" keys anyone with the dump could read.
     */
    private static function fileSecret(): string
    {
        $dir = defined('EVO_CORE_PATH')
            ? rtrim(EVO_CORE_PATH, '/\\') . '/custom'
            : sys_get_temp_dir();

        $file = $dir . '/aimage.secret';

        if (is_file($file)) {
            $secret = trim((string) file_get_contents($file));

            if ($secret !== '') {
                return $secret;
            }
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('AIMage cannot create ' . $dir . ' to hold its encryption secret.');
        }

        $secret = bin2hex(random_bytes(32));

        if (@file_put_contents($file, $secret, LOCK_EX) === false) {
            throw new RuntimeException(
                'AIMage cannot write ' . $file . '. Set the AIMAGE_SECRET environment variable instead, '
                . 'or make that directory writable — API keys are not stored unencrypted.'
            );
        }

        @chmod($file, 0600);

        return $secret;
    }
}
