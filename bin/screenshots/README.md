# Listing screenshots

Tooling for the wordpress.org screenshots (`.wordpress-org/screenshot-N.png`). It lives in `bin/`, which is export-ignored, so none of it ships in the plugin zip.

Everything runs on a disposable site that the smoke gate builds. Never point it at the DDEV site, and never log in as a real user. The only login is the disposable site's own `admin` / `admin`.

## 1. Start the site

```
ddev exec bash /var/www/plugin-src/bin/smoke-gate.sh --shots
```

Run it in the background. It builds a WordPress site in `/tmp/aafm-smoke`, installs WooCommerce and Yoast SEO, copies `shots-mu.php` and `demo-plugin.php` into `mu-plugins`, seeds demo data with `seed-demo.php`, binds the server to `0.0.0.0:8070`, and prints `SHOTS_URL=http://<container ip>:8070` and `SHOTS_READY`. It needs network access for WordPress core and the two plugins.

Check the host can reach it before you go on: `curl -s -o /dev/null -w '%{http_code}\n' "<SHOTS_URL>/wp-login.php"` should print 200. On OrbStack it does (checked 2026-10-15). If it ever does not, run the same seed on WordPress Playground instead (`npx @wp-playground/cli server`, pinned to a release at least two weeks old) with a blueprint that copies the two mu-plugins and runs `seed-demo.php`.

To shoot the first-run wizard on a site that has not been configured, start with `AAFM_SHOTS_SEED=0`, shoot `--only=1`, then seed with `wp --allow-root --path=/tmp/aafm-smoke eval-file /var/www/plugin-src/bin/screenshots/seed-demo.php` inside the container. The seeded route also works, because `shots-mu.php` fakes the first-run state per request for `?aafm_shot=wizard`.

## 2. Install Playwright outside the repo

```
mkdir -p <scratch>/pw && cd <scratch>/pw && npm init -y && npm i playwright@1.63.0 && npx playwright install chromium
```

1.63.0 was published 2026-09-04.

## 3. Capture

```
PW_DIR=<scratch>/pw node bin/screenshots/capture.mjs "<SHOTS_URL>" "<scratch>/shots" [--only=1,2,9]
```

Run it from the repo root. Each page's text is checked against a deny list before the shot is written. A `refused` line names the match: fix the seed or the page state, not the list.

The deny list is the generic patterns in `capture.mjs` plus `.claude/screenshots-deny.txt`, one regular expression per line, `#` for comments. That file is untracked on purpose, because real client names would be public here. The script exits 2 if the file is missing.

## 4. Stop the site

```
ddev exec touch /tmp/aafm-smoke/shots.done
```

The gate then removes the site and its database. `ddev exec ls /tmp | grep aafm-smoke` should print nothing.

## Image rules

PNG, lowercase `screenshot-N.png`, 1440 x 900 at device scale factor 1, under 10 MB each, no personal data, no browser chrome. Look at every image before it goes into `.wordpress-org/`.

## Which route made which image

Images 1 to 13 (the 1.8.0 set, taken 2026-10-15; 9 and 10 were retaken for 1.9.0, see below): the smoke gate's `--shots` site reached from the host over the container IP, Playwright 1.63.0, seeded run. Screenshot 1 used the `?aafm_shot=wizard` switch, 3 and 4 used `?aafm_shot=readonly`.

For 1.9.0, images 9 and 10 were retaken and image 14 was added, all committed on 2026-10-16 and shot with `capture.mjs`. The routes are image 9 `tab=activity` (waits for the log table rows), image 10 `tab=dashboard`, and image 14 `tab=settings` scrolled to the Notifications card (`#aafm-notify-mode`). The other images are still the 1.8.0 captures.
