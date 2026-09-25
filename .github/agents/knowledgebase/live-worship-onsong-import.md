# Live Worship OnSong import

The licensed song source is kept as a local, ignored Git clone at:

```text
live-worship/import-sources/worship/
```

This directory is intentionally not committed or deployed. Its current source
revision is recorded by Git in that clone. The upstream repository is
`https://github.com/mattgraham/worship.git` and contains `.onsong` files in the
plain-text OnSong/ChordPro-style format.

From the MainzWare repository root, apply the Live Worship migrations and run a
preview import with the numeric ID of an active Live Worship leader:

```sh
php live-worship/api/bin/migrate.php
php live-worship/api/bin/import-onsong.php live-worship/import-sources/worship \
  --member-id=123 --limit=10
```

If the preview is correct, add `--commit` to import the songs:

```sh
php live-worship/api/bin/import-onsong.php live-worship/import-sources/worship \
  --member-id=123 --commit
```

The importer records the source URL, relative file path, and file SHA-256 in
`live_worship.songs`. Re-running it skips the same source paths. Use `--update`
only when intentionally replacing previously imported records. Existing catalog
title/writer duplicates are skipped unless `--allow-duplicates` is supplied.

To update the local source clone later:

```sh
git -C live-worship/import-sources/worship pull --ff-only
```

The importer keeps chord-only rhythm rows such as `/ / /` and positions inline
chords such as `[B]` and `[E/B]` for musician view.

The frontend paste importer also recognizes Worship Together-style copied charts:
section headings without colons, markdown-linked writer lines, separate chord
rows above lyric fragments, and bar rows such as `| B / | F#(add4) / |`. These
are converted into positioned chord marks and retained rhythm rows for musician
view.

## Production rollout

Commit and deploy the application changes using the normal deploy script. The
script copies `live-worship/api/` and runs the Live Worship migration runner on
the VPS automatically; it does not copy the ignored song-source clone.

```sh
./deploy-mainzware.sh
```

After the deployment completes, connect to the VPS, load the production API
environment, and clone the source into a temporary server directory:

```sh
ssh "$DEPLOY_REMOTE"
cd /var/www/mainzware
git clone https://github.com/mattgraham/worship.git /tmp/worship-source
set -a; . api/.env; set +a
```

Find an active production leader ID, preview the import, then commit it:

```sh
php -r 'require "live-worship/api/src/bootstrap.php"; $db=\LiveWorship\Database::connection(); foreach ($db->query("SELECT id, role, active FROM live_worship.members ORDER BY id") as $r) { echo $r["id"]." | ".$r["role"]." | ".($r["active"] ? "active" : "inactive").PHP_EOL; }'

php live-worship/api/bin/import-onsong.php /tmp/worship-source \
  --member-id=PRODUCTION_LEADER_ID --limit=10

php live-worship/api/bin/import-onsong.php /tmp/worship-source \
  --member-id=PRODUCTION_LEADER_ID --commit
```

Use the production member ID, which may differ from local development. Do not
run the local member ID blindly in production.
