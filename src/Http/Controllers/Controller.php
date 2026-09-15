<?php

namespace Elcreator\aIMage\Http\Controllers;

use Elcreator\aIMage\Gateway\Client;
use Elcreator\aIMage\Services\Actor;
use Elcreator\aIMage\Services\Catalog;
use Elcreator\aIMage\Services\Jobs;
use Elcreator\aIMage\Services\Keys;
use Elcreator\aIMage\Services\WorkbenchException;
use Elcreator\aIMage\Support\ImageScope;
use Illuminate\Http\JsonResponse;

/**
 * The HTTP face of the workbench.
 *
 * Every decision - who may do what, which files they may touch, what the key
 * state is - lives in Services\Actor and the services built on it; the same
 * objects answer the MCP tools. A controller only turns a request into a call
 * and the answer (or refusal) into JSON, so the page and an agent can never
 * disagree about what a manager is allowed to do.
 */
abstract class Controller
{
    public const PERMISSION = Actor::PERMISSION;

    private ?Actor $actor = null;

    protected function actor(): Actor
    {
        return $this->actor ??= Actor::current();
    }

    /** The signed-in manager, or 0 when there is somehow no session. */
    protected function userId(): int
    {
        return $this->actor()->userId();
    }

    protected function authorized(): bool
    {
        return $this->actor()->authorized();
    }

    protected function scope(): ImageScope
    {
        return $this->actor()->scope();
    }

    /** A gateway client for this manager, or null when no key is available. */
    protected function client(): ?Client
    {
        return $this->actor()->client();
    }

    /** Describes the key situation for the UI, without ever sending the key. */
    protected function keyState(): array
    {
        return $this->actor()->keyState();
    }

    protected function catalogService(): Catalog
    {
        return new Catalog($this->actor());
    }

    protected function jobs(): Jobs
    {
        return new Jobs($this->actor());
    }

    protected function keys(): Keys
    {
        return new Keys($this->actor());
    }

    /**
     * Run one workbench call and answer with its payload, or with the refusal
     * shaped like every other response, so the front end has one code path
     * for "no" rather than a special case per endpoint.
     *
     * @param callable(): array $call
     */
    protected function respond(callable $call): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        try {
            return $this->ok($call());
        } catch (WorkbenchException $e) {
            return $this->fail($e->error, $e->getMessage(), $e->status);
        }
    }

    protected function denied(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'forbidden',
            'message' => __('aIMage::global.error_forbidden'),
        ], 403);
    }

    protected function fail(string $error, string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $error,
            'message' => $message,
        ], $status);
    }

    protected function ok(array $payload = []): JsonResponse
    {
        return response()->json(['ok' => true] + $payload);
    }
}
