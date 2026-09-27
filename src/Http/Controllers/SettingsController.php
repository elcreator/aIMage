<?php

namespace Elcreator\aIMage\Http\Controllers;

use Elcreator\aIMage\Gateway\Connect;
use Elcreator\aIMage\Gateway\GatewayException;
use Elcreator\aIMage\Services\WorkbenchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a manager puts their key, and where an administrator puts the
 * fallback - see Services\Keys; this only maps the request onto it.
 *
 * Or, better, where they get one without copying anything: "Connect with
 * ai.artur.work" (the gateway's "Connect a site", OAuth2 code + PKCE). The
 * verifier and state live in the manager's session between the two requests;
 * the key itself never passes through the browser.
 */
class SettingsController extends Controller
{
    public const SESSION_KEY = 'aimage_connect';

    public function saveKey(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->keys()->save(
            (string) $request->input('scope', 'user'),
            (string) $request->input('key', '')
        ));
    }

    /** Send the browser to the gateway's consent page. `scope=site` connects the site-wide key. */
    public function connect(Request $request): RedirectResponse
    {
        $scope = (string) $request->query('scope', 'user') === 'site' ? 'site' : 'user';
        if (!$this->authorized() || ($scope === 'site' && !$this->actor()->canManageSiteKey())) {
            return $this->backToPage('connect_failed', __('aIMage::global.error_forbidden'));
        }
        $pkce = Connect::pkce();
        $_SESSION[self::SESSION_KEY] = ['verifier' => $pkce['verifier'], 'state' => $pkce['state'],
            'scope' => $scope, 'at' => time()];

        return new RedirectResponse(Connect::authorizeUrl(
            Connect::APP,
            Connect::siteOrigin(),
            $this->callbackUrl(),
            $pkce['state'],
            $pkce['challenge']
        ));
    }

    /** Back from the gateway with `?code&state` (or `?error`): trade the code for a key and save it. */
    public function connectCallback(Request $request): RedirectResponse
    {
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        $state = (string) $request->query('state', '');
        if (!is_array($pending) || $state === '' || !hash_equals((string) $pending['state'], $state)
            || time() - (int) $pending['at'] > Connect::PENDING_TTL_SEC) {
            return $this->backToPage('connect_failed', __('aIMage::global.connect_expired'));
        }
        if ((string) $request->query('error', '') !== '') {
            return $this->backToPage('connect_failed', __('aIMage::global.connect_denied'));
        }

        try {
            $key = (new Connect())->exchange((string) $request->query('code', ''), (string) $pending['verifier'], $this->callbackUrl());
            $this->keys()->save((string) $pending['scope'], $key['access_token']);
        } catch (GatewayException|WorkbenchException $e) {
            return $this->backToPage('connect_failed', $e->getMessage());
        }

        return $this->backToPage('connected', __('aIMage::global.connect_done'));
    }

    /** The absolute URL the gateway sends the browser back to: must be on the site's host. */
    private function callbackUrl(): string
    {
        return route('aimage.connect.callback');
    }

    private function backToPage(string $flag, string $message): RedirectResponse
    {
        return new RedirectResponse(route('aimage.index') . '?' . http_build_query([$flag => 1, 'message' => $message]));
    }
}
