<?php

namespace EvolutionCMS\aIMage\Http\Controllers;

use EvolutionCMS\aIMage\Console\BatchHandler;
use EvolutionCMS\aIMage\Support\Config;
use EvolutionCMS\Services\SystemTasks\SystemTaskRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The module page.
 *
 * Rendered inside the manager's main frame, so it owns its whole document. It
 * hands the front end everything that cannot be derived in the browser: the
 * route prefix (which is a hash of the module name and therefore not
 * guessable), the CSRF token, and the two health facts that decide whether the
 * page can honestly promise unattended batching.
 */
class PageController extends Controller
{
    public function index(Request $request): View|string
    {
        if (!$this->authorized()) {
            return view('aIMage::denied');
        }

        $scope = $this->scope();

        return view('aIMage::page', [
            // Every fetch the page makes is relative to this. Nothing in the
            // JavaScript spells out a module path, because the prefix moves if
            // the module is ever renamed.
            'baseUrl' => rtrim($request->getBaseUrl() . $request->getPathInfo(), '/'),
            'csrfToken' => csrf_token(),
            'keyState' => $this->keyState(),
            'canManageSiteKey' => $this->canManageSiteKey(),
            'defaults' => [
                'text_model' => Config::defaultModel('text'),
                'image_model' => Config::defaultModel('image'),
                'voice_model' => Config::defaultModel('voice'),
                'speech_model' => Config::defaultModel('speech'),
                'output_folder' => $scope->outputFolder(),
            ],
            'limits' => [
                'max_images_per_job' => (int) Config::limit('max_images_per_job', 200),
                'approval_threshold_eur' => (float) Config::limit('approval_threshold_eur', 5.0),
            ],
            'scope' => [
                'unrestricted' => $scope->isUnrestricted(),
                'extensions' => $scope->allowedExtensions(),
            ],
            // Batching is only real if something is going to run it. Saying so
            // up front beats a manager queueing an overnight job that silently
            // never starts because nothing calls schedule:run.
            'batching' => $this->batchingState(),
        ]);
    }

    /**
     * Only a super administrator may set the key everyone else falls back to.
     *
     * Role 1 rather than a permission, matching how the CMS gates its own
     * site-wide settings — a manager with `aimage` may spend the site key but
     * may not change what it is.
     */
    private function canManageSiteKey(): bool
    {
        return (int) ($_SESSION['mgrRole'] ?? 0) === 1;
    }

    /**
     * Can queued work actually be carried out on this installation?
     *
     * Two independent requirements, reported separately because the fixes are
     * different: the task type has to be registered (an old core, or the
     * provider not loaded), and the scheduler has to be alive (nothing is
     * calling `schedule:run` or `schedule:work`).
     */
    private function batchingState(): array
    {
        $registered = class_exists(SystemTaskRegistry::class)
            && SystemTaskRegistry::has(BatchHandler::TYPE);

        $schedulerHealthy = null;

        try {
            $status = (new \EvolutionCMS\Services\SystemTasks\SchedulerHealthService())->getStatusPayload();
            $schedulerHealthy = ($status['status'] ?? 'unhealthy') !== 'unhealthy';
        } catch (\Throwable $e) {
            // An installation without the health tables. Unknown, not broken.
            $schedulerHealthy = null;
        }

        return [
            'registered' => $registered,
            'scheduler_healthy' => $schedulerHealthy,
            'available' => $registered && $schedulerHealthy !== false,
        ];
    }
}
