# Public demo readiness

Environment: https://tabularius.gitpush.app (the former staging; the owner decided on 2026-11-24 that it is the public demo). As of 2026-11-24.

This is the [first-release checklist](../standards/development/deployment-setup.md#checklist-before-the-first-release) for the public demo, which runs on synthetic business data. A production release for a real organisation is a separate stage and is not covered here.

## Owner decisions and accepted risks

| Date | Decision | Accepted risk |
|---|---|---|
| 2026-11-21 | Retention: messages, subjects, addresses 7 days; pseudonymous audit 90 days; report files 7 days | — |
| 2026-11-24 | The public demo runs on the current staging; there is no separate test environment | Changes reach the public demo after local tests and CI only |
| 2026-11-24 | Backups as in Profilo: the VPS provider's daily server backup plus a dump before each deployment | No copy at another provider, no scheduled restoration test |
| 2026-11-24 | Monitoring as in Profilo: Better Stack, Sentry, GitHub e-mails | Not active until the owner sets the accounts up |
| 2026-11-24 | Environment login off (`STAGING_PUBLIC=true`) and demo replies to every sender (`STAGING_MAIL_PUBLIC_REPLIES=1`), before the legal pages were final | Public use with draft legal pages and without monitoring |

## Checklist

| Item | State | Evidence or what remains |
|---|---|---|
| Domain, DNS, TLS with automatic renewal, HTTPS with HSTS, HTTP → HTTPS | Done | The host's nginx with certbot; the deploy workflow checks the redirect and HSTS from outside on every deployment |
| Outgoing mail with SPF, DKIM and DMARC; a test message arrives outside spam | Partly | Brevo sends and threads (T-1008 thread on 2026-11-23). **Owner:** check SPF, DKIM and DMARC for gitpush.app in Brevo and the DNS, and confirm that mail lands outside spam at Gmail and Outlook |
| Secrets through the secrets mechanism; debug off; seed and test accounts unavailable | Done (for a demo) | GitHub secrets and the server's `.env` (mode 600); `APP_ENV=prod`. The shared demo accounts are public by design (brief §4.1) and cannot be changed or blocked (ID-DEMO-5) |
| Backup created and test-restored, deletions re-applied | Accepted risk | See the decisions above; the restore steps are in the [runbook](../runbook.md#backups) |
| Monitoring and alerts confirmed by a test alert | Waiting for the owner | Code and CI ready (TASK-015). **Owner:** runbook „Monitoring and alerts”, setup steps 1–5 |
| Error pages, `robots.txt`, `sitemap.xml`; `noindex` only in test environments | Done | The owner decided on indexing on 2026-11-24 (`STAGING_INDEX=true`: `X-Robots-Tag: all`). `robots.txt` excludes the dashboards and the API; `sitemap.xml` lists the public pages. Unknown paths show the app's „Nie znaleziono strony” page |
| Request limits and bot protection on public forms | Done | Mail: 5 per hour and 20 per day per sender, 200 per day in total. Login: 5 per 15 minutes; password reset: 3 per hour per address and 10 per IP; error reports: 30 per minute per IP. There are no public sign-up forms |
| Legal documents ready and reviewed by a human | Drafts | `/regulamin` and `/polityka-prywatnosci` are live, linked in every footer and in the demo notice (TASK-016). **Owner:** fill in the placeholders and review the text (see below) |
| Runbook with responsibility and the alert route | Done | [Runbook](../runbook.md): deployment, operations, monitoring, alert response (owner, kontakt@webhome.pl), backups |
| Repository cleaned up (artifact lifecycle) | Done for leftovers; proposals open | Removed a compiled Python file and the obsolete prototype script (2026-11-24). **Owner:** approve or reject removing the brief, the mockups and the `DONE` task files ([final summary](../case-study/final-summary.md#repository-clean-up)) |

## Placeholders to fill in (owner)

Both legal pages mark these as `[do uzupełnienia: …]`:
- **Service provider:** name, address, tax number (NIP).
- **Contact addresses:** one for personal data matters, and one for contact and complaints (can be the same).
- **Hosting:** the provider of the server and the mailbox, and how long its backups are kept.
- **Transfers to the USA:** the basis for sending data to OpenAI and Sentry (e.g. EU-U.S. Data Privacy Framework or standard contractual clauses).
- **Terms:** the provider's liability towards consumers, and optionally the governing law and court.

The agent can replace them once the owner gives the values. Legal review remains the owner's.

## Known facts the documents rely on (checked in code on 2026-11-24)

- **Message contents (7 days):** sender, subject, header block and text of incoming mail, and the recipient and bodies of notifications, are purged after 7 days (`app:mail:purge`, daily). The pseudonymous sender hash (HMAC) and the ticket metadata stay.
- **Report files (7 days):** purged by `app:files:purge`, daily.
- **Audit log (90 days):** purged by `app:audit:purge`, daily (TASK-016).
- **Container logs:** rotated by size (3 × 10 MB per service), not by time. They contain no message bodies.
- **OpenAI:** requests are sent with `store: false` and carry the request's subject and full text (a signature included), never the sender's address from the header.
- **Cookies:** one session cookie, `tabularius_session` (HttpOnly, SameSite=Lax, session lifetime). No analytics.
