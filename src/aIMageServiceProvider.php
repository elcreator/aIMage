<?php

namespace Elcreator\aIMage;

use Elcreator\aIMage\Console\BatchHandler;
use Elcreator\aIMage\Support\Config;
use EvolutionCMS\ServiceProvider;
use EvolutionCMS\Services\SystemTasks\SystemTaskRegistry;

class aIMageServiceProvider extends ServiceProvider
{
    /** The manager menu entry, and the name the route prefix is derived from. */
    public const MODULE_NAME = 'AIMage';

    public function register(): void
    {
        // Config has to be merged in `register()` rather than `boot()`,
        // because the task registration below reads it, and a worker resolves
        // handlers before any boot method of ours has run.
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/aIMage.php', 'cms.settings.aIMage');

        $this->registerTaskType();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
        $this->loadTranslationsFrom(dirname(__DIR__) . '/lang', 'aIMage');
        $this->loadViewsFrom(dirname(__DIR__) . '/views', 'aIMage');

        $this->ensureTranslationFallback();

        if (!Config::isEnabled()) {
            return;
        }

        $this->registerManagerModule();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__) . '/public' => public_path('assets/modules/aimage'),
            ], 'public');
        }
    }

    /**
     * Declare `aimage.batch` to the CMS task queue.
     *
     * Registered unconditionally, and in `register()`, because the consumer is
     * `system:task-worker` — a CLI process with no manager session, no module
     * menu and no routes. If this were gated on being in the backend, queued
     * batches would be picked up and immediately failed with
     * TASK_TYPE_NOT_ALLOWED.
     *
     * `concurrent` is the important part. The three types the CMS ships are
     * exclusive because they rewrite the installation; a batch of images does
     * not, and marking it exclusive would mean one manager's overnight job
     * blocked every site update until morning.
     */
    protected function registerTaskType(): void
    {
        if (!class_exists(SystemTaskRegistry::class)) {
            // An older core without the task registry. The module still works;
            // only the queue-backed batching is unavailable, and the manager
            // page says so rather than queueing work nothing will run.
            return;
        }

        if (SystemTaskRegistry::has(BatchHandler::TYPE)) {
            return;
        }

        SystemTaskRegistry::register(BatchHandler::TYPE, BatchHandler::class, [
            'mode' => SystemTaskRegistry::MODE_CONCURRENT,
            'parallelism' => max(1, (int) Config::limit('parallelism', 3)),
            'permissions' => ['aimage'],
            'label' => 'AIMage batch',
        ]);
    }

    /**
     * Add the manager menu entry and the routes behind it.
     *
     * `registerRoutingModule()` does both, and refuses outside the backend, so
     * the routes exist only where a manager session can reach them.
     */
    protected function registerManagerModule(): void
    {
        $this->app->registerRoutingModule(
            self::MODULE_NAME,
            __DIR__ . '/Http/routes.php',
            'fa fa-wand-magic-sparkles'
        );
    }

    /**
     * Give the translator a fallback when the CMS has not set one.
     *
     * Evolution's container answers null to getFallbackLocale(), so under a
     * manager language this package ships no file for, every line would render
     * as its own key. Only filled in when nothing else has set one.
     */
    protected function ensureTranslationFallback(): void
    {
        try {
            $translator = $this->app['translator'];
        } catch (\Throwable $e) {
            return;
        }

        if (!method_exists($translator, 'getFallback') || !method_exists($translator, 'setFallback')) {
            return;
        }

        if ((string) $translator->getFallback() !== '') {
            return;
        }

        $fallback = trim((string) config('app.fallback_locale', 'en'));

        $translator->setFallback($fallback !== '' ? $fallback : 'en');
    }
}
