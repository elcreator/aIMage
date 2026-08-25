<?php

/**
 * Test bootstrap.
 *
 * AIMage has no vendor directory of its own — it is installed into an
 * Evolution CMS core and borrows that core's autoloader, which is also where
 * Pest, Eloquent and Guzzle come from. So the suite points at the core it is
 * developed against and maps this package's namespace on top.
 *
 * Everything the CMS would normally define at boot is stubbed here rather than
 * booting the CMS: these are unit tests against a real database, not
 * integration tests against a running site. The stub is deliberately thin — if
 * a test needs more of the CMS than this, it is testing the wrong thing.
 */

$core = getenv('EVO_CORE_PATH_TEST') ?: 'C:/projects/Opensource/evolution/core';
$core = rtrim(str_replace('\\', '/', $core), '/');

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "AIMage tests need an Evolution CMS core to borrow an autoloader from.\n"
        . "Looked in: {$core}\n"
        . "Set EVO_CORE_PATH_TEST to point at one.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

// This package's own namespace.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Elcreator\\aIMage\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

/**
 * A file tree for the suite to treat as the site.
 *
 * Fixed for the whole run because EVO_BASE_PATH is a constant, and torn down
 * at shutdown so a failed run leaves nothing behind.
 */
$root = sys_get_temp_dir() . '/aimage-tests-' . getmypid();
@mkdir($root . '/assets/images', 0775, true);
@mkdir($root . '/assets/aimage', 0775, true);
@mkdir($root . '/storage', 0775, true);

define('AIMAGE_TEST_ROOT', str_replace('\\', '/', $root));
define('EVO_BASE_PATH', AIMAGE_TEST_ROOT . '/');
define('EVO_CORE_PATH', AIMAGE_TEST_ROOT . '/core/');
define('EVO_STORAGE_PATH', AIMAGE_TEST_ROOT . '/storage');

// `evo()` guards a CSRF check behind these three.
define('IN_MANAGER_MODE', false);
define('IN_INSTALL_MODE', false);
define('EVO_API_MODE', false);

// Keys are encrypted at rest; pinning the secret keeps the suite from writing
// a real one into core/custom.
putenv('AIMAGE_SECRET=aimage-test-secret');

/**
 * Stands in for `EvolutionCMS\Core` so the package's `evo()` and `config()`
 * calls resolve.
 *
 * `EVO_CLASS` is the documented seam for this: `evo()` reflects the named
 * class and calls `getInstance()` on it.
 */
final class AIMageTestCore
{
    public static ?AIMageTestCore $instance = null;

    /** Settings `evo()->getConfig()` answers with; tests may write to it. */
    public static array $config = [
        'use_udperms' => true,
        'site_url' => 'https://example.test/',
    ];

    private \Illuminate\Config\Repository $repository;

    public function __construct()
    {
        $this->repository = new \Illuminate\Config\Repository([
            'cms' => ['settings' => ['aIMage' => require dirname(__DIR__) . '/config/aIMage.php']],
            'app' => ['fallback_locale' => 'en'],
        ]);
    }

    public function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function make(string $abstract, array $parameters = [])
    {
        return $abstract === 'config' ? $this->repository : null;
    }

    public function getConfig(string $key, $default = null)
    {
        return self::$config[$key] ?? $default;
    }

    /** Reset between tests so one test's config cannot leak into the next. */
    public static function reset(): void
    {
        self::$config = [
            'use_udperms' => true,
            'site_url' => 'https://example.test/',
        ];
    }
}

define('EVO_CLASS', AIMageTestCore::class);

// The package calls the `__()` translator helper for user-facing strings. The
// real one needs a booted container; the message text is not what these tests
// are about, so the key is echoed back.
if (!function_exists('__')) {
    function __($key = null, $replace = [], $locale = null)
    {
        $text = (string) $key;

        foreach ((array) $replace as $search => $value) {
            $text = str_replace(':' . $search, (string) $value, $text);
        }

        return $text;
    }
}

/**
 * Schema and fixtures.
 *
 * Required from here rather than left in a `Pest.php`: this package's tests
 * run against an Evolution CMS core's Pest binary, so Pest's own root is that
 * core and it would never discover a `Pest.php` living here. Loading the
 * helpers from the bootstrap makes the suite independent of where Pest thinks
 * it is.
 */
require __DIR__ . '/helpers.php';

register_shutdown_function(static function () use ($root) {
    $remove = static function (string $path) use (&$remove): void {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $remove($path . '/' . $entry);
        }

        @rmdir($path);
    };

    $remove($root);
});
