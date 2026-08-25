<?php

namespace Elcreator\aIMage\Http\Controllers;

use Elcreator\aIMage\Gateway\Client;
use Elcreator\aIMage\Gateway\GatewayException;
use Elcreator\aIMage\Support\ApiKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a manager puts their key, and where an administrator puts the fallback.
 *
 * The key is verified against the gateway before it is stored. That is worth
 * one round trip: a key saved without checking fails later inside a queued
 * batch, at which point the failure is a row in a job's step list rather than
 * a message beside the field the person is looking at.
 */
class SettingsController extends Controller
{
    public function saveKey(Request $request): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $scope = trim((string) $request->input('scope', 'user'));
        $key = trim((string) $request->input('key', ''));

        if ($scope === 'site' && !$this->canManageSiteKey()) {
            return $this->denied();
        }

        if ($scope === 'site' && ApiKeys::siteKeyIsFromConfig()) {
            return $this->fail('key_from_config', __('aIMage::global.error_key_from_config'), 409);
        }

        // An empty value clears the key rather than storing an empty one, so
        // "remove my key and fall back to the site's" needs no separate verb.
        if ($key === '') {
            $scope === 'site' ? ApiKeys::setSiteKey(null) : ApiKeys::setUserKey($this->userId(), null);

            return $this->ok(['key' => $this->keyState(), 'verified' => false]);
        }

        $verification = $this->verify($key);

        if ($verification !== null) {
            return $verification;
        }

        $scope === 'site' ? ApiKeys::setSiteKey($key) : ApiKeys::setUserKey($this->userId(), $key);

        return $this->ok(['key' => $this->keyState(), 'verified' => true]);
    }

    /**
     * Try the key against the gateway. Null means it worked.
     *
     * `GET /models` is the probe: it is the cheapest authenticated call the
     * gateway has, it costs nothing, and a key pinned to an IP range fails
     * here — which is exactly the failure worth catching at save time.
     */
    private function verify(string $key): ?JsonResponse
    {
        try {
            (new Client($key))->models(['model' => 'gpt-image-1']);
        } catch (GatewayException $e) {
            if ($e->isAuthFailure()) {
                return $this->fail('key_rejected', __('aIMage::global.error_key_rejected'), 403);
            }

            // The gateway being unreachable is not evidence the key is bad, so
            // the manager is told what happened and nothing is stored.
            return $this->fail('gateway_unreachable', $e->getMessage(), 502);
        }

        return null;
    }

    private function canManageSiteKey(): bool
    {
        return (int) ($_SESSION['mgrRole'] ?? 0) === 1;
    }
}
