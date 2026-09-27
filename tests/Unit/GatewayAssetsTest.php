<?php

use Elcreator\aIMage\Agent\Executor;
use Elcreator\aIMage\Gateway\Connect;
use Elcreator\aIMage\Gateway\GatewayException;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Support\ImageScope;

/**
 * What this package asks of the gateway beyond "make an image": normalised options,
 * private sources and results, one Idempotency-Key per step so a crashed worker never
 * pays twice, provenance kept with the step, a key verified against /account, and
 * "Connect with ai.artur.work" instead of copying a key.
 */

beforeEach(fn () => aimageReset());

function aimageAssetsJob(): Job
{
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    return aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
}

function aimageSentJson(array $history, int $i = 0): array
{
    return json_decode((string) $history[$i]['request']->getBody(), true);
}

// ---------------------------------------------------------------------------
// Options, privacy, idempotency
// ---------------------------------------------------------------------------

test('a generation asks through normalised options, keeps the result private and names its idempotency key', function () {
    $job = aimageAssetsJob();
    $step = aimageStep($job, ['params_json' => ['n' => 3, 'aspect_ratio' => '16:9', 'quality' => 'high', 'folder' => 'aimage']]);
    $history = [];

    (new Executor(
        aimageRecordingClient([aimageJsonResponse(['taskId' => 't-7'])], $history),
        ImageScope::forUser(7),
        aimageDownloader([])
    ))->advance($job, $step);

    $sent = aimageSentJson($history);
    expect($sent['options'])->toBe(['n' => 3, 'strict' => false, 'aspectRatio' => '16:9'])
        ->and($sent)->not->toHaveKey('aspect_ratio')
        ->and($sent)->not->toHaveKey('n')
        ->and($sent['quality'])->toBe('high')
        ->and($sent['outputs'])->toBe(['private' => true])
        ->and($sent)->not->toHaveKey('inputs')
        ->and($history[0]['request']->getHeaderLine('Idempotency-Key'))->toBe($step->fresh()->idempotencyKey());
});

test('with privacy switched off, no privacy flags are sent', function () {
    config()->set('cms.settings.aIMage.privacy.private', false);
    $job = aimageAssetsJob();
    $step = aimageStep($job);
    $history = [];

    (new Executor(aimageRecordingClient([aimageJsonResponse(['taskId' => 't'])], $history), ImageScope::forUser(7), aimageDownloader([])))
        ->advance($job, $step);

    expect(aimageSentJson($history))->not->toHaveKey('outputs');
    config()->set('cms.settings.aIMage.privacy.private', true);
});

test('a step keeps its idempotency key across a crash, and moves to a new one only on a deliberate retry', function () {
    $job = aimageAssetsJob();
    $step = aimageStep($job);
    $first = $step->idempotencyKey();

    // The worker dies after calling the gateway: the next slice runs the step again.
    $step->markRunning();
    expect($step->fresh()->idempotencyKey())->toBe($first);

    // The first call is still running at the gateway: ask again with the same key.
    $step->requeue('in progress', false);
    expect($step->fresh()->idempotencyKey())->toBe($first);

    // A transient failure (the gateway stores no 5xx): a new round, a new key.
    $step->requeue('upstream 503');
    expect($step->fresh()->idempotencyKey())->not->toBe($first)
        ->and($step->fresh()->idempotencyKey())->toEndWith('-r1');
});

test('an idempotency_in_progress answer is retried with the same key', function () {
    $job = aimageAssetsJob();
    $step = aimageStep($job);
    $key = $step->idempotencyKey();
    $history = [];

    (new Executor(aimageRecordingClient([aimageJsonResponse(['type' => 'error', 'error' => [
        'type' => 'invalid_request_error', 'code' => 'idempotency_in_progress', 'message' => 'still running']], 409)], $history),
        ImageScope::forUser(7), aimageDownloader([])))->advance($job, $step);

    expect($step->fresh()->status)->toBe(JobStep::STATUS_QUEUED)
        ->and($step->fresh()->idempotencyKey())->toBe($key);
});

