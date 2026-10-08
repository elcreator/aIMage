<?php

namespace Elcreator\aIMage\Services;

use Elcreator\aIMage\Gateway\GatewayException;
use Elcreator\aIMage\Support\Config;

/**
 * What the gateway says about the money behind the key: the balance, whether it is
 * low, this key's own cap, and where the person tops up.
 *
 * `GET /account` is cheap and free, and the page only asks on load, so nothing is
 * cached. A failure is never an error for the page: the balance is a courtesy, and a
 * gateway that is down must not hide the workbench - the answer is simply null.
 */
final class Account
{
    public function __construct(private readonly Actor $actor)
    {
    }

    /**
     * @return array{balance_eur:?float, low_balance:bool, threshold_eur:?float, cap_eur:?float,
     *               spent_eur:?float, period:?string, resets_at:?string, top_up_url:string}|null
     */
    public function summary(): ?array
    {
        $client = $this->actor->client();
        if ($client === null) {
            return null;
        }

        try {
            return self::normalise($client->account());
        } catch (GatewayException) {
            return null;
        }
    }

    /** The gateway's camelCase answer in the page's shape; a missing top-up link falls back to the profile. */
    public static function normalise(array $raw): array
    {
        $key = is_array($raw['key'] ?? null) ? $raw['key'] : [];
        $number = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;
        $text = static fn ($v): ?string => is_string($v) && $v !== '' ? $v : null;

        return [
            'balance_eur' => $number($raw['balanceEur'] ?? null),
            'low_balance' => (bool) ($raw['lowBalance'] ?? false),
            'threshold_eur' => $number($raw['lowBalanceThresholdEur'] ?? null),
            'cap_eur' => $number($key['capEur'] ?? null),
            'spent_eur' => $number($key['spentEur'] ?? null),
            'period' => $text($key['period'] ?? null),
            'resets_at' => $text($key['resetsAt'] ?? null),
            'top_up_url' => $text($raw['topUpUrl'] ?? null) ?? self::fallbackTopUpUrl(),
        ];
    }

    /** Where to pay when the gateway did not say: the profile of the gateway's own site. */
    public static function fallbackTopUpUrl(): string
    {
        return Config::gatewaySiteUrl() . '/profile';
    }
}
