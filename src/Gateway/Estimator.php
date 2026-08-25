<?php

namespace Elcreator\aIMage\Gateway;

/**
 * Turns a catalogue entry into the two numbers the manager asked for before
 * committing: what this will cost, and how long it will take.
 *
 * Everything here is derived from `GET /models`, whose own `legend` explains
 * what each basis and latency source is worth. The rules that matter:
 *
 *  - A model with `variants` is priced per variant, selected by the controls
 *    the request will actually send. Quoting the model's own `price.amount`
 *    for a gpt-image-1 request is quoting the dearest tier for what may be the
 *    cheapest one — a 15× error between `low`/1024 and `high`/1536.
 *  - `price.amount` is null for token-metered models by design: the length of
 *    an answer is the caller's choice. Those are estimated from `price.rates`
 *    and flagged inexact.
 *  - `latency.source` of `seeded` is a prior, not a measurement. It is passed
 *    through so the UI can say so rather than presenting a guess as history.
 */
class Estimator
{
    /**
     * Characters per token, for estimating a prompt's input cost.
     *
     * Deliberately conservative: real ratios run near 4 for English and closer
     * to 2 for Cyrillic, and a low divisor over-estimates, which is the safe
     * direction for a number a manager decides to spend money on.
     */
    private const CHARS_PER_TOKEN = 3.0;

    /** What a planner turn is assumed to emit when nothing better is known. */
    private const ASSUMED_OUTPUT_TOKENS = 900;

    public function __construct(private readonly ModelCatalog $catalog)
    {
    }

    /**
     * One image-producing call.
     *
     * @param array $controls the request's own quality/size/background, used to select a variant
     */
    public function image(string $model, int $count = 1, array $controls = []): Estimate
    {
        $count = max(1, $count);
        $entry = $this->catalog->find($model);

        if ($entry === null) {
            return Estimate::unknown(["The gateway does not offer a model called \"{$model}\"."]);
        }

        [$price, $latency] = $this->resolveVariant($entry, $controls);

        $amount = $this->perImageAmount($price, $entry);
        $notes = [];

        if ($amount === null) {
            $notes[] = 'This model has no current per-image price; only its token rates are published.';
        }

        if (($price['basis'] ?? null) === 'tariff-max') {
            $notes[] = 'Several resolution tiers are priced and this is the dearest, so the real charge can only be lower.';
        }

        if (($price['basis'] ?? null) === 'observed') {
            $notes[] = 'Priced from what comparable runs actually billed, not from a tariff.';
        }

        return new Estimate(
            $amount === null ? null : round($amount * $count, 6),
            (string) ($price['currency'] ?? $this->catalog->currency()),
            (bool) ($price['exact'] ?? false),
            (string) ($price['basis'] ?? 'none'),
            $this->latencyValue($latency, 'p50', $count),
            $this->latencyValue($latency, 'p90', $count),
            (string) ($latency['source'] ?? 'none'),
            (int) ($latency['n'] ?? 0),
            $notes
        );
    }

    /**
     * An upscale, which is priced against the fixed upstream model rather than
     * whichever image model the manager has selected — `/images/upscale`
     * ignores the model in the request.
     */
    public function upscale(int $count = 1): Estimate
    {
        return $this->image(ModelCatalog::UPSCALE_MODEL, $count)
            ->withNotes(['Upscaling always runs on ' . ModelCatalog::UPSCALE_MODEL . '; the gateway fixes the model for this operation.']);
    }

    /**
     * One planner turn.
     *
     * Both halves are guesses — the prompt is measured but tokenised by
     * approximation, and the answer has not been written yet — so the result is
     * always inexact however precise the published rates are.
     */
    public function chat(string $model, int $promptChars, ?int $outputTokens = null): Estimate
    {
        $entry = $this->catalog->find($model);

        if ($entry === null) {
            return Estimate::unknown(["The gateway does not offer a model called \"{$model}\"."]);
        }

        $rates = (array) ($entry['price']['rates'] ?? []);
        $latency = (array) ($entry['latency'] ?? []);

        $inputTokens = (int) ceil(max(0, $promptChars) / self::CHARS_PER_TOKEN);
        $outputTokens = $outputTokens ?? self::ASSUMED_OUTPUT_TOKENS;

        $inputRate = $rates['inputPerMTok'] ?? $rates['inputTextPerMTok'] ?? null;
        $outputRate = $rates['outputPerMTok'] ?? null;

        if ($inputRate === null && $outputRate === null) {
            return Estimate::unknown(['This model publishes no token rates, so a chat turn cannot be priced.'])
                ->withNotes([]);
        }

        $amount = ($inputTokens / 1_000_000) * (float) ($inputRate ?? 0)
            + ($outputTokens / 1_000_000) * (float) ($outputRate ?? 0);

        return new Estimate(
            round($amount, 6),
            (string) ($entry['price']['currency'] ?? $this->catalog->currency()),
            false,
            'rates',
            $this->latencyValue($latency, 'p50', 1),
            $this->latencyValue($latency, 'p90', 1),
            (string) ($latency['source'] ?? 'none'),
            (int) ($latency['n'] ?? 0),
            ['Token counts are estimated from the prompt length; the answer has not been written yet.']
        );
    }