test('the provenance and any option warnings are kept with the step that produced the files', function () {
    $job = aimageAssetsJob();
    $step = aimageStep($job);

    (new Executor(
        aimageClientWithout([aimageJsonResponse([
            'created' => 1,
            'data' => [['url' => 'https://ai.artur.work/v1/media/abc?exp=9&sig=s', 'expires_at' => time() + 600]],
            'provenance' => ['runId' => '41', 'model' => 'gpt-image-1', 'providerModel' => 'gpt-image-1', 'c2pa' => 'present'],
            'warnings' => ['options.aspectRatio: 4:5 is not available; the nearest is 2:3.'],
            'moderation' => null,
        ])]),
        ImageScope::forUser(7),
        aimageDownloader([aimageImageResponse()])
    ))->advance($job, $step);

    $result = $step->fresh()->result_json;
    expect($step->fresh()->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and($result['provenance']['runId'])->toBe('41')
        ->and($result['provenance']['c2pa'])->toBe('present')
        ->and($result['warnings'][0])->toContain('nearest is 2:3');
});

test('an edit and a variation send their sources privately', function () {
    $job = aimageAssetsJob();
    aimagePutImage('images/p.png');
    $history = [];
    $client = aimageRecordingClient([aimageJsonResponse(['taskId' => 'a']), aimageJsonResponse(['taskId' => 'b'])], $history);
    $executor = new Executor($client, ImageScope::forUser(7), aimageDownloader([]));

    $executor->advance($job, aimageStep($job, ['type' => JobStep::TYPE_EDIT, 'source_path' => 'images/p.png']));
    $executor->advance($job, aimageStep($job, ['type' => JobStep::TYPE_VARIATE, 'source_path' => 'images/p.png']));

    foreach ([0, 1] as $i) {
        $body = (string) $history[$i]['request']->getBody();
        expect($body)->toContain('name="inputs[private]"')->and($body)->toContain('name="outputs[private]"')
            ->and($history[$i]['request']->getHeaderLine('Idempotency-Key'))->toStartWith('aimage-');
    }
});

test('a configured upscaler that answers at once is stored without polling', function () {
    config()->set('cms.settings.aIMage.defaults.upscale_model', 'recraft-crisp-upscale');
    $job = aimageAssetsJob();
    aimagePutImage('images/p.png');
    $step = aimageStep($job, ['type' => JobStep::TYPE_UPSCALE, 'model' => '', 'source_path' => 'images/p.png',
        'params_json' => ['scale' => 2, 'folder' => 'aimage']]);
    $history = [];

    (new Executor(
        aimageRecordingClient([aimageJsonResponse(['created' => 1, 'data' => [['url' => 'https://cdn.test/up.png']]])], $history),
        ImageScope::forUser(7),
        aimageDownloader([aimageImageResponse()])
    ))->advance($job, $step);

    expect($step->fresh()->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and((string) $history[0]['request']->getBody())->toContain('recraft-crisp-upscale');
    config()->set('cms.settings.aIMage.defaults.upscale_model', null);
});

// ---------------------------------------------------------------------------
// Keys and Connect
// ---------------------------------------------------------------------------

test('a key is verified against /account, which needs the key, not the public catalogue', function () {
    $history = [];
    aimageRecordingClient([aimageJsonResponse(['balanceEur' => 12.5])], $history)->account();
    expect((string) $history[0]['request']->getUri())->toEndWith('account')
        ->and($history[0]['request']->getHeaderLine('X-Api-Key'))->toBe('test-key');
});

test('connect computes the RFC 7636 challenge and a consent URL on the gateway', function () {
    expect(Connect::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');

    $pkce = Connect::pkce();
    $url = Connect::authorizeUrl(Connect::APP, 'https://example.test', 'https://example.test/manager/cb', $pkce['state'], $pkce['challenge']);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://ai.artur.work/connect?')
        ->and($query['app'])->toBe('aimage')
        ->and($query['code_challenge'])->toBe(Connect::challenge($pkce['verifier']))
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and(Connect::siteOrigin())->toBe('https://example.test');
});

test('connect trades the code for a key, and reports a refused code', function () {
    $history = [];
    $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
        aimageJsonResponse(['access_token' => 'k-1', 'token_type' => 'Bearer', 'key' => ['app' => 'aimage']]),
        aimageJsonResponse(['error' => 'invalid_grant', 'error_description' => 'The code has expired.'], 400),
    ]));
    $stack->push(\GuzzleHttp\Middleware::history($history));
    $connect = new Connect(new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => false]));

    expect($connect->exchange('c', 'v', 'https://example.test/cb')['access_token'])->toBe('k-1')
        ->and((string) $history[0]['request']->getUri())->toBe('https://ai.artur.work/connect/token')
        ->and(aimageSentJson($history))->toBe(['grant_type' => 'authorization_code', 'code' => 'c',
            'code_verifier' => 'v', 'redirect_uri' => 'https://example.test/cb']);

    try {
        $connect->exchange('old', 'v', 'https://example.test/cb');
        $this->fail('expected a refusal');
    } catch (GatewayException $e) {
        expect($e->errorCode)->toBe('invalid_grant')->and($e->getMessage())->toBe('The code has expired.');
    }
});
