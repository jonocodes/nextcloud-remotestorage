# Client compatibility testing

**2026-10-05 · exploratory test report**

This app was tested against real, third-party remoteStorage clients, not just the protocol
conformance suites. The test rig, run scripts and raw evidence live in the
[harness](https://github.com/jonocodes/remotestorage-nextcloud-harness) (its `explore/`
directory); this document is the findings, kept with the app. For the protocol-level results
(AT1–AT12, the community `api-test-suite`), see the harness's `REPORT-app.md`.

## Summary

- **Three real, third-party browser apps work unchanged** — My Favorite Drinks
  (`myfavoritedrinks`), Notes Together (`documents`) and RS Inspektor (whole account, `*`) —
  connecting through their own widget/OAuth, storing, reading, syncing across two browser
  contexts, and deleting, with data visible through Nextcloud WebDAV.
- **A Node backup tool works end to end**: `rs-backup` discovers the account via WebFinger,
  backs it up and restores it into a fresh account, byte-identical and with Content-Types.
- **A second conformance suite mostly passes**: `0dataapp/spec-check` reports 64 passing /
  6 pending / 6 failing, every failure explained (below).
- **One ecosystem client is dead**: `remotestorage-fuse` builds and mounts but parses the
  obsolete (draft-02) listing format, so no path resolves. Unmaintained since 2013; a client
  problem, not the app's.
- The only genuine app-side gap found is that **DELETE responses carry no ETag**.

## Environment

Nextcloud 35.0.1.1 (`nextcloud:35-apache`, SQLite), app `remotestorage` 0.2.0. Clients pinned
and run in the harness (`client-probe` / `runner` containers, per-app origins).

| Client | Pin | Module / scope | Result |
| --- | --- | --- | --- |
| `rs-backup` / `rs-restore` | npm 1.10.0 (webfinger.js pinned 2.7.1) | `*` | pass |
| `remotestorage-fuse` | `2a25a1c` | `*` (base_url + token) | fail (stale client) |
| My Favorite Drinks | `b51503e` | `myfavoritedrinks` rw | pass |
| Notes Together | `321c5a1` (v0.3.3) | `documents` rw | pass |
| RS Inspektor (m5x5) | `b499d16` | `*` rw | pass |
| `0dataapp/spec-check` | `e969675` | `api-test-suite:rw`/`:r`, `*:rw` | 64/76 pass, 6 explained |

## What the passing runs show

- **The connect flow is the ecosystem's, unchanged.** Each browser app brought its own
  `remotestorage-widget`; WebFinger discovery, Nextcloud login and the consent page worked
  as-is, and the consent page correctly named the origin, module and scope (e.g. *"Connect
  http://mfav? … myfavoritedrinks — read and write"*).
- **Arbitrary module names work.** `myfavoritedrinks` and `documents`, and the whole account
  with `*`, all cross the scope check; nothing is special-cased to `notes`.
- **Content-Type round-trips.** rs-backup archives the folder-description (which carries
  Content-Type) and restores the exact type, including a `text/plain` deliberately stored on
  a `.json`.
- **Cross-device sync.** A note written by Notes Together on one browser context was read
  back from the server by a second, fresh context.

## Findings

### The one app-side gap: DELETE has no ETag

`spec-check` (spec ≥ 2) expects the deleted item's ETag on the DELETE response; Nextcloud core
sends none. The harness recorded the same deviation for T12, and remoteStorage.js never reads
one, so impact is low — but the app already rewrites DELETE to answer `200` (instead of
WebDAV's `204`), so it could also return the ETag it read before deleting. Worth doing.

### Client-side issues (no app change possible or needed)

- **rs-backup 1.10.0 is broken as published on modern Node.** It `require()`s
  `webfinger.js ^2.7.1`, which resolves to the ESM-only 2.8.2 and throws. Pin `webfinger.js`
  to `2.7.1` (last CJS release).
- **RS Inspektor loses all file metadata.** It constructs `new RemoteStorage({ cache: true })`
  and calls `getListing`, which — as remoteStorage.js documents and tracks in its issues
  721/1108 — returns `{name: true}` when caching is on. Inspektor therefore shows every item
  as `application/octet-stream` with no size/ETag, cannot preview images, and doesn't
  tree-render JSON. The app serves correct metadata to the same request.
- **remotestorage-fuse is obsolete.** Unmaintained since 2013 and written to an old listing
  format; use a maintained substitute (e.g. `zen-fs-remotestoragejs`) or skip the filesystem
  case.
- **spec-check's cross-user test is vacuous for path-embedded storage URLs.** It retargets
  another account by replacing `/<account>` at the end of the base URL (5apps-style); this
  app's URL ends in `/remoteStorage`, so the test silently re-tests the same account. Cross-user
  rejection was verified directly instead: a `*:rw` token gets **403** on another user's path,
  with nothing written.

### Upstream (candidate Nextcloud issue)

- **A first-request race for fresh users.** Restoring into an account whose home storage had
  never been provisioned produced `UNIQUE constraint failed: oc_storages.id` from
  `TokenAuth::login → OC_Util::setupFS → SetupManager::oneTimeUserSetup` — the normal
  per-request login path; lazy home-storage creation is not concurrency-safe. A client that
  fires its first requests in parallel can hit it.

## Reproducing

The rig lives in the harness:

```sh
git clone https://github.com/jonocodes/remotestorage-nextcloud-harness
git clone https://github.com/jonocodes/nextcloud-remotestorage
cd remotestorage-nextcloud-harness
# persistent stack with this app installed (setup/common.sh, setup/rsapp.sh), then:
export NC_VERSION=35 VARIANT=rsapp
bash explore/clients/rs-backup/run.sh
bash explore/clients/remotestorage-fuse/run.sh
bash explore/clients/myfavoritedrinks/run.sh
bash explore/clients/notes-together/run.sh
bash explore/clients/inspektor/run.sh
bash explore/clients/spec-check/run.sh
```

Each writes `explore/sessions/<client>/result.json`, `notes.md` and screenshots/logs. The plan
and per-client evidence are in the harness's `explore/`; only Nextcloud 35/Apache was exercised
here (the harness's AT matrix covers 34 and nginx).
