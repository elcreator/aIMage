<?php

namespace EvolutionCMS\aIMage\Gateway;

use EvolutionCMS\aIMage\Support\Config;
use Throwable;

/**
 * The catalogue behind `GET /models`, indexed and cached.
 *
 * The endpoint is public, hourly-cached upstream and ETag'd, so this cache
 * exists only to keep a page render from making a network call — not to be a
 * source of truth. When the fetch fails and a stale copy exists, the stale copy
 * is served with `stale` set, because a catalogue an hour out of date is far
 * more useful than an empty model picker.
 */
class ModelCatalog
{
    /** Every uiAction the gateway advertises, in the order the UI groups them. */
    public const ACTION_TEXT_TO_IMAGE = 'textToImage';
    public const ACTION_IMAGES_AND_TEXT_TO_IMAGE = 'imagesAndTextToImage';
    public const ACTION_EDIT_IMAGE = 'editImage';
    public const ACTION_UPSCALE_IMAGE = 'upscaleImage';
    public const ACTION_VARIATE_IMAGE = 'imageToImageVariation';
    public const ACTION_CHAT = 'respondChat';
    public const ACTION_IMAGE_TO_TEXT = 'imageToText';
    public const ACTION_TRANSCRIBE = 'transcribeVoice';
    public const ACTION_SPEAK = 'speakText';

    /**
     * The model `/images/upscale` is fixed to upstream.
     *
     * The endpoint ignores whatever model the request names, so the price and
     * latency the UI shows for an upscale must come from this entry and not
     * from whichever image model the manager happens to have picked.
     */
    public const UPSCALE_MODEL = 'Qubico/image-toolkit';

    private ?array $snapshot = null;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * The whole snapshot: models, legend, currency, and our own `stale` flag.
     */
    public function snapshot(bool $refresh = false): array
    {
        if ($this->snapshot !== null && !$refresh) {
            return $this->snapshot;
        }

        $cached = $refresh ? null : $this->readCache();

        if (is_array($cached)) {
            return $this->snapshot = $cached;
        }

        try {
            $fresh = $this->client->models();
            $fresh['fetchedAt'] = time();
            $fresh['stale'] = false;
            $this->writeCache($fresh);

            return $this->snapshot = $fresh;
        } catch (Throwable $e) {
            $stale = $this->readCache(true);

            if (is_array($stale)) {
                $stale['stale'] = true;
                $stale['staleReason'] = $e->getMessage();

                return $this->snapshot = $stale;
            }

            throw $e;
        }
    }

    /** @return array<string,array> keyed by model id */
    public function all(bool $refresh = false): array
    {
        $indexed = [];

        foreach ((array) ($this->snapshot($refresh)['models'] ?? []) as $model) {
            if (isset($model['model'])) {
                $indexed[(string) $model['model']] = $model;
            }
        }

        return $indexed;
    }

    public function find(string $model): ?array
    {
        return $this->all()[$model] ?? null;
    }

    public function has(string $model): bool
    {
        return $this->find($model) !== null;
    }

    /**
     * Every model advertising one of the given actions, cheapest-looking first.
     *
     * @param string|string[] $actions matched as OR — a model offering any of them qualifies
     * @return array<int,array>
     */
    public function forAction(string|array $actions): array
    {
        $wanted = (array) $actions;
        $matched = [];

        foreach ($this->all() as $model) {
            if (array_intersect($wanted, (array) ($model['actions'] ?? [])) !== []) {
                $matched[] = $model;
            }
        }

        usort($matched, static function (array $a, array $b): int {
            $priceA = $a['price']['amount'] ?? null;
            $priceB = $b['price']['amount'] ?? null;

            // Token-metered models have no per-request price. They sort after
            // the priced ones rather than before them, because "no amount" is
            // not "free" and must not look like the cheapest option.
            if ($priceA === null && $priceB === null) {
                return strcmp((string) $a['model'], (string) $b['model']);
            }
            if ($priceA === null) {
                return 1;
            }
            if ($priceB === null) {
                return -1;
            }

            return $priceA <=> $priceB;
        });

        return $matched;
    }

    /**
     * The control vocabulary of one model — sizes, qualities, backgrounds.
     *
     * Which values are legal is per-model, so the UI renders exactly these and
     * offers nothing else.
     */
    public function controls(string $model): array
    {
        return (array) ($this->find($model)['controls'] ?? []);
    }

    public function currency(): string
    {
        return (string) ($this->snapshot()['currency'] ?? 'EUR');
    }

    public function legend(): array
    {
        return (array) ($this->snapshot()['legend'] ?? []);
    }

    /**
     * Does this model support this action? Asked before a step is queued, so a
     * plan naming an impossible pairing fails at planning time and not two
     * hours into a batch.
     */
    public function supports(string $model, string $action): bool
    {
        return in_array($action, (array) ($this->find($model)['actions'] ?? []), true);
    }

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------

    private function cacheFile(): string
    {
        $dir = defined('EVO_STORAGE_PATH')
            ? rtrim(EVO_STORAGE_PATH, '/\\') . '/aimage'
            : sys_get_temp_dir() . '/aimage';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Keyed by gateway host so a site pointed at a different gateway does
        // not read the previous one's catalogue.
        return $dir . '/models-' . substr(sha1(Config::baseUrl()), 0, 12) . '.json';
    }

    private function readCache(bool $ignoreTtl = false): ?array
    {
        $file = $this->cacheFile();

        if (!is_file($file)) {
            return null;
        }

        if (!$ignoreTtl && (time() - (int) filemtime($file)) > Config::catalogTtl()) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        return is_array($decoded) && isset($decoded['models']) ? $decoded : null;
    }

    private function writeCache(array $snapshot): void
    {
        $file = $this->cacheFile();
        $temp = $file . '.' . getmypid() . '.tmp';

        // Written through a temp file: a worker reading the catalogue while
        // another writes it must never see half a JSON document.
        if (@file_put_contents($temp, json_encode($snapshot, JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($temp, $file);
        }
    }
}
