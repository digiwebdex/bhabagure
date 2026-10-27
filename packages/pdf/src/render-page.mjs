#!/usr/bin/env node
/**
 * One website page → PDF with headless Chrome (docs/tour-landing-pages.md §7): a tour landing page's print view.
 *
 *   node render-page.mjs <manifest.json> <output.pdf>
 *
 * Unlike render.mjs (local HTML, no network at all), this loads a page from the website on this server — and only what
 * the manifest allows, nothing else:
 *   - the page itself, at exactly `url` (the `headers` added, e.g. a staff preview token): 200 or the render fails;
 *   - the website's own static files under `staticPrefix` (styles, fonts): GET only, 200 or the render fails;
 *   - the photos listed in `assets`, served from the JPEG files the API prepared — never fetched;
 * no redirect is followed. Anything else is blocked, and a page that needed it fails rather than printing incomplete.
 * Scripts don't run: the print view is complete as the server renders it. Exit 0 and a PDF only when nothing failed.
 */
import { readFileSync, writeFileSync } from 'node:fs';

import { chromium } from 'playwright-core';

const [manifestFile, outputPdf] = process.argv.slice(2);
const manifest = JSON.parse(readFileSync(manifestFile, 'utf8'));
const { url, headers = {}, staticPrefix, assets = {}, pageTimeoutMs = 20_000, assetsTimeoutMs = 10_000 } = manifest;

const problems = [];
const blocked = new Set();
// An address without its query, for the report: nothing sensitive in it, and short.
const shown = (address) => address.split(/[?#]/)[0];

const browser = await chromium.launch({
  executablePath: process.env.CHROME_PATH || undefined,
  // As render.mjs: one small render at a time on a shared box.
  args: ['--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-dev-shm-usage', '--disable-crash-reporter', '--font-render-hinting=none'],
});

let exitCode = 1;
try {
  const context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block', acceptDownloads: false });
  await context.route(/.*/, async (route) => {
    const request = route.request();
    const address = request.url();
    try {
      if (address === url) {
        const response = await route.fetch({ headers: { ...request.headers(), ...headers }, maxRedirects: 0, timeout: pageTimeoutMs });
        if (response.status() !== 200) {
          problems.push(`page answered ${response.status()}`);
          return route.abort('failed');
        }
        return route.fulfill({ response });
      }
      if (staticPrefix && address.startsWith(staticPrefix) && request.method() === 'GET') {
        const response = await route.fetch({ maxRedirects: 0, timeout: assetsTimeoutMs });
        if (response.status() !== 200) {
          problems.push(`${shown(address)} answered ${response.status()}`);
          return route.abort('failed');
        }
        return route.fulfill({ response });
      }
      if (Object.hasOwn(assets, address)) {
        return route.fulfill({ path: assets[address], contentType: 'image/jpeg' });
      }
    } catch (error) {
      problems.push(`${shown(address)}: ${error.message.split('\n')[0]}`);
      return route.abort('failed');
    }
    blocked.add(shown(address));
    return route.abort('blockedbyclient');
  });

  const page = await context.newPage();
  const response = await page.goto(url, { waitUntil: 'load', timeout: pageTimeoutMs });
  if (!response || response.status() !== 200) problems.push(`page not loaded (${response?.status() ?? 'no response'})`);

  // Fonts and photos in, or the render fails: a brochure is never printed with a hole in it.
  const check = await Promise.race([
    page.evaluate(async () => {
      await document.fonts.ready;
      const images = [...document.images];
      await Promise.all(
        images.map((img) =>
          img.complete
            ? null
            : new Promise((done) => {
                img.addEventListener('load', done);
                img.addEventListener('error', done);
              }),
        ),
      );
      return { images: images.length, broken: images.filter((img) => img.naturalWidth === 0).map((img) => img.currentSrc || img.src) };
    }),
    new Promise((_, reject) => setTimeout(() => reject(new Error('fonts or photos took too long')), assetsTimeoutMs)),
  ]).catch((error) => ({ images: 0, broken: [], error: error.message }));
  if (check.error) problems.push(check.error);
  for (const src of check.broken) problems.push(`photo not loaded: ${shown(src)}`);
  if (blocked.size > 0) problems.push(`blocked: ${[...blocked].join(', ')}`);

  if (problems.length === 0) {
    writeFileSync(outputPdf, await page.pdf({ preferCSSPageSize: true, printBackground: true, margin: { top: 0, right: 0, bottom: 0, left: 0 } }));
    exitCode = 0;
  }
  console.log(JSON.stringify({ ok: problems.length === 0, images: check.images, problems }));
} catch (error) {
  console.log(JSON.stringify({ ok: false, problems: [...problems, error.message.split('\n')[0]] }));
} finally {
  await browser.close();
}
process.exit(exitCode);
