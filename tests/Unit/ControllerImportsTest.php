<?php

/**
 * A class a controller names without importing it is a fatal error on the page that
 * uses it, and nothing else in the suite loads controllers (they need a running CMS).
 * That is how `Account` once shipped missing from two `use` lists. This reads the
 * source instead: every `Name::` / `new Name` in a controller must be imported, live in
 * the controller's own namespace, or be PHP's own.
 */

test('every class a controller names is imported or local', function () {
    $dir = dirname(__DIR__, 2) . '/src/Http/Controllers';
    $problems = [];

    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        // Comments and strings would produce false hits.
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        preg_match_all('/^use\s+(?:[\w\\\\]+\\\\)?(\w+)(?:\s+as\s+(\w+))?\s*[;{]/m', $code, $uses, PREG_SET_ORDER);
        $known = ['self', 'static', 'parent'];
        foreach ($uses as $use) {
            $known[] = $use[2] ?? $use[1];
        }
        // Names declared in the same namespace (sibling controllers).
        foreach (glob($dir . '/*.php') as $sibling) {
            $known[] = basename($sibling, '.php');
        }

        preg_match_all('/(?<![\\\\\w$>:])([A-Z]\w+)\s*::|\bnew\s+([A-Z]\w+)/', $code, $hits, PREG_SET_ORDER);
        foreach ($hits as $hit) {
            $name = $hit[1] !== '' ? $hit[1] : $hit[2];
            if (!in_array($name, $known, true) && !class_exists($name) && !interface_exists($name)) {
                $problems[] = basename($file) . ': ' . $name;
            }
        }
    }

    expect(array_values(array_unique($problems)))->toBe([]);
});
