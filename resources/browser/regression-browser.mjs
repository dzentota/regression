#!/usr/bin/env node
// Headless browser helper for sugarcrm/regression.
//
// Protocol: one JSON request per line on stdin, one JSON response per line on stdout.
//   {"id": 1, "cmd": "visit", "url": "...", "cookies": [...], "localStorage": {...}, "headers": {...},
//    "waitUntil": "load", "timeoutMs": 30000, "settleMs": 1500, "markers": ["rgxss_..."]}
//   -> {"id": 1, "ok": true, "status": 200, "finalUrl": "...", "hits": [{"kind": "dialog", "type": "alert",
//       "message": "rgxss_..."}], "pageErrors": [...], "console": [...]}
//   {"id": 2, "cmd": "close"}
//
// A "hit" is evidence that script injected by a scenario executed: a JavaScript dialog
// (alert/confirm/prompt), a call to window.__rgXss(marker), or a print() call.
// When markers are given, only hits mentioning one of them count (others are reported as "unmatched"),
// so dialogs the application shows on its own don't produce false positives.

import { createInterface } from 'node:readline';

let chromium;
try {
  ({ chromium } = await import('playwright'));
} catch (e) {
  process.stdout.write(JSON.stringify({ id: 0, ok: false, fatal: true,
    error: 'playwright is not installed: run `npm install` in ' + new URL('.', import.meta.url).pathname
      + ' and `npx playwright install chromium`' }) + '\n');
  process.exit(2);
}

// Browser: Playwright's Chromium (`npx playwright install chromium`) by default; an installed browser with
// REGRESSION_BROWSER_CHANNEL=chrome|msedge or REGRESSION_BROWSER_EXECUTABLE=/path/to/chrome.
let browser;
try {
  browser = await chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage'],
    channel: process.env.REGRESSION_BROWSER_CHANNEL || undefined,
    executablePath: process.env.REGRESSION_BROWSER_EXECUTABLE || undefined,
  });
} catch (e) {
  process.stdout.write(JSON.stringify({ id: 0, ok: false, fatal: true,
    error: 'cannot launch browser: ' + String(e && e.message || e).split('\n')[0]
      + ' (run `npx playwright install chromium` or set REGRESSION_BROWSER_CHANNEL=chrome)' }) + '\n');
  process.exit(2);
}

const INIT_SCRIPT = `
  (() => {
    const report = (kind, message) => { try { window.__rgReport(kind, String(message)); } catch (e) {} };
    window.__rgXss = (marker) => report('hook', marker);
    const origPrint = window.print;
    window.print = function () { report('print', 'print()'); };
  })();
`;

