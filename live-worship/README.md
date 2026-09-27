# Live Worship

Live Worship is a standalone worship song application. PTC Worship is the initial
configurable instance name; the application itself has no PTC-specific behavior.

## Product boundary

- Its React frontend lives in `frontend/` and builds separately from the portal.
- Its PHP API, migrations, and private upload directory live under `api/`.
- Local legacy data remains in PostgreSQL schema `live_worship` while the
  UUID-based tenant runtime uses shared `lw_control` and `lw_master` schemas
  plus one UUID-derived tenant schema per team. Tenant URLs resolve through
  `/live-worship/<slug>` and never accept a user-supplied SQL schema name; see
  `.github/agents/knowledgebase/live-worship-multitenancy.md`.
- Membership and the three app roles (`leader`, `choir`, `musician`) are stored
  separately from portal authorization. A member can reference a MainzWare
  identity or a leader-managed Live Worship account; app role checks must
  always use `live_worship.members`.
- The migration is app-owned. It is intentionally not added to the portal's
  migration runner, which would couple product lifecycles.

## Features

The app provides a song catalog, two song entry modes (manual ChordPro and
paper-photo lyric recognition), service setlists and archive, live song/section controls,
separate choir and musician song views, role management, and configurable team
name and logo.
Photo recognition uses an app-specific PaddleOCR service on the same machine as
the PHP API. It extracts text lines; the app keeps lyric and section-label
suggestions and drops separate chord-only rows, since chord placement from a
photo is unreliable. Review every imported lyric before saving. The service uses
CPU inference, listens only on `127.0.0.1:8765`, and keeps its model cache inside
`live-worship/ocr/.cache`. It does not use or replace the Tesseract installation
used by other MainzWare apps. Paddle's model files download on first startup.
PaddleOCR is Apache-2.0 open source, so recognition has no per-image API charge;
initial package and model downloads need internet access.

Manual entry and editing use ChordPro text. Bracketed chords such as `[G]` are
placed inline before their lyric words; `{start_of_verse: Verse 1}` and related
section directives organize the song. Switching to Song parts shows the same
content in the structured lyric and chord-placement editor. ChordPro is only the
editing format: PostgreSQL keeps ordered named sections and plain lyrics, with
chord positions in each section's `chord_marks` field. Organization-specific
labels such as Verse 1, Verse 2, and Chorus remain the database section names.

Songs, setlists, roles, and live section state persist in the `live_worship`
PostgreSQL schema. Team logos are the only persistent Live Worship image asset;
they are stored privately under `api/storage/` with random filenames. Song-page
photos are transient OCR input held in the browser/PHP upload temp area only;
reviewed text and structured song content are saved, then the image is discarded.
Live Worship has no scanned-page or cover-art data model.

The public tenant flow uses an existing MainzWare session. The legacy
leader-managed Live Worship username/password path remains only as a temporary
base-URL compatibility path while the original instance is retired; it cannot
select or create a UUID tenant. If a browser has both a legacy marker and a
MainzWare session, the app explicitly hands the session back to MainzWare before
resolving tenant membership. Live Worship account passwords are stored as
password hashes in the app schema; they are separate from MainzWare login
credentials. Membership and roles are separate records, and every app API route
checks that membership. Only leaders can add/remove members, manage songs and
setlists, change the app name, or send live section/song selections. Choir and
musician devices poll the current service state and update their song view.
Setlists archive on API access after their service time plus six hours.

MainzWare-authenticated onboarding offers Create Team and Join Team. Create Team
derives the formal URL slug and queues privileged tenant provisioning. Leaders
can issue one-time, seven-day invitation codes from Manage access; accepting a
code creates the member in the UUID-derived tenant schema and selects that team
context. Invitation tokens are hashed in the control plane and every create,
accept, and revoke decision is audited.

Users who belong to multiple teams can select and switch teams from the
workspace. A control-plane membership directory makes discovery efficient, but
each selected context is still checked against the tenant member row before
tenant data is served.

Live guidance has two modes: any active leader can manually select parts, or one
leader device can claim automatic voice guidance. The automatic controller is
leased in `live_worship.live_state`, so another leader device cannot publish
competing recognition results. A manual selection from any leader tablet releases
the automatic lease. The frontend uses the browser speech-recognition API as an
initial voice-guide adapter; unsupported browsers continue to use synchronized
manual controls.

OCR text and reviewed song fields are saved to PostgreSQL. Uploaded page photos
are never stored as song assets and are discarded after recognition/review.

Production deploy packages the OCR service separately, installs its Python
dependencies in `live-worship/ocr/.venv`, and runs it as `www-data` under
systemd. The PHP API proxies recognition requests to loopback. Production uses
Nginx; local development uses Apache.

## Frontend development

