<?php

namespace Elcreator\aIMage\Services;

use Elcreator\aIMage\Gateway\GatewayException;
use Elcreator\aIMage\Gateway\ModelCatalog;

/**
 * The model picker and the numbers behind it, plus the files a manager may use.
 *
 * This is what answers "when a person picks a model, roughly how long will it
 * take and what will the call cost" — so it does not simply forward the
 * catalogue. It resolves the priced *variant* for the controls the request
 * would actually send, and it passes the latency source through untouched so
 * the caller can distinguish a measurement from a prior. A number presented
 * without that provenance is a number somebody will treat as a quote.
 */
final class Catalog
{
    public function __construct(private readonly Actor $actor)
    {
    }

    /**
     * The catalogue grouped by the job the manager is choosing for.
     *
     * Without a key this is not an error: the page renders its "enter a key"
     * state from the empty answer.
     *
     * @throws WorkbenchException
     */
    public function models(bool $refresh = false): array
    {
        $client = $this->actor->client();

        if ($client === null) {
            return ['key' => $this->actor->keyState(), 'models' => [], 'groups' => []];
        }

        $catalog = $this->actor->catalog($client);

        try {
            $snapshot = $catalog->snapshot($refresh);
        } catch (GatewayException $e) {
            throw new WorkbenchException(
                $e->isAuthFailure() ? 'key_rejected' : 'catalog_unavailable',
                $e->getMessage(),
                $e->isAuthFailure() ? 403 : 502
            );
        }

        return [
            'key' => $this->actor->keyState(),
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
        ];
    }

    /**
     * Price and time one prospective operation.
     *
     * Called as the manager changes a dropdown, so it must be cheap — the
     * catalogue is file-cached and nothing here touches the network on a warm
     * cache.
     *
     * @param array<string, mixed> $params model, count, action (image|upscale|chat|transcribe|speak),
     *                                     size, quality, background, aspect_ratio, prompt_chars, seconds, chars
     * @throws WorkbenchException
     */
    public function estimate(array $params): array
    {
        $client = $this->actor->requireClient();

        $model = trim((string) ($params['model'] ?? ''));
        $count = max(1, min(1000, (int) ($params['count'] ?? 1)));
        $action = trim((string) ($params['action'] ?? 'image')) ?: 'image';
        $controls = array_filter([
            'size' => $params['size'] ?? null,
            'quality' => $params['quality'] ?? null,
            'background' => $params['background'] ?? null,
            'aspect_ratio' => $params['aspect_ratio'] ?? null,
        ], static fn ($value) => is_string($value) && $value !== '');

        $estimator = $this->actor->estimator($client);

        try {
            $estimate = match ($action) {
                'upscale' => $estimator->upscale($count),
                'chat' => $estimator->chat($model, (int) ($params['prompt_chars'] ?? 400)),
                'transcribe' => $estimator->transcribe($model, (float) ($params['seconds'] ?? 30)),
                'speak' => $estimator->speak($model, (int) ($params['chars'] ?? 400)),
                default => $estimator->image($model, $count, $controls),
            };
        } catch (GatewayException $e) {
            throw new WorkbenchException('catalog_unavailable', $e->getMessage(), 502);
        }

        return ['estimate' => $estimate->toArray()];
    }

    /**
     * Folders and images this manager may work with.
     *
     * Never a raw directory listing: `ImageScope` filters both by the CMS's
     * `file_groups` rules and by the allowed image extensions, so what comes
     * back here is exactly what the file manager would show the same person.
     *
     * @throws WorkbenchException
     */
    public function files(string $requested = '', bool $recursive = false): array
    {
        $scope = $this->actor->scope();
        $requested = trim($requested);

        // One effective folder for both listings. Without this the browser
        // showed the folders of one directory beside the images of another:
        // `listFolders('')` starts at the image base, while `listImages('')`
        // would have walked the file root.
        $folder = $requested === '' ? $scope->imageBase() : $scope->resolveWriteFolder($requested);

        if ($folder === null) {
            throw new WorkbenchException('folder_denied', __('aIMage::global.error_folder_denied', ['folder' => $requested]));
        }

        return [
            'folder' => $folder,
            'parent' => $scope->parentFolder($folder),
            'base' => $scope->imageBase(),
            'unrestricted' => $scope->isUnrestricted(),
            'output_folder' => $scope->outputFolder(),
            'writable' => $scope->canWrite($folder . '/probe.png'),
            'extensions' => $scope->allowedExtensions(),
            'folders' => $scope->listFolders($folder),
            'images' => $scope->listImages($folder, $recursive),
        ];
    }

    /**
     * Everything the preview pane shows about one image.
     *
     * Separate from the listing so that opening a folder of five hundred
     * images does not measure five hundred of them.
     *
     * @throws WorkbenchException
     */
    public function fileInfo(string $path): array
    {
        $info = $this->actor->scope()->imageInfo($path);

        if ($info === null) {
            throw new WorkbenchException('not_found', __('aIMage::global.error_file_not_found'), 404);
        }

        return ['file' => $info];
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
