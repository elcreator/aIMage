<?php

namespace Elcreator\aIMage\Agent;

use Elcreator\aIMage\Gateway\Client;
use Elcreator\aIMage\Gateway\GatewayException;
use Elcreator\aIMage\Gateway\ModelCatalog;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Support\Config;
use Elcreator\aIMage\Support\ImageScope;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions;
use Throwable;

/**
 * Carries out one step: the only place in this package that changes a file.
 *
 * Two rules shape everything here.
 *
 * **Nothing blocks.** A step is either finished within one request or parked in
 * `polling` with a provider task id. There is no sleep-and-wait anywhere,
 * because the worker that runs this is the same one the CMS uses for site
 * updates, and holding it for the length of a two-hundred-image batch would
 * starve everything else on the queue.
 *
 * **A step is safe to run twice.** A worker can be killed between calling a
 * provider and recording the result, and the lease will hand the job to
 * another slice. So results are written under a freshly chosen unique name and
 * the step is only marked succeeded after the bytes are on disk — a duplicated
 * attempt costs an extra image, never a corrupted or half-written one.
 */
class Executor
{
    private HttpClient $http;

    public function __construct(
        private readonly Client $client,
        private readonly ImageScope $scope,
        ?HttpClient $http = null
    ) {
        // Separate from the gateway client: this one fetches finished images
        // from a CDN and must not carry the API key to a third-party host.
        $this->http = $http ?? new HttpClient([
            'http_errors' => false,
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Advance one step as far as it will go without waiting.
     *
     * @return bool whether anything changed, so the caller can tell progress
     *              from a slice that only found unfinished provider tasks
     */
    public function advance(Job $job, JobStep $step): bool
    {
        try {
            if ((string) $step->status === JobStep::STATUS_POLLING) {
                return $this->poll($job, $step);
            }

            return $this->dispatch($job, $step);
        } catch (GatewayException $e) {
            $this->recordFailure($step, $e);

            return true;
        } catch (Throwable $e) {
            $step->markFailed('STEP_FAILED', $e->getMessage());

            return true;
        }
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    private function dispatch(Job $job, JobStep $step): bool
    {
        $step->markRunning();

        $response = match ((string) $step->type) {
            JobStep::TYPE_GENERATE => $this->submitGenerate($step),
            JobStep::TYPE_EDIT => $this->submitEdit($step),
            JobStep::TYPE_VARIATE => $this->submitVariate($step),
            JobStep::TYPE_UPSCALE => $this->submitUpscale($step),
            default => null,
        };

        if ($response === null) {
            $step->markFailed('UNKNOWN_STEP_TYPE', 'Nothing knows how to run a "' . $step->type . '" step.');

            return true;
        }

        // An asynchronous provider hands back an id instead of an image.
        $taskId = $this->taskIdOf($response);

        if ($taskId !== null) {
            $step->markPolling($taskId, $this->pollModelFor($step));

            return true;
        }

        return $this->storeResults($job, $step, $response);
    }

    private function submitGenerate(JobStep $step): array
    {
        $body = array_filter([
            'model' => (string) $step->model,
            'prompt' => (string) $step->prompt,
            'size' => $step->param('size'),
            'quality' => $step->param('quality'),
            'background' => $step->param('background'),
            'options' => $this->options($step, $step->expectedImages()),
        ] + $this->privacy(false), static fn ($value) => $value !== null && $value !== '' && $value !== []);

        return $this->client->generateImage($body, $step->idempotencyKey());
    }

    /**
     * The gateway's normalised options (feature 23): an aspect ratio every model
     * understands, and `n` delivered by the gateway even where a model makes one
     * image per call. Not strict: a model that can only approximate the ratio still
     * draws, and the gateway lists what it changed in `warnings`, which the step keeps.
     */
    private function options(JobStep $step, int $n): array
    {
        $ratio = $step->param('aspect_ratio');
        $options = ['n' => $n, 'strict' => false];
        if (is_string($ratio) && preg_match('/^\s*(\d{1,4})\s*[:x]\s*(\d{1,4})\s*$/', $ratio, $m)) {
            $options['aspectRatio'] = $m[1] . ':' . $m[2];
        }

        return $options;
    }

    /**
     * Keep sources and results private at the gateway (`privacy.private`): a source
     * never gets a public URL there, and a result comes back as a signed link that
     * expires - which is fine, because `storeResults()` downloads it at once.
     *
     * @return array<string,array{private:bool}>
     */
    private function privacy(bool $hasInputs): array
    {
        if (!Config::privateAssets()) {
            return [];
        }

        return array_filter([
            'inputs' => $hasInputs ? ['private' => true] : null,
            'outputs' => ['private' => true],
        ]);
    }

    private function submitEdit(JobStep $step): array
    {
        $image = $this->readSource($step);

        return $this->client->editImage(
            array_filter([
                'model' => (string) $step->model,
                'prompt' => (string) $step->prompt,
                'n' => 1,
                'size' => $step->param('size'),
                'quality' => $step->param('quality'),
                'background' => $step->param('background'),
            ] + $this->privacy(true), static fn ($value) => $value !== null && $value !== ''),
            [$image],
            $step->idempotencyKey()
        );
    }

    private function submitVariate(JobStep $step): array
    {
        $image = $this->readSource($step);

        return $this->client->variateImage(
            array_filter([
                'model' => (string) $step->model,
                'n' => $step->expectedImages(),
                'size' => $step->param('size'),
                'prompt' => (string) $step->prompt,
            ] + $this->privacy(true), static fn ($value) => $value !== null && $value !== ''),
            [$image],
            $step->idempotencyKey()
        );
    }

    /**
     * Upscaling sends the file itself, like an edit. It used to take a URL the
     * provider fetched, so a site on localhost, behind HTTP auth or with its files
     * outside the web root could not upscale at all; the gateway now keeps the
     * upload privately (a signed link for a URL-only provider, the bytes for one that
     * takes them) and deletes it when the run finishes.
     */
    private function submitUpscale(JobStep $step): array
    {
        $image = $this->readSource($step);

        return $this->client->upscale(
            array_filter([
                'model' => (string) ($step->model ?: Config::upscaleModel()),
                'scale' => (int) $step->param('scale', 2),
            ] + $this->privacy(true), static fn ($value) => $value !== null && $value !== ''),
            $image,
            $step->idempotencyKey()
        );
    }

    /**
     * @return array{name: string, contents: string, filename: string}
     */
    private function readSource(JobStep $step): array
    {
        $path = (string) $step->source_path;
        $bytes = $this->scope->read($path);

        if ($bytes === null) {
            throw new GatewayException(
                'The source image "' . $path . '" is missing, too large, or no longer accessible.',
                0,
                false,
                'SOURCE_UNAVAILABLE'
            );
        }

        return ['name' => 'image', 'contents' => $bytes, 'filename' => basename($path)];
    }

    // ------------------------------------------------------------------
    // Polling
    // ------------------------------------------------------------------

    private function poll(Job $job, JobStep $step): bool
    {
        $response = $this->client->imageStatus(
            (string) $step->provider_model,
            (string) $step->provider_task_id
        );

        // An unfinished task answers 200 with an empty body. That is neither a
        // result nor an error — it means come back later, so the step stays
        // parked and the slice reports no progress rather than failing it.
        if (!$this->hasImages($response)) {
            return false;
        }

        return $this->storeResults($job, $step, $response);
    }

    /**
     * Which model name the status route needs.
     *
     * Upscale tasks are polled under the literal segment `upscale`, not under
     * the model that ran them — the gateway routes that one specially.
     */
    private function pollModelFor(JobStep $step): string
    {
        if ((string) $step->type !== JobStep::TYPE_UPSCALE) {
            return (string) $step->model;
        }
        // The GoApi toolkit's tasks are polled under the literal `upscale`; any other
        // upscaler that answers asynchronously is polled under its own name.
        $model = (string) ($step->model ?: Config::upscaleModel());

        return $model === ModelCatalog::UPSCALE_MODEL ? 'upscale' : $model;
    }

    // ------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------

    private function storeResults(Job $job, JobStep $step, array $response): bool
    {
        $images = $this->extractImages($response);

        if ($images === []) {
            $step->markFailed('NO_IMAGE_RETURNED', 'The provider finished but returned no image.');

            return true;
        }

        $folder = (string) $step->param('folder', $job->output_folder ?: $this->scope->outputFolder());
        $basename = $this->basenameFor($step);

        $written = [];

        foreach ($images as $index => $image) {
            $bytes = $this->materialise($image);

            if ($bytes === null) {
                continue;
            }

            $extension = $this->extensionFor($bytes, (string) ($image['url'] ?? ''));

            if ($extension === null) {
                continue;
            }

            $stem = count($images) > 1 ? $basename . '-' . ($index + 1) : $basename;
            $target = $this->scope->uniqueRelativePath($folder, $stem, $extension);

            if ($target === null || !$this->scope->write($target, $bytes)) {
                continue;
            }

            $written[] = $target;
        }

        if ($written === []) {
            $step->markFailed(
                'WRITE_FAILED',
                'The provider returned an image but it could not be written to "' . $folder . '".'
            );

            return true;
        }

        // How it was made (feature 24): model, vendor model id, input hashes, options,
        // C2PA - kept with the step, next to the files it produced.
        $step->markSucceeded($written[0], array_filter([
            'paths' => $written,
            'count' => count($written),
            'provenance' => is_array($response['provenance'] ?? null) ? $response['provenance'] : null,
            'warnings' => is_array($response['warnings'] ?? null) ? array_values($response['warnings']) : null,
        ], static fn ($value) => $value !== null && $value !== []));

        return true;
    }

    /**
     * Turn one result entry into bytes.
     *
     * Both shapes the gateway can answer with are handled: a CDN url, and an
     * inline base64 payload when `response_format=b64_json` was honoured.
     */
    private function materialise(array $image): ?string
    {
        if (isset($image['b64_json']) && is_string($image['b64_json'])) {
            $bytes = base64_decode($image['b64_json'], true);

            return $bytes === false ? null : $bytes;
        }

        $url = (string) ($image['url'] ?? '');

        return $url === '' ? null : $this->download($url);
    }

    /**
     * Fetch a finished image.
     *
     * Only http(s) is followed, and the response is capped: a provider is not
     * a trusted peer, and a redirect to `file://` or a body with no end are
     * both things a careless client would honour.
     */
    private function download(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $response = $this->http->get($url, [
            RequestOptions::HEADERS => ['Accept' => 'image/*'],
            RequestOptions::ALLOW_REDIRECTS => ['max' => 3, 'protocols' => ['http', 'https']],
        ]);

        if ($response->getStatusCode() >= 400) {
            return null;
        }

        $limit = Config::maxResultBytes();
        $stream = $response->getBody();
        $bytes = '';

        while (!$stream->eof()) {
            $bytes .= $stream->read(262144);

            if (strlen($bytes) > $limit) {
                return null;
            }
        }

        return $bytes === '' ? null : $bytes;
    }

    /**
     * Decide the extension from the bytes, and only from the bytes.
     *
     * A provider naming a file `.png` while sending a JPEG is harmless; a
     * response that is not an image at all is not. These results are written
     * into a folder the site serves publicly, so the magic-byte check is the
     * thing standing between a provider having a bad day — an HTML error page,
     * a JSON fault, a truncated body — and that page being served from the
     * site's own domain.
     *
     * There is deliberately **no fallback to the URL's extension**. One existed
     * here and it defeated the entire check: any response at all was accepted
     * as long as the URL happened to end in `.png`, which a CDN URL always
     * does. The cost of refusing is that a format PHP cannot recognise is
     * rejected; that is the right side to err on.
     */
    private function extensionFor(string $bytes, string $url): ?string
    {
        $info = @getimagesizefromstring($bytes);

        if (!is_array($info) || !isset($info[2])) {
            return null;
        }

        $known = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];

        if (defined('IMAGETYPE_AVIF')) {
            $known[IMAGETYPE_AVIF] = 'avif';
        }

        $extension = $known[$info[2]] ?? null;

        // Recognised, but in a format this site does not accept for uploads.
        return $extension !== null && in_array($extension, $this->scope->allowedExtensions(), true)
            ? $extension
            : null;
    }

    private function basenameFor(JobStep $step): string
    {
        $configured = (string) $step->param('basename', '');

        if ($configured !== '') {
            return $this->scope->sanitizeBasename($configured) ?: 'aimage';
        }

        // Derived from the source so an edited image is recognisably related
        // to the one it came from when a manager scrolls a folder of results.
        $source = (string) $step->source_path;

        if ($source !== '') {
            $stem = pathinfo($source, PATHINFO_FILENAME);
            $suffix = match ((string) $step->type) {
                JobStep::TYPE_UPSCALE => '-x' . (int) $step->param('scale', 2),
                JobStep::TYPE_VARIATE => '-var',
                default => '-edit',
            };

            return $this->scope->sanitizeBasename($stem . $suffix) ?: 'aimage';
        }

        return 'aimage';
    }

    // ------------------------------------------------------------------
    // Response shapes
    // ------------------------------------------------------------------

    private function taskIdOf(array $response): ?string
    {
        foreach (['taskId', 'task_id', 'id'] as $key) {
            $value = $response[$key] ?? null;

            if (is_string($value) && $value !== '' && !$this->hasImages($response)) {
                return $value;
            }
        }

        return null;
    }

    private function hasImages(array $response): bool
    {
        return $this->extractImages($response) !== [];
    }

    /**
     * @return array<int, array{url?: string, b64_json?: string}>
     */
    private function extractImages(array $response): array
    {
        $data = $response['data'] ?? null;

        if (!is_array($data)) {
            return [];
        }

        $images = [];

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (
                (isset($entry['url']) && is_string($entry['url']) && $entry['url'] !== '')
                || (isset($entry['b64_json']) && is_string($entry['b64_json']) && $entry['b64_json'] !== '')
            ) {
                $images[] = $entry;
            }
        }

        return $images;
    }

    private function recordFailure(JobStep $step, GatewayException $e): void
    {
        $maxAttempts = (int) Config::limit('max_attempts', 4);

        if ($e->retryable && (int) $step->attempt_count < $maxAttempts) {
            // The first call with this key is still running at the gateway: ask again
            // with the same key, or the retry would become a second, paid task.
            $step->requeue('Retrying after: ' . $e->getMessage(), $e->errorCode !== 'idempotency_in_progress');

            return;
        }

        $step->markFailed(
            $e->errorCode ?: ($e->isAuthFailure() ? 'KEY_REJECTED' : 'PROVIDER_FAILED'),
            $e->getMessage()
        );
    }

    /** The model name to poll an upscale under, exposed for the catalogue's benefit. */
    public static function upscaleModel(): string
    {
        return ModelCatalog::UPSCALE_MODEL;
    }
}
