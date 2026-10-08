<?php

use Elcreator\aIMage\Agent\Executor;
use Elcreator\aIMage\Gateway\GatewayException;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Services\Account;
use Elcreator\aIMage\Support\ImageScope;

/**
 * Money running out is the first thing a freshly connected account meets (the gateway
 * gives no starting credit), so it has to read as "top up", not as a broken step.
 */

beforeEach(fn () => aimageReset());

test('a 402 is told apart: empty balance versus this key\'s own cap', function () {
    $empty = GatewayException::fromStatus(402, 'no money', ['error' => ['code' => 'insufficient_quota']]);
    $bare = GatewayException::fromStatus(402, 'no money');
    $cap = GatewayException::fromStatus(402, 'cap', ['error' => ['code' => 'key_spend_cap_reached']]);

    expect($empty->paymentCode())->toBe('INSUFFICIENT_BALANCE')
        ->and($bare->paymentCode())->toBe('INSUFFICIENT_BALANCE')
        ->and($cap->paymentCode())->toBe('KEY_CAP_REACHED')
        ->and(GatewayException::fromStatus(500, 'x')->paymentCode())->toBeNull()
        ->and(GatewayException::fromStatus(403, 'x')->paymentCode())->toBeNull();
});

test('a step that meets a 402 fails at once with the balance code, without retrying', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['type' => 'error', 'error' => ['type' => 'x', 'message' => 'Insufficient balance', 'code' => 'insufficient_quota']], 402)]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );
    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED)
        ->and($step->error_code)->toBe('INSUFFICIENT_BALANCE')
        ->and((int) $step->attempt_count)->toBeLessThanOrEqual(1);
});

test('a step that meets the key cap fails with the cap code', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['type' => 'error', 'error' => ['type' => 'x', 'message' => 'cap', 'code' => 'key_spend_cap_reached']], 402)]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );
    $executor->advance($job, $step);

    expect($step->refresh()->error_code)->toBe('KEY_CAP_REACHED');
});

test('the account answer is reduced to what the page shows', function () {
    $a = Account::normalise([
        'balanceEur' => 0, 'lowBalance' => true, 'lowBalanceThresholdEur' => 5,
        'key' => ['capEur' => 10, 'period' => 'month', 'spentEur' => 2.5, 'resetsAt' => '2026-11-01T00:00:00Z'],
        'topUpUrl' => 'https://ai.artur.work/profile',
    ]);

    expect($a)->toMatchArray([
        'balance_eur' => 0.0, 'low_balance' => true, 'threshold_eur' => 5.0, 'cap_eur' => 10.0,
        'spent_eur' => 2.5, 'period' => 'month', 'top_up_url' => 'https://ai.artur.work/profile',
    ]);
});

test('an account answer without a top-up link, cap or balance still gives a usable shape', function () {
    $a = Account::normalise([]);

    expect($a['balance_eur'])->toBeNull()
        ->and($a['cap_eur'])->toBeNull()
        ->and($a['low_balance'])->toBeFalse()
        ->and($a['top_up_url'])->toEndWith('/profile');
});
