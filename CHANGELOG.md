# Changelog

All notable changes to this app. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the app uses [Semantic Versioning](https://semver.org/) (0.x: minor versions may break things).
The Nextcloud app store shows each version's section as its release notes.

## [Unreleased]

### Added
- OAuth code flow with PKCE (spec §10.1): the authorization endpoint issues a one-time code
  for `response_type=code` with an S256 challenge (new table `remotestorage_auth_codes`), and
  `POST /apps/remotestorage/oauth/token` redeems it. WebFinger advertises the flow; the
  implicit grant still works.
- Admin-only debug endpoints: `/debug/config` (effective configuration) and `/debug/explain`
  (why a method/path/scope is allowed or denied).
- A redacted token inventory for support.
- Connected apps are grouped by client in personal settings; Disconnect revokes all of an app's tokens.

### Fixed
- Parallel PUTs into a folder that does not exist yet no longer fail with 423 or 404: missing
  parents are created under a per-user lock.
- WebFinger resolves user ids that contain an `@` (common with LDAP/SSO e-mail uids).
- DELETE of a folder named without its trailing slash answers 404 instead of deleting it recursively.
- A specific scope is no longer overridden by a wildcard: `*:r notes:rw` can write to `/notes/`
  (access is the sum of the scope items, per spec §9).
- A root document without a trailing slash (e.g. `/notes`) is no longer treated as module `notes`.
- `Bearer` is accepted case-insensitively, keeping CORS headers for lowercase clients.
- DELETE no longer removes empty parent folders, so a concurrent PUT cannot be deleted with them.
- DELETE responses carry the deleted document's ETag.
- Concurrent PUTs no longer collide when saving the Content-Type.
- A rejected remoteStorage token is answered with a `WWW-Authenticate: Bearer` challenge.

### Security
- Storage paths are anchored to the WebDAV base, so a nested `remote.php/dav/files/...` in a URL
  can no longer make the scope check look at a different path than WebDAV serves.
- Storage paths with encoded slashes, NULs, empty or `.`/`..` segments are rejected.
- Stricter OAuth request validation.

## [0.2.0] - 2026-10-03

### Fixed
- Spec fixes found by the community server test suite; ETag and compression guards.

## [0.1.0] - 2026-10-02

### Added
- First version: WebFinger discovery, OAuth consent page with per-folder scopes, scoped
  tokens, and remoteStorage listings and CORS on Nextcloud's WebDAV.
