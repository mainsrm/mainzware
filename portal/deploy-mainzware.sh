#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$ROOT_DIR/frontend"
API_DIR="$ROOT_DIR/api"
SCRAPER_DIR="$ROOT_DIR/../services/property-scraper"
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

printf '%s\n' 'Installing production API dependencies...'
composer --working-dir="$API_DIR" install --no-dev --prefer-dist --optimize-autoloader --no-interaction

mkdir -p "$RELEASE_DIR/frontend" "$RELEASE_DIR/api" "$RELEASE_DIR/SaleAddressMapper" "$RELEASE_DIR/deploy"
rsync -a "$FRONTEND_DIR/dist/" "$RELEASE_DIR/frontend/"
rsync -a --exclude='.env' --exclude='public/uploads/' "$API_DIR/" "$RELEASE_DIR/api/"
rsync -a --exclude='.venv/' --exclude='output/' "$SCRAPER_DIR/" "$RELEASE_DIR/SaleAddressMapper/"
rsync -a "$ROOT_DIR/deploy/" "$RELEASE_DIR/deploy/"

tar -czf "$ARCHIVE" -C "$(dirname "$RELEASE_DIR")" "$(basename "$RELEASE_DIR")"

printf '%s\n' "Uploading release to $REMOTE..."
scp "$ARCHIVE" "$REMOTE:/tmp/mainzware-release.tar.gz"

printf '%s\n' 'Deploying on VPS...'
ssh "$REMOTE" "REMOTE_ROOT='$REMOTE_ROOT' bash -s" <<'REMOTE_SCRIPT'
set -euo pipefail

mkdir -p "$REMOTE_ROOT"
if [ -f "$REMOTE_ROOT/api/.env" ]; then
  cp "$REMOTE_ROOT/api/.env" /tmp/mainzware-production.env
fi
if [ -d "$REMOTE_ROOT/api/public/uploads" ]; then
  mv "$REMOTE_ROOT/api/public/uploads" /tmp/mainzware-uploads
fi

rm -rf /tmp/mainzware-extract
mkdir -p /tmp/mainzware-extract
tar -xzf /tmp/mainzware-release.tar.gz -C /tmp/mainzware-extract --strip-components=1
rm -rf "$REMOTE_ROOT/frontend" "$REMOTE_ROOT/api"
cp -a /tmp/mainzware-extract/frontend "$REMOTE_ROOT/frontend"
cp -a /tmp/mainzware-extract/api "$REMOTE_ROOT/api"
rm -rf /var/www/SaleAddressMapper
cp -a /tmp/mainzware-extract/SaleAddressMapper /var/www/SaleAddressMapper

if [ -f /tmp/mainzware-production.env ]; then
  cp /tmp/mainzware-production.env "$REMOTE_ROOT/api/.env"
fi
mkdir -p "$REMOTE_ROOT/api/public/uploads"
if [ -d /tmp/mainzware-uploads ]; then
  cp -a /tmp/mainzware-uploads/. "$REMOTE_ROOT/api/public/uploads/"
fi

if [ -f "$REMOTE_ROOT/api/.env" ]; then
  (set -a; . "$REMOTE_ROOT/api/.env"; set +a; php "$REMOTE_ROOT/api/bin/migrate_db.php")
else
  echo "WARNING: $REMOTE_ROOT/api/.env not found; skipping migrations (create it and rerun migrate_db.php manually)." >&2
fi

chown -R mainz:www-data "$REMOTE_ROOT"
chown root:www-data "$REMOTE_ROOT/api/.env"
chmod 640 "$REMOTE_ROOT/api/.env"
chown -R www-data:www-data "$REMOTE_ROOT/api/public/uploads"
chmod 770 "$REMOTE_ROOT/api/public/uploads"

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

cp /tmp/mainzware-extract/deploy/mainzware-property-scraper.service /etc/systemd/system/mainzware-property-scraper.service
cp /tmp/mainzware-extract/deploy/mainzware-property-scraper.timer /etc/systemd/system/mainzware-property-scraper.timer
chown -R www-data:www-data /var/www/SaleAddressMapper
systemctl daemon-reload
systemctl enable --now mainzware-property-scraper.timer

php-fpm8.3 -t
nginx -t
systemctl restart php8.3-fpm
systemctl reload nginx
rm -rf /tmp/mainzware-extract /tmp/mainzware-production.env /tmp/mainzware-uploads /tmp/mainzware-release.tar.gz
printf '%s\n' 'Deployment complete.'
REMOTE_SCRIPT
