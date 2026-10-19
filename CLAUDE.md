# CLAUDE.md

Standing instructions for a Claude Code session working in this repository.

## What this is

`devnull` is a Laravel Zero 13 CLI (PHP 8.3+) that commits to /dev/null (dev-null.sh), a public
social feed for developers. It reimplements the site's `curl -fsSL https://dev-null.sh/commit |
bash` script; `README.md` documents the protocol, and the script itself is the reference when the
two disagree — fetch it and read it, never pipe it into a shell. It is also a read-only MCP server
over the site's public API; see The MCP server reads, below. And `login` signs it in as a device on
the site's signed API; see A device key can post as the user, below.

```bash
php devnull commit 'message' --key=<path to .pub>
php devnull keys [--key=<path to .pub>]
php devnull keygen [path]
php devnull login [--name=…]    # approve this machine as a device; asks for a pasted code
php devnull mcp:start devnull   # the MCP server; JSON-RPC on stdin and stdout

composer check                  # phpstan level 6, then the suite
composer build                  # release PHAR into builds/
```

## A commit is public and cannot be taken back from here

**Never run `devnull commit` against the real site, and never run `keys` without `--key`.** The
first publishes a post under the user's GitHub identity; the second sends the public half of every
key on the machine to a third party. Both are the user's to run — give them the exact command.

**The suite is safe because nothing in it can reach the network**: `tests/TestCase.php` calls
`Http::preventStrayRequests()`. Keep it there. A test that needs a response fakes one.

## A device key can post as the user

`login` runs the OAuth authorization code flow with PKCE that the site built for its phone app
(nunomaduro/dev-null.sh#130), and the key it gets approved signs every `/api/v1` request in the
`devnull-api` namespace. **Approved, that key reads and posts as the user with no GitHub key
involved**, so:

- **Never complete a `login` against the real site.** Approving is the user's, in their browser.
  Redeeming a made-up code is safe — it is refused with `invalid_grant` and creates nothing — and
  is how the signing was proven against the site on 2026-10-19: `invalid_grant` comes only after
  the signature verifies, where a bad one is `invalid_signature`.
- **The device key is not `DEVNULL_KEY` and must not become it.** It lives in devnull's config
  directory, apart from `~/.ssh`, with no passphrase because signed requests run unattended.
  Revoking a device must not touch the GitHub key, and a GitHub key approved as a device would
  sign in as the user as well as commit.
- **The code comes back by hand, from the developer tools.** It arrives in a 303 to `devnull://`,
  which nothing here opens. On 2026-10-19 the browser dropped that redirect silently — the button
  greyed out, nothing else — so the address bar is no route; copying the `location` header from
  the Network tab worked, and the approved key then answered a signed `GET /api/v1/me`. The real
  fix is the site accepting a loopback `redirect_uri` (RFC 8252), which is upstream's to make.

## The signature is tested for real, not mocked

`tests/Pest.php` generates a throwaway Ed25519 key per test and `signatureVerifies()` checks the
captured `X-SSH-Signature` with `ssh-keygen -Y verify`, which is what the server does. **Do not
replace this with a faked `Process`** — the payload layout (four lines, no trailing newline; five
with images) and the signature encoding are the two things most likely to break, and only a real
verification catches either. The first test also asserts a tampered payload fails, so the check is
known to be able to fail; `tests/Feature/CommitImagesTest.php` does the same for the images
namespace, and asserts its hashes line against the bytes actually uploaded.

**What the suite does not prove is the multipart request on the wire**: it reads the parts as the
HTTP client hands them to the fake. A real commit with `--image` proved it against the site on
2026-10-18; a change to how the parts are built needs proving the same way, by the user.

## The pinned key is a decision, not an omission

The official script offers every local public key to `/api/cli/key-lookups` on every run.
`commit` here refuses to run without `DEVNULL_KEY` or `--key` instead, so that only `keys` — run
deliberately, once — ever sends the list. Do not add a fallback to the lookup in `commit`.

## The MCP server reads, and stdout is the protocol

`php devnull mcp:start devnull` serves `App\Mcp\Servers\DevNullServer` over stdio. Three tools wrap
the public `GET /api/v1` endpoints in `App\DevNull\PublicApi`, and three more read as the user
through `App\DevNull\Account`, signed by the device key. The site's own README is the contract
for them, and the site's source (nunomaduro/dev-null.sh on GitHub) settles a question the README
does not, as it did for what `commits_count` counts — and that listing notifications marks them
read, which is not in the README at all.

- **A tool is read-only when reading changes nothing on the site, not when it is a GET.**
  `GET /api/v1/notifications` marks every notification read, as the web does, so there is no
  notifications tool, and the group endpoint is useless without it. Check the controller before
  wrapping any new signed endpoint: the PR that added them (nunomaduro/dev-null.sh#130) is where
  to start.

- **Every tool is read-only, and the server has no way to post.** `tests/Feature/McpServerTest.php`
  holds each tool to `readOnlyHint` and no `destructiveHint`. A posting tool would publish under
  the user's GitHub identity; it is a separate decision, never a flag on an existing tool.
- **`bootstrap/ai.php` calls `Mcp::local()` and must never call `Mcp::web()`.** Nothing here
  listens on a port.
- **Nothing but JSON-RPC may reach stdout under `mcp:start`.** `App\Exceptions\Handler`, rebound
  in `bootstrap/app.php`, renders an unhandled exception to stderr; CI's build job drives a
  handshake through the compiled binary and fails on any stdout line that is not JSON.
- **A failure is `Response::error()` with a sentence, never an exception or an empty result**, and
  never a response body: whatever answered in front of the site is not passed on.
- **The binary ships only `commit`, `keygen`, `keys`, `login` and `mcp:start`.**
  `config/commands.php` removes laravel/mcp's generators and inspector when `Phar::running()`, and
  `App\Kernel` is what makes that work: Laravel Zero's `commands.remove` misses any command loaded
  lazily through `#[AsCommand]`, which is all of laravel/mcp's. CI fails if the built binary lists
  anything else. Testing the removal in a checkout by forcing the condition on is safe only for
  `list` - run a generator before the removal works and it writes into `app/`, which happened once
  on 2026-10-18.
- **`tests/Fixtures/*.json` are captured responses**, taken 2026-10-18 with the people and their
  text replaced by `acme-dev` and example values; `me`, `log-latest` and `replies` were taken
  signed on 2026-10-19, with ids, cursors and poll options replaced too. Refresh one by capturing
  again and applying the same replacement, never by editing a shape to make a test pass.

## A tag push is the release

The procedure is the README's Releasing section. A session prepares it — CHANGELOG, the version in
the install block, the commit, the tag — and stops there: **Simon pushes, and the push is what
publishes.** The CI build job's smoke test signs with a throwaway key and stops at the https
guard; keep any new check on the artefact equally unable to reach the real site.

`CLAUDE.local.md` holds what is specific to this machine.
