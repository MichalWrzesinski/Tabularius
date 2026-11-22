# Runbook

## Staging (owner's shared VPS)

Staging runs at `https://tabularius.gitpush.app` on the owner's VPS, which hosts other projects too. The host's nginx (certbot) terminates TLS and forwards the domain to the stack's own Caddy (`edge`) on `127.0.0.1:8091`. Nothing server-wide changes: no firewall, SSH or package changes.

- Stack: `infra/server/compose.yml`, project `tabularius`: PostgreSQL, api, worker, purge (daily retention), business (synthetic demo system), client and edge. Images are the ones CI publishes to GHCR for a commit on `main` (`ghcr.io/michalwrzesinski/tabularius-*:<sha>`).
- Deployment: `.github/workflows/deploy-staging.yml` runs after CI succeeds on `main`, or manually. It uploads `compose.yml`, `Caddyfile`, `deploy.sh` and the database init script to `/srv/Tabularius/releases/<sha>/`, then logs the server in to GHCR with the workflow's read-only token (logged out afterwards) and runs `deploy.sh`. `deploy.sh` does the rest:
  1. pulls the images;
  2. starts the database and dumps it (`backups/`, last 3 kept);
  3. migrates both databases;
  4. starts everything and waits for health;
  5. on the first deployment only, seeds the synthetic business data, the shared demo accounts and three sample tickets;
  6. smoke-tests through the local port and switches `current`.

  If the start or the smoke tests fail, the previous release is started again. Migrations are additive and are not reverted.
- Mail: none yet. Demo mode sends account mail to the capture transport, which is `null://` on staging. Brevo is connected with mail ingestion (increment 3), after the owner agrees that step.
- Secrets: database passwords, app secrets and the business API token are generated on the server by `setup-shared-host.sh` into `/srv/Tabularius/.env` (mode 600). They never leave the server and are not in GitHub. GitHub holds only the deploy key and the host's key fingerprint.

### First-time setup (owner, once)

The deploy user created here can run Docker. **The docker group is root-equivalent on the whole server, including the other projects.** Its key lives only in the GitHub secret and is limited with `restrict` (no tunnels, no terminal).

Commands use `SERVER` for the server's address and `you` for your sudo account.

1. **Deploy key** (on your computer, in the repository folder):
   ```bash
   ssh-keygen -t ed25519 -N "" -C "github-actions-tabularius-staging" -f tabularius-staging-deploy
   ```
2. **Move the temporary page away.** Nothing is deleted; the setup refuses a directory with other files. Find the nginx site that serves the domain, and check its certificate and the certbot files:
   ```bash
   ssh -t you@SERVER 'sudo mv /srv/Tabularius /srv/Tabularius-placeholder; grep -l "tabularius.gitpush.app" /etc/nginx/sites-enabled/*; ls /etc/letsencrypt/live/tabularius.gitpush.app/ /etc/letsencrypt/options-ssl-nginx.conf /etc/letsencrypt/ssl-dhparams.pem'
   ```
   - From now until the first deployment (step 6) the domain shows an nginx error instead of the temporary page.
   - If certbot uses `webroot`, put its path in the `acme-challenge` block of `infra/server/nginx-tabularius.conf`.
   - If one of the two `/etc/letsencrypt/…` files is missing, remove its line from that file.
3. **Prepare the server.** This creates the user `tabularius_deploy`, `/srv/Tabularius` and its `.env`, and checks that port 8091 and the network 172.29.0.0/24 are free:
   ```bash
   scp infra/server/setup-shared-host.sh infra/server/nginx-tabularius.conf you@SERVER:/tmp/
   ssh -t you@SERVER "sudo bash /tmp/setup-shared-host.sh tabularius.gitpush.app \"$(cat tabularius-staging-deploy.pub)\""
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
   ssh -t you@SERVER 'site=$(readlink -f /etc/nginx/sites-enabled/SITE) && sudo cp -n "$site" /root/tabularius.nginx.before && sudo cp /tmp/nginx-tabularius.conf "$site" && { sudo nginx -t && sudo systemctl reload nginx; } || { sudo cp /root/tabularius.nginx.before "$site" && sudo nginx -t && echo "nginx config restored"; }'
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
