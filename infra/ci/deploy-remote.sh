#!/usr/bin/env bash
# Runs in GitHub Actions (deploy-staging): uploads the stack files of one commit to the server, logs the server in to
# GHCR with the workflow's read-only token, runs deploy.sh there and checks the site from outside.
#   SHA DOMAIN SSH_HOST SSH_USER APP_DIR SSH_KEY KNOWN_HOSTS GHCR_USER GHCR_TOKEN
#   optional: MAILBOX_DSN (IMAP), MAILER_DSN (SMTP, e.g. Brevo), MAIL_ALLOWED_RECIPIENTS (controlled test addresses),
#   OPENAI_API_KEY, OPENAI_MODEL, SENTRY_DSN (error reporting, TASK-015)
set -euo pipefail

fail() { echo "::error::$*"; exit 1; }
[[ "${SHA:-}" =~ ^[0-9a-f]{40}$ ]] || fail "invalid commit SHA"
[[ -n "${DOMAIN:-}" && -n "${SSH_HOST:-}" && -n "${SSH_KEY:-}" && -n "${KNOWN_HOSTS:-}" ]] \
  || fail "domain, SSH host, key or known hosts missing (docs/runbook.md, Staging)"
[[ "$DOMAIN" =~ ^[a-z0-9.-]+$ ]] || fail "invalid domain"
[[ "${SSH_USER:-}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || fail "invalid SSH user"
[[ "${APP_DIR:-}" =~ ^/srv/[A-Za-z0-9._-]+$ ]] || fail "the app directory must be a directory directly in /srv"
[[ "${GHCR_USER:-}" =~ ^[A-Za-z0-9-]+$ ]] || fail "invalid GHCR user"
if [[ -n "${MAILBOX_DSN:-}" ]]; then
  [[ "$MAILBOX_DSN" =~ ^imaps?://[^[:space:]\']+@[A-Za-z0-9.-]+(:[0-9]{2,5})?(/[^[:space:]\']*)?$ ]] \
    || fail "MAILBOX_DSN must look like imaps://user:password@host:993/INBOX (URL-encoded, no quotes)"
fi
if [[ -n "${MAILER_DSN:-}" ]]; then
  # Diagnostics name the failing rule, the scheme and the host only: never the login or the key.
  scheme="${MAILER_DSN%%://*}"
  host="${MAILER_DSN##*@}"
  [[ "$MAILER_DSN" == *"://"* && "$scheme" =~ ^smtps?$ ]] || fail "MAILER_DSN: scheme must be smtp:// or smtps:// (got '${scheme:0:20}')"
  [[ "$MAILER_DSN" != *[[:space:]]* ]] || fail "MAILER_DSN contains whitespace or a line break"
  [[ "$MAILER_DSN" != *\'* && "$MAILER_DSN" != *\"* ]] || fail "MAILER_DSN contains a quote"
  [[ "$MAILER_DSN" == *@* ]] || fail "MAILER_DSN has no user:password@ part"
  [[ "$host" =~ ^[A-Za-z0-9.-]+(:[0-9]{2,5})?(\?.*)?$ ]] || fail "MAILER_DSN: host part must be host:port (got '${host:0:60}')"
fi
[[ -z "${OPENAI_API_KEY:-}" || "$OPENAI_API_KEY" =~ ^sk-[A-Za-z0-9_-]{20,}$ ]] || fail "OPENAI_API_KEY must look like sk-… (no spaces or quotes)"
[[ "${OPENAI_MODEL:-}" =~ ^[A-Za-z0-9._:-]*$ ]] || fail "OPENAI_MODEL: letters, digits, . _ : - only"
[[ "${MAIL_INGEST_SINCE:-}" =~ ^([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]{8}(Z|[+-][0-9]{2}:[0-9]{2}))?$ ]] || fail "MAIL_INGEST_SINCE must be ISO 8601, e.g. 2026-11-22T18:00:00Z"
[[ -z "${SENTRY_DSN:-}" || "$SENTRY_DSN" =~ ^https://[A-Za-z0-9]+@[A-Za-z0-9.-]+/[0-9]+$ ]] || fail "SENTRY_DSN must look like https://key@oNNN.ingest.sentry.io/NNN"
[[ "${MAIL_ALLOWED_RECIPIENTS:-}" =~ ^[A-Za-z0-9.@_+,\ -]*$ ]] || fail "MAIL_ALLOWED_RECIPIENTS: addresses or @domains separated by commas"

install -d -m 700 ~/.ssh
printf '%s\n' "$SSH_KEY" > ~/.ssh/id_ed25519
printf '%s\n' "$KNOWN_HOSTS" > ~/.ssh/known_hosts
chmod 600 ~/.ssh/id_ed25519 ~/.ssh/known_hosts
printf 'Host server\n  HostName %s\n  User %s\n  IdentityFile ~/.ssh/id_ed25519\n  StrictHostKeyChecking yes\n' \
  "$SSH_HOST" "$SSH_USER" > ~/.ssh/config

# SHA and APP_DIR are validated above, so expanding them on the client side is safe.
# shellcheck disable=SC2029
{
  echo "::group::Upload stack files of $SHA"
  ssh server "mkdir -p '$APP_DIR/releases/$SHA/postgres-init'"
  scp -q infra/server/compose.yml infra/server/Caddyfile infra/server/deploy.sh "server:$APP_DIR/releases/$SHA/"
  scp -q infra/postgres/init/01-roles-and-databases.sh "server:$APP_DIR/releases/$SHA/postgres-init/"
  echo "::endgroup::"

  # Mail and LLM settings and secrets: a file of their own on the server (mode 600), rewritten at every deployment.
  {
    if [[ -n "${MAILBOX_DSN:-}" ]]; then printf "MAILBOX_DSN='%s'\n" "$MAILBOX_DSN"; fi
    if [[ -n "${MAILER_DSN:-}" ]]; then printf "MAILER_DSN='%s'\n" "$MAILER_DSN"; fi
    printf "MAIL_ALLOWED_RECIPIENTS='%s'\n" "${MAIL_ALLOWED_RECIPIENTS:-}"
    printf "MAIL_INGEST_SINCE='%s'\n" "${MAIL_INGEST_SINCE:-}"
    if [[ -n "${OPENAI_API_KEY:-}" ]]; then printf "OPENAI_API_KEY='%s'\n" "$OPENAI_API_KEY"; fi
    if [[ -n "${OPENAI_MODEL:-}" ]]; then printf "OPENAI_MODEL='%s'\n" "$OPENAI_MODEL"; fi
    if [[ -n "${SENTRY_DSN:-}" ]]; then printf "SENTRY_DSN='%s'\n" "$SENTRY_DSN"; fi
  } | ssh server "umask 077 && cat > '$APP_DIR/.env.mail'"

  printf '%s' "$GHCR_TOKEN" | ssh server "docker login ghcr.io -u '$GHCR_USER' --password-stdin" >/dev/null
  trap 'ssh server "docker logout ghcr.io" >/dev/null 2>&1 || true' EXIT

  echo "::group::deploy.sh $SHA"
  status=0
  ssh server "APP_DIR='$APP_DIR' bash '$APP_DIR/releases/$SHA/deploy.sh' $SHA" || status=$?
  echo "::endgroup::"
}
[[ "$status" == 0 ]] || fail "deployment failed (deploy.sh exit $status); see the log above"

echo "::group::External smoke tests"
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "https://$DOMAIN/api/ready") || true
[[ "$code" == 200 ]] || fail "/api/ready returned $code"
# Private staging: everything else needs the environment login (the server's deploy.sh tests behind it).
for path in / /admin/logowanie /api/v1/admin/tickets; do
  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "https://$DOMAIN$path") || true
  [[ "$code" == 401 ]] || fail "$path without the staging login returned $code (expected 401)"
done
headers=$(curl -sS -D - -o /dev/null --max-time 15 "http://$DOMAIN/") || true
grep -qi '^location: https://' <<<"$headers" || fail "HTTP does not redirect to HTTPS"
# Both an application response and the environment login's 401 (Caddy's error path) carry the security headers.
for path in /api/ready /; do
  secure=$(curl -sS -D - -o /dev/null --max-time 15 "https://$DOMAIN$path") || true
  for h in strict-transport-security content-security-policy x-content-type-options referrer-policy; do
    grep -qi "^$h:" <<<"$secure" || fail "missing header $h on $path"
  done
done
echo "::endgroup::"
echo "staging serves $SHA at https://$DOMAIN"
