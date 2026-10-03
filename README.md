# remoteStorage for Nextcloud

A Nextcloud app that makes Nextcloud a [remoteStorage](https://remotestorage.io) server.
Users connect any remoteStorage app with their address `user@your-nextcloud`, approve the
access it asks for, and the app's data is stored as normal files in their Nextcloud.

**Status:** early (0.1.0), not yet in the app store. Tested on Nextcloud 34 and 35 by the
[test harness](https://github.com/jonocodes/remotestorage-nextcloud-harness) (`app/`), including
an unmodified remoteStorage.js app connecting, syncing and deleting.

## For admins

1. Install and enable the app (until it is in the app store, copy this repository without
   `vendor/`, `tests/` and `.git/` to `custom_apps/remotestorage`, or use `just package`):
   `occ app:enable remotestorage`
2. Make sure `/.well-known/webfinger` reaches Nextcloud with CORS intact. With Apache, the
   `.htaccess` that ships with Nextcloud already does this (tested). With nginx, Nextcloud's
   documented config answers with a 301 redirect to `/index.php/.well-known/webfinger`;
   browsers only follow that cross-origin if the redirect itself carries
   `Access-Control-Allow-Origin: *`, so add that header to the redirect (untested so far).
   Check with `curl -i https://your-nextcloud/.well-known/webfinger?resource=acct:<user>@your-nextcloud`:
   the final response must be 200 JSON with `Access-Control-Allow-Origin: *`.
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
- Connected apps are listed in the same settings section with their access and last use;
  **Disconnect** revokes one immediately.
- The data is in the `remoteStorage` folder of your files: visible in the Files app, synced by
  the desktop client, with versions and trash like any other file.

## How it works

The app fills only the gaps between Nextcloud and the remoteStorage protocol, through
Nextcloud's public extension points. Every file operation stays with Nextcloud's own WebDAV.

| Piece | Extension point | What it does |
| --- | --- | --- |
| WebFinger | `OCP\Http\WellKnown\IHandler` | Answers `acct:user@host` with the storage URL (`/remote.php/dav/files/<user>/remoteStorage`) and the OAuth URL. |
| OAuth dialog | app route `/apps/remotestorage/oauth` | Implicit grant (RFC 6749 §4.2). `client_id` must be the origin of `redirect_uri`. |
| Login | `SabrePluginAuthInitEvent` | Accepts the app's own `rs_…` bearer tokens, for that request only (nothing is written to the session). Other bearer tokens pass through to core untouched. |
| WebDAV plugin | `SabrePluginAddEvent` | Scope checks; folder GET → remoteStorage JSON listing; PUT creates missing parents; DELETE removes emptied parents; `If-None-Match` 304 on folders; CORS. |

Without one of its tokens, the app changes nothing about WebDAV: the harness compares
Basic-auth WebDAV responses with the app disabled and enabled (AT10).

### Security model

- **Tokens** are `rs_` + 43 random alphanumerics (~256 bits). Only their SHA-256 is stored.
  They do not expire; users revoke them in settings, and they are deleted with the user.
- **Scopes:** `notes:rw` covers `/notes/` and `/public/notes/`; `notes:r` is read-only; `*:rw`
  covers everything, including the root. Requests outside the scope get 403, and a token can
  never reach files outside the storage root.
- **Public folder:** documents under `/public/<module>/` are readable without a token, as the
  protocol requires; listings, writes and everything else are not.
- **CORS:** `Access-Control-Allow-Origin: *` on token and anonymous requests under the storage
  root, never a reflected origin, so browsers never send cookies with them. Basic-auth requests
  keep core's behaviour.
- **Brute force:** failed `rs_` tokens are registered with Nextcloud's throttler; failures are
  slowed and an IP is blocked after too many, like core logins. Valid tokens are never slowed.
- WebDAV methods other than GET, HEAD, PUT, DELETE and OPTIONS are refused for app tokens.

### Admin and scripting

`occ remotestorage:token:issue <user> "<scope>" [label]` issues a token without the dialog
(for scripts and tests); it shows up in the user's settings like any other.

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
repository checked out next to it.

## Known limitations

- Only tested behind Apache (the official `nextcloud:*-apache` images). nginx setups are untested;
  see the WebFinger note in the admin steps.
- Implicit grant only; no OAuth code flow with PKCE yet.
- A brand-new user's first login shows Nextcloud's first-run wizard on top of the consent page;
  they have to close it before choosing Allow.
- Not yet run against the community server suite
  ([remotestorage/api-test-suite](https://github.com/remotestorage/api-test-suite)).
- Not yet in the app store (needs a signing certificate).

## License

AGPL-3.0-or-later. The CORS approach follows
[WebAppPassword](https://github.com/digital-blueprint/webapppassword) (AGPL-3.0).
