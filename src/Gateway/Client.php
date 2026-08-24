<?php

namespace EvolutionCMS\aIMage\Gateway;

use EvolutionCMS\aIMage\Support\Config;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * The ai.artur.work gateway, as this package uses it.
 *
 * One instance carries one API key, because the key is the manager's — a worker
 * processing two managers' jobs builds two clients rather than mutating a
 * header between calls.
 *
 * The gateway speaks the OpenAI dialect on /chat/completions and the Anthropic
 * dialect on /messages, and translates for models of the other family. That
 * translation is lossy in exactly one place that matters here: on
 * /chat/completions a Claude reply is reduced to its text blocks, so tool_use
 * is lost, and on /messages a non-Claude reply is rebuilt as a single text
 * block, so tool_calls are lost. `converse()` therefore picks the route by
 * model family rather than by preference — see Dialect.
 */
class Client
{
    private HttpClient $http;

    private string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        ?HttpClient $http = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?? Config::baseUrl(), '/');
        $this->http = $http ?? new HttpClient([
            'base_uri' => $this->baseUrl . '/',
            'http_errors' => false,
            'timeout' => Config::timeout(),
            'connect_timeout' => Config::connectTimeout(),
        ]);
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    /**
     * GET /models — public and unauthenticated, but sent with the key anyway so
     * a key pinned to an IP range fails here rather than three screens later.
     *
     * @param array<string,string> $filters input|output|action|provider|model
     */
    public function models(array $filters = []): array
    {
        return $this->json('GET', 'models', [RequestOptions::QUERY => $filters]);
    }

    // ------------------------------------------------------------------
    // Text
    // ------------------------------------------------------------------

    /**
     * A chat turn, on whichever route keeps tool calls intact for this model.
     *
     * @param array $body dialect-neutral: model, messages, system, tools, max_tokens
     * @return array the raw response, in the dialect the route answered in
     */
    public function converse(array $body): array
    {
        $model = (string) ($body['model'] ?? '');

        return $this->json('POST', Dialect::routeFor($model), [
            RequestOptions::JSON => Dialect::encodeRequest($body),
        ]);
    }

    // ------------------------------------------------------------------
    // Images
    // ------------------------------------------------------------------

    /**
     * POST /images/generations. JSON, unlike every other image route.
     *
     * Synchronous providers answer `{data:[{url}]}`; asynchronous ones answer
     * `{taskId}` and the caller polls. We deliberately do not pass `?wait=1`:
     * a job here may hold hundreds of images, and holding an HTTP request open
     * for each is the thing the worker exists to avoid.
     */
    public function generateImage(array $body): array
    {
        return $this->json('POST', 'images/generations', [RequestOptions::JSON => $body]);
    }

    /**
     * POST /images/edits — multipart. Every uploaded part is forwarded as `image`.
     *
     * @param array<int,array{name?:string,contents:string,filename?:string}> $images
     */
    public function editImage(array $fields, array $images): array
    {
        return $this->json('POST', 'images/edits', [
            RequestOptions::MULTIPART => $this->multipart($fields, $images),
        ]);
    }

    /**
     * POST /images/variations — multipart.
     *
     * For OpenAI models the gateway drops the form fields and forwards only the
     * uploads, matching the upstream endpoint, which takes no prompt. Other
     * providers do accept a prompted variation.
     *
     * @param array<int,array{name?:string,contents:string,filename?:string}> $images
     */
    public function variateImage(array $fields, array $images): array
    {
        return $this->json('POST', 'images/variations', [
            RequestOptions::MULTIPART => $this->multipart($fields, $images),
        ]);
    }

    /**
     * POST /images/upscale — gateway-specific, always asynchronous, and the
     * model is fixed upstream to Qubico/image-toolkit rather than taken from
     * the request. Poll with the literal segment `upscale` as the model.
     *
     * It takes a URL, not an upload, so the image has to be publicly reachable
     * — which is why Executor uploads a private source to the gateway first.
     */
    public function upscale(string $imageUrl, int $scale = 2): array
    {
        return $this->json('POST', 'images/upscale', [
            RequestOptions::MULTIPART => [
                ['name' => 'imageUrl', 'contents' => $imageUrl],
                ['name' => 'scale', 'contents' => (string) $scale],
            ],
        ]);
    }

    /**
     * GET /images/status/{model}/{taskId}.
     *
     * An unfinished task answers 200 with an empty body object — that is not an
     * error and not a result, it means keep polling. A finished one carries `data`.
     */
    public function imageStatus(string $model, string $taskId): array
    {
        return $this->json('GET', 'images/status/' . rawurlencode($model) . '/' . rawurlencode($taskId));
    }

    // ------------------------------------------------------------------
    // Voice
    // ------------------------------------------------------------------

    /** POST /audio/transcriptions — multipart. webm/ogg are transcoded upstream. */
    public function transcribe(string $model, string $bytes, string $filename, array $extra = []): array
    {
        $parts = [
            ['name' => 'file', 'contents' => $bytes, 'filename' => $filename],
            ['name' => 'model', 'contents' => $model],
        ];

        foreach ($extra as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = ['name' => (string) $name, 'contents' => (string) $value];
        }

        return $this->json('POST', 'audio/transcriptions', [RequestOptions::MULTIPART => $parts]);
    }

    /** POST /audio/speech — answers with raw audio, not JSON. Returns the bytes. */
    public function speak(string $model, string $text, string $voice, string $format = 'mp3'): string
    {
        $response = $this->send('POST', 'audio/speech', [
            RequestOptions::JSON => [
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
                'response_format' => $format,
            ],
        ]);

        $body = (string) $response->getBody();

        if ($response->getStatusCode() >= 400) {
            throw GatewayException::fromStatus(
                $response->getStatusCode(),
                $this->describeFailure($response, $body),
                json_decode($body, true) ?: null
            );
        }

        return $body;
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $fields
     * @param array<int,array{name?:string,contents:string,filename?:string}> $files
     */
    private function multipart(array $fields, array $files): array
    {
        $parts = [];

        foreach ($fields as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = ['name' => (string) $name, 'contents' => (string) $value];
        }

        foreach ($files as $file) {
            $parts[] = [
                'name' => $file['name'] ?? 'image',
                'contents' => $file['contents'],
                'filename' => $file['filename'] ?? 'image.png',
            ];
        }

        return $parts;
    }

    private function json(string $method, string $path, array $options = []): array
    {
        $response = $this->send($method, $path, $options);
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);

        if ($response->getStatusCode() >= 400) {
            throw GatewayException::fromStatus(
                $response->getStatusCode(),
                $this->describeFailure($response, $raw),
                is_array($decoded) ? $decoded : null
            );
        }

        if (!is_array($decoded)) {
            throw GatewayException::fromStatus(
                $response->getStatusCode(),
                'The gateway answered with something that is not JSON: ' . mb_substr(trim($raw), 0, 200)
            );
        }

        return $decoded;
    }

    private function send(string $method, string $path, array $options = []): ResponseInterface
    {
        $options[RequestOptions::HEADERS] = ($options[RequestOptions::HEADERS] ?? []) + [
            // The gateway accepts the key as X-Api-Key or as a bearer token.
            // X-Api-Key is the safer of the two: the bearer header is shared
            // with the mesh worker tokens, which are resolved first.
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'AIMage/1.0 (Evolution CMS)',
        ];

        try {
            return $this->http->request($method, ltrim($path, '/'), $options);
        } catch (ConnectException $e) {
            throw new GatewayException('The gateway is unreachable: ' . $e->getMessage(), 0, true);
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response === null) {
                throw new GatewayException('The gateway call failed: ' . $e->getMessage(), 0, true);
            }

            return $response;
        }
    }

    private function describeFailure(ResponseInterface $response, string $raw): string
    {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            foreach ([['error', 'message'], ['error'], ['message'], ['detail']] as $path) {
                $value = $decoded;
                foreach ($path as $segment) {
                    $value = is_array($value) ? ($value[$segment] ?? null) : null;
                }
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return 'HTTP ' . $response->getStatusCode() . ': ' . mb_substr(trim($raw), 0, 300);
    }
}
