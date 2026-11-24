# Production artefact: static build served by Caddy as a non-root user.
FROM node:26.13.0-alpine@sha256:8996bb5821d62a8fd9bb27c1cb07462f3fdce6e47d640c6a881ead0b064ce216 AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
# Source maps (TASK-015): debug ids tie each script to its map (no Sentry token needed); the maps then leave the served
# directory, and the deploy workflow uploads them to Sentry from /opt/sourcemaps.
RUN npm run build \
  && npx sentry-cli sourcemaps inject dist/assets \
  && cd dist \
  && find . -name '*.map' -exec sh -c 'mkdir -p "/sourcemaps/$(dirname "$1")" && mv "$1" "/sourcemaps/$1"' _ {} \; \
  && mkdir -p /sourcemaps

FROM caddy:2.11.9-alpine@sha256:003a97808e781aa9f7e75cc2f43d34d461099031faf5e20faefb9f61126836f0 AS prod
COPY --from=build /app/dist /srv
COPY --from=build /sourcemaps /opt/sourcemaps
COPY Caddyfile.static /etc/caddy/Caddyfile
RUN adduser -D -H -u 10001 web && chown -R web /config /data
USER web
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=3s CMD wget -qO- http://127.0.0.1:8080/ >/dev/null || exit 1