    /** Speech to text, priced per minute of input audio. */
    public function transcribe(string $model, float $seconds): Estimate
    {
        $entry = $this->catalog->find($model);

        if ($entry === null) {
            return Estimate::unknown(["The gateway does not offer a model called \"{$model}\"."]);
        }

        $rate = $entry['price']['rates']['perInputMinute'] ?? null;
        $latency = (array) ($entry['latency'] ?? []);
        $minutes = max(0.0, $seconds) / 60;

        return new Estimate(
            $rate === null ? null : round($minutes * (float) $rate, 6),
            (string) ($entry['price']['currency'] ?? $this->catalog->currency()),
            false,
            $rate === null ? 'unpriced' : 'rates',
            $this->latencyValue($latency, 'p50', 1),
            $this->latencyValue($latency, 'p90', 1),
            (string) ($latency['source'] ?? 'none'),
            (int) ($latency['n'] ?? 0)
        );
    }

    /**
     * Text to speech.
     *
     * Two rate shapes are in the catalogue for this: `perMillionChars` on the
     * tts-1 family and `outputPerMTok` on the gpt-4o TTS models. Whichever is
     * published is used; a model publishing neither is reported unpriced.
     */
    public function speak(string $model, int $chars): Estimate
    {
        $entry = $this->catalog->find($model);

        if ($entry === null) {
            return Estimate::unknown(["The gateway does not offer a model called \"{$model}\"."]);
        }

        $rates = (array) ($entry['price']['rates'] ?? []);
        $latency = (array) ($entry['latency'] ?? []);
        $chars = max(0, $chars);

        if (isset($rates['perMillionChars'])) {
            $amount = ($chars / 1_000_000) * (float) $rates['perMillionChars'];
        } elseif (isset($rates['outputPerMTok'])) {
            $amount = ($chars / self::CHARS_PER_TOKEN / 1_000_000) * (float) $rates['outputPerMTok'];
        } else {
            $amount = null;
        }

        return new Estimate(
            $amount === null ? null : round($amount, 6),
            (string) ($entry['price']['currency'] ?? $this->catalog->currency()),
            false,
            $amount === null ? 'unpriced' : 'rates',
            $this->latencyValue($latency, 'p50', 1),
            $this->latencyValue($latency, 'p90', 1),
            (string) ($latency['source'] ?? 'none'),
            (int) ($latency['n'] ?? 0)
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Select the priced variant matching the controls this request will send.
     *
     * A variant's `when` maps a control name to either one value or a list of
     * them. All named controls must match; a variant naming a control the
     * request does not set cannot be selected, because assuming a default here
     * would quote a tier the request may not land in.
     *
     * @return array{0:array,1:array} price, latency
     */
    private function resolveVariant(array $entry, array $controls): array
    {
        $bestScore = -1;
        $best = null;

        foreach ((array) ($entry['variants'] ?? []) as $variant) {
            $when = (array) ($variant['when'] ?? []);

            if ($when === []) {
                continue;
            }

            $matched = true;

            foreach ($when as $control => $accepted) {
                $requested = $controls[$control] ?? null;

                if ($requested === null || !in_array((string) $requested, array_map('strval', (array) $accepted), true)) {
                    $matched = false;
                    break;
                }
            }

            // More conditions met is a tighter match, so the most specific
            // variant wins rather than whichever happened to be listed first.
            if ($matched && count($when) > $bestScore) {
                $bestScore = count($when);
                $best = $variant;
            }
        }

        if ($best !== null) {
            return [
                (array) ($best['price'] ?? []),
                (array) ($best['latency'] ?? $entry['latency'] ?? []),
            ];
        }

        return [(array) ($entry['price'] ?? []), (array) ($entry['latency'] ?? [])];
    }

    /**
     * A per-image amount, or null when the model is only token-metered.
     *
     * `price.unit` distinguishes the two: `image` means one request is one
     * artefact and `amount` is that artefact's price; `Mtok` means the amount
     * is meaningless per request even when one is present.
     */
    private function perImageAmount(array $price, array $entry): ?float
    {
        $amount = $price['amount'] ?? null;

        if ($amount === null || $amount === '') {
            return null;
        }

        $unit = (string) ($price['unit'] ?? $entry['price']['unit'] ?? 'image');

        return $unit === 'image' ? (float) $amount : null;
    }

    /**
     * A latency quantile, multiplied out over a count.
     *
     * The quantiles describe one call, so n calls of a model are n times the
     * quantile; how much of that the manager actually waits depends on the
     * worker's concurrency, which Estimate::sum() applies.
     */
    private function latencyValue(array $latency, string $quantile, int $count): ?int
    {
        $value = $latency[$quantile] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return (int) ceil((float) $value * max(1, $count));
    }
}
