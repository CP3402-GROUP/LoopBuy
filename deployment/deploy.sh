#!/usr/bin/env bash
set -Eeuo pipefail

PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${PROJECT_DIR:-$(cd -- "$SCRIPT_DIR/.." && pwd)}"
GIT_BRANCH="${GIT_BRANCH:-main}"
COMPOSE_FILE="${COMPOSE_FILE:-$PROJECT_DIR/compose.yaml}"
ENV_FILE="${ENV_FILE:-$PROJECT_DIR/.env}"
PROVISION_FILE="${PROVISION_FILE:-$SCRIPT_DIR/provision-wordpress.php}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-loopbuy}"
LOG_FILE="${LOG_FILE:-$SCRIPT_DIR/deploy.log}"
STATE_FILE="${STATE_FILE:-$SCRIPT_DIR/.last-successful-deploy}"
PROVISION_STATE_FILE="${PROVISION_STATE_FILE:-$SCRIPT_DIR/.last-successful-provision}"
LOCK_FILE="${LOCK_FILE:-/tmp/loopbuy-deploy.lock}"
MAX_LOG_BYTES="${MAX_LOG_BYTES:-1048576}"
DEPLOY_TIMEOUT_SECONDS="${DEPLOY_TIMEOUT_SECONDS:-180}"
LOG_NOOP="${LOG_NOOP:-0}"
FORCE_DEPLOY="${FORCE_DEPLOY:-0}"
REQUIRE_PRODUCTION_ENV="${REQUIRE_PRODUCTION_ENV:-1}"

DATE_FMT="+%Y-%m-%d %H:%M:%S"

GIT="${GIT:-$(command -v git || true)}"
DOCKER="${DOCKER:-$(command -v docker || true)}"
FLOCK="${FLOCK:-$(command -v flock || true)}"
REMOTE_REF="refs/remotes/origin/$GIT_BRANCH"

log() {
  mkdir -p "$(dirname "$LOG_FILE")"
  printf '[%s] %s\n' "$(date "$DATE_FMT")" "$*" | tee -a "$LOG_FILE"
}

rotate_log() {
  if [ -f "$LOG_FILE" ]; then
    local size
    size="$(wc -c <"$LOG_FILE" 2>/dev/null || printf '0')"
    if [ "$size" -gt "$MAX_LOG_BYTES" ]; then
      mv -f "$LOG_FILE" "$LOG_FILE.1"
    fi
  fi
}

fail() {
  rotate_log
  log "ERROR: $*"
  exit 1
}

run_logged() {
  local description="$1"
  shift

  log "$description"
  if ! "$@" >>"$LOG_FILE" 2>&1; then
    log "ERROR: failed: $description"
    return 1
  fi
}

require_cmd() {
  local name="$1"
  local path="$2"

  if [ -z "$path" ] || [ ! -x "$path" ]; then
    fail "required command not found or not executable: $name"
  fi
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

provision_wordpress() {
  compose exec -T --user www-data wordpress php <"$PROVISION_FILE"
}

env_file_value() {
  local key="$1"
  local line

  line="$(grep -E "^[[:space:]]*(export[[:space:]]+)?${key}=" "$ENV_FILE" | tail -n 1 || true)"
  if [ -z "$line" ]; then
    return 1
  fi

  line="$(printf '%s\n' "$line" | sed -E "s/^[[:space:]]*(export[[:space:]]+)?${key}=//")"
  line="${line%$'\r'}"

  case "$line" in
    \"*\") line="${line#\"}"; line="${line%\"}" ;;
    \'*\') line="${line#\'}"; line="${line%\'}" ;;
  esac

  printf '%s' "$line"
}

validate_deploy_env() {
  local key
  local value
  local invalid=""

  if [ "$REQUIRE_PRODUCTION_ENV" != "1" ]; then
    return 0
  fi

  for key in WORDPRESS_DB_PASSWORD WORDPRESS_DB_ROOT_PASSWORD \
    BACKEND_DB_PASSWORD BACKEND_DB_ROOT_PASSWORD JWT_SECRET QDRANT_API_KEY OPENAI_API_KEY \
    GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET GOOGLE_REDIRECT_URIS BFF_SHARED_SECRET \
    RESEND_API_KEY RESEND_FROM EMAIL_VERIFICATION_URL; do
    value="$(env_file_value "$key" || true)"
    case "$value" in
      ""|change-me|change-root-me|change-*|replace-*|loopbuy-*-local-only|dev-*|test-*)
        invalid="$invalid $key"
        ;;
    esac
  done

  if [ -n "$invalid" ]; then
    fail "deployment env has missing or placeholder values:$invalid"
  fi

  value="$(env_file_value JWT_SECRET || true)"
  if [ "${#value}" -lt 32 ]; then
    fail "JWT_SECRET must contain at least 32 characters"
  fi

  value="$(env_file_value BFF_SHARED_SECRET || true)"
  if [ "${#value}" -lt 32 ]; then
    fail "BFF_SHARED_SECRET must contain at least 32 independent characters"
  fi

  local qwen_key
  qwen_key="$(env_file_value QWEN_API_KEY || true)"
  if [ -z "$qwen_key" ]; then
    qwen_key="$(env_file_value DASHSCOPE_API_KEY || true)"
  fi
  if [ -z "$qwen_key" ]; then
    fail "QWEN_API_KEY or DASHSCOPE_API_KEY is required for production deployment"
  fi

  value="$(env_file_value QWEN_BASE_URL || true)"
  case "$value" in
    https://*) ;;
    *) fail "QWEN_BASE_URL must be a non-empty HTTPS URL for production deployment" ;;
  esac

  value="$(env_file_value GOOGLE_REDIRECT_URIS || true)"
  case "$value" in
    *https://*) ;;
    *) fail "GOOGLE_REDIRECT_URIS must include an HTTPS production callback" ;;
  esac

  value="$(env_file_value EMAIL_VERIFICATION_URL || true)"
  case "$value" in
    https://*) ;;
    *) fail "EMAIL_VERIFICATION_URL must be an HTTPS URL for production deployment" ;;
  esac

  value="$(env_file_value RESEND_BASE_URL || true)"
  case "$value" in
    https://*) ;;
    *) fail "RESEND_BASE_URL must be an HTTPS URL for production deployment" ;;
  esac

  value="$(env_file_value RESEND_FROM || true)"
  case "$value" in
    *@loopbuy.store*) ;;
    *) fail "RESEND_FROM must use the verified loopbuy.store domain" ;;
  esac

  value="$(env_file_value WORDPRESS_DEBUG || true)"
  if [ "$value" != "0" ]; then
    fail "WORDPRESS_DEBUG must be 0 for deployment (or set REQUIRE_PRODUCTION_ENV=0 for a development server)"
  fi
}

