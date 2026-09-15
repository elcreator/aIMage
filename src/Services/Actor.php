<?php

namespace Elcreator\aIMage\Services;

use Elcreator\aIMage\Gateway\Client;
use Elcreator\aIMage\Gateway\Estimator;
use Elcreator\aIMage\Gateway\ModelCatalog;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Support\ApiKeys;
use Elcreator\aIMage\Support\ImageScope;

/**
 * The manager the workbench is acting for.
 *
 * Two guards, and they are different questions. `aimage` decides whether this
 * manager may use the workbench at all; `ImageScope` decides which files they
 * may touch once inside it. Neither substitutes for the other — a manager with
 * the permission and no file groups sees an empty workbench, which is correct.
 *
 * The API-key state is a third thing again, and deliberately not an error:
 * "no key configured" is the normal first-run state, and the page's job is to
 * ask for one rather than to show a failure.
 *
 * Built from the manager session, which is also what an MCP request carries
 * once eMCP has impersonated the token owner — so the page and an agent go
 * through exactly the same object.
 */
final class Actor
{
    public const PERMISSION = 'aimage';

    private ?ImageScope $scope = null;

    public function __construct(
        private readonly int $userId,
        private readonly bool $hasPermission,
        private readonly bool $isAdministrator
    ) {
    }

    /** The signed-in manager, from the session. */
    public static function current(): self
    {
        $userId = (int) evo()->getLoginUserID('mgr');

        return new self(
            $userId,
            $userId > 0 && (bool) evo()->hasPermission(self::PERMISSION),
            (int) ($_SESSION['mgrRole'] ?? 0) === 1
        );
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function authorized(): bool
    {
        return $this->userId > 0 && $this->hasPermission;
    }

    /** @throws WorkbenchException */
    public function authorize(): self
    {
        if (!$this->authorized()) {
            throw WorkbenchException::forbidden();
        }

        return $this;
    }

    public function canManageSiteKey(): bool
    {
        return $this->isAdministrator;
    }

    public function scope(): ImageScope
    {
        return $this->scope ??= ImageScope::forUser($this->userId);
    }

    /**
     * A gateway client for this manager, or null when no key is available.
     *
     * Null is a state the caller must handle, not an exception: it is what the
     * very first visit looks like.
     */
    public function client(): ?Client
    {
        $key = ApiKeys::forUser($this->userId);

        return $key === null ? null : new Client($key);
    }

    /** @throws WorkbenchException when no key is configured */
    public function requireClient(): Client
    {
        return $this->client() ?? throw WorkbenchException::noKey();
    }

    public function catalog(Client $client): ModelCatalog
    {
        return new ModelCatalog($client);
    }

    public function estimator(Client $client): Estimator
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
    public function findJob(string $uuid): ?Job
    {
        $uuid = trim($uuid);

        if ($uuid === '') {
            return null;
        }

        return Job::query()
            ->where('uuid', $uuid)
            ->where('user_id', $this->userId)
            ->first();
    }

    /** @throws WorkbenchException */
    public function job(string $uuid): Job
    {
        return $this->findJob($uuid) ?? throw WorkbenchException::jobNotFound();
    }

    /** Describes the key situation for the UI, without ever sending the key. */
    public function keyState(): array
    {
        $source = ApiKeys::sourceFor($this->userId);

        return [
            'source' => $source,
            'configured' => $source !== ApiKeys::SOURCE_NONE,
            'own_key_masked' => ApiKeys::mask(ApiKeys::userKey($this->userId)),
            'site_key_available' => ApiKeys::siteKey() !== null,
            'site_key_from_config' => ApiKeys::siteKeyIsFromConfig(),
        ];
    }
}
