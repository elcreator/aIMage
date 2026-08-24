<?php

namespace EvolutionCMS\aIMage\Http\Controllers;

use EvolutionCMS\aIMage\Gateway\GatewayException;
use EvolutionCMS\aIMage\Gateway\ModelCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The model picker and the numbers behind it.
 *
 * This is the endpoint that answers "when a person picks a model, roughly how
 * long will it take and what will the call cost" — so it does not simply
 * forward the catalogue. It resolves the priced *variant* for the controls the
 * request would actually send, and it passes the latency source through
 * untouched so the page can distinguish a measurement from a prior. A number
 * presented without that provenance is a number somebody will treat as a
 * quote.
 */
class CatalogController extends Controller
{
    public function models(Request $request): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $client = $this->client();

        if ($client === null) {
            // Not an error. The page renders its "enter a key" state from this.
            return $this->ok(['key' => $this->keyState(), 'models' => [], 'groups' => []]);
        }

        $catalog = $this->catalog($client);

        try {
            $snapshot = $catalog->snapshot((bool) $request->query('refresh'));
        } catch (GatewayException $e) {
            return $this->fail(
                $e->isAuthFailure() ? 'key_rejected' : 'catalog_unavailable',
                $e->getMessage(),
                $e->isAuthFailure() ? 403 : 502
            );
        }

        return $this->ok([
            'key' => $this->keyState(),
            'currency' => $catalog->currency(),
            'legend' => $catalog->legend(),
            'stale' => (bool) ($snapshot['stale'] ?? false),
            // Grouped by the job the manager is choosing for, rather than
            // handed over as one flat list of sixty-eight: the text model and
            // the image model are different decisions with different criteria.
            'groups' => [
                'text' => $this->describe($catalog, ModelCatalog::ACTION_CHAT),
                'image' => $this->describe($catalog, [
                    ModelCatalog::ACTION_TEXT_TO_IMAGE,
                    ModelCatalog::ACTION_IMAGES_AND_TEXT_TO_IMAGE,
                ]),
                'voice' => $this->describe($catalog, ModelCatalog::ACTION_TRANSCRIBE),
                'speech' => $this->describe($catalog, ModelCatalog::ACTION_SPEAK),
            ],
        ]);
    }

    /**
     * Price and time one prospective operation.
     *
     * Called as the manager changes a dropdown, so it must be cheap — the
     * catalogue is file-cached and nothing here touches the network on a warm
     * cache.
     */
    public function estimate(Request $request): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $client = $this->client();

        if ($client === null) {
            return $this->fail('no_key', __('aIMage::global.error_no_key'), 409);
        }

        $model = trim((string) $request->query('model'));
        $count = max(1, min(1000, (int) $request->query('count', 1)));
        $action = trim((string) $request->query('action', 'image'));

        $controls = array_filter([
            'size' => $request->query('size'),
            'quality' => $request->query('quality'),
            'background' => $request->query('background'),
            'aspect_ratio' => $request->query('aspect_ratio'),
        ], static fn ($value) => is_string($value) && $value !== '');

        $estimator = $this->estimator($client);

        try {
            $estimate = match ($action) {
                'upscale' => $estimator->upscale($count),
                'chat' => $estimator->chat($model, (int) $request->query('prompt_chars', 400)),
                'transcribe' => $estimator->transcribe($model, (float) $request->query('seconds', 30)),
                'speak' => $estimator->speak($model, (int) $request->query('chars', 400)),
                default => $estimator->image($model, $count, $controls),
            };
        } catch (GatewayException $e) {
            return $this->fail('catalog_unavailable', $e->getMessage(), 502);
        }

        return $this->ok(['estimate' => $estimate->toArray()]);
    }

    /**
     * Folders and images this manager may work with.
     *
     * Never a raw directory listing: `ImageScope` filters both by the CMS's
     * `file_groups` rules and by the allowed image extensions, so what comes
     * back here is exactly what the file manager would show the same person.
     */
    public function files(Request $request): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $scope = $this->scope();
        $folder = (string) $request->query('folder', '');
        $recursive = (bool) $request->query('recursive');

        return $this->ok([
            'folder' => $folder,
            'unrestricted' => $scope->isUnrestricted(),
            'output_folder' => $scope->outputFolder(),
            'extensions' => $scope->allowedExtensions(),
            'folders' => $scope->listFolders($folder),
            'images' => $scope->listImages($folder, $recursive),
        ]);
    }

    /**
     * Reduce catalogue entries to what a picker needs.
     *
     * @param string|string[] $actions
     */
    private function describe(ModelCatalog $catalog, string|array $actions): array
    {
        $described = [];

        foreach ($catalog->forAction($actions) as $model) {
            $described[] = [
                'model' => (string) $model['model'],
                'title' => (string) ($model['title'] ?? $model['model']),
                'provider' => (string) ($model['provider'] ?? ''),
                'actions' => (array) ($model['actions'] ?? []),
                'controls' => (array) ($model['controls'] ?? []),
                // Passed through whole rather than flattened to a single
                // number: `basis` says whether the price is a tariff or a
                // guess, and `source` says whether the latency was measured or
                // seeded. The page shows both.
                'price' => (array) ($model['price'] ?? []),
                'latency' => (array) ($model['latency'] ?? []),
                'has_variants' => !empty($model['variants']),
            ];
        }

        return $described;
    }
}
