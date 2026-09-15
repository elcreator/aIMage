<?php

namespace Elcreator\aIMage\Services;

use Elcreator\aIMage\Gateway\Client;
use Elcreator\aIMage\Gateway\GatewayException;
use Elcreator\aIMage\Support\ApiKeys;

/**
 * Where a manager puts their key, and where an administrator puts the fallback.
 *
 * The key is verified against the gateway before it is stored. That is worth
 * one round trip: a key saved without checking fails later inside a queued
 * batch, at which point the failure is a row in a job's step list rather than
 * a message beside the field the person is looking at.
 */
final class Keys
{
    public function __construct(private readonly Actor $actor)
    {
    }

    /**
     * @param string $scope `user` (the manager's own key) or `site` (the fallback, administrators only)
     * @throws WorkbenchException
     */
    public function save(string $scope, string $key): array
    {
        $scope = trim($scope) ?: 'user';
        $key = trim($key);

        if ($scope === 'site' && !$this->actor->canManageSiteKey()) {
            throw WorkbenchException::forbidden();
        }

        if ($scope === 'site' && ApiKeys::siteKeyIsFromConfig()) {
            throw new WorkbenchException('key_from_config', __('aIMage::global.error_key_from_config'), 409);
        }

        // An empty value clears the key rather than storing an empty one, so
        // "remove my key and fall back to the site's" needs no separate verb.
        if ($key === '') {
            $scope === 'site' ? ApiKeys::setSiteKey(null) : ApiKeys::setUserKey($this->actor->userId(), null);

            return ['key' => $this->actor->keyState(), 'verified' => false];
        }

        $this->verify($key);

        $scope === 'site' ? ApiKeys::setSiteKey($key) : ApiKeys::setUserKey($this->actor->userId(), $key);

        return ['key' => $this->actor->keyState(), 'verified' => true];
    }

    /**
     * Try the key against the gateway.
     *
     * `GET /models` is the probe: it is the cheapest authenticated call the
     * gateway has, it costs nothing, and a key pinned to an IP range fails
     * here — which is exactly the failure worth catching at save time.
     *
     * @throws WorkbenchException
     */
    private function verify(string $key): void
    {
        try {
            (new Client($key))->models(['model' => 'gpt-image-1']);
        } catch (GatewayException $e) {
            if ($e->isAuthFailure()) {
                throw new WorkbenchException('key_rejected', __('aIMage::global.error_key_rejected'), 403);
            }

            // The gateway being unreachable is not evidence the key is bad, so
            // the manager is told what happened and nothing is stored.
            throw new WorkbenchException('gateway_unreachable', $e->getMessage(), 502);
        }
    }
}
