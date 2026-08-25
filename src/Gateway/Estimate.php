<?php

namespace Elcreator\aIMage\Gateway;

/**
 * What one operation, or a whole plan, is expected to cost and how long it is
 * expected to take.
 *
 * `exact` is the field to read before quoting a number to anyone. A tariffed
 * image has an exact price; a token-metered chat turn does not, because the
 * length of the answer is the model's choice and we are guessing it. The two
 * are deliberately not flattened into one "price" — an estimate presented as a
 * quote is how a manager ends up surprised by an invoice.
 */
final class Estimate
{
    /**
     * @param float|null $amount null means genuinely unknown — an unpriced
     *                           model — and must not be rendered as zero.
     * @param string[] $notes human-readable caveats, already localised by the caller
     */
    public function __construct(
        public readonly ?float $amount,
        public readonly string $currency = 'EUR',
        public readonly bool $exact = false,
        public readonly string $basis = 'none',
        public readonly ?int $etaP50 = null,
        public readonly ?int $etaP90 = null,
        public readonly string $latencySource = 'none',
        public readonly int $latencySamples = 0,
        public readonly array $notes = []
    ) {
    }

    public static function unknown(array $notes = []): self
    {
        return new self(null, 'EUR', false, 'unpriced', null, null, 'none', 0, $notes);
    }

    /**
     * Fold a plan's per-step estimates into one.
     *
     * Cost adds. Latency does not: `$concurrency` steps run at once, so the
     * wall-clock total is the serial sum divided by the width of the pipe.
     * Every unpriced step poisons the total's exactness rather than being
     * quietly skipped, because a total that silently omits a step is worse
     * than one that admits it is incomplete.
     *
     * @param self[] $estimates
     */
    public static function sum(array $estimates, int $concurrency = 1): self
    {
        $concurrency = max(1, $concurrency);

        $amount = 0.0;
        $anyAmount = false;
        $anyUnknown = false;
        $exact = true;
        $p50 = 0;
        $p90 = 0;
        $bases = [];
        $sources = [];
        $notes = [];
        $samples = 0;

        foreach ($estimates as $estimate) {
            if ($estimate->amount === null) {
                $anyUnknown = true;
                $exact = false;
            } else {
                $amount += $estimate->amount;
                $anyAmount = true;
            }

            $exact = $exact && $estimate->exact;
            $p50 += (int) ($estimate->etaP50 ?? 0);
            $p90 += (int) ($estimate->etaP90 ?? 0);
            $bases[$estimate->basis] = true;
            $sources[$estimate->latencySource] = true;
            $samples += $estimate->latencySamples;

            foreach ($estimate->notes as $note) {
                $notes[$note] = true;
            }
        }

        if ($anyUnknown) {
            $notes['Some steps use a model with no current price, so the total is a floor, not a quote.'] = true;
        }

        $bases = array_keys($bases);
        $sources = array_keys($sources);

        return new self(
            $anyAmount ? round($amount, 6) : null,
            'EUR',
            $exact && $anyAmount,
            count($bases) === 1 ? $bases[0] : 'mixed',
            (int) ceil($p50 / $concurrency),
            (int) ceil($p90 / $concurrency),
            count($sources) === 1 ? $sources[0] : 'mixed',
            $samples,
            array_keys($notes)
        );
    }

    public function withNotes(array $notes): self
    {
        return new self(
            $this->amount,
            $this->currency,
            $this->exact,
            $this->basis,
            $this->etaP50,
            $this->etaP90,
            $this->latencySource,
            $this->latencySamples,
            array_values(array_unique(array_merge($this->notes, $notes)))
        );
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'exact' => $this->exact,
            'basis' => $this->basis,
            'eta' => [
                'p50' => $this->etaP50,
                'p90' => $this->etaP90,
                'unit' => 's',
                'source' => $this->latencySource,
                'n' => $this->latencySamples,
            ],
            'notes' => $this->notes,
        ];
    }
}
