<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// A real 1x1 PNG, and the first bytes of a JPEG and a WebP: enough for the content check here,
// which is all the client does - the server decodes and measures them itself.
const PNG = "\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1F\x15\xC4\x89"
    ."\x00\x00\x00\rIDATx\x9Cc\xF8\x0F\x00\x00\x01\x01\x00\x05\x18\xD8N\x00\x00\x00\x00IEND\xAEB`\x82";
const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00not a whole jpeg";
const WEBP = "RIFF\x24\x00\x00\x00WEBPVP8 not a whole webp";

beforeEach(function () {
    $this->dir = scratchDir();
    $this->key = throwawayKey($this->dir);

    foreach (['shipped.png' => PNG, 'photo.jpg' => JPEG, 'art.webp' => WEBP] as $name => $bytes) {
        file_put_contents("{$this->dir}/{$name}", $bytes);
    }
});

afterEach(fn () => cleanupDir($this->dir));

/**
 * The multipart parts a faked request carried, by part name; images[] is a list.
 *
 * @return array{payload: array<string, mixed>, images: list<array<string, mixed>>}
 */
function parts(Request $request): array
{
    $parts = ['payload' => [], 'images' => []];

    foreach ($request->data() as $part) {
        $part['name'] === 'images[]' ? $parts['images'][] = $part : $parts[$part['name']] = $part;
    }

    return $parts;
}

it('sends images with a signature that verifies over their hashes', function () {
    Http::fake(['dev-null.sh/api/cli/commits' => Http::response("Committed: https://dev-null.sh/@x/commits/1\n", 201)]);

    $this->artisan('commit', [
        'message' => ['Shipped!'],
        '--image' => ["{$this->dir}/shipped.png", "{$this->dir}/photo.jpg"],
        '--key' => $this->key,
    ])->expectsOutput('Committed: https://dev-null.sh/@x/commits/1')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $parts = parts($request);
        $payload = $parts['payload']['contents'];
        [$timestamp, $nonce, $replyTo, $hashes, $message] = explode("\n", $payload, 5);
        $signature = $request->header('X-SSH-Signature')[0];

        expect($request->isMultipart())->toBeTrue()
            ->and($parts['payload']['filename'])->toBe('commit')
            ->and((int) $timestamp)->toBeGreaterThan(time() - 60)
            ->and($nonce)->toMatch('/^[0-9a-f]{32}$/')
            ->and($replyTo)->toBe('')
            ->and($message)->toBe('Shipped!')
            // in upload order, and of the bytes uploaded - which is what the server compares
            ->and($hashes)->toBe(hash('sha256', PNG).','.hash('sha256', JPEG))
            ->and(array_map(fn (array $image) => hash('sha256', $image['contents']), $parts['images']))
            ->toBe([hash('sha256', PNG), hash('sha256', JPEG)])
            ->and(array_column($parts['images'], 'filename'))->toBe(['image-1', 'image-2'])
            ->and(array_map(fn (array $image) => $image['headers']['Content-Type'], $parts['images']))
            ->toBe(['image/png', 'image/jpeg'])
            ->and(signatureVerifies($this->dir, $this->key, $payload, $signature, 'devnull-commit-images'))->toBeTrue()
            // a commit with images is never valid as one without: the namespaces differ
            ->and(signatureVerifies($this->dir, $this->key, $payload, $signature, 'devnull-commit'))->toBeFalse();

        return true;
    });
});

it('accepts a WebP, and a reply with an image', function () {
    Http::fake(['dev-null.sh/*' => Http::response('ok', 201)]);

    $this->artisan('commit', [
        'message' => ['Agreed!'],
        '--reply' => '0be0130',
        '--image' => ["{$this->dir}/art.webp"],
        '--key' => $this->key,
    ])->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $lines = explode("\n", parts($request)['payload']['contents'], 5);

        return $lines[2] === '0be0130' && $lines[3] === hash('sha256', WEBP) && $lines[4] === 'Agreed!';
    });
});

it('still sends a commit without images as plain text, in four lines', function () {
    Http::fake(['dev-null.sh/*' => Http::response('ok', 201)]);

    $this->artisan('commit', ['message' => ['No pictures.'], '--key' => $this->key])->assertSuccessful();

    Http::assertSent(fn (Request $request) => ! $request->isMultipart()
        && count(explode("\n", $request->body())) === 4
        && signatureVerifies($this->dir, $this->key, $request->body(), $request->header('X-SSH-Signature')[0]));
});

it('refuses an image the server would refuse, before signing or sending anything', function (Closure $images, string $expected) {
    $this->artisan('commit', [
        'message' => ['Shipped!'],
        '--image' => $images($this->dir),
        '--key' => $this->key,
    ])->expectsOutputToContain($expected)->assertFailed();

    Http::assertNothingSent();
})->with([
    'four images' => [fn (string $dir) => array_fill(0, 4, "{$dir}/shipped.png"), 'up to 3 images'],
    'not an image' => [function (string $dir) {
        file_put_contents("{$dir}/notes.png", 'just text with a .png name');

        return ["{$dir}/notes.png"];
    }, 'is not a JPEG, PNG or WebP file'],
    'a GIF' => [function (string $dir) {
        file_put_contents("{$dir}/anim.gif", "GIF89a\x01\x00\x01\x00");

        return ["{$dir}/anim.gif"];
    }, 'is not a JPEG, PNG or WebP file'],
    'over 10 MB' => [function (string $dir) {
        $handle = fopen("{$dir}/huge.png", 'w');
        fwrite($handle, PNG);
        ftruncate($handle, 10_240_001);
        fclose($handle);

        return ["{$dir}/huge.png"];
    }, 'is larger than 10 MB'],
    'missing' => [fn (string $dir) => ["{$dir}/nowhere.png"], 'Could not read the image'],
]);

it('reports the reason when the server refuses the images', function () {
    Http::fake(['dev-null.sh/*' => Http::response("The attached images do not match the signed payload.\n", 401)]);

    $this->artisan('commit', ['message' => ['Shipped!'], '--image' => ["{$this->dir}/shipped.png"], '--key' => $this->key])
        ->expectsOutput('devnull: The attached images do not match the signed payload.')
        ->assertFailed();
});
