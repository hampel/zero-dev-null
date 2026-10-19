<?php

use App\DevNull\DeviceAuthorization;
use App\DevNull\DevNullException;
use App\DevNull\PublicKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PASTE = 'Paste the devnull:// link, or the code from it';
const CODE = '6f1d0c8e2b7a4f3e9d5c1b0a8f7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b1a0f9e';

beforeEach(function () {
    $this->dir = scratchDir();
    $this->key = "{$this->dir}/device_ed25519.pub";
    config(['devnull.device_key' => $this->key]);
});

afterEach(fn () => cleanupDir($this->dir));

it('makes a device key and redeems the code with a request that verifies in devnull-api', function () {
    Http::fake(['dev-null.sh/api/v1/devices' => Http::response([
        'data' => ['username' => 'acme-dev', 'name' => 'Acme Dev'],
        'unread_notifications_count' => 0,
    ])]);

    $this->artisan('login')
        ->expectsOutputToContain('Made a device key')
        ->expectsOutputToContain('https://dev-null.sh/mobile/authorize?fingerprint=SHA256%3A')
        ->expectsQuestion(PASTE, CODE)
        ->expectsOutput('Signed in as Acme Dev (@acme-dev).')
        ->expectsOutputToContain('https://dev-null.sh/settings/devices')
        ->assertSuccessful();

    expect(PublicKey::fromFile($this->key)->type)->toBe('ssh-ed25519')
        ->and(fileperms(substr($this->key, 0, -4)) & 0777)->toBe(0600);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $body = $request->body();
        $sent = json_decode($body, true);
        $timestamp = $request->header('X-Devnull-Timestamp')[0];
        $nonce = $request->header('X-Devnull-Nonce')[0];
        $signature = $request->header('X-SSH-Signature')[0];
        $signed = implode("\n", [$timestamp, $nonce, 'POST', '/api/v1/devices', $body]);

        expect($request->method())->toBe('POST')
            ->and($request->header('Content-Type')[0])->toBe('application/json')
            ->and($sent['code'])->toBe(CODE)
            // what the server accepts as a verifier, which is what the challenge was made from
            ->and($sent['code_verifier'])->toMatch('/^[A-Za-z0-9._~-]{43,128}$/')
            ->and((int) $timestamp)->toBeGreaterThan(time() - 60)
            ->and($nonce)->toMatch('/^[0-9a-f]{32}$/')
            ->and(signatureVerifies($this->dir, $this->key, $signed, $signature, 'devnull-api'))->toBeTrue()
            // the method and path are signed: the same signature for another request does not verify
            ->and(signatureVerifies($this->dir, $this->key, str_replace("\nPOST\n", "\nDELETE\n", $signed), $signature, 'devnull-api'))->toBeFalse()
            // and a device signature is never a commit signature
            ->and(signatureVerifies($this->dir, $this->key, $signed, $signature, 'devnull-commit'))->toBeFalse();

        return true;
    });
});

it('reuses the device key it made before', function () {
    $fingerprint = PublicKey::fromFile(throwawayKey($this->dir, 'device_ed25519'))->fingerprint();
    Http::fake(['dev-null.sh/*' => Http::response(['data' => ['username' => 'acme-dev', 'name' => '']])]);

    $this->artisan('login')
        ->doesntExpectOutputToContain('Made a device key')
        ->expectsOutputToContain($fingerprint)
        ->expectsQuestion(PASTE, CODE)
        ->expectsOutput('Signed in as @acme-dev.')
        ->assertSuccessful();
});

it('reports /dev/null\'s reason when the code is refused', function () {
    Http::fake(['dev-null.sh/*' => Http::response([
        'message' => 'This sign-in has expired or was already used. Sign in again in the app.',
        'code' => 'invalid_grant',
    ], 400)]);

    $this->artisan('login')
        ->expectsQuestion(PASTE, CODE)
        ->expectsOutput('devnull: This sign-in has expired or was already used. Sign in again in the app.')
        ->assertFailed();
});

it('refuses a paste that is not a code, before sending anything', function () {
    $this->artisan('login')
        ->expectsQuestion(PASTE, 'I clicked approve')
        ->expectsOutput('devnull: That is neither the devnull:// link nor the code from it.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('needs a terminal', function () {
    $this->artisan('login', ['--no-interaction' => true])->assertFailed();

    Http::assertNothingSent();
    expect(file_exists($this->key))->toBeFalse();
});

it('refuses a site that is not https, before making a key', function () {
    config(['devnull.url' => 'http://dev-null.sh']);

    $this->artisan('login')
        ->expectsOutput('devnull: Refusing to send a sign-in to http://dev-null.sh: it is not an https:// URL.')
        ->assertFailed();

    expect(file_exists($this->key))->toBeFalse();
});

it('makes the S256 challenge of RFC 7636', function () {
    // The example in RFC 7636, appendix B.
    $authorization = new DeviceAuthorization('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'state');

    expect($authorization->challenge())->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

it('asks for what /dev/null\'s authorize page requires', function () {
    $key = PublicKey::fromFile(throwawayKey($this->dir));
    $authorization = DeviceAuthorization::start();

    parse_str((string) parse_url($authorization->url('https://dev-null.sh/mobile/authorize', $key, 'devnull CLI'), PHP_URL_QUERY), $query);

    // the server's own rules, from ShowMobileAuthorizationRequest
    expect($query['fingerprint'])->toBe($key->fingerprint())->toMatch('#^SHA256:[A-Za-z0-9+/]{43}$#')
        ->and($query['device_name'])->toBe('devnull CLI')
        ->and($query['code_challenge'])->toBe($authorization->challenge())->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['state'])->toBe($authorization->state)
        ->and($query['redirect_uri'])->toBe('devnull://auth/callback');
});

it('takes the code from the link, only for this sign-in', function (string $pasted, ?string $expected) {
    $authorization = new DeviceAuthorization('verifier', 'abc123');

    if ($expected === null) {
        expect(fn () => $authorization->code($pasted))->toThrow(DevNullException::class);
    } else {
        expect($authorization->code($pasted))->toBe($expected);
    }
})->with([
    'the link' => ['devnull://auth/callback?code='.CODE.'&state=abc123', CODE],
    'the link, with spaces around it' => ['  devnull://auth/callback?code='.CODE."&state=abc123\n", CODE],
    'the bare code' => [CODE, CODE],
    'another sign-in\'s link' => ['devnull://auth/callback?code='.CODE.'&state=other', null],
    'a link without state' => ['devnull://auth/callback?code='.CODE, null],
    'a cancelled sign-in' => ['devnull://auth/callback?error=access_denied&state=abc123', null],
    'a link without a code' => ['devnull://auth/callback?state=abc123', null],
    'nothing' => ['', null],
]);
