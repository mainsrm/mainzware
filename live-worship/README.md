# Live Worship

Live Worship is a standalone worship song application. PTC Worship is the initial
configurable instance name; the application itself has no PTC-specific behavior.

## Product boundary

- Its React frontend lives in `frontend/` and builds separately from the portal.
- Its PHP API, migrations, and private upload directory live under `api/`.
- All product data belongs in PostgreSQL schema `live_worship`.
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

Songs, ordered page references, setlists, roles, and live section state persist in
the `live_worship` PostgreSQL schema. Original song pages and the optional team
logo are stored privately under `api/storage/` with random filenames; PostgreSQL
stores their relative paths, MIME types, and byte counts. The worship settings page reports
the total number of pages and their combined stored size. Images are served only
through an authenticated app API route.

The app accepts either an existing MainzWare session or a leader-managed
Live Worship username and password. Live Worship account passwords are stored
as password hashes in the app schema; they are separate from MainzWare login
credentials. Membership and roles are separate records, and every app API route
checks that membership. Only leaders can add/remove members, manage songs and
setlists, change the app name, or send live section/song selections. Choir and
musician devices poll the current service state and update their song view.
Setlists archive on API access after their service time plus six hours.

OCR text and reviewed song fields are saved to PostgreSQL. Original page photos
are uploaded to the private app storage folder.

Production deploy packages the OCR service separately, installs its Python
dependencies in `live-worship/ocr/.venv`, and runs it as `www-data` under
systemd. The PHP API proxies recognition requests to loopback. Production uses
Nginx; local development uses Apache.

## Frontend development

The root `start-mainzworld.sh` launcher starts both the portal and this separate
Vite development server. Open `http://localhost:5173/live-worship/`; the portal
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
Deployment runs the app-owned migration runner. User uploads are excluded from
releases and retained in the storage directory across updates.
