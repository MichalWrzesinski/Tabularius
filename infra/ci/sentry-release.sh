#!/usr/bin/env bash
# Runs in deploy-staging after a successful deployment (TASK-015): creates the Sentry release for the commit, uploads
# the dashboard's source maps from the published client image (they are in the image but never served) and records the
# deployment for the environment. Without a token it is skipped with a notice.
#
#   SHA ENV_NAME SENTRY_AUTH_TOKEN SENTRY_ORG SENTRY_PROJECT [SENTRY_URL] GHCR_USER GHCR_TOKEN
#   SENTRY_URL only for a user token or a self-hosted Sentry: an organisation token (sntrys_…) carries its own address.
set -euo pipefail

CLI_IMAGE=getsentry/sentry-cli:3.8.1@sha256:9b5edc78fc946050e833e745041a9e63568ab410b02f03245ca835d5724e71b2
fail() { echo "::error::$*"; exit 1; }

if [[ -z "${SENTRY_AUTH_TOKEN:-}" ]]; then
  echo "::notice::no Sentry token for $ENV_NAME: release and source maps not uploaded (docs/runbook.md, Monitoring)"
  exit 0
fi
[[ "${SHA:-}" =~ ^[0-9a-f]{40}$ ]] || fail "invalid commit SHA"
[[ "${SENTRY_ORG:-}" =~ ^[a-z0-9_-]+$ && "${SENTRY_PROJECT:-}" =~ ^[a-z0-9_-]+$ ]] || fail "SENTRY_ORG and SENTRY_PROJECT must be Sentry slugs"
[[ "${GHCR_USER:-}" =~ ^[A-Za-z0-9-]+$ ]] || fail "invalid GHCR user"
url_env=()
if [[ -n "${SENTRY_URL:-}" ]]; then
  [[ "$SENTRY_URL" =~ ^https://[a-z0-9.-]+/?$ ]] || fail "invalid SENTRY_URL"
  url_env=(-e SENTRY_URL)
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"; docker rm -f tabularius-sourcemaps >/dev/null 2>&1 || true' EXIT

echo "::group::Source maps from the client image"
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USER" --password-stdin >/dev/null
image="ghcr.io/michalwrzesinski/tabularius-client:$SHA"
docker pull --quiet "$image" >/dev/null
docker logout ghcr.io >/dev/null
docker create --name tabularius-sourcemaps "$image" >/dev/null
# Scripts (with debug ids) and their maps side by side, as sentry-cli expects them. Images built before TASK-015 have
# no maps: release only.
maps=true
docker cp tabularius-sourcemaps:/srv/assets "$work/assets"
if docker run --rm --entrypoint test "$image" -d /opt/sourcemaps; then
  docker cp tabularius-sourcemaps:/opt/sourcemaps/. "$work/"
else
  maps=false
  echo "::notice::the client image of $SHA has no source maps (built before TASK-015)"
fi
echo "$(find "$work" -name '*.map' | wc -l) source maps"
echo "::endgroup::"

cli() {
  docker run --rm -e SENTRY_AUTH_TOKEN -e SENTRY_ORG -e SENTRY_PROJECT "${url_env[@]}" -v "$work:/work" -w /work "$CLI_IMAGE" "$@"
}
echo "::group::Sentry release $SHA ($ENV_NAME)"
cli info  # server, organisation and token validity (the token itself is not printed)
cli releases new "$SHA"
cli releases set-commits "$SHA" --commit "MichalWrzesinski/Tabularius@$SHA" \
  || echo "::warning::commits not linked (Sentry GitHub integration missing?)"
[[ "$maps" == true ]] && cli sourcemaps upload --release "$SHA" assets
cli releases finalize "$SHA"
cli deploys new --release "$SHA" -e "$ENV_NAME"
echo "::endgroup::"
