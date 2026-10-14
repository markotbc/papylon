# Runbook: Git → CI/CD → Staging (→ Production → Launch)

## 1. Git repository

Remote: `git@github.com:markotbc/papylon.git`. Branches: `main` (production-ready) and `dev` (staging). Both are permanent.

GitHub → Settings:

- **Branches**: protect `main` (require PR, require status check "Lint & build", no force-push). Protect `dev` lightly (require the check).
- **Environments**: `staging` (exists). Later, `production` with _Required reviewers_ (you), so every prod deploy needs one click.
- Default branch stays `main`. Day-to-day work goes through `dev`: feature branch → PR into `dev` → PR `dev` into `main`.

### Production is not set up yet

A push to `dev` runs lint & build, then deploys to staging. A push to `main` only runs lint & build. It deploys only after you create the `production` environment, add its secrets and `SITE_URL`, and set the repository variable `PRODUCTION_ENABLED` = `true` (Settings → Secrets and variables → Actions → Variables).

### Secrets / variables (per environment)

| Name              | Type     | Staging value / how to get it                               |
| ----------------- | -------- | ----------------------------------------------------------- |
| `SSH_HOST`        | secret   | `<ssh-host>`                                                |
| `SSH_USER`        | secret   | `<ssh-user>`                                                |
| `SSH_PORT`        | secret   | `222` (the workflow defaults to 222 if unset)               |
| `SSH_PRIVATE_KEY` | secret   | contents of the deploy key (private)                        |
| `SSH_KNOWN_HOSTS` | secret   | output of `ssh-keyscan -p 222 <ssh-host>`                   |
| `WP_PATH`         | secret   | full server path, e.g. `<home>/public_html/papylon-staging` |
| `SITE_URL`        | variable | `<staging-url>`                                             |

**Hetzner:** SSH for commands runs on port **222**. Port 22 is SFTP-only, so rsync/ssh must use 222.

Create the key once: `ssh-keygen -t ed25519 -f deploy_key -C "github-deploy"`. Add `deploy_key.pub` to `~/.ssh/authorized_keys` on the server (or via the Hetzner panel), paste the private key into the secret, then delete the local copy.

## 2. Theme structure & assets

Theme path: `wp-content/themes/woodmart-child`. Same layout as the weareai theme: `assets/src/scss` (base / layout / components / pages / utils, entry `main.scss`) and `assets/src/js` are compiled by Gulp into `assets/dest/css` and `assets/dest/js`.

Both `dest/css` and `dest/js` are generated (in CI by `npm run build:production`) and git-ignored. Only `.gitkeep` is tracked. Never edit or commit them.

The original child `style.css` header and `woodmart_child_enqueue_styles()` are kept. `functions.php` enqueues `main.css`, `main.js` and `shop.js` only if they exist. `papylon_asset()` adds a `?time=` cache-buster whenever `WP_ENVIRONMENT_TYPE` is not `production`. Every `inc/*.php` file is auto-loaded.

Add new JS files in `assets/src/js` and add their handle to the `foreach` array in `functions.php`.

## 3. Deploy (what CI does)

1. Build: PHP syntax check, `npm ci && npm run build:production` in the theme. Pull requests stop here.
2. On push: `rsync -az --delete` over SSH (port 222) of the theme to `$WP_PATH/wp-content/themes/woodmart-child/` and of the plugin to `$WP_PATH/wp-content/plugins/papylon-pangraf-sync/`. Other plugins are not touched.
3. `wp cache flush && wp rewrite flush` via WP-CLI (`~/bin/wp`). Skipped if WP-CLI is missing.
4. Health check: `SITE_URL` must return HTTP 200.

Because of `--delete`, never edit theme or plugin files directly on the server. They are overwritten on the next deploy.

## 4. Server setup

