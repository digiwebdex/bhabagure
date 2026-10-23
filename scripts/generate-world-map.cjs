/**
 * Makes admin/src/features/analytics/world-map.json, the visitor-analytics world map (docs/visitor-analytics.md):
 * every country as an SVG path in a 960×480 Natural Earth projection, keyed by its ISO 3166-1 alpha-2 code — the code
 * Cloudflare gives each visit. Run once, offline; the output is committed, so the admin has no map service, key or
 * runtime dependency.
 *
 * Data: Natural Earth 1:110m countries (public domain) via world-atlas 2.0.2 (ISC).
 *
 *   mkdir deps && cd deps && npm i world-atlas@2.0.2 topojson-client@3.1.0 d3-geo@1.12.1 i18n-iso-countries@7.11.0
 *   node scripts/generate-world-map.cjs <path to deps/node_modules> admin/src/features/analytics/world-map.json
 */
const fs = require('node:fs');
const path = require('node:path');

const [modules, out] = process.argv.slice(2);
if (!modules || !out) {
  console.error('usage: node scripts/generate-world-map.cjs <node_modules dir> <output json>');
  process.exit(1);
}
const need = (name) => require(path.join(path.resolve(modules), name));
const topojson = need('topojson-client');
const d3 = need('d3-geo');
const countries = need('i18n-iso-countries');

const WIDTH = 960;
const HEIGHT = 480;
const world = JSON.parse(fs.readFileSync(path.join(path.resolve(modules), 'world-atlas', 'countries-110m.json'), 'utf8'));
const features = topojson.feature(world, world.objects.countries).features.filter((f) => f.properties.name !== 'Antarctica');
const projection = d3.geoNaturalEarth1().fitSize([WIDTH, HEIGHT], { type: 'FeatureCollection', features });
const toPath = d3.geoPath(projection);
// One decimal is a tenth of a pixel at this size: plenty, and a third the bytes.
const round = (d) => d.replace(/-?\d+\.\d+/g, (n) => String(Math.round(Number(n) * 10) / 10));

// Natural Earth names a few places without an ISO number; these are their codes.
const BY_NAME = { Kosovo: 'XK', 'N. Cyprus': 'CY', Somaliland: 'SO' };
// One shape per code: N. Cyprus joins Cyprus and Somaliland joins Somalia, as Cloudflare reports them.
const byCode = new Map();
for (const feature of features) {
  const code = (feature.id && countries.numericToAlpha2(feature.id)) || BY_NAME[feature.properties.name];
  const d = toPath(feature);
  if (!code || !d) continue;
  const known = byCode.get(code);
  if (known) known.d += round(d);
  else byCode.set(code, { c: code, n: countries.getName(code, 'en') || feature.properties.name, d: round(d) });
}
const shapes = [...byCode.values()].sort((a, b) => a.c.localeCompare(b.c));

// Too small for the 1:110m outlines, and places this agency's visitors live or travel to: a dot at the capital
// (longitude, latitude). Add more here.
const SMALL = { SG: [103.82, 1.35], MV: [73.51, 4.18], BH: [50.58, 26.22], HK: [114.17, 22.32], MU: [57.5, -20.16], BN: [114.94, 4.94], MT: [14.51, 35.9] };
const have = new Set(shapes.map((s) => s.c));
const points = Object.entries(SMALL)
  .filter(([code]) => !have.has(code))
  .map(([code, at]) => {
    const [x, y] = projection(at);
    return { c: code, n: countries.getName(code, 'en'), x: Math.round(x * 10) / 10, y: Math.round(y * 10) / 10 };
  });

fs.writeFileSync(out, JSON.stringify({ width: WIDTH, height: HEIGHT, source: 'Natural Earth 1:110m (public domain) via world-atlas 2.0.2', countries: shapes, points }));
console.log(`${shapes.length} countries and ${points.length} dots → ${out} (${fs.statSync(out).size} bytes)`);
