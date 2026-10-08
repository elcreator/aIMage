<?php

namespace Elcreator\aIMage\Support;

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

        foreach (array_merge([self::secret()], self::previousSecrets()) as $secret) {
            $plaintext = self::open($raw, $secret);

            if ($plaintext !== null) {
                return $plaintext;
            }
        }

        return null;
    }

    /**
     * Is this value readable only with an OLD secret?
     *
     * That is the signature of a rotation or a move (file secret -> `AIMAGE_SECRET`,
     * or a new `AIMAGE_SECRET` with the old one in `AIMAGE_SECRET_PREVIOUS`): the key
     * is still good, it just should be written again under the secret now in force.
     * Nobody has to know a secret for that to happen - see ApiKeys.
     */
    public static function needsRotation(?string $value): bool
    {
        $value = trim((string) $value);

        if (!str_starts_with($value, self::PREFIX)) {
            return false;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) < 29 || self::open($raw, self::secret()) !== null) {
            return false;
        }

        foreach (self::previousSecrets() as $secret) {
            if (self::open($raw, $secret) !== null) {
                return true;
            }
        }

        return false;
    }

    /** Forget the memoised secrets (a test, or a long-running worker after the environment changed). */
    public static function flush(): void
    {
        self::$secret = null;
    }

    private static function open(string $raw, string $secret): ?string
    {
        $plaintext = openssl_decrypt(substr($raw, 28), self::CIPHER, $secret, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * The secrets that used to be in force, newest first, as 32-byte keys:
     *
     * - `AIMAGE_SECRET_PREVIOUS`, comma-separated, for a deliberate rotation;
     * - the generated `core/custom/aimage.secret`, when `AIMAGE_SECRET` has since taken over.
     *   The person who sets the variable usually never knew the file existed, and
     *   should not have to: their saved keys keep working and are rewritten on first use.
     *
     * Read-only - this never creates the file.
     *
     * @return string[]
     */
    private static function previousSecrets(): array
    {
        $secrets = [];

        foreach (preg_split('/[\s,]+/', (string) getenv('AIMAGE_SECRET_PREVIOUS'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $old) {
            $secrets[] = hash('sha256', $old, true);
        }

        if (trim((string) (getenv('AIMAGE_SECRET') ?: '')) !== '') {
            $file = self::secretFile();

            if (is_file($file) && trim((string) file_get_contents($file)) !== '') {
                $secrets[] = hash('sha256', trim((string) file_get_contents($file)), true);
            }
        }

        return array_values(array_unique($secrets));
    }

    private static function secretFile(): string
    {
        return (defined('EVO_CORE_PATH') ? rtrim(EVO_CORE_PATH, '/\\') . '/custom' : sys_get_temp_dir()) . '/aimage.secret';
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
        $file = self::secretFile();
        $dir = dirname($file);

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
