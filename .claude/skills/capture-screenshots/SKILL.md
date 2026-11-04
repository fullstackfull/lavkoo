---
name: capture-screenshots
description: Capture Playwright screenshots of one or more routes and save them to a target folder with a given file name. Use when the user asks to screenshot a page/route, grab a screenshot of the app, or capture multiple pages at once.
---

# Capture Screenshots

Navigate to one or more routes with Playwright (Chromium) and save a screenshot of each
into a target folder using a provided file name.

## Inputs

The user provides:
- **routes** — a single route (e.g. `/auction`) or several (e.g. `/`, `/auction`, `/login`).
- **folder** — destination folder for the screenshots (relative to project root or absolute).
- **name** — the output image file name (e.g. `home.png`).

## How to run

Call the bundled script with the parsed inputs:

```bash
node .claude/skills/capture-screenshots/scripts/capture.js \
  --routes "<route or comma-separated routes>" \
  --folder "<folder>" \
  --name "<file-name.png>"
```

### Examples

Single route:
```bash
node .claude/skills/capture-screenshots/scripts/capture.js \
  --routes "/auction" --folder "screenshots/auction" --name "auction-list.png"
```

Multiple routes (comma-separated). Each file is auto-suffixed with a route slug so they
don't overwrite each other (`page-home.png`, `page-auction.png`, `page-login.png`):
```bash
node .claude/skills/capture-screenshots/scripts/capture.js \
  --routes "/,/auction,/login" --folder "screenshots/smoke" --name "page.png"
```

To control naming explicitly, put `{slug}` or `{i}` in `--name`
(e.g. `--name "shot-{i}.png"` → `shot-1.png`, `shot-2.png`).

## Options

- `--base <url>` — base URL. Defaults to `APP_URL` from `.env`, else `http://localhost`.
  The 6Valley app usually serves on a port (e.g. `php artisan serve` → `http://localhost:8000`,
  or MAMP). If shots come back as error pages, pass the correct `--base`.
- `--full` — capture the full scrollable page instead of just the viewport.
- `--width <px>` / `--height <px>` — viewport size (default 1440×900).
- `--wait <value>` — extra wait before the shot: a CSS selector, `networkidle`, or milliseconds.
- `--timeout <ms>` — navigation timeout (default 30000).

## Behavior notes

- Reuses a single browser/context across all routes (efficient for many routes).
- Creates the target folder recursively if it doesn't exist.
- Reports per-route success/failure and exits non-zero if any route failed.

## Steps for the assistant

1. Parse the user's routes, folder, and file name from the request.
2. Confirm the base URL — check `APP_URL` in `.env`; if the app runs on a port, pass `--base`.
3. Run the command above.
4. Report which screenshots were saved and where; if any route failed, surface the error
   (commonly a wrong `--base`/port or the dev server not running).