require_cmd "git" "$GIT"
require_cmd "docker" "$DOCKER"
require_cmd "flock" "$FLOCK"

mkdir -p "$(dirname "$LOCK_FILE")"
exec 9>"$LOCK_FILE"
if ! "$FLOCK" -n 9; then
  exit 0
fi

cd "$PROJECT_DIR" || fail "cannot cd to project directory: $PROJECT_DIR"

if [ ! -d .git ]; then
  fail "not a git repository: $PROJECT_DIR"
fi

require_file "$COMPOSE_FILE"
require_file "$ENV_FILE"
require_file "$PROVISION_FILE"
validate_deploy_env

current_branch="$("$GIT" symbolic-ref --quiet --short HEAD || true)"
if [ "$current_branch" != "$GIT_BRANCH" ]; then
  fail "expected branch '$GIT_BRANCH', current branch is '${current_branch:-detached HEAD}'"
fi

fetch_output=""
if ! fetch_output="$("$GIT" fetch --prune origin "+refs/heads/$GIT_BRANCH:$REMOTE_REF" 2>&1)"; then
  rotate_log
  log "ERROR: git fetch failed"
  printf '%s\n' "$fetch_output" >>"$LOG_FILE"
  exit 1
fi

local_commit="$("$GIT" rev-parse HEAD)"
remote_commit="$("$GIT" rev-parse "$REMOTE_REF")"
last_deployed_commit=""
if [ -r "$STATE_FILE" ]; then
  last_deployed_commit="$(head -n 1 "$STATE_FILE")"
fi
provision_signature="$("$GIT" hash-object "$PROVISION_FILE")"
last_provision_signature=""
if [ -r "$PROVISION_STATE_FILE" ]; then
  last_provision_signature="$(head -n 1 "$PROVISION_STATE_FILE")"
fi

if [ "$local_commit" = "$remote_commit" ] \
  && [ "$last_deployed_commit" = "$remote_commit" ] \
  && [ "$last_provision_signature" = "$provision_signature" ] \
  && [ "$FORCE_DEPLOY" != "1" ]; then
  if [ "$LOG_NOOP" = "1" ]; then
    rotate_log
    log "No changes: HEAD=$local_commit origin/$GIT_BRANCH=$remote_commit"
  fi
  exit 0
fi

rotate_log

if [ "$local_commit" != "$remote_commit" ]; then
  log "Changes detected: $local_commit -> $remote_commit"

  if [ -n "$("$GIT" status --porcelain --untracked-files=no)" ]; then
    fail "tracked files contain local changes; refusing to overwrite them"
  fi

  if ! "$GIT" merge-base --is-ancestor "$local_commit" "$remote_commit"; then
    fail "local branch cannot be fast-forwarded to origin/$GIT_BRANCH"
  fi

  run_logged "Fast-forward repository to origin/$GIT_BRANCH" \
    "$GIT" merge --ff-only "$REMOTE_REF" || exit 1
elif [ "$FORCE_DEPLOY" = "1" ]; then
  log "Forced deployment of HEAD=$local_commit"
else
  log "HEAD=$local_commit has pending deployment or WordPress provisioning; retrying"
fi

run_logged "Check Docker daemon" \
  "$DOCKER" info || exit 1

run_logged "Validate Docker Compose configuration" \
  compose config --quiet || exit 1

run_logged "Pull third-party Docker images" \
  compose pull --ignore-buildable || exit 1

run_logged "Build LoopBuy API and ML images" \
  compose build --pull api ml || exit 1

run_logged "Start Docker Compose services" \
  compose up -d --remove-orphans --wait --wait-timeout "$DEPLOY_TIMEOUT_SECONDS" || exit 1

provision_signature="$("$GIT" hash-object "$PROVISION_FILE")"
run_logged "Provision WordPress pages and settings" \
  provision_wordpress || exit 1

run_logged "Record Docker Compose service status" \
  compose ps || exit 1

printf '%s\n' "$remote_commit" >"$STATE_FILE.tmp"
mv -f "$STATE_FILE.tmp" "$STATE_FILE"
printf '%s\n' "$provision_signature" >"$PROVISION_STATE_FILE.tmp"
mv -f "$PROVISION_STATE_FILE.tmp" "$PROVISION_STATE_FILE"

log "Deploy completed successfully: HEAD=$remote_commit"
