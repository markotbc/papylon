# Runbook: Git → CI/CD → Production → Launch

## 1. Git repository

```bash
cd papylon-repo
git init -b main
git add . && git commit -m "Initial commit: child theme + sync plugin"
git remote add origin git@github.com:<ORG>/papylon.git
git push -u origin main
git checkout -b dev && git push -u origin dev
```

GitHub → Settings:
- **Branches**: protect `main` (require PR, require status check "Lint & build", no force-push). Protect `dev` lightly (require the check).
- **Environments**: create `staging` and `production`. On `production` add *Required reviewers* (you) so every prod deploy needs one click.
- Default branch can stay `main`; day-to-day work goes through `dev`.

### No production yet?
Staging works on its own. Pushes to `main` only run lint/build until you create the `production` environment, add its secrets, and set a repository variable `PRODUCTION_ENABLED` = `true` (Settings → Secrets and variables → Actions → Variables).

### Secrets / variables (per environment, different values!)
| Name | Type | Example |
|------|------|---------|
| `SSH_HOST` | secret | server IP / hostname |
| `SSH_USER` | secret | `deploy` |
| `SSH_PORT` | secret (optional) | `22` |
| `SSH_PRIVATE_KEY` | secret | contents of deploy key (private) |
| `SSH_KNOWN_HOSTS` | secret | output of `ssh-keyscan -p 22 <host>` |
| `WP_PATH` | secret | staging: `/home/USER/public_html/papylon-staging`; production: your production WP root |
| `SITE_URL` | variable | `https://staging.papylon.com` / `https://papylon.com` |

Create the key once: `ssh-keygen -t ed25519 -f deploy_key -C "github-deploy"`; put `deploy_key.pub` in `~deploy/.ssh/authorized_keys` on each server; paste the private key into the secret; delete the local copy.

## 2. Theme structure & assets
Same layout as the weareai theme: `assets/src/scss` (base / layout / components / pages / utils, entry `main.scss`) and `assets/src/js` are compiled by Gulp into `assets/dest/css` and `assets/dest/js`.
`dest/css` is git-ignored and built in CI (`npm run build:production`). `dest/js` is committed but overwritten by CI with the minified build. The original child `style.css` header and `woodmart_child_enqueue_styles()` are kept; `functions.php` enqueues `main.css`, `main.js` and `shop.js` only if they exist.
Add new JS files in `assets/src/js` and add their handle to the `foreach` array in `functions.php`.

## 3. Production server setup
1. PHP 8.1+ with `mysqli, curl, gd/imagick, mbstring, xml, zip, intl`; Nginx/Apache + HTTPS; MariaDB/MySQL.
2. Install WordPress + the **Woodmart parent theme** (premium, licence from ThemeForest) + WooCommerce and other plugins manually once. These are **not** in Git.
3. Install WP-CLI (`wp`) and give the `deploy` user write access to `wp-content/themes/papylon` and `wp-content/plugins/papylon-sync`.
4. `wp-config.php` (per environment, never in Git):
```php
define('WP_ENVIRONMENT_TYPE', 'production');   // 'staging' on staging – theme's get_asset() uses this
define('DISABLE_WP_CRON', true);               // system cron takes over
define('WP_DEBUG', false);
define('DISALLOW_FILE_EDIT', true);
define('WP_AUTO_UPDATE_CORE', 'minor');
```
5. Staging: same stack on `staging.<domain>`, protected with HTTP basic auth, `Settings → Reading → Discourage search engines` ON, `WP_ENVIRONMENT_TYPE = 'staging'`.
6. Backups: daily DB + `uploads` backup with off-server copy. Test a restore once.
7. First deploy: activate theme "Woodmart Child" and plugin "Papylon Sync" in wp-admin.

## 4. DNS
| Record | Name | Value |
|--------|------|-------|
| A | `@` | production IP |
| CNAME/A | `www` | `@` / production IP |
| A | `staging` | staging IP |

- Lower TTL to **300 s at least 24 h before launch**; raise to 3600 after.
- Keep MX/TXT (email, SPF, DKIM) records untouched.
- Issue the TLS cert (certbot / hosting panel) – after DNS points to the server, or use DNS-01 validation.
- Redirect `www` ↔ apex (pick one canonical) and http → https.

## 5. System cron (sync)
As the web-server user (e.g. `www-data`): `crontab -e`
```cron
# WP-Cron replacement (scheduled posts, etc.)
*/5 * * * * cd /home/USER/public_html/papylon && /usr/local/bin/wp cron event run --due-now >/dev/null 2>&1
# Custom sync (every 15 min)
*/15 * * * * WP_PATH=/home/USER/public_html/papylon /usr/local/bin/cron-sync.sh
```
First copy it once: `sudo install -m 755 scripts/cron-sync.sh /usr/local/bin/cron-sync.sh`. Make sure `/var/log/papylon-sync.log` is writable by the cron user.
Test manually first: `wp papylon sync`. Verify with `wp option get papylon_sync_last_run`.

## 6. Launch day
1. Code freeze; merge `dev` → `main` via PR; approve the production deploy.
2. Full backup (DB + files).
3. If migrating from staging: `wp search-replace 'https://staging.domain' 'https://domain' --all-tables --skip-columns=guid` (dry-run with `--dry-run` first), then `wp cache flush`.
4. Turn OFF "Discourage search engines"; remove staging basic auth only on staging copy, never push staging robots settings to prod.
5. Switch DNS A/CNAME records. Watch `dig +short domain` and propagate-check.
6. Confirm HTTPS cert valid, redirects work.
7. `wp rewrite flush`, resave permalinks, purge CDN/page cache.

## 7. Post-launch checklist
- [ ] Homepage, shop, category, product, cart, checkout load (HTTP 200)
- [ ] Place a real test order (payment gateway in LIVE mode, then refund); order emails arrive (SMTP/SPF/DKIM)
- [ ] Woodmart theme options / Elementor-WPBakery pages and header/footer builder render; JS console has no errors
- [ ] WooCommerce: currency, taxes, shipping zones, stock sync (`wp papylon sync`), webhooks
- [ ] `robots.txt` allows crawling; no `noindex` meta; sitemap URL submitted in Search Console
- [ ] Product schema renders; canonical URLs correct
- [ ] Cron running: `tail /var/log/papylon-sync.log`, `wp cron event list`
- [ ] Mixed content check (no http:// assets), PageSpeed/Lighthouse run
- [ ] Analytics/Tag Manager firing; 301s from old URLs if structure changed
- [ ] Error log clean for 24–48 h; uptime monitor (UptimeRobot etc.) added
- [ ] Backups run on schedule; DNS TTL raised back

## 8. Rollback
Revert the merge commit on `main` (`git revert -m 1 <sha>`) and push – CI redeploys the previous code. For DB issues restore the pre-launch backup.
