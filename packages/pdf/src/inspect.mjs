#!/usr/bin/env node
/**
 * Measures a rendered PDF the way a printer sees it — used by the API's invoice tests.
 *
 *   node inspect.mjs <file.pdf> [--dpi 200] [--ink-top-mm 0] [--ink-bottom-mm 40] [--barcode]
 *
 * Prints JSON: page size in mm; every text run with its position in mm from the top-left of page 1; the count of dark
 * pixels in a horizontal band of the rasterised page (to prove a region is blank); and any Code 128 decoded from the
 * raster (to prove the barcode scans, not just that it was drawn).
 */
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

import { createCanvas } from '@napi-rs/canvas';
import { BarcodeFormat, BinaryBitmap, DecodeHintType, HybridBinarizer, MultiFormatReader, RGBLuminanceSource } from '@zxing/library';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const PT_TO_MM = 25.4 / 72;

function option(args, name, fallback) {
  const i = args.indexOf(`--${name}`);
  return i >= 0 ? args[i + 1] : fallback;
}

export async function inspectPdf(file, { dpi = 200, inkTopMm = null, inkBottomMm = null, barcode = false } = {}) {
  const pdf = await getDocument({ data: new Uint8Array(readFileSync(file)), useSystemFonts: false, isEvalSupported: false }).promise;
  const page = await pdf.getPage(1);
  const viewport = page.getViewport({ scale: 1 });
  const heightPt = viewport.height;

  const content = await page.getTextContent();
  const texts = content.items
    .filter((item) => 'str' in item && item.str.trim() !== '')
    .map((item) => ({
      str: item.str,
      xMm: +(item.transform[4] * PT_TO_MM).toFixed(2),
      // PDF y is the baseline from the bottom; report the top of the glyph box from the page top.
      yMm: +((heightPt - item.transform[5] - item.height) * PT_TO_MM).toFixed(2),
      baselineMm: +((heightPt - item.transform[5]) * PT_TO_MM).toFixed(2),
    }));

  const result = {
    pages: pdf.numPages,
    widthMm: +(viewport.width * PT_TO_MM).toFixed(2),
    heightMm: +(heightPt * PT_TO_MM).toFixed(2),
    texts,
  };

  if (inkTopMm !== null || barcode) {
    const scale = dpi / 72;
    const raster = page.getViewport({ scale });
    const canvas = createCanvas(Math.ceil(raster.width), Math.ceil(raster.height));
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    await page.render({ canvas, canvasContext: context, viewport: raster }).promise;
    const { data, width, height } = context.getImageData(0, 0, canvas.width, canvas.height);
    const pxPerMm = dpi / 25.4;

    if (inkTopMm !== null) {
      const top = Math.max(0, Math.floor(inkTopMm * pxPerMm));
      const bottom = Math.min(height, Math.ceil((inkBottomMm ?? inkTopMm) * pxPerMm));
      let dark = 0;
      let firstDarkRowMm = null;
      for (let y = top; y < bottom; y++) {
        for (let x = 0; x < width; x++) {
          const i = (y * width + x) * 4;
          // Anything visibly not white counts as ink (light tints included).
          if (data[i] < 245 || data[i + 1] < 245 || data[i + 2] < 245) {
            dark++;
            if (firstDarkRowMm === null) firstDarkRowMm = +(y / pxPerMm).toFixed(2);
          }
        }
      }
      result.ink = { topMm: inkTopMm, bottomMm: inkBottomMm, darkPixels: dark, firstDarkRowMm };
    }

    if (barcode) {
      const luminance = new Uint8ClampedArray(width * height);
      for (let p = 0; p < width * height; p++) {
        luminance[p] = (data[p * 4] * 299 + data[p * 4 + 1] * 587 + data[p * 4 + 2] * 114) / 1000;
      }
      const reader = new MultiFormatReader();
      reader.setHints(new Map([[DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.CODE_128]], [DecodeHintType.TRY_HARDER, true]]));
      try {
        const decoded = reader.decode(new BinaryBitmap(new HybridBinarizer(new RGBLuminanceSource(luminance, width, height))));
        result.barcode = { text: decoded.getText(), format: BarcodeFormat[decoded.getBarcodeFormat()] };
      } catch {
        result.barcode = null;
      }
    }
  }

  return result;
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const args = process.argv.slice(2);
  const file = args[0];
  if (!file) {
    console.error('usage: inspect.mjs <file.pdf> [--dpi 200] [--ink-top-mm N --ink-bottom-mm N] [--barcode]');
    process.exit(2);
  }
  const inkTop = option(args, 'ink-top-mm', null);
  inspectPdf(file, {
    dpi: Number(option(args, 'dpi', 200)),
    inkTopMm: inkTop === null ? null : Number(inkTop),
    inkBottomMm: inkTop === null ? null : Number(option(args, 'ink-bottom-mm', inkTop)),
    barcode: args.includes('--barcode'),
  })
    .then((result) => process.stdout.write(JSON.stringify(result)))
    .catch((error) => {
      console.error(error instanceof Error ? error.stack : String(error));
      process.exit(1);
    });
}
