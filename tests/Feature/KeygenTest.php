<?php

use App\DevNull\PublicKey;

beforeEach(fn () => $this->dir = scratchDir());

afterEach(fn () => cleanupDir($this->dir));

it('writes an Ed25519 key pair and says to add it as a signing key', function () {
    $this->artisan('keygen', ['path' => "{$this->dir}/devnull"])
        ->expectsOutputToContain('signing key')
        ->assertSuccessful();

    expect(PublicKey::fromFile("{$this->dir}/devnull.pub")->type)->toBe('ssh-ed25519')
        ->and(fileperms("{$this->dir}/devnull") & 0777)->toBe(0600);
});

it('never overwrites an existing key', function () {
    file_put_contents("{$this->dir}/devnull", 'precious');

    $this->artisan('keygen', ['path' => "{$this->dir}/devnull"])->assertFailed();

    expect(file_get_contents("{$this->dir}/devnull"))->toBe('precious');
});
