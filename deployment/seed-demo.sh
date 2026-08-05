#!/usr/bin/env bash
set -Eeuo pipefail

PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${PROJECT_DIR:-$(cd -- "$SCRIPT_DIR/.." && pwd)}"
COMPOSE_FILE="${COMPOSE_FILE:-$PROJECT_DIR/compose.yaml}"
ENV_FILE="${ENV_FILE:-$PROJECT_DIR/.env}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-loopbuy}"
SEED_TIMEOUT_SECONDS="${SEED_TIMEOUT_SECONDS:-180}"
EXPECTED_DEMO_LISTINGS="${EXPECTED_DEMO_LISTINGS:-12}"
LOCK_BASE="${XDG_RUNTIME_DIR:-${TMPDIR:-/tmp}}"
LOCK_DIR="$LOCK_BASE/loopbuy-$UID"
LOCK_FILE="$LOCK_DIR/deploy.lock"

DOCKER="${DOCKER:-$(command -v docker || true)}"
FLOCK="${FLOCK:-$(command -v flock || true)}"

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

prepare_lock_file() {
  if [ -L "$LOCK_DIR" ]; then
    fail "lock directory must not be a symlink: $LOCK_DIR"
  fi

  if [ ! -e "$LOCK_DIR" ]; then
    if ! (umask 077 && mkdir -- "$LOCK_DIR"); then
      [ -d "$LOCK_DIR" ] && [ ! -L "$LOCK_DIR" ] \
        || fail "cannot create private lock directory: $LOCK_DIR"
    fi
  fi

  if [ ! -d "$LOCK_DIR" ] || [ ! -O "$LOCK_DIR" ]; then
    fail "lock directory must be owned by the deploy user: $LOCK_DIR"
  fi

  chmod 700 -- "$LOCK_DIR" || fail "cannot secure lock directory: $LOCK_DIR"

  if [ -L "$LOCK_FILE" ]; then
    fail "lock file must not be a symlink: $LOCK_FILE"
  fi
  if [ -e "$LOCK_FILE" ] && { [ ! -f "$LOCK_FILE" ] || [ ! -O "$LOCK_FILE" ]; }; then
    fail "lock file must be a regular file owned by the deploy user: $LOCK_FILE"
  fi
  if [ ! -e "$LOCK_FILE" ]; then
    (umask 077 && : >"$LOCK_FILE") || fail "cannot create lock file: $LOCK_FILE"
  fi

  if [ -L "$LOCK_FILE" ] || [ ! -f "$LOCK_FILE" ] || [ ! -O "$LOCK_FILE" ]; then
    fail "lock file failed its post-create safety check: $LOCK_FILE"
  fi

  chmod 600 -- "$LOCK_FILE" || fail "cannot secure lock file: $LOCK_FILE"
}

require_file() {
  local path="$1"

  if [ ! -r "$path" ]; then
    fail "required file is missing or unreadable: $path"
  fi
}

compose() {
  "$DOCKER" compose \
    --project-name "$COMPOSE_PROJECT_NAME" \
    --project-directory "$PROJECT_DIR" \
    --file "$COMPOSE_FILE" \
    --env-file "$ENV_FILE" \
    "$@"
}

if [ -z "$DOCKER" ] || [ ! -x "$DOCKER" ]; then
  fail "docker is required"
fi
if [ -z "$FLOCK" ] || [ ! -x "$FLOCK" ]; then
  fail "flock is required"
fi

case "$EXPECTED_DEMO_LISTINGS" in
  ''|*[!0-9]*|0) fail "EXPECTED_DEMO_LISTINGS must be a positive integer" ;;
esac

require_file "$COMPOSE_FILE"
require_file "$ENV_FILE"

prepare_lock_file
exec 9<>"$LOCK_FILE"
if ! "$FLOCK" -n 9; then
  fail "another LoopBuy deployment or demo seed is already running"
fi

cd "$PROJECT_DIR" || fail "cannot cd to project directory: $PROJECT_DIR"

compose config --quiet || fail "Docker Compose configuration is invalid"

if ! compose ps --status running --services backend-db | grep -qx 'backend-db'; then
  fail "backend-db is not running; run deployment/deploy.sh first"
fi

printf 'Recreating the API once with DEMO_SEED_ENABLED=true...\n'
DEMO_SEED_ENABLED=true "$DOCKER" compose \
  --project-name "$COMPOSE_PROJECT_NAME" \
  --project-directory "$PROJECT_DIR" \
  --file "$COMPOSE_FILE" \
  --env-file "$ENV_FILE" \
  up -d --no-deps --force-recreate --wait \
  --wait-timeout "$SEED_TIMEOUT_SECONDS" api

seed_summary="$(compose exec -T backend-db sh -ec '
  MYSQL_PWD="$MYSQL_PASSWORD" mysql \
    --protocol=TCP \
    --host=127.0.0.1 \
    --user="$MYSQL_USER" \
    --batch --skip-column-names \
    "$MYSQL_DATABASE" \
    --execute="
      SELECT CONCAT(
        COUNT(DISTINCT seeded.seed_key), CHAR(58),
        COUNT(DISTINCT seeded.listing_id), CHAR(58),
        COUNT(DISTINCT CASE
          WHEN LOCATE(0x2f6d656469612f64656d6f2f, image.image_url) > 0
            THEN seeded.listing_id
          ELSE NULL
        END)
      )
      FROM demo_seed_listings AS seeded
      JOIN listings AS listing
        ON listing.listing_id = seeded.listing_id
      LEFT JOIN listing_images AS image
        ON image.listing_id = seeded.listing_id;
    "
')" || fail "could not verify demo seed registry"

IFS=':' read -r seed_keys listings_with_registry listings_with_local_images <<<"$seed_summary"

case "$seed_keys:$listings_with_registry:$listings_with_local_images" in
  ''|*[!0-9:]*) fail "unexpected demo seed verification result: $seed_summary" ;;
esac

if [ "$seed_keys" -ne "$EXPECTED_DEMO_LISTINGS" ]; then
  fail "expected $EXPECTED_DEMO_LISTINGS demo listings but found $seed_keys"
fi
if [ "$seed_keys" -ne "$listings_with_registry" ]; then
  fail "demo seed registry contains inconsistent listing references"
fi
if [ "$seed_keys" -ne "$listings_with_local_images" ]; then
  fail "one or more demo listings do not reference local /media/demo assets"
fi

printf 'Demo catalogue ready: %s stable seed keys, %s listings, %s local images.\n' \
  "$seed_keys" "$listings_with_registry" "$listings_with_local_images"
