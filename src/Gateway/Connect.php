<?php

namespace Elcreator\aIMage\Gateway;

use Elcreator\aIMage\Support\Config;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions;

/**
 * "Connect a site" on the gateway: a key without anybody copying one.
 *
 * OAuth2 authorisation code with PKCE (S256 only). The manager's browser goes to
 * `{gateway}/connect?app=aimage&site&redirect_uri&state&code_challenge`, the
 * signed-in owner presses Allow (with a monthly cap), and the browser comes back
 * with `?code&state`. The code is traded for a key here, server-to-server, with the
 * verifier only this side knows - so a code intercepted on the way back is useless.
 *
 * The gateway insists `redirect_uri` is on the same host as `site`, `site` is https
 * (http only on localhost), codes live five minutes and are single-use. A key
 * created this way is private by default: sources never get a public URL and
 * results come back as expiring signed links.
 */
final class Connect
{
    /** The app slug the gateway knows this package by. */
    public const APP = 'aimage';

    /** How long a started connect may take before its state is refused. */
    public const PENDING_TTL_SEC = 900;

    private HttpClient $http;

    public function __construct(?HttpClient $http = null, private readonly ?string $gatewaySiteUrl = null)
    {
        $this->http = $http ?? new HttpClient(['http_errors' => false, 'timeout' => 30, 'connect_timeout' => 10]);
    }

    /** @return array{verifier:string, challenge:string, state:string} */
    public static function pkce(): array
    {
        $verifier = self::base64url(random_bytes(48));

        return ['verifier' => $verifier, 'challenge' => self::challenge($verifier), 'state' => self::base64url(random_bytes(24))];
    }

    /** RFC 7636 S256. */
    public static function challenge(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    public static function authorizeUrl(string $app, string $site, string $redirectUri, string $state, string $challenge, ?string $gatewaySiteUrl = null): string
    {
        return rtrim($gatewaySiteUrl ?? Config::gatewaySiteUrl(), '/') . '/connect?' . http_build_query([
            'app' => $app,
            'site' => $site,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** This site's origin as the gateway wants it: scheme and host, no path. */
    public static function siteOrigin(): string
    {
        $url = (string) evo()->getConfig('site_url', '');
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim($url, '/');
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Trade the code for a key.
     *
     * @return array{access_token:string, token_type:string, key:array}
     * @throws GatewayException `invalid_grant` for an expired, used or mismatched code
     */
    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        $response = $this->http->request('POST', rtrim($this->gatewaySiteUrl ?? Config::gatewaySiteUrl(), '/') . '/connect/token', [
            RequestOptions::JSON => ['grant_type' => 'authorization_code', 'code' => $code,
                'code_verifier' => $verifier, 'redirect_uri' => $redirectUri],
            RequestOptions::HEADERS => ['Accept' => 'application/json', 'User-Agent' => 'AIMage/1.0 (Evolution CMS)'],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];
        if ($response->getStatusCode() >= 400 || !is_string($body['access_token'] ?? null)) {
            $message = is_string($body['error_description'] ?? null) ? $body['error_description']
                : ('The gateway did not hand over a key (HTTP ' . $response->getStatusCode() . ').');
            throw new GatewayException($message, $response->getStatusCode(), false,
                is_string($body['error'] ?? null) ? $body['error'] : 'connect_failed', $body);
        }

        return $body;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
