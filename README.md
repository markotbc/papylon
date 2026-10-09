# Papylon – WordPress / WooCommerce (Woodmart child theme + custom plugin)

| Branch | Deploys to | Trigger                |
| ------ | ---------- | ---------------------- |
| `dev`  | Staging    | push                   |
| `main` | Production | push (manual approval) |

Flow: feature branch → PR into `dev` → test on staging → PR `dev` into `main` → production.

- `wp-content/themes/woodmart-child` – child theme (Gulp 5, SCSS `@use/@forward`: `assets/src` → `assets/dest`)
- The parent theme **Woodmart** and all other plugins (WooCommerce, etc.) are installed on the servers and are NOT in Git.

Local dev: `cd wp-content/themes/woodmart-child && npm ci && npm start` (edit `proxy` in `gulpfile.js`).
Full setup: `docs/RUNBOOK.md`.
