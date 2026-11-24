# Runbook

## Staging (owner's shared VPS)

Staging runs at `https://tabularius.gitpush.app` on the owner's VPS, which hosts other projects too. The host's nginx (certbot) terminates TLS and forwards the domain to the stack's own Caddy (`edge`) on `127.0.0.1:8091`. Nothing server-wide changes: no firewall, SSH or package changes.

- Stack: `infra/server/compose.yml`, project `tabularius-staging` (never the local stack's name), with log rotation and memory limits per service: PostgreSQL, api, worker, purge (daily retention), business (synthetic demo system), client and edge. Images are the ones CI publishes to GHCR for a commit on `main` (`ghcr.io/michalwrzesinski/tabularius-*:<sha>`).
- Deployment: `.github/workflows/deploy-staging.yml` runs after CI succeeds on `main`, or manually. It uploads `compose.yml`, `Caddyfile`, `deploy.sh` and the database init script to `/srv/Tabularius/releases/<sha>/`, then logs the server in to GHCR with the workflow's read-only token (logged out afterwards) and runs `deploy.sh`. `deploy.sh` does the rest:
  1. pulls the images;
  2. starts the database and dumps it (`backups/`, last 3 kept);
  3. migrates both databases;
  4. starts everything and waits for health;
  5. on the first deployment only, seeds the synthetic business data, the shared demo accounts and three sample tickets;
  6. smoke-tests through the local port and switches `current`.

  If the start or the smoke tests fail, the previous release is started again. Migrations are additive and are not reverted.
- **Public since 2026-11-24 (owner's decision):** the repository variable `STAGING_PUBLIC=true` turns the environment login off, so the dashboard is open to everyone. The application's own login still guards every API call and file.
  - **Closing it again:** set the variable to anything else (or delete it) and deploy again. The edge proxy then asks for one shared login (Basic Auth) for the whole environment again; only `/api/health` and `/api/ready` stay open.
  - **The shared login:** the first deployment generated it. The password is in `/srv/Tabularius/.staging-auth` (mode 600) and its bcrypt hash in `.env`. Read it with `ssh -t webhome-vps 'sudo cat /srv/Tabularius/.staging-auth'`. To change it, delete the `STAGING_AUTH_*` lines from `.env` and deploy again.
  - **Unchanged by this switch:** pages still carry `noindex`. Demo replies still reach only the controlled test addresses (`MAIL_PUBLIC_REPLIES=0`).
- Checks: `deploy.sh` tests `/api/ready`, `/` and `/admin/logowanie` (→ 200) and the refusals: 401 from the admin API without a session, and, while private, 401 without the environment login. CI checks HTTPS, headers and the same answers from outside. `.deployed-history` records commit, time and image digests.
- Incoming mail (TASK-006): set the GitHub secret `STAGING_MAILBOX_DSN` (`imaps://user:password@host:993/INBOX`, URL-encoded, e.g. `%40` for `@` in the user name). The next deployment writes it to `/srv/Tabularius/.env.mail` (mode 600), and the worker polls every 60 s, read-only. The mailbox is never changed: progress is the last ingested UID. The variable `STAGING_MAIL_INGEST_SINCE` (ISO 8601, set to the moment the mailbox was connected: 2026-11-22) makes mail that was already in the mailbox be recorded without contents and never become a ticket.
- LLM (TASK-011): set the secret `STAGING_OPENAI_API_KEY` (and optionally the variable `STAGING_OPENAI_MODEL`). Without it staging interprets with the rule-based baseline. The daily spend guard (`OPENAI_DAILY_BUDGET_USD`, default 2) stops calls and sends tickets to manual handling (`ai_limit`). Usage and cost per call are in the `ai_usage` table.
- Outgoing mail (TASK-009): set the secret `STAGING_MAILER_DSN` (e.g. `smtp://LOGIN:KEY@smtp-relay.brevo.com:2525`; on the Profilo host ports 25, 465 and 587 were blocked and 2525 worked) and the variable `STAGING_MAIL_ALLOWED_RECIPIENTS` (controlled test addresses, comma-separated). Until the public demo is approved, every other recipient is captured, i.e. dropped. Without the secret all mail goes to `null://`. Demo mode sends account mail to the capture transport, which is `null://` on staging. Brevo is connected with mail ingestion (increment 3), after the owner agrees that step.
- Secrets: database passwords, app secrets and the business API token are generated on the server by `setup-shared-host.sh` into `/srv/Tabularius/.env` (mode 600). They never leave the server and are not in GitHub. GitHub holds only the deploy key and the host's key fingerprint.

### First-time setup (owner, once)

The deploy user created here can run Docker. **The docker group is root-equivalent on the whole server, including the other projects.** Its key lives only in the GitHub secret and is limited with `restrict` (no tunnels, no terminal).

Commands use `SERVER` for the server's address (151.240.121.172) and the SSH alias `webhome-vps` for your sudo account (`michal`, key `~/.ssh/webhome_vps`). Done once on 2026-11-22.

1. **Deploy key** (on your computer, in the repository folder):
   ```bash
   ssh-keygen -t ed25519 -N "" -C "github-actions-tabularius-staging" -f tabularius-staging-deploy
   ```
2. **Move the temporary page away.** Nothing is deleted; the setup refuses a directory with other files. Find the nginx site that serves the domain, and check its certificate and the certbot files:
   ```bash
   ssh -t webhome-vps 'sudo mv /srv/Tabularius /srv/Tabularius-placeholder; grep -l "tabularius.gitpush.app" /etc/nginx/sites-enabled/*; ls /etc/letsencrypt/live/tabularius.gitpush.app/ /etc/letsencrypt/options-ssl-nginx.conf /etc/letsencrypt/ssl-dhparams.pem'
   ```
   - From now until the first deployment (step 6) the domain shows an nginx error instead of the temporary page.
   - If certbot uses `webroot`, put its path in the `acme-challenge` block of `infra/server/nginx-tabularius.conf`.
   - If one of the two `/etc/letsencrypt/…` files is missing, remove its line from that file.
3. **Prepare the server.** This creates the user `tabularius_deploy`, `/srv/Tabularius` and its `.env`, and checks that port 8091 and the network 172.29.0.0/24 are free:
   ```bash
   scp infra/server/setup-shared-host.sh infra/server/nginx-tabularius.conf webhome-vps:/tmp/
   ssh -t webhome-vps "sudo bash /tmp/setup-shared-host.sh tabularius.gitpush.app \"$(cat tabularius-staging-deploy.pub)\""
   ```
   - If the port or the network is taken, pass others as the 5th and 6th arguments, then set the same port in the nginx file.
   - An `AllowUsers` line in `/etc/ssh/sshd_config` may need the new user. Check the login:
     ```bash
     ssh -i tabularius-staging-deploy tabularius_deploy@SERVER 'docker ps --format "{{.Names}}" | head -3'
     ```
4. **GitHub secrets and variables** (from the repository folder, with `gh` logged in):
   ```bash
   gh secret set STAGING_SSH_HOST --body "SERVER"
   gh secret set STAGING_SSH_KEY < tabularius-staging-deploy
   ssh-keyscan -t ed25519 SERVER | gh secret set STAGING_SSH_KNOWN_HOSTS
   gh variable set STAGING_DOMAIN --body "tabularius.gitpush.app"
   ```
   Compare the scanned fingerprint with the server's (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`), then delete `tabularius-staging-deploy*` from your computer.
5. **nginx.** This saves the current site file and switches to the new one. If the configuration test fails, the old file is restored automatically, so a broken config never stays. Replace `SITE` with the file found in step 2:
   ```bash
   ssh -t webhome-vps 'site=$(readlink -f /etc/nginx/sites-enabled/SITE) && sudo cp -n "$site" /root/tabularius.nginx.before && sudo cp /tmp/nginx-tabularius.conf "$site" && { sudo nginx -t && sudo systemctl reload nginx; } || { sudo cp /root/tabularius.nginx.before "$site" && sudo nginx -t && echo "nginx config restored"; }'
   ```
   The domain answers 502 until the first deployment.
6. **First deployment:**
   ```bash
   gh workflow run deploy-staging -f sha=$(git rev-parse origin/main)
   ```
   Later deployments run automatically after CI on `main`.

### Operations

- **Logs:**
  ```bash
  ssh tabularius_deploy@SERVER 'cd /srv/Tabularius/current && docker compose --env-file ../../.env logs --tail 100 api worker'
  ```
  (`restrict` keys allow commands, not a terminal.)
- **Earlier release:** run the workflow with `-f sha=<older commit on main>`. Its images are still in GHCR.
- **Reseed the demo data:** delete `/srv/Tabularius/.seeded` and deploy again. This replaces all business data and does not remove tickets.
- **Recovery:** dumps before each deployment are in `/srv/Tabularius/backups/` (`pg_dumpall`, gzip).
- **Undo the nginx change:** copy `/root/tabularius.nginx.before` over the site file, then `sudo nginx -t` and reload.

### Known gaps

- No backup at another provider: accepted by the owner on 2026-11-24 (see Backups).

## Monitoring and alerts (TASK-015)

Decided by the owner on 2026-11-24: the same arrangement as in Profilo. Alerts go by e-mail to the owner (kontakt@webhome.pl); handling is manual by the owner, so an alert means nobody has looked at it yet.

| What | Watches | Alert |
|---|---|---|
| Better Stack uptime monitor | `https://tabularius.gitpush.app/api/ready` (open without the environment login; database check, no data) every 3 min | the service is down |
| Sentry project `tabularius` | API errors (error-level logs and uncaught exceptions) and dashboard errors through the API's tunnel `/api/monitoring/envelope` | more than 20 events in 5 minutes; a new issue |
| GitHub e-mail notifications | failed `ci` and `Deploy staging` runs | failed deployment |

What reaches Sentry is scrubbed in the API ([Scrubber](../api/src/Module/Monitoring/Scrubber.php)), for API and browser events alike:
- **Never sent:** the user, cookies and request bodies.
- **Removed:** values under sensitive keys (passwords, tokens, sessions, addresses, mail subjects and texts), and e-mail addresses, bearer/JWT tokens, long secrets and SQL parameters inside texts.
- **Not collected:** the dashboard collects no breadcrumbs, sessions, tracing or replay.

The release is the deployed commit and the environment is `staging`. Without `SENTRY_DSN` both sides report nothing.

### Setup (owner, once)

1. **Sentry:**
   - Create the project `tabularius` (platform PHP; the browser events use the same project).
   - Set *Data Scrubbing* on and add an alert of the type "Number of events" above 20 in 5 minutes.
   - Optionally connect the GitHub repository (links releases to commits).
2. **GitHub secrets and variables:**
   - Secrets:
     - `STAGING_SENTRY_DSN`: the project's DSN;
     - `STAGING_SENTRY_AUTH_TOKEN`: an organisation token with release permissions, used only by the `Sentry release` job and never sent to the server.
   - Variables: `SENTRY_ORG` and `SENTRY_PROJECT` (slugs).

   The next deployment writes the DSN to `/srv/Tabularius/.env.mail`. The `Sentry release` job then creates the release, uploads the dashboard's source maps from the client image (`/opt/sourcemaps`, never served) and records the deployment.
3. **Better Stack:** create a monitor for `https://tabularius.gitpush.app/api/ready` (expected 200) with e-mail alerts to kontakt@webhome.pl, and send a test alert.
4. **GitHub:** notification settings → Actions → e-mail for failed workflows only.
5. **Check:**
   - a test error from the API: `ssh -t webhome-vps 'sudo docker exec tabularius-staging-api-1 php bin/console app:monitoring:test'`;
   - a test error from the dashboard (browser console: `setTimeout(() => { throw new Error('test') })`);
   - both appear in Sentry without addresses or texts.

### On an alert

1. **Service down:** check the last `Deploy staging` run and `/api/ready`. Read the logs (Operations above) for `api`, `worker` and `db`. If the latest release is at fault, deploy the previous commit (`gh workflow run deploy-staging -f sha=<previous>`).
2. **Error spike:** open the Sentry issue and match the release to the commit. If tickets are affected, they are in „Wymaga interwencji” with a reason (TASK-014). The administrator retries or rejects them after the fix.
3. **Failed deployment:** the previous release keeps running (`deploy.sh` restarts it); read the run's log.
4. **Possible personal data in a report, or access to data by someone unauthorised:** treat it as a breach (privacy standard: the owner assesses notification within 72 hours).

## Backups

Decided by the owner on 2026-11-24, the same arrangement as in Profilo:
- the VPS provider backs up the whole server daily;
- `deploy.sh` dumps both databases before each deployment (`/srv/Tabularius/backups/`, last 3).

There is no encrypted copy at another provider and no scheduled restoration test. The owner accepted this risk. The business data is synthetic and re-seedable, and tickets, addresses and texts are kept only 7 days.

**Restore from a dump** (owner, sudo):
1. Stop `worker` and `purge`, so that no mail is answered and no job runs.
2. Load the dump into a fresh database with `psql`.
3. Re-run `app:mail:purge` and `app:files:purge` before starting the workers again, so that deletions made after the dump are applied again.
