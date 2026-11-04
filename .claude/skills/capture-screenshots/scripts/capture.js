#!/usr/bin/env node
/**
 * capture.js — navigate to one or more routes and save a screenshot of each.
 *
 * Usage:
 *   node capture.js --routes "/,/auction,/login" --folder screenshots/auth --name page.png
 *   node capture.js --routes /dashboard --folder shots --name dash.png --base http://localhost:8000 --full
 *
 * Flags:
 *   --routes   Required. One route or a comma-separated list. Repeatable (--routes a --routes b).
 *              May also be a JSON array string, e.g. '["/", "/auction"]'.
 *   --folder   Required. Destination folder (relative to project root or absolute). Created if missing.
 *   --name     Required. Output file name, e.g. "home.png".
 *              Supports placeholders {i} (1-based index) and {slug} (route slug).
 *              With multiple routes and no placeholder, "-{slug}" is inserted before the extension
 *              so files never overwrite each other.
 *   --base     Base URL. Defaults to APP_URL from .env, else http://localhost.
 *   --full     Capture the full scrollable page (default: viewport only).
 *   --width    Viewport width (default 1440).
 *   --height   Viewport height (default 900).
 *   --wait     Extra wait before shot: a CSS selector, "networkidle", or milliseconds (default: load + networkidle).
 *   --timeout  Navigation timeout in ms (default 30000).
 */

const fs = require('fs');
const path = require('path');

function parseArgs(argv) {
  const args = { routes: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const key = a.slice(2);
    const boolFlags = ['full'];
    if (boolFlags.includes(key)) {
      args[key] = true;
      continue;
    }
    const val = argv[++i];
    if (key === 'routes') args.routes.push(val);
    else args[key] = val;
  }
  return args;
}

function readEnvAppUrl() {
  try {
    const env = fs.readFileSync(path.resolve(process.cwd(), '.env'), 'utf8');
    const m = env.match(/^APP_URL=(.*)$/m);
    if (m) return m[1].trim().replace(/^["']|["']$/g, '');
  } catch { /* no .env */ }
  return null;
}

function normalizeRoutes(raw) {
  const out = [];
  for (const chunk of raw) {
    const trimmed = chunk.trim();
    if (trimmed.startsWith('[')) {
      try {
        for (const r of JSON.parse(trimmed)) out.push(String(r));
        continue;
      } catch { /* fall through to comma split */ }
    }
    for (const r of trimmed.split(',')) {
      if (r.trim()) out.push(r.trim());
    }
  }
  return out;
}

function slugify(route) {
  const s = route.replace(/^https?:\/\/[^/]+/, '').replace(/^\/+|\/+$/g, '');
  const slug = s.replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase();
  return slug || 'home';
}

function joinUrl(base, route) {
  if (/^https?:\/\//i.test(route)) return route;
  return base.replace(/\/+$/, '') + '/' + route.replace(/^\/+/, '');
}

function resolveName(name, route, index, total) {
  const ext = path.extname(name) || '.png';
  const stem = name.slice(0, name.length - path.extname(name).length) || 'screenshot';
  let resolved = stem
    .replace(/\{i\}/g, String(index + 1))
    .replace(/\{slug\}/g, slugify(route));
  if (total > 1 && resolved === stem) {
    resolved = `${stem}-${slugify(route)}`;
  }
  return resolved + ext;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const routes = normalizeRoutes(args.routes);

  if (!routes.length) throw new Error('Missing --routes (one route or a comma-separated/JSON list).');
  if (!args.folder) throw new Error('Missing --folder (destination folder).');
  if (!args.name) throw new Error('Missing --name (output file name).');

  const base = args.base || readEnvAppUrl() || 'http://localhost';
  const folder = path.resolve(process.cwd(), args.folder);
  const width = parseInt(args.width, 10) || 1440;
  const height = parseInt(args.height, 10) || 900;
  const timeout = parseInt(args.timeout, 10) || 30000;
  const fullPage = !!args.full;

  fs.mkdirSync(folder, { recursive: true });

  const { chromium } = require('playwright');
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width, height } });

  const results = [];
  try {
    for (let i = 0; i < routes.length; i++) {
      const route = routes[i];
      const url = joinUrl(base, route);
      const fileName = resolveName(args.name, route, i, routes.length);
      const filePath = path.join(folder, fileName);

      const page = await context.newPage();
      try {
        await page.goto(url, { waitUntil: 'networkidle', timeout });
        if (args.wait) {
          if (args.wait === 'networkidle') await page.waitForLoadState('networkidle', { timeout });
          else if (/^\d+$/.test(args.wait)) await page.waitForTimeout(parseInt(args.wait, 10));
          else await page.waitForSelector(args.wait, { timeout });
        }
        await page.screenshot({ path: filePath, fullPage });
        results.push({ route, url, file: filePath, ok: true });
        console.log(`✓ ${url} -> ${path.relative(process.cwd(), filePath)}`);
      } catch (err) {
        results.push({ route, url, file: filePath, ok: false, error: err.message });
        console.error(`✗ ${url} -> ${err.message}`);
      } finally {
        await page.close();
      }
    }
  } finally {
    await context.close();
    await browser.close();
  }

  const failed = results.filter(r => !r.ok).length;
  console.log(`\nDone: ${results.length - failed}/${results.length} captured into ${path.relative(process.cwd(), folder) || '.'}`);
  if (failed) process.exitCode = 1;
}

main().catch(err => {
  console.error('Error:', err.message);
  process.exit(1);
});
