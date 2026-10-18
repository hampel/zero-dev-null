# Changelog

Notable changes to devnull.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-10-18

### Added

- `commit --image`: attach up to 3 JPEG, PNG or WebP images of up to 10 MB, as the official
  script's new `--image` does. They are signed by hash in the `devnull-commit-images` namespace
  and sent as multipart, and a file /dev/null would refuse is refused before anything is sent.

## [1.1.2] - 2026-10-18

### Fixed

- `user-commits` failed in an MCP client on the last page of a user's commits, which for most
  users is the only one: its output schema declared `next_cursor` a string, and on the last page
  it is null, so a client that checks answers against the schema - Claude Code does - rejected
  the whole answer.

### Changed

- The README registers the MCP server with `claude mcp add --scope user` and the binary's full
  path. Without `--scope user` the server exists only in the directory the command was run from.

## [1.1.1] - 2026-10-18

### Fixed

- The built binary no longer lists or runs laravel/mcp's `make:mcp-*` generators or
  `mcp:inspector`, which are for developing a server rather than running one. A source checkout
  keeps them.

## [1.1.0] - 2026-10-18

### Added

- `mcp:start devnull`: a read-only MCP server over stdio, with `user`, `user-commits` and `commit`
  tools on the public API.
- A quick start in the README.

### Changed

- The README installs into `~/.local/bin`, which is on the PATH by default where `~/bin` often
  is not.
- The README says to sign in on dev-null.sh again after adding a key on GitHub: the site does not
  see a key added since the last sign-in.

## [1.0.0] - 2026-10-18

### Added

- `commit`, `keys` and `keygen`: commit to /dev/null from the command line, signed with an SSH
  key on your GitHub account.
- A release is built and published by GitHub Actions when a version tag is pushed.
