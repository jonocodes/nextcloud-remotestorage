# Client compatibility testing

**2026-10-07 · exploratory test report** (re-run of the 2026-10-05 report; upstream RS
Inspektor added 2026-10-08)

This app was tested against real, third-party remoteStorage clients, not just the protocol
conformance suites. The test rig, run scripts and raw evidence live in the
[harness](https://github.com/jonocodes/remotestorage-nextcloud-harness) (its `explore/`
directory); this document is the findings, kept with the app. For the protocol-level results
(AT1–AT13, the community `api-test-suite`), see the harness's `REPORT-app.md`.

## Summary

- **Three real, third-party browser apps work unchanged**: My Favorite Drinks
  (`myfavoritedrinks`), RS Inspektor (whole account, `*`) and `m5x5/inspektor`, a rewrite of
  it. Each connects through its own widget/OAuth, then stores, reads and deletes, with the data
  visible through Nextcloud WebDAV. Two-device sync with an unmodified remoteStorage.js is
  covered by the harness's AT12.
- **A Node backup tool works end to end**: `rs-backup` discovers the account via WebFinger,
  backs it up and restores it into a fresh account, byte-identical and with Content-Types.
- **A second conformance suite mostly passes**: `0dataapp/spec-check` reports 66 passing /
  6 pending / 4 failing, every failure explained (below).
