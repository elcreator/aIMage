<?php

use Elcreator\aIMage\aIMageServiceProvider;
use Elcreator\aIMage\Gateway\Dialect;

test('the suite loads this checkout, not a copy installed in the core it borrows', function (string $class) {
    $file = (new ReflectionClass($class))->getFileName();

    expect(realpath($file))->toStartWith(realpath(dirname(__DIR__, 2) . '/src') . DIRECTORY_SEPARATOR);
})->with([aIMageServiceProvider::class, Dialect::class]);
