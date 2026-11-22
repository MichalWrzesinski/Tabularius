#!/usr/bin/env bash
# Runs ON THE SERVER as the deploy user (called by infra/ci/deploy-remote.sh from GitHub Actions):
#
#   APP_DIR=/srv/Tabularius deploy.sh <commit sha>
#
# Release files are in $APP_DIR/releases/<sha>/ (compose.yml, Caddyfile, postgres-init/, deploy.sh). Steps: pull the
# images, start the database, dump it, migrate both databases, seed the synthetic demo data once, start everything,
# smoke-test through the local port. On failure after the switch, the previous release is started again (a database
# migration is not reverted: migrations are additive; the dump is kept in $APP_DIR/backups).
# Staging only: a short restart gap is accepted (no blue/green).
set -euo pipefail

export APP_DIR="${APP_DIR:-/srv/Tabularius}"
TAG="${1:?commit sha required}"
[[ "$TAG" =~ ^[0-9a-f]{40}$ ]] || { echo "invalid commit sha: $TAG" >&2; exit 2; }
DIR="$APP_DIR/releases/$TAG"
[[ -f "$DIR/compose.yml" && -f "$DIR/Caddyfile" && -d "$DIR/postgres-init" ]] || { echo "release files missing in $DIR" >&2; exit 2; }
KEEP_RELEASES=5
KEEP_DUMPS=3

log() { echo "[deploy $(date -u +%H:%M:%S)] $*"; }

exec 9>"$APP_DIR/.deploy.lock"
flock -n 9 || { echo "another deployment is running" >&2; exit 3; }

set -a
# shellcheck disable=SC1091
. "$APP_DIR/.env"
set +a

PREV=""
[[ -L "$APP_DIR/current" ]] && PREV="$(basename "$(readlink "$APP_DIR/current")")"

compose() {  # compose <tag> <args…>
  local tag="$1"; shift
  IMAGE_TAG="$tag" docker compose --env-file "$APP_DIR/.env" --project-directory "$APP_DIR/releases/$tag" \
    -f "$APP_DIR/releases/$tag/compose.yml" "$@"
}

smoke() {
  local deadline=$((SECONDS + 90)) path code
  for path in /api/ready /; do
    while true; do
      code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 -H "Host: $DOMAIN" -H "X-Forwarded-Proto: https" \
        "http://127.0.0.1:$PROXY_PORT$path" 2>/dev/null)" || true
      [[ "$code" == 200 ]] && break
      if (( SECONDS >= deadline )); then
        log "smoke test failed: $path returned $code"
        return 1
      fi
      sleep 3
    done
  done
  log "smoke tests passed (/api/ready, /)"
}

rollback() {
  if [[ -z "$PREV" || "$PREV" == "$TAG" ]]; then
    log "no previous release to start again; see docs/runbook.md (Staging, recovery)"
    return 1
  fi
  log "starting the previous release $PREV again"
  compose "$PREV" up -d --remove-orphans --wait --wait-timeout 180 && smoke && log "previous release $PREV serves again"
}

if [[ "${SKIP_PULL:-0}" != 1 ]]; then  # SKIP_PULL=1 only for a local rehearsal with locally built images
  log "pulling images for $TAG"
  compose "$TAG" pull --quiet
fi

log "database"
compose "$TAG" up -d --wait --wait-timeout 120 db

if [[ -n "$PREV" ]]; then
  install -d -m 700 "$APP_DIR/backups"
  file="$APP_DIR/backups/before-$TAG-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
  log "dumping the databases to $file"
  (umask 077 && compose "$TAG" exec -T db pg_dumpall -U postgres | gzip > "$file.part") && mv "$file.part" "$file"
  find "$APP_DIR/backups" -maxdepth 1 -name 'before-*.sql.gz' -printf '%T@ %p\n' | sort -rn \
    | awk -v keep="$KEEP_DUMPS" 'NR > keep {print $2}' | xargs -r rm -f
fi

log "migrations"
# shellcheck disable=SC2016 # expanded inside the container
compose "$TAG" run --rm --no-deps api sh -c 'DATABASE_URL="$MIGRATIONS_DATABASE_URL" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
# shellcheck disable=SC2016
compose "$TAG" run --rm --no-deps business sh -c 'DATABASE_URL="$MIGRATIONS_DATABASE_URL" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'

log "starting $TAG"
if ! compose "$TAG" up -d --remove-orphans --wait --wait-timeout 180; then
  log "services did not become healthy"
  rollback || true
  exit 1
fi

# Synthetic demo data and the shared demo accounts, once per environment (docs/runbook.md: reseeding).
if [[ ! -f "$APP_DIR/.seeded" ]]; then
  log "seeding synthetic business data, shared demo accounts and sample tickets"
  compose "$TAG" exec -T business bin/console app:seed --no-interaction
  compose "$TAG" exec -T api bin/console app:accounts:demo --no-interaction
  compose "$TAG" exec -T api bin/console app:tickets:demo --no-interaction
  date -u +%Y-%m-%dT%H:%M:%SZ > "$APP_DIR/.seeded"
fi

if ! smoke; then
  rollback || true
  exit 1
fi

ln -sfn "$DIR" "$APP_DIR/current"
echo "$TAG" >> "$APP_DIR/.deployed-history"
log "release $TAG is live"

# Keep the newest releases (by deployment time); never the live one.
find "$APP_DIR/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -rn \
  | awk -v keep="$KEEP_RELEASES" 'NR > keep {print $2}' | while read -r old; do
    [[ "$old" == "$DIR" ]] || rm -rf "$old"
  done