1. Hosting: Hetzner shared hosting. Staging lives in `public_html/papylon-staging`.
2. Install WordPress + the **Woodmart parent theme** (premium, licence from ThemeForest) + WooCommerce and other third-party plugins manually, once per environment. These are **not** in Git. Plugin settings live in the database.
3. WP-CLI is installed at `~/bin/wp`.
4. `wp-config.php` (per environment, never in Git):

```php
define('WP_ENVIRONMENT_TYPE', 'staging');      // 'local' / 'staging' / 'production' – used by papylon_asset()
define('WP_DEBUG', false);
define('DISALLOW_FILE_EDIT', true);
define('WP_AUTO_UPDATE_CORE', 'minor');
```

5. Staging: protect with HTTP basic auth, and turn ON `Settings → Reading → Discourage search engines`.
6. Backups: daily DB + `uploads` backup with an off-server copy. Test a restore once. Back up before any WooCommerce / Woodmart update (they can change the database).
7. First deploy: activate the theme "Woodmart Child" and the plugin "Papylon Pangraf Sync" in wp-admin.
8. Configure **WooCommerce → Pangraf sync**: API URL, token, supplier IDs (`id_dobavljac`), `id_m` values, image URL pattern, schedule. Use **Test connection**, then **Run sync now** once before enabling the schedule. The token can instead be set in `wp-config.php` as `define('PAPYLON_PANGRAF_TOKEN', '...');`.

---

> **Future reference:** sections 5–7 apply only once production exists. Production is **not** set up yet.

## 5. DNS (future, production)

| Record  | Name  | Value               |
| ------- | ----- | ------------------- |
| A       | `@`   | production IP       |
| CNAME/A | `www` | `@` / production IP |

- Lower TTL to **300 s at least 24 h before launch**; raise to 3600 after.
- Keep MX/TXT (email, SPF, DKIM) records untouched.
- Issue the TLS cert (hosting panel / certbot) after DNS points to the server, or use DNS-01 validation.
- Redirect `www` ↔ apex (pick one canonical) and http → https.

## 6. Launch day (future)

1. Create the `production` environment with secrets and `SITE_URL`; set `PRODUCTION_ENABLED` = `true`.
2. Code freeze; merge `dev` → `main` via PR; approve the production deploy.
3. Full backup (DB + files).
4. If migrating from staging: `wp search-replace '<staging-url>' '<production-url>' --all-tables --skip-columns=guid` (run with `--dry-run` first), then `wp cache flush`.
5. Turn OFF "Discourage search engines" on production. Never copy staging robots/basic-auth settings to production.
6. Switch DNS A/CNAME records. Watch `dig +short <domain>` and check propagation.
7. Confirm HTTPS cert is valid and redirects work.
8. `wp rewrite flush`, resave permalinks, purge CDN/page cache.

## 7. Post-launch checklist (future)

- [ ] Homepage, shop, category, product, cart, checkout load (HTTP 200)
- [ ] Place a real test order (payment gateway in LIVE mode, then refund); order emails arrive (SMTP/SPF/DKIM)
- [ ] Woodmart theme options / page builder pages and header/footer builder render; JS console has no errors
- [ ] WooCommerce: currency, taxes, shipping zones, stock, webhooks
- [ ] `robots.txt` allows crawling; no `noindex` meta; sitemap URL submitted in Search Console
- [ ] Product schema renders; canonical URLs correct
- [ ] WP cron running: `wp cron event list`
- [ ] Mixed content check (no http:// assets), PageSpeed/Lighthouse run
- [ ] Analytics/Tag Manager firing; 301s from old URLs if structure changed
- [ ] Error log clean for 24–48 h; uptime monitor (UptimeRobot etc.) added
- [ ] Backups run on schedule; DNS TTL raised back

## 8. Rollback

Revert the bad merge commit on the branch (`git revert -m 1 <sha>`) and push. CI redeploys the previous code (staging for `dev`, production for `main` once enabled). For DB issues, restore the latest backup.
