#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORTAL_DIR="$ROOT_DIR/portal"
FRONTEND_DIR="$PORTAL_DIR/frontend"
API_DIR="$PORTAL_DIR/api"
SCRAPER_DIR="$ROOT_DIR/services/property-scraper"
WORSHIP_FRONTEND_DIR="$ROOT_DIR/live-worship/frontend"
WORSHIP_API_DIR="$ROOT_DIR/live-worship/api"
WORSHIP_OCR_DIR="$ROOT_DIR/live-worship/ocr"
RELEASE_DIR="$(mktemp -d /tmp/mainzware-release.XXXXXX)"
ARCHIVE="${RELEASE_DIR}.tar.gz"
REMOTE="${DEPLOY_REMOTE:-root@129.121.142.227}"
REMOTE_ROOT="${DEPLOY_ROOT:-/var/www/mainzware}"

cleanup() {
  rm -rf "$RELEASE_DIR" "$ARCHIVE"
}
trap cleanup EXIT

printf '%s\n' 'Building frontend...'
npm --prefix "$FRONTEND_DIR" run build

printf '%s\n' 'Building Live Worship frontend...'
npm --prefix "$WORSHIP_FRONTEND_DIR" run build

printf '%s\n' 'Installing production API dependencies...'
composer --working-dir="$API_DIR" install --no-dev --prefer-dist --optimize-autoloader --no-interaction

mkdir -p "$RELEASE_DIR/frontend" "$RELEASE_DIR/api" "$RELEASE_DIR/live-worship/api" "$RELEASE_DIR/live-worship/ocr" "$RELEASE_DIR/SaleAddressMapper" "$RELEASE_DIR/deploy"
rsync -a "$FRONTEND_DIR/dist/" "$RELEASE_DIR/frontend/"
mkdir -p "$RELEASE_DIR/frontend/live-worship"
rsync -a "$WORSHIP_FRONTEND_DIR/dist/" "$RELEASE_DIR/frontend/live-worship/"
rsync -a --exclude='.env' --exclude='public/uploads/' "$API_DIR/" "$RELEASE_DIR/api/"
rsync -a --exclude='.venv/' --exclude='output/' "$SCRAPER_DIR/" "$RELEASE_DIR/SaleAddressMapper/"
rsync -a --exclude='storage/' "$WORSHIP_API_DIR/" "$RELEASE_DIR/live-worship/api/"
rsync -a --exclude='.venv/' --exclude='.cache/' "$WORSHIP_OCR_DIR/" "$RELEASE_DIR/live-worship/ocr/"
rsync -a "$PORTAL_DIR/deploy/" "$RELEASE_DIR/deploy/"

tar -czf "$ARCHIVE" -C "$(dirname "$RELEASE_DIR")" "$(basename "$RELEASE_DIR")"

printf '%s\n' "Uploading release to $REMOTE..."
scp "$ARCHIVE" "$REMOTE:/tmp/mainzware-release.tar.gz"

printf '%s\n' 'Deploying on VPS...'
ssh "$REMOTE" "REMOTE_ROOT='$REMOTE_ROOT' bash -s" <<'REMOTE_SCRIPT'
set -euo pipefail

# Per-run staging dir: fixed /tmp paths survived a failed deploy, and the next
# run's mv nested the leftover inside itself and aborted.
STAGING_DIR="$(mktemp -d /tmp/mainzware-deploy.XXXXXX)"
trap 'rm -rf "$STAGING_DIR"' EXIT

mkdir -p "$REMOTE_ROOT"
if [ -f "$REMOTE_ROOT/api/.env" ]; then
  cp "$REMOTE_ROOT/api/.env" "$STAGING_DIR/production.env"
fi
if [ -d "$REMOTE_ROOT/api/public/uploads" ]; then
  mkdir -p "$STAGING_DIR/uploads"
  cp -a "$REMOTE_ROOT/api/public/uploads/." "$STAGING_DIR/uploads/"
fi

mkdir -p "$STAGING_DIR/extract"
tar -xzf /tmp/mainzware-release.tar.gz -C "$STAGING_DIR/extract" --strip-components=1
rm -rf "$REMOTE_ROOT/frontend" "$REMOTE_ROOT/api"
cp -a "$STAGING_DIR/extract/frontend" "$REMOTE_ROOT/frontend"
cp -a "$STAGING_DIR/extract/api" "$REMOTE_ROOT/api"
mkdir -p "$REMOTE_ROOT/live-worship/api"
cp -a "$STAGING_DIR/extract/live-worship/api/." "$REMOTE_ROOT/live-worship/api/"
mkdir -p "$REMOTE_ROOT/live-worship/ocr"
cp -a "$STAGING_DIR/extract/live-worship/ocr/." "$REMOTE_ROOT/live-worship/ocr/"
rm -rf /var/www/SaleAddressMapper
cp -a "$STAGING_DIR/extract/SaleAddressMapper" /var/www/SaleAddressMapper

