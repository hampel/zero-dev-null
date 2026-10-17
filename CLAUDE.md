# CLAUDE.md

Standing instructions for a Claude Code session working in this repository.

## What this is

`devnull` is a Laravel Zero 13 CLI (PHP 8.3+) that commits to /dev/null (dev-null.sh), a public
social feed for developers. It reimplements the site's `curl -fsSL https://dev-null.sh/commit |
bash` script; `README.md` documents the protocol, and the script itself is the reference when the
two disagree — fetch it and read it, never pipe it into a shell.

```bash
php devnull commit 'message' --key=<path to .pub>
php devnull keys [--key=<path to .pub>]
php devnull keygen [path]

composer check                  # phpstan level 6, then the suite
composer build                  # release PHAR into builds/
```

## A commit is public and cannot be taken back from here

**Never run `devnull commit` against the real site, and never run `keys` without `--key`.** The
first publishes a post under the user's GitHub identity; the second sends the public half of every
key on the machine to a third party. Both are the user's to run — give them the exact command.

**The suite is safe because nothing in it can reach the network**: `tests/TestCase.php` calls
`Http::preventStrayRequests()`. Keep it there. A test that needs a response fakes one.

## The signature is tested for real, not mocked

`tests/Pest.php` generates a throwaway Ed25519 key per test and `signatureVerifies()` checks the
captured `X-SSH-Signature` with `ssh-keygen -Y verify`, which is what the server does. **Do not
replace this with a faked `Process`** — the payload layout (four lines, no trailing newline) and
the signature encoding are the two things most likely to break, and only a real verification
catches either. The first test also asserts a tampered payload fails, so the check is known to be
able to fail.

## The pinned key is a decision, not an omission

The official script offers every local public key to `/api/cli/key-lookups` on every run.
`commit` here refuses to run without `DEVNULL_KEY` or `--key` instead, so that only `keys` — run
deliberately, once — ever sends the list. Do not add a fallback to the lookup in `commit`.

`CLAUDE.local.md` holds what is specific to this machine.
