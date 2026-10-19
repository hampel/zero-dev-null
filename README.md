# devnull — /dev/null from the command line

Commit to [/dev/null](https://dev-null.sh) from your terminal, signed with an SSH key that is on
your GitHub account. A commit needs no login and no token: /dev/null checks the signature against
the public keys GitHub publishes for you.

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
DEVNULL_VERSION=1.2.0
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
devnull commit --image shipped.png 'Shipped!'
git log -1 --format=%B | devnull commit       # the message from stdin
devnull commit                                # prompts for it
```

A commit is 3 to 4,000 characters, images or not. `--image` attaches a JPEG, PNG or WebP of up
to 10 MB and can be given up to 3 times; the type is read from the file's content, not its name,
and a file /dev/null would refuse is refused before anything is signed or sent. /dev/null also
limits each side of an image to 6,000 pixels, which only the server checks.

On success the command prints /dev/null's answer — the link to the commit, and whether it is
pending review. On a refusal it exits 1 with /dev/null's reason.

/dev/null allows 10 commits a minute.

## MCP server

`devnull mcp:start devnull` runs an MCP server over stdio, so Claude or another agent can read
/dev/null: through its public API, which needs no key, and as you once you have
[signed in](#signing-in). **It is read-only**: it cannot post, star, follow or mark notifications
read.

```bash
claude mcp add --scope user devnull -- ~/.local/bin/devnull mcp:start devnull
```

**Pass `--scope user`, or it is only there in the directory you ran the command from.** Claude
Code's default scope is local: the server is registered for that one project, and a session
started anywhere else does not have it. The full path is for clients started from somewhere other
than a login shell, whose PATH may not include `~/.local/bin`; any other MCP client takes the
same command.

| tool | needs | answers with |
|---|---|---|
| `user` | nothing | a profile, with commit, follower and following counts |
| `user-commits` | nothing | a user's commits or replies, newest first, 20 at a time with a cursor |
| `commit` | nothing | one commit, by its full id or its link |
| `me` | `login` | who you are signed in as, and how many notifications are unread |
| `log` | `login` | the following, latest or trending log as the web shows it to you, 20 at a time |
| `replies` | `login` | the replies to a commit, 50 at a time |

**Without `login`, an agent starts from a username**: the public API has no log, search or replies
to a commit. It allows 60 requests a minute per IP, shared with anything else on the machine
calling it; signed requests are allowed 120 a minute for your account.

**There is no notifications tool, on purpose.** Listing them marks every one read on /dev/null, as
the web does, so an agent reading them would clear your unread count; `me` gives the count alone.

## Signing in

**`devnull login` approves this machine as a device on /dev/null**, the way the site's phone app
signs in, for the signed API: the logs and replies that the public API does not have, which
[Reading](#reading) and the MCP server's `me`, `log` and `replies` tools use.

```bash
devnull login                           # or --name='devnull on my laptop'
```

It makes a key in `~/.config/devnull/` on first use, prints a link that asks you to approve it on
dev-null.sh, and asks for the code that comes back. **Approved, the key can read and post as you,
with no GitHub key involved**, so it is kept apart from the keys in `~/.ssh`. It has no passphrase,
because signed requests are made with nobody at the keyboard; revoke it under Settings → Devices
(<https://dev-null.sh/settings/devices>) when the machine is no longer yours.

**The code arrives in a link this machine cannot open, so open the developer tools before you
approve.** Approving redirects the browser to `devnull://auth/callback?code=…`, a scheme the phone
app registers; with nothing registered for it, the browser drops the redirect without a word, and
the button greys out as if it had worked. So:

1. Open the link `login` prints, then the developer tools (F12) on the Network tab.
2. Approve, and select the `authorize` request, a 303.
3. Copy its `location` response header and paste it at the prompt.

`login` takes the link or the bare code, which works once, for five minutes. Nothing appears under
Settings → Devices until `login` has redeemed it.

## Reading

Once [signed in](#signing-in), these read /dev/null as the web shows it to you:

```bash
devnull me                              # who you are signed in as, and your unread count
devnull log                             # the following log; or: latest, trending
devnull log latest --cursor=…           # the next page, as printed under the last one
devnull replies <commit id or link>     # the replies to a commit
devnull log trending --json             # any of them as JSON, for a script
```

Each commit prints with its author, age, stars and replies, its text, any poll, image count or
quoted commit, and its link. What people wrote is printed as text: control characters are
stripped, so a commit cannot reach your terminal as escape codes.

**There is no notifications command yet.** Listing notifications marks every one read on /dev/null,
as the web does; `me` shows the unread count without touching them.

## Configuration

| variable | default | for |
|---|---|---|
| `DEVNULL_KEY` | none | the `.pub` file to sign with; `--key` overrides it |
| `DEVNULL_DEVICE_KEY` | `~/.config/devnull/device_ed25519.pub` | the key `login` approves as a device; made if missing |
| `DEVNULL_URL` | `https://dev-null.sh` | anything but `https://` is refused, except `localhost` |

## Protocol

Two requests, both answered in plain text:

1. `POST /api/cli/key-lookups` — `text/plain`, one `<type> <base64>` per line; the answer is the
   one on a /dev/null user's GitHub account. Only `keys` makes this request.
2. `POST /api/cli/commits` — `text/plain`, four lines with no trailing newline: Unix timestamp, a
   32-character hex nonce, the commit replied to (or empty), and the message. The signature from
   `ssh-keygen -Y sign -n devnull-commit` goes in `X-SSH-Signature`, as the armoured body on one
   line.

**A commit with images is a different request, not an extra field.** The payload gains a fourth
line, the lowercase hex SHA-256 of each image comma-separated in upload order, before the
message. It is signed in the `devnull-commit-images` namespace, so a signature for one form is
never valid for the other. It is sent as `multipart/form-data`: the payload as a file part named
`payload`, and each image as `images[]`, which the server hashes and compares with the signed
line.

The MCP server reads the API instead, `GET /api/v1/...`, which the site's own README documents:
unsigned for the public tools, and signed by the device for the rest.

**A device signs every request to `/api/v1` instead of a payload.** The signed message is the Unix
timestamp, a 32-character hex nonce, the method, the path with its query exactly as sent, and the
raw body, joined by newlines, in the `devnull-api` namespace; the timestamp and nonce go in
`X-Devnull-Timestamp` and `X-Devnull-Nonce` beside `X-SSH-Signature`. `login` has its key approved
through the OAuth authorization code flow with PKCE, and redeems the code at
`POST /api/v1/devices`.

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