The root `start-mainzworld.sh` launcher starts both the portal and this separate
Vite development server. Open `http://localhost:5173/live-worship/` for the
common landing, login, Create Team, and Join Team flow. After authentication,
the app redirects to `/live-worship/<slug>` for the selected tenant; the portal
proxies that path to Live Worship at port 5174. The PHP API and database must
also be running. For standalone frontend work, run `npm install` and
`npm run dev -- --port 5174` in `frontend/`. The Vite base path is
`/live-worship/`.

### Local PaddleOCR setup (macOS Apple Silicon)

Use an Apple Silicon Python 3.9–3.13 installation with PaddlePaddle's macOS
arm64 CPU package. The launcher starts this service automatically when the
app-specific virtual environment exists; it never changes the machine's shared
Tesseract installation.

```sh
cd live-worship/ocr
python3 -m venv .venv
.venv/bin/python -m pip install -r requirements.txt
```

The model files are fetched and cached on first service startup. Photo import
needs the OCR service running; manual ChordPro entry works without it.
On macOS, the service disables Paddle's native crash signal handler so stopping
development does not leave a stalled process holding port 8765. If photo import
fails locally, check `curl --max-time 5 http://127.0.0.1:8765/health`; an open port
alone does not mean the service is responding.

## Importing the worship song repository

The app includes a repeatable command-line importer for the licensed
[mattgraham/worship](https://github.com/mattgraham/worship) repository. Keep the
repository as a separate local clone; the importer records the repository URL,
relative source path, and SHA-256 for every imported song. It does not execute
or copy repository files into the application.

After running migrations, clone the repository and preview the import:

```sh
git clone https://github.com/mattgraham/worship.git import-sources/worship
php api/bin/migrate.php
php api/bin/import-onsong.php import-sources/worship --member-id=LEADER_ID --limit=10
```

Review the preview, then import the catalog with `--commit`. Existing catalog
titles are skipped by default. Re-running the command skips files already
imported from the same source path; use `--update` when intentionally replacing
those records. Use `--allow-duplicates` only when duplicate titles are wanted.
The importer accepts `.onsong` files recursively and converts inline bracketed
chords into the positioned `chord_marks` used by musician view.

To test parsing without a database write:

```sh
php api/tests/onsong.php
```

## Database and first leader

With the portal API environment loaded, run:

```sh
php api/bin/migrate.php
php api/bin/grant-leader.php <mainzware-username>
```

The first leader command connects an existing active MainzWare account to the
Live Worship role table. After that, leaders can add either active MainzWare
accounts or Live Worship-only accounts and assign `leader`, `choir`, or
`musician`. Live Worship migrations use their own numbered files in
`api/db_migrations/`, tracked and applied in filename order by
`php api/bin/migrate.php`; do not add them to the portal migration runner.
Deployment runs the app-owned lifecycle migration, target platform migration,
and active-tenant migration runners. User-facing team creation only queues a
control-plane provisioning job; run `php api/bin/provision-queued-tenants.php`
with the privileged migration credential to create queued tenant schemas.
For the local development cutover, the original single-instance data can be
copied into the first UUID tenant with:

```sh
php api/bin/backfill-legacy-tenant.php --owner-actor-id=ACTOR_UUID
```

The command leaves the `live_worship` source schema untouched, copies songs,
settings, members, setlists, and live state, and does not copy legacy scan-page
assets.
Tenant removal is also worker-driven: request it with
`php api/bin/request-deprovision.php`, then run
`php api/bin/deprovision-queued-tenants.php` after the retention window.
Pending tenant-created songs are snapshotted into the shared catalog review
queue before their tenant schema is removed.
User uploads are excluded from releases;
deployment also removes any legacy `song-pages` files left by the pre-lifecycle
implementation.

MainzWare administrators can troubleshoot an active team through the Live
Worship admin portal. A support session is explicitly started for one tenant,
lasts 5–30 minutes, is audited, and is read-only. The tenant workspace marks
the session visibly and the runtime rejects all non-GET requests while it is
active. Deprovisioning ends support sessions before removing the tenant schema.
The portal also exposes recent login/context activity and the current seeded
feature matrix for Live Worship, Pro, and 360. Tenant runtime access is resolved
through the server-side entitlement service; plan rows are tied to the exact
subscription and plan that granted them, while explicit grants/overrides are
separately expirable. The entitlement regression covers Free, Pro, 360, grants,
expiration, and `past_due` behavior. Billing remains provider-neutral until
provider integration and final product packaging are approved.

## Saved song keys

Leaders can use **Transpose to** on a song to immediately save its new key and
transposed chords for the catalog, set lists, and live followers. The original
key is preserved and shown beside the current key. In the song editor, changing
the key transposes the submitted chords when **Save** is pressed. Songs without
a known key keep their chords when a key is first assigned. The original key
remains on the song row; no source image is retained.

Migration `005_song_original_key.sql` adds the original key and backfills existing
songs from their current key. Run the app-owned migration runner before serving
the updated API. To check chord transposition, run
`php api/tests/transpose.php` from `live-worship/`.
