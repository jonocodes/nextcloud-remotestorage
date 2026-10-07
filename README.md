# remoteStorage for Nextcloud

[![CI](https://github.com/jonocodes/nextcloud-remotestorage/actions/workflows/ci.yml/badge.svg)](https://github.com/jonocodes/nextcloud-remotestorage/actions/workflows/ci.yml)

A Nextcloud app that makes Nextcloud a [remoteStorage](https://remotestorage.io) server.
Users connect any remoteStorage app with their address `user@your-nextcloud`, approve the
access it asks for, and the app's data is stored as normal files in their Nextcloud.

**Status:** early (0.3.0), not yet in the app store. Tested on Nextcloud 34 and 35, behind Apache
and behind nginx (with the WebFinger rewrite below), by the
[test harness](https://github.com/jonocodes/remotestorage-nextcloud-harness) (`app/`), including
an unmodified remoteStorage.js app connecting from two devices, syncing, editing and deleting,
and the community's [server test suite](https://github.com/remotestorage/api-test-suite)
(52 of 53 pass; the one failure is a false positive caused by Nextcloud core's session cookies).

## For admins

1. Install and enable the app (until it is in the app store, copy this repository without
   `vendor/`, `tests/` and `.git/` to `custom_apps/remotestorage`, or use `just package`):
   `occ app:enable remotestorage`
2. Make sure `/.well-known/webfinger` is answered by Nextcloud **directly, without a redirect**.
   remoteStorage.js cannot follow a WebFinger redirect in a browser (its WebFinger library
   fetches with `redirect: "manual"`, which browsers turn into an opaque response), whatever
   CORS headers the redirect carries.
   - **Apache:** the `.htaccess` that ships with Nextcloud already does this.
   - **nginx:** Nextcloud's documented config needs two additions. It redirects every other
     `/.well-known/` path to `/index.php/...` with a 301, which breaks discovery; add this inside
     its `location ^~ /.well-known { ... }` block, next to the `caldav`/`carddav` lines:

     ```nginx
     location = /.well-known/webfinger {
         rewrite ^ /index.php/.well-known/webfinger last;
     }
     ```

     And it gzips JSON, which makes nginx turn ETags weak (`W/`) while remoteStorage requires
     strong ones; add this at the top of its `location ~ \.php(?:$|/) { ... }` block:

     ```nginx
     if ($http_authorization ~* "^Bearer rs_") {
         gzip off;
     }
     ```

     Both are tested (harness variant `rsapp-nginx-fixed`, config in its `docker/nginx/`).

   Check with `curl -i "https://your-nextcloud/.well-known/webfinger?resource=acct:<user>@your-nextcloud"`:
   the very first response must be `200` JSON with `Access-Control-Allow-Origin: *`, not a `301`.
3. Nothing else. No core patches, no CORS allow-list, no per-app configuration.

Optional: `occ config:app:set remotestorage storage_root --value=<folder>` changes the folder in
each user's files that holds remoteStorage data (default `remoteStorage`).

Works alongside [WebAppPassword](https://apps.nextcloud.com/apps/webapppassword); the harness
tests both installed together.

## For users

- Your remoteStorage address is shown in **Settings → Security → remoteStorage**, e.g.
  `alice@cloud.example.com`. Type it into any remoteStorage app's connect widget.
- You log in to Nextcloud as usual (two-factor and SSO apply), then see which folders the app
  asks for (for example `notes`, read and write) and choose **Allow** or **Deny**.
- Connected apps are listed in the same settings section, one row per app with the access it
  was granted and when it last used it; **Disconnect** revokes all of that app's tokens at once
  (reconnecting, or connecting from another device, adds a token to the existing row).
- The data is in the `remoteStorage` folder of your files: visible in the Files app, synced by
  the desktop client, with versions and trash like any other file.

## How it works

The app fills only the gaps between Nextcloud and the remoteStorage protocol, through
Nextcloud's public extension points. Every file operation stays with Nextcloud's own WebDAV.

| Piece | Extension point | What it does |
| --- | --- | --- |
| WebFinger | `OCP\Http\WellKnown\IHandler` | Answers `acct:user@host` with the storage URL (`/remote.php/dav/files/<user>/remoteStorage`) and the OAuth URL. |
| OAuth dialog | app route `/apps/remotestorage/oauth` | Implicit grant (RFC 6749 §4.2). `client_id` must be the origin of `redirect_uri`. |
| Login | `SabrePluginAuthInitEvent` | Accepts the app's own `rs_…` bearer tokens, for that request only (nothing is written to the session). Other bearer tokens pass through to core untouched. A rejected `rs_…` token is answered with a `WWW-Authenticate: Bearer` challenge (RFC 6750 §3), next to core's; Basic-auth requests are untouched. |
| WebDAV plugin | `SabrePluginAddEvent` | Scope checks; folder GET → remoteStorage JSON listing (empty and missing folders list as empty; empty subfolders are not listed); PUT creates missing parents; DELETE leaves emptied parents on disk; `If-None-Match` 304 on folders; CORS; the details below. |

Where Nextcloud's WebDAV differs from the remoteStorage spec, the plugin corrects it for
remoteStorage requests only:

- **Content-Type:** Nextcloud guesses a file's type from its name; remoteStorage requires the
  type sent with the PUT. The app stores it per file (table `remotestorage_ctypes`, keyed by file
  id and ETag) and returns it on GET, HEAD and in listings, while the ETag is unchanged; files
  edited outside remoteStorage fall back to Nextcloud's guess.
- **ETags of same-second writes:** Nextcloud's local storage derives a file's ETag from its
  mtime in whole seconds, inode and size, so a same-size overwrite within a second keeps the old
  ETag and `If-Match` could not detect a concurrent change. After such a PUT the app sets a fresh
  ETag (`ICache::update`) and propagates it to the parent folders (`IPropagator`).
- **Compressed responses:** Apache's `mod_deflate` appends `-gzip` to ETags, nginx makes them
  weak (`W/`), and clients send those back. The app strips both from `If-Match` and
  `If-None-Match`, and on Apache with mod_php disables compression for its responses.
- **Status codes:** overwrites and deletes answer 200, not WebDAV's 204.
- **Preflights** are answered before authentication, even if they carry a token.

Without one of its tokens, the app changes nothing about WebDAV: the harness compares
Basic-auth WebDAV responses with the app disabled and enabled (AT10).

### Security model

- **Tokens** are `rs_` + 43 random alphanumerics (~256 bits). Only their SHA-256 is stored.
  They do not expire; users revoke them in settings, and they are deleted with the user.
- **Scopes:** `notes:rw` covers `/notes/` and `/public/notes/`; `notes:r` is read-only; `*:rw`
  covers everything, including the root. A token's access is the sum of its scope items, so
  `*:r notes:rw` reads everywhere and also writes `notes`. Requests outside the scope get 403,
  and a token can never reach files outside the storage root.
- **Public folder:** documents under `/public/<module>/` are readable without a token, as the
  protocol requires; listings, writes and everything else are not.
- **CORS:** on token and anonymous requests under the storage root, the request's `Origin` is
  echoed (with `Vary: Origin`) and `Access-Control-Allow-Credentials` is never sent, so browsers
  never send cookies with them. Basic-auth requests keep core's behaviour.
- **Brute force:** failed `rs_` tokens are registered with Nextcloud's throttler; failures are
  slowed and an IP is blocked after too many, like core logins. Valid tokens are never slowed.
- WebDAV methods other than GET, HEAD, PUT, DELETE and OPTIONS are refused for app tokens.

### Admin and scripting

`occ remotestorage:token:issue <user> "<scope>" [label]` issues a token without the dialog
(for scripts and tests); it shows up in the user's settings like any other.

Read-only JSON endpoints help with troubleshooting (use `-u <user>:<app-password>` with curl):

- `GET /apps/remotestorage/debug/config` — admin-only; the effective `storage_root`, app and spec version,
  WebFinger `rel`, CORS methods/headers and the supported Nextcloud range.
- `GET /apps/remotestorage/debug/explain?method=PUT&path=/remote.php/dav/files/alice/remoteStorage/notes/a.txt&scope=notes:r`
  — admin-only; a dry run of the access decision for that request: the resolved path, the parsed scope,
  `allow` / `forbidden` / `method-not-allowed`, and why. Leave `scope` empty to simulate an
  anonymous request. It never takes or shows a token.
- `GET /apps/remotestorage/debug/tokens/mine` — the caller's own tokens (any logged-in user).
- `GET /apps/remotestorage/debug/tokens?user=<uid>` — admin-only; any user's tokens.

The token endpoints return redacted records (`id`, `clientId`, `scope`, `createdAt`, `lastUsedAt`);
`token_hash` and secrets are never emitted.

## Development

PHP tooling runs in Docker, so no local PHP is needed. Requires `just` and Docker/Podman.

```sh
just test       # unit tests (pure logic: scopes, paths, access policy, listings, OAuth, tokens)
just lint       # php -l on every file
just harness    # integration: the harness's app/run.sh against this checkout (Nextcloud 35 and 34)
just package    # build/remotestorage.tar.gz
```

DAV integration (auth, plugin, OAuth pages, remoteStorage.js) is tested in the
[harness](https://github.com/jonocodes/remotestorage-nextcloud-harness), which expects this
repository checked out next to it. Compatibility with real third-party remoteStorage clients,
and how to reproduce it, is in [TESTING.md](TESTING.md).

## Known limitations

- Implicit grant only; no OAuth code flow with PKCE yet.
- A brand-new user's first login shows Nextcloud's first-run wizard on top of the consent page;
  they have to close it before choosing Allow.
- Nextcloud core still sends session cookies on every WebDAV response, including anonymous ones.
- Not yet in the app store (needs a signing certificate).

## License

AGPL-3.0-or-later. The CORS approach follows
[WebAppPassword](https://github.com/digital-blueprint/webapppassword) (AGPL-3.0).
