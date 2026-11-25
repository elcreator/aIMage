<?php

use Elcreator\aIMage\aIMageServiceProvider;

test('the module registers an image icon from the available Evo icon set', function (bool $tabler, string $icon) {
    $stylesheet = EVO_BASE_PATH . 'manager/media/style/common/icons/manager-icons.css';
    if ($tabler) {
        aimageEnsureDir(dirname($stylesheet));
        file_put_contents($stylesheet, '.evo-icon-file-type-jpg {}');
    }

    try {
        $app = new class {
            public array $module = [];

            public function registerRoutingModule(string $name, string $file, string $icon): void
            {
                $this->module = [$name, realpath($file), $icon];
            }
        };
        $provider = new class($app) extends aIMageServiceProvider {
            public function registerManagerModule(): void
            {
                parent::registerManagerModule();
            }
        };

        $provider->registerManagerModule();

        expect($app->module)->toBe([
            aIMageServiceProvider::MODULE_NAME,
            realpath(dirname(__DIR__, 2) . '/src/Http/routes.php'),
            $icon,
        ]);
    } finally {
        if ($tabler) {
            unlink($stylesheet);
        }
    }
})->with([
    'Evo 3.5' => [false, 'fa fa-file-image-o'],
    'Evo with Tabler' => [true, 'evo-icon evo-icon-file-type-jpg'],
]);
