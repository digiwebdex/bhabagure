#!/usr/bin/env node
/**
 * HTML → PDF with headless Chrome (best Bengali shaping; PHP PDF libraries break conjuncts).
 *
 *   node render.mjs <input.html> <output.pdf>
 *
 * The HTML is the API's print view. A `<!--bh:fonts-->` marker is replaced with @font-face rules pointing at the
 * bundled Hind Siliguri and Bricolage Grotesque files, so the output never depends on fonts installed on the server
 * (the VPS has no Bengali fonts). Chrome: CHROME_PATH if set (Chrome for Testing inside the project on the VPS),
 * otherwise Playwright's own Chromium.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

import { chromium } from 'playwright-core';

const require = createRequire(import.meta.url);

export function fontFaceCss() {
  const hind = dirname(require.resolve('@fontsource/hind-siliguri/package.json'));
  const bricolage = dirname(require.resolve('@fontsource-variable/bricolage-grotesque/package.json'));
  const url = (file) => pathToFileURL(file).href;
  const faces = [];
  for (const weight of [400, 500, 600, 700]) {
    for (const subset of ['bengali', 'latin', 'latin-ext']) {
      faces.push(
        `@font-face{font-family:'Hind Siliguri';font-style:normal;font-weight:${weight};font-display:block;` +
          `src:url('${url(join(hind, 'files', `hind-siliguri-${subset}-${weight}-normal.woff2`))}') format('woff2');` +
          `unicode-range:${subset === 'bengali' ? 'U+0951-0952,U+0964-0965,U+0980-09FE,U+200C-200D,U+25CC' : subset === 'latin' ? 'U+0000-00FF,U+2000-206F,U+2212' : 'U+0100-024F'}}`,
      );
    }
  }
  for (const subset of ['latin', 'latin-ext']) {
    faces.push(
      `@font-face{font-family:'Bricolage Grotesque';font-style:normal;font-weight:200 800;font-display:block;` +
        `src:url('${url(join(bricolage, 'files', `bricolage-grotesque-${subset}-wght-normal.woff2`))}') format('woff2');` +
        `unicode-range:${subset === 'latin' ? 'U+0000-00FF,U+2000-206F,U+2212' : 'U+0100-024F'}}`,
    );
  }
  return `<style>${faces.join('\n')}</style>`;
}

export async function renderPdf(inputHtml, outputPdf) {
  const html = readFileSync(inputHtml, 'utf8').replace('<!--bh:fonts-->', fontFaceCss());
  const prepared = `${inputHtml}.prepared.html`;
  writeFileSync(prepared, html);

  const browser = await chromium.launch({
    executablePath: process.env.CHROME_PATH || undefined,
    // One small render at a time on a shared box: no GPU, no extra processes, no shared-memory or crash-report files
    // outside the directories the API points HOME and TMPDIR at.
    args: [
      '--disable-gpu',
      '--no-first-run',
      '--no-default-browser-check',
      '--disable-extensions',
      '--disable-dev-shm-usage',
      '--disable-crash-reporter',
      '--font-render-hinting=none',
    ],
  });
  try {
    const page = await browser.newPage();
    // Nothing leaves the machine: the print view is self-contained (fonts and images are local files or data URIs).
    await page.route(/^https?:/, (route) => route.abort());
    await page.goto(pathToFileURL(resolve(prepared)).href, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    await page.pdf({ path: outputPdf, preferCSSPageSize: true, printBackground: true, margin: { top: 0, right: 0, bottom: 0, left: 0 } });
  } finally {
    await browser.close();
  }
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const [input, output] = process.argv.slice(2);
  if (!input || !output) {
    console.error('usage: render.mjs <input.html> <output.pdf>');
    process.exit(2);
  }
  renderPdf(input, output).catch((error) => {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
  });
}