- **Confirmed by hand on a real deployment**: a self-hosted instance connected to
  [savr](https://savr.link) from two browsers on the same account, and the files synced.
- **Two app-side gaps found, both fixed**: DELETE responses carried no ETag (2026-10-05), and
  **parallel PUTs into a new folder could fail** with 423 or 404 (2026-10-07, below).

## Environment

Nextcloud 35.0.1 (`nextcloud:35-apache`, SQLite, default database file locking), app
`remotestorage` 0.3.0 (`6679df2`). Clients pinned and run in the harness (`client-probe` /
`runner` containers). Browser apps load from `http://localhost:<port>` through the harness's
loopback proxy, one port per app, because the app accepts plain-http OAuth redirect URIs only
on loopback hosts. The upstream RS Inspektor run was added separately (2026-10-08) against
app 0.3.0 (`273df2b`).

| Client | Pin | Module / scope | Result |
| --- | --- | --- | --- |
| `rs-backup` / `rs-restore` | npm 1.10.0 (webfinger.js pinned 2.7.1) | `*` | pass |
| My Favorite Drinks | `b51503e` | `myfavoritedrinks` rw | pass |
| RS Inspektor (upstream, [`raucao/inspektor`](https://gitea.kosmos.org/raucao/inspektor)) | `0bece35` | `*` rw | pass, full metadata |
| `m5x5/inspektor` (rewrite of RS Inspektor) | `b499d16` | `*` rw | pass |
| `0dataapp/spec-check` | `e969675` | `api-test-suite:rw`/`:r`, `*:rw` | 66/76 pass, 4 explained |

Retired since 2026-10-05 (kept at `archive/…` tags in the harness, see its `ARCHIVE.md`):
`remotestorage-fuse` (fail: stale client, below) and Notes Together (pass on 2026-10-05; same
remoteStorage.js flow as My Favorite Drinks, and two-device sync is covered by AT12).

## What the passing runs show

- **The connect flow is the ecosystem's, unchanged.** Each browser app brought its own
  `remotestorage-widget`; WebFinger discovery, Nextcloud login and the consent page worked
  as-is, and the consent page correctly named the origin, module and scope.
- **Arbitrary module names work.** `myfavoritedrinks` (and, on 2026-10-05, Notes Together's
  `documents`) and the whole account with `*` all cross the scope check; nothing is
  special-cased to `notes`.
- **Content-Type round-trips.** rs-backup archives the folder-description (which carries
  Content-Type) and restores the exact type, including a `text/plain` deliberately stored on
  a `.json`.
- **Parallel writes into new folders.** rs-restore PUTs a backup's documents in parallel into a
  fresh account; with the fix below, 24 of 25 consecutive restores were complete.

## Findings

### Fixed: parallel PUTs into a new folder (2026-10-07)

rs-restore failed about one restore in three: some documents were never restored. A
remoteStorage PUT creates missing parent folders, and the parallel PUTs raced to create the same
ones:

- **423 Locked.** Nextcloud's `mkdir` takes a shared lock and upgrades it, and the database
  lock backend (the default without Redis) keeps a request's shared locks until it ends, so
  concurrent creators of one folder lock each other out for good.
- **404.** A folder another request had just made could be on disk but not yet in Nextcloud's
  file cache, which WebDAV resolves paths with.

`RsPlugin::createParents` now creates missing parents under a per-user lock, only if they are
still missing once it is held, and looks them up the way WebDAV does. Afterwards: 24 of 25
consecutive restores complete. The remaining failure was a 423 inside Nextcloud core's own
PUT (`Directory::createFile`), with the new folder briefly locked; clients that retry on 423
recover. Not yet measured with Redis file locking, which does not hold shared locks to the end
of the request.

### Fixed: DELETE responses now carry an ETag (2026-10-05)

`spec-check` (spec ≥ 2) expects the deleted item's ETag on the DELETE response, and Nextcloud
core sends none. `RsPlugin` now remembers the document's ETag before the delete and returns it
on the `200` response (`rememberDeleteETag` + `afterDelete`), so the two `spec-check` cases
pass. remoteStorage.js never read this header, so nothing else changes.

### Client-side issues (no app change possible or needed)

- **rs-backup 1.10.0 is broken as published on modern Node.** It `require()`s
  `webfinger.js ^2.7.1`, which resolves to the ESM-only 2.8.2 and throws. Pin `webfinger.js`
  to `2.7.1` (last CJS release).
- **`m5x5/inspektor` loses all file metadata.** This is m5x5's 2026 Next.js rewrite of
  RS Inspektor, not the original: it carries the upstream history but is not a GitHub fork,
  and the rewrite commit (`6313ce6`, 2026-02-28) switched to
  `new RemoteStorage({ cache: true })`. It then calls `getListing`, which — as
  remoteStorage.js documents and tracks in its issues 721/1108 — returns `{name: true}` when
  caching is on. The rewrite therefore shows every item as `application/octet-stream` with
  no size/ETag, cannot preview images, and doesn't tree-render JSON. The app serves correct
  metadata to the same request (reported as
  [m5x5/inspektor#2](https://github.com/m5x5/inspektor/issues/2)). Its delete step is also
  occasionally flaky in the UI (the DELETE is never sent; a re-run passes). Upstream
  RS Inspektor ([`raucao/inspektor`](https://gitea.kosmos.org/raucao/inspektor)) deliberately
  uses `cache: false`, since an inspector should show live data, and is not affected: run
  against the app it shows the correct Content-Type, size and ETag for every item, renders
  JSON as a tree, previews images, and deletes. (It previews an image only when its
  Content-Type carries `charset=binary`, which rs.js adds on binary uploads; a plain
  `image/png` stored by curl shows correct metadata but no preview. That is upstream's own
  rule, not the app's.)
- **remotestorage-fuse is obsolete.** It builds and mounts but parses the obsolete (draft-02)
  listing format, so no path resolves. Unmaintained since 2013; retired from the harness. Use
  a maintained substitute (e.g. `zen-fs-remotestoragejs`) for a filesystem-level client.
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
bash explore/clients/myfavoritedrinks/run.sh
bash explore/clients/inspektor/run.sh            # m5x5/inspektor
bash explore/clients/inspektor-upstream/run.sh   # upstream RS Inspektor (harness PR #4)
bash explore/clients/spec-check/run.sh
```

Each writes `explore/sessions/<client>/result.json`, `notes.md` and screenshots/logs. The plan
and per-client evidence are in the harness's `explore/`; the automated runs here used Nextcloud
35/Apache (the harness's AT matrix covers 34 and nginx), and the by-hand savr run was on a real
self-hosted deployment.
