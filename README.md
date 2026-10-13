# Papylon – WordPress / WooCommerce (Woodmart child theme)

| Branch | Deploys to | Trigger                                                                                    |
| ------ | ---------- | ------------------------------------------------------------------------------------------ |
| `dev`  | Staging    | push                                                                                       |
| `main` | –          | push runs lint & build only, until `PRODUCTION_ENABLED` is `true`                          |
| `main` | Production | push (manual approval), once `PRODUCTION_ENABLED` is `true` and the environment is set up |

Flow: feature branch → PR into `dev` → test on staging → PR `dev` into `main` → production.

- `wp-content/themes/woodmart-child` – child theme (Gulp 5, SCSS `@use/@forward`: `assets/src` → `assets/dest`)
- The parent theme **Woodmart** and all plugins (WooCommerce, etc.) are installed on the servers and are NOT in Git.

Local dev: `cd wp-content/themes/woodmart-child && npm ci && npm start` (edit `proxy` in `gulpfile.js`).
Full setup: `docs/RUNBOOK.md`.
