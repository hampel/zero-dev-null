# devnull — /dev/null from the command line

Commit to [/dev/null](https://dev-null.sh) from your terminal, signed with an SSH key that is on
your GitHub account. There is no login and no token: /dev/null checks the signature against the
public keys GitHub publishes for you.

It speaks the same protocol as the site's own `curl -fsSL https://dev-null.sh/commit | bash`
script, as a command you install once rather than a script you pipe into a shell each time.

## Quick start

1. Install `devnull` from a release — see [Installation](#installation).
2. Generate a key for signing /dev/null commits and nothing else:

   ```bash
   devnull keygen                  # writes ~/.ssh/devnull_ed25519 and .pub
   ```

3. Add the `.pub` it prints to GitHub at <https://github.com/settings/ssh/new>, choosing
   **Signing key** as the key type. Then sign in on <https://dev-null.sh> — again, if you already
   have an account: /dev/null does not see a key added on GitHub after you last signed in.
4. Pin it, in your shell profile so it survives a new session:

   ```bash
   export DEVNULL_KEY=~/.ssh/devnull_ed25519.pub
   ```

5. Check that /dev/null can see it on your GitHub account:

   ```bash
   devnull keys --key=~/.ssh/devnull_ed25519.pub
   ```

6. Commit:

   ```bash
   devnull commit 'Hello world!'
   ```

It prints the link to your commit, and whether it is pending review. A refusal exits 1 with
/dev/null's reason. To use a key already on your GitHub account instead, see
[Choosing a key](#choosing-a-key).

## Requirements

- PHP 8.3 or later
- `ssh-keygen` and, to sign through an agent, `ssh-add` — OpenSSH 8.1 or later, for `-Y sign`
- an Ed25519 or RSA key on your GitHub account, as an authentication or a signing key

## Installation

### From a release

Each release publishes a single-file executable and a `SHA256SUMS` to check it against, on the
[releases page](https://github.com/hampel/zero-dev-null/releases). It needs PHP, and nothing else
from Composer:

```bash
DEVNULL_VERSION=1.1.1
BASE=https://github.com/hampel/zero-dev-null/releases/download/$DEVNULL_VERSION

curl -LO $BASE/devnull-$DEVNULL_VERSION
curl -LO $BASE/SHA256SUMS

# the names in SHA256SUMS are the release asset names, so keep the downloaded
# file called devnull-<version> until this has passed
sha256sum --ignore-missing -c SHA256SUMS

mkdir -p ~/.local/bin
install -m 755 devnull-$DEVNULL_VERSION ~/.local/bin/devnull
```

**`~/.local/bin` is on the PATH of most Linux desktops and servers**, but only if it existed when
you logged in — Ubuntu's and Debian's default `~/.profile` add it conditionally. If `devnull` is
not found after the first install, open a new login shell. On macOS neither it nor `~/bin` is on
the PATH by default; use `sudo install -m 755 devnull-$DEVNULL_VERSION /usr/local/bin/devnull`.

### From source

```bash
composer install
composer build                  # writes builds/devnull
mkdir -p ~/.local/bin
install -m 755 builds/devnull ~/.local/bin/devnull
```

## Choosing a key

**`commit` signs with one pinned key and sends nothing else.** Set `DEVNULL_KEY` to the `.pub`
file of a key on your GitHub account; its private half must sit beside it or be in your SSH agent.

```bash
export DEVNULL_KEY=~/.ssh/devnull_ed25519.pub
```

If you are not sure which key GitHub knows, ask once:

```bash
devnull keys                                # asks about every key in the agent and ~/.ssh
devnull keys --key=~/.ssh/id_ed25519.pub    # asks about only this one
```

**Without `--key`, `keys` sends /dev/null the public half of every key on the machine.** Public
keys are not secret, but the list is an inventory of what the machine can reach, which is why
`commit` never does this and the official script does it on every run.

### A key for this alone

```bash
devnull keygen                  # writes ~/.ssh/devnull_ed25519 and .pub
```

Add the `.pub` to GitHub at <https://github.com/settings/ssh/new> as a **signing key**. A signing
key can sign /dev/null commits but cannot push code or log in, so a key with no passphrase costs
little. The signature is made in the `devnull-commit` namespace, so it is not valid for a git
commit either.

## Usage

```bash
devnull commit 'It was DNS.'
devnull commit --reply 0be0130 'Agreed!'
git log -1 --format=%B | devnull commit       # the message from stdin
devnull commit                                # prompts for it
```

A commit is 3 to 4,000 characters. On success the command prints /dev/null's answer — the link
to the commit, and whether it is pending review. On a refusal it exits 1 with /dev/null's reason.

/dev/null allows 10 commits a minute.

## MCP server

`devnull mcp:start devnull` runs an MCP server over stdio, so Claude or another agent can read
/dev/null through its public API. **It is read-only**: it cannot post, star or follow, and needs no
key — the public API takes no authentication.

```bash
claude mcp add --scope user devnull -- ~/.local/bin/devnull mcp:start devnull
```

**Pass `--scope user`, or it is only there in the directory you ran the command from.** Claude
Code's default scope is local: the server is registered for that one project, and a session
started anywhere else does not have it. The full path is for clients started from somewhere other
than a login shell, whose PATH may not include `~/.local/bin`; any other MCP client takes the
same command.

| tool | answers with |
|---|---|
| `user` | a profile, with commit, follower and following counts |
| `user-commits` | a user's commits or replies, newest first, 20 at a time with a cursor |
| `commit` | one commit, by its full id or its link |

The public API has no timeline, search or replies to a commit, so neither does the server: an
agent starts from a username. It allows 60 requests a minute per IP, shared with anything else on
the machine calling it.

## Configuration

| variable | default | for |
|---|---|---|
| `DEVNULL_KEY` | none | the `.pub` file to sign with; `--key` overrides it |
| `DEVNULL_URL` | `https://dev-null.sh` | anything but `https://` is refused, except `localhost` |

## Protocol

Two plain-text requests, both `Content-Type: text/plain; charset=utf-8`:

The MCP server uses the read-only public API instead, `GET /api/v1/...`, which the site's own
README documents.

1. `POST /api/cli/key-lookups` — one `<type> <base64>` per line; the answer is the one on a
   /dev/null user's GitHub account. Only `keys` makes this request.
2. `POST /api/cli/commits` — four lines with no trailing newline: Unix timestamp, a 32-character
   hex nonce, the commit replied to (or empty), and the message. The signature from
   `ssh-keygen -Y sign -n devnull-commit` goes in `X-SSH-Signature`, as the armoured body on one
   line.

## Development

```bash
composer check                  # phpstan, then the test suite
```

The suite makes no network requests — `Http::preventStrayRequests()` fails any test that tries,
since a real request would publish a commit. Signing is real: tests generate a throwaway key and
check each signature with `ssh-keygen -Y verify`, as /dev/null does.

## Releasing

**Pushing a version tag is the release.** `.github/workflows/ci.yml` runs the analysis and the
suite and compiles the binary on every push; a tag also publishes `devnull-<version>` and
`SHA256SUMS` as a GitHub release, with that version's CHANGELOG section as the notes.

1. In `CHANGELOG.md`, rename `## [Unreleased]` to `## [<version>] - <date>`. Do not open a new
   empty one: `## [Unreleased]` exists only while there is a change in it, added above the latest
   release with that first change. The version heading must match the tag exactly, or the release
   falls back to generated notes.
2. Update `DEVNULL_VERSION` in the installation block above.
3. Commit, tag with the bare version (`1.0.0`, not `v1.0.0`), and push both.

**Tag before building, never after.** `app:build` compiles the version from `git describe` into
the binary, so a binary built before its tag announces the previous release. The release job
builds from the tag and refuses to publish if `--version` disagrees with it.

**If a tag push starts no workflow run, run it by hand on the tag.** In Actions, choose the `ci`
workflow, **Run workflow**, and set **Use workflow from** to the tag rather than `master` — the
release job keys on the ref, so a manual run on a tag publishes exactly as a tag push would. This
happened with 1.0.0 on 2026-10-18: both refs reached GitHub, the branch push ran and the tag push
started nothing, with no failed or cancelled run to show for it. The cause was not established.

## Licence

MIT. See [LICENSE](LICENSE).
