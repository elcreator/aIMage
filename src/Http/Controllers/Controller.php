<?php

namespace Elcreator\aIMage\Http\Controllers;

use Elcreator\aIMage\Gateway\Client;
use Elcreator\aIMage\Gateway\Estimator;
use Elcreator\aIMage\Gateway\ModelCatalog;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Support\ApiKeys;
use Elcreator\aIMage\Support\ImageScope;
use Illuminate\Http\JsonResponse;

/**
 * What every AIMage endpoint needs before it can do anything.
 *
 * Two guards, and they are different questions. `aimage` decides whether this
 * manager may use the workbench at all; `ImageScope` decides which files they
 * may touch once inside it. Neither substitutes for the other — a manager with
 * the permission and no file groups sees an empty workbench, which is correct.
 *
 * The API-key state is a third thing again, and deliberately not an error:
 * "no key configured" is the normal first-run state, and the page's job is to
 * ask for one rather than to show a failure.
 */
abstract class Controller
{
    public const PERMISSION = 'aimage';

    /** The signed-in manager, or 0 when there is somehow no session. */
    protected function userId(): int
    {
        return (int) evo()->getLoginUserID('mgr');
    }

    protected function authorized(): bool
    {
        return $this->userId() > 0 && (bool) evo()->hasPermission(self::PERMISSION);
    }

    /**
     * A refusal shaped like every other response, so the front end has one
     * code path for "no" rather than a special case per endpoint.
     */
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

    protected function scope(): ImageScope
    {
        return ImageScope::forUser($this->userId());
    }

    /**
     * A gateway client for this manager, or null when no key is available.
     *
     * Null is a state the caller must handle, not an exception: it is what the
     * very first visit looks like.
     */
    protected function client(): ?Client
    {
        $key = ApiKeys::forUser($this->userId());

        return $key === null ? null : new Client($key);
    }

    protected function catalog(Client $client): ModelCatalog
    {
        return new ModelCatalog($client);
    }

    protected function estimator(Client $client): Estimator
    {
        return new Estimator($this->catalog($client));
    }

    /**
     * Find one of this manager's jobs.
     *
     * Scoped by `user_id` rather than looked up by uuid alone: a uuid is not a
     * capability, and one manager must not be able to read, approve or cancel
     * another's batch by pasting an identifier.
     */
    protected function findJob(string $uuid): ?Job
    {
        $uuid = trim($uuid);

        if ($uuid === '') {
            return null;
        }

        return Job::query()
            ->where('uuid', $uuid)
            ->where('user_id', $this->userId())
            ->first();
    }

    /** Describes the key situation for the UI, without ever sending the key. */
    protected function keyState(): array
    {
        $userId = $this->userId();
        $source = ApiKeys::sourceFor($userId);

        return [
            'source' => $source,
            'configured' => $source !== ApiKeys::SOURCE_NONE,
            'own_key_masked' => ApiKeys::mask(ApiKeys::userKey($userId)),
            'site_key_available' => ApiKeys::siteKey() !== null,
            'site_key_from_config' => ApiKeys::siteKeyIsFromConfig(),
        ];
    }
}