if [ -f "$STAGING_DIR/production.env" ]; then
  cp "$STAGING_DIR/production.env" "$REMOTE_ROOT/api/.env"
fi
mkdir -p "$REMOTE_ROOT/api/public/uploads"
if [ -d "$STAGING_DIR/uploads" ]; then
  cp -a "$STAGING_DIR/uploads/." "$REMOTE_ROOT/api/public/uploads/"
fi
mkdir -p "$REMOTE_ROOT/live-worship/api/storage/song-pages"

if [ -f "$REMOTE_ROOT/api/.env" ]; then
  (set -a; . "$REMOTE_ROOT/api/.env"; set +a; php "$REMOTE_ROOT/api/bin/check_env.php" && php "$REMOTE_ROOT/api/bin/migrate_db.php" && php "$REMOTE_ROOT/live-worship/api/bin/migrate.php")
else
  echo "WARNING: $REMOTE_ROOT/api/.env not found; skipping migrations (create it and rerun migrate_db.php manually)." >&2
fi

chown -R mainz:www-data "$REMOTE_ROOT"
chown root:www-data "$REMOTE_ROOT/api/.env"
chmod 640 "$REMOTE_ROOT/api/.env"
chown -R www-data:www-data "$REMOTE_ROOT/api/public/uploads"
chmod 770 "$REMOTE_ROOT/api/public/uploads"
chown -R www-data:www-data "$REMOTE_ROOT/live-worship/api/storage"
chmod -R u=rwX,g=rwX,o= "$REMOTE_ROOT/live-worship/api/storage"

if [ ! -x /var/www/SaleAddressMapper/.venv/bin/python ]; then
  apt-get update
  apt-get install -y python3 python3-venv
  python3 -m venv /var/www/SaleAddressMapper/.venv
fi
/var/www/SaleAddressMapper/.venv/bin/pip install --quiet -r /var/www/SaleAddressMapper/requirements.txt
/var/www/SaleAddressMapper/.venv/bin/python -m playwright install --with-deps chromium
mkdir -p /var/www/SaleAddressMapper/.browsers
chown -R www-data:www-data /var/www/SaleAddressMapper/.browsers
runuser -u www-data -- env PLAYWRIGHT_BROWSERS_PATH=/var/www/SaleAddressMapper/.browsers /var/www/SaleAddressMapper/.venv/bin/python -m playwright install chromium

if [ ! -x "$REMOTE_ROOT/live-worship/ocr/.venv/bin/python" ]; then
  apt-get update
  apt-get install -y python3 python3-venv libgomp1
  python3 -m venv "$REMOTE_ROOT/live-worship/ocr/.venv"
fi
"$REMOTE_ROOT/live-worship/ocr/.venv/bin/pip" install --quiet -r "$REMOTE_ROOT/live-worship/ocr/requirements.txt"
mkdir -p "$REMOTE_ROOT/live-worship/ocr/.cache"
chown -R www-data:www-data "$REMOTE_ROOT/live-worship/ocr/.cache"
cat > /etc/systemd/system/live-worship-ocr.service <<OCR_SERVICE
[Unit]
Description=Live Worship PaddleOCR photo recognition
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$REMOTE_ROOT/live-worship/ocr
Environment=LIVE_WORSHIP_OCR_HOST=127.0.0.1
Environment=LIVE_WORSHIP_OCR_PORT=8765
Environment=PADDLE_PDX_CACHE_HOME=$REMOTE_ROOT/live-worship/ocr/.cache
ExecStart=$REMOTE_ROOT/live-worship/ocr/.venv/bin/python server.py
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=full
ReadWritePaths=$REMOTE_ROOT/live-worship/ocr/.cache

[Install]
WantedBy=multi-user.target
OCR_SERVICE

cp "$STAGING_DIR/extract/deploy/mainzware-property-scraper.service" /etc/systemd/system/mainzware-property-scraper.service
cp "$STAGING_DIR/extract/deploy/mainzware-property-scraper.timer" /etc/systemd/system/mainzware-property-scraper.timer
chown -R www-data:www-data /var/www/SaleAddressMapper
systemctl daemon-reload
systemctl enable --now mainzware-property-scraper.timer
systemctl enable live-worship-ocr.service
systemctl restart live-worship-ocr.service

php-fpm8.3 -t
nginx -t
systemctl restart php8.3-fpm
systemctl reload nginx
rm -f /tmp/mainzware-release.tar.gz
printf '%s\n' 'Deployment complete.'
REMOTE_SCRIPT