async function visit(req) {
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: req.headers || {},
    javaScriptEnabled: true,
  });
  const hits = [];
  const pageErrors = [];
  const consoleMessages = [];
  const evaluated = [];
  try {
    if (Array.isArray(req.cookies) && req.cookies.length) {
      await context.addCookies(req.cookies);
    }
    await context.exposeBinding('__rgReport', (_source, kind, message) => {
      hits.push({ kind, type: kind, message });
    });
    await context.addInitScript(INIT_SCRIPT);
    if (req.localStorage && Object.keys(req.localStorage).length) {
      await context.addInitScript((items) => {
        for (const [k, v] of Object.entries(items)) {
          try { window.localStorage.setItem(k, v); } catch (e) {}
        }
      }, req.localStorage);
    }

    const page = await context.newPage();
    const started = Date.now();
    const requests = [];
    if (req.traceRequests) {
      page.on('requestfinished', async (r) => {
        const t = r.timing();
        const total = t.responseEnd > 0 ? t.responseEnd : -1;
        let status = null;
        try { status = (await r.response())?.status() ?? null; } catch (e) {}
        requests.push({ url: r.url().slice(0, 160), ms: Math.round(total), status, at: Date.now() - started });
      });
      page.on('requestfailed', (r) => requests.push({ url: r.url().slice(0, 160), failed: r.failure()?.errorText, at: Date.now() - started }));
    }
    page.on('dialog', async (dialog) => {
      hits.push({ kind: 'dialog', type: dialog.type(), message: dialog.message() });
      try { await dialog.dismiss(); } catch (e) {}
    });
    page.on('pageerror', (err) => pageErrors.push(String(err && err.message || err)));
    page.on('console', (msg) => {
      if (consoleMessages.length < 50) consoleMessages.push(`${msg.type()}: ${msg.text()}`.slice(0, 500));
    });

    let response;
    const render = async (reload) => {
    response = reload
      ? await page.reload({ waitUntil: req.waitUntil || 'load', timeout: req.timeoutMs || 30000 })
      : await page.goto(req.url, { waitUntil: req.waitUntil || 'load', timeout: req.timeoutMs || 30000 });
    // Sidecar routes (#Module/...) render asynchronously after the app has booted and authenticated.
    const sidecar = req.waitForSidecar ?? new URL(req.url).hash.length > 1;
    if (sidecar) {
      // The login form shows briefly while the token is validated: wait for the user, not for the form.
      const waitForUser = () => page.waitForFunction(
        () => window.SUGAR && SUGAR.App && SUGAR.App.user && SUGAR.App.user.get('id'),
        null,
        { timeout: req.sidecarTimeoutMs || 45000 },
      ).then(() => true, () => false);
      let ready = await waitForUser();
      sidecarReadyMs = Date.now() - started;
      if (!ready && await page.$('input[name=username]')) {
        // After a metadata change (Studio, dropdowns...) Sidecar wipes its storage, token included, on boot.
        // The init script restores the token on the next navigation.
        await page.reload({ waitUntil: req.waitUntil || 'load' });
        ready = await waitForUser();
        sidecarReadyMs = Date.now() - started;
      }
      if (!ready) {
        throw new Error(await page.$('input[name=username]')
          ? 'Sidecar shows the login page: the session was not accepted'
          : 'Sidecar did not finish loading');
      }
    }
    for (const action of req.actions || []) {
      if (action.click) await page.click(action.click, { timeout: req.timeoutMs || 30000 });
      // Click the innermost element containing the text, even if hidden or outside the viewport (DOM click).
      if (action.clickText) {
        // Content is often loaded by AJAX after "load", and listings may be cached server-side for a moment:
        // wait for the text, reloading the page a few times.
        for (let attempt = 0; attempt <= (action.reloads ?? 3); attempt++) {
          const found = await page.waitForFunction((text) => document.body && document.body.textContent.includes(text),
            action.clickText, { timeout: action.waitMs ?? 8000 }).then(() => true, () => false);
          if (found) break;
          await page.reload({ waitUntil: req.waitUntil || 'load' });
        }
        const clicked = await page.evaluate((text) => {
          const all = [...document.querySelectorAll('a, button, [onclick], [data-onclick], span, td, li, div')]
            .filter((el) => (el.textContent || '').includes(text));
          const target = all.find((el) => ![...el.children].some((c) => (c.textContent || '').includes(text))) || all[0];
          if (!target) return false;
          (target.closest('a, button, [onclick]') || target).click();
          return true;
        }, action.clickText);
        if (!clicked && !action.optional) throw new Error(`no element contains text "${action.clickText}"`);
      }
      if (action.waitFor) await page.waitForSelector(action.waitFor, { timeout: req.timeoutMs || 30000 });
      if (action.eval) await page.evaluate(action.eval);
      if (action.evaluate) evaluated.push(await page.evaluate(action.evaluate));
      if (action.fill) await page.fill(action.fill, action.value ?? '', { timeout: req.timeoutMs || 30000 });
      if (action.press) await page.press(action.press, action.key || 'Enter');
      if (action.wait) await page.waitForTimeout(action.wait);
    }
    // Let deferred handlers (onerror of images, timers, Sidecar rendering) run.
    await page.waitForTimeout(req.settleMs ?? 1500);

    };
    let sidecarReadyMs;
    const markers = req.markers || [];
    const countMarkers = async () => {
      const html = (await Promise.all(page.frames().map((f) => f.content().catch(() => '')))).join('\n');
      return Object.fromEntries(markers.map((m) => [m, html.split(m).length - 1]));
    };
    await render(false);
    // Where the payload ended up: a test only proves something if its payload is on the page. Studio/metadata
    // driven pages may show fresh data only after caches catch up: reload a few times before giving up.
    let markersInPage = {};
    for (let attempt = 0; ; attempt++) {
      if (!markers.length) break;
      markersInPage = await countMarkers();
      const present = Object.values(markersInPage).some((n) => n > 0);
      if (present || hits.length || attempt >= (req.markerRetries ?? 3)) break;
      await page.waitForTimeout(req.markerRetryDelayMs ?? 3000);
      await render(true);
    }

    // Tokens may have been refreshed by the page: report them so the caller can keep its session in sync.
    let storage;
    if (Array.isArray(req.returnStorage) && req.returnStorage.length) {
      storage = await page.evaluate((keys) => Object.fromEntries(keys.map((k) => [k, localStorage.getItem(k)])),
        req.returnStorage).catch(() => undefined);
    }
    const matched = markers.length
      ? hits.filter((h) => markers.some((m) => h.message.includes(m)))
      : hits;
    const unmatched = hits.filter((h) => !matched.includes(h));
    return {
      ok: true,
      status: response ? response.status() : null,
      finalUrl: page.url(),
      hits: matched,
      unmatched,
      pageErrors: pageErrors.slice(0, 20),
      console: consoleMessages,
      evaluated,
      storage,
      markersInPage,
      timing: { totalMs: Date.now() - started, sidecarReadyMs: typeof sidecarReadyMs === 'undefined' ? null : sidecarReadyMs },
      requests: req.traceRequests ? requests.sort((a, b) => (b.ms ?? 0) - (a.ms ?? 0)).slice(0, req.traceRequests === true ? 15 : req.traceRequests) : undefined,
    };
  } finally {
    await context.close();
  }
}

const rl = createInterface({ input: process.stdin });
for await (const line of rl) {
  if (!line.trim()) continue;
  let req;
  try {
    req = JSON.parse(line);
  } catch (e) {
    process.stdout.write(JSON.stringify({ id: null, ok: false, error: 'invalid JSON request' }) + '\n');
    continue;
  }
  if (req.cmd === 'close') break;
  let res;
  try {
    if (req.cmd !== 'visit') throw new Error(`unknown command ${req.cmd}`);
    res = await visit(req);
  } catch (e) {
    res = { ok: false, error: String(e && e.message || e) };
  }
  process.stdout.write(JSON.stringify({ id: req.id, ...res }) + '\n');
}
await browser.close();
