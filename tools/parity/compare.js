#!/usr/bin/env node
/**
 * Scout parity check, step 2: compare two captures (Site Standard rule 20).
 *
 *   node tools/parity/compare.js before/ after/ [--pixels 0.5]
 *
 * Reports, page by page: addresses that answer differently (status, final
 * address), pages only one capture has, head tags that changed (title,
 * description, canonical, robots, Open Graph, Twitter, hreflang), JSON-LD that
 * changed (compared as data, so key order and spacing do not count), the h1,
 * and how many pixels differ at each width. Writes after/parity-report.md.
 * Exits 1 when anything differs beyond --pixels (percent, default 0.5), so a
 * conversion step can stop on it. Every difference must be explained and
 * approved before a site counts as converted.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const [a, b] = args.filter(x => !x.startsWith('--') && !/^\d/.test(x));
const pxIdx = args.indexOf('--pixels');
const pxLimit = pxIdx === -1 ? 0.5 : parseFloat(args[pxIdx + 1]);
if (!a || !b) { console.error('Usage: node tools/parity/compare.js <before dir> <after dir> [--pixels 0.5]'); process.exit(2); }
const load = d => JSON.parse(fs.readFileSync(path.join(d, 'pages.json'), 'utf8'));
const A = load(a), B = load(b);
const slug = p => (p.replace(/^\/+|\/+$/g, '').replace(/[^a-z0-9]+/gi, '-') || 'home').slice(0, 80);
// Addresses carry the site's host; compare paths so a staging copy can be checked against live.
const hosts = [A.base, B.base].map(u => new URL(u).origin);
const norm = v => {
  if (typeof v === 'string') { let s = v; for (const h of hosts) s = s.split(h).join('{site}'); return s.replace(/\?ver=[\w.-]+/g, ''); }
  if (Array.isArray(v)) return v.map(norm);
  if (v && typeof v === 'object') return Object.fromEntries(Object.keys(v).sort().map(k => [k, norm(v[k])]));
  return v;
};
const stable = v => JSON.stringify(norm(v));
// JSON-LD @graph order is not meaning: sort nodes by @id or @type.
const ld = list => stable((list || []).flatMap(x => x && x['@graph'] ? x['@graph'] : [x]).map(norm).sort((x, y) => String(x['@id'] || x['@type']).localeCompare(String(y['@id'] || y['@type']))));

(async () => {
  const rows = [];
  let problems = 0;
  const all = [...new Set([...Object.keys(A.pages), ...Object.keys(B.pages)])];
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.setContent('<canvas id="c"></canvas>');
  const diffPct = async (f1, f2) => page.evaluate(async ([d1, d2]) => {
    const img = src => new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = src; });
    const [x, y] = await Promise.all([img(d1), img(d2)]);
    const w = Math.max(x.width, y.width), h = Math.max(x.height, y.height);
    const c = document.getElementById('c'); c.width = w; c.height = h; const g = c.getContext('2d', { willReadFrequently: true });
    g.clearRect(0, 0, w, h); g.drawImage(x, 0, 0); const p = g.getImageData(0, 0, w, h).data;
    g.clearRect(0, 0, w, h); g.drawImage(y, 0, 0); const q = g.getImageData(0, 0, w, h).data;
    let n = 0; for (let i = 0; i < p.length; i += 4) { if (Math.abs(p[i] - q[i]) + Math.abs(p[i + 1] - q[i + 1]) + Math.abs(p[i + 2] - q[i + 2]) + Math.abs(p[i + 3] - q[i + 3]) > 24) n++; }
    return { pct: (100 * n) / (w * h), sizes: [x.width + 'x' + x.height, y.width + 'x' + y.height] };
  }, [f1, f2].map(f => 'data:image/png;base64,' + fs.readFileSync(f).toString('base64')));

  for (const p of all) {
    const x = A.pages[p], y = B.pages[p];
    const diffs = [];
    if (!x || !y) { diffs.push(!x ? 'only in the after capture' : 'missing from the after capture'); }
    else {
      if (x.status !== y.status) diffs.push(`status ${x.status} → ${y.status}`);
      if (norm(x.final) !== norm(y.final)) diffs.push(`final address ${x.final} → ${y.final}`);
      if (x.title !== y.title) diffs.push(`title "${x.title}" → "${y.title}"`);
      if (norm(x.canonical) !== norm(y.canonical)) diffs.push(`canonical ${x.canonical || '(none)'} → ${y.canonical || '(none)'}`);
      const keys = [...new Set([...Object.keys(x.meta || {}), ...Object.keys(y.meta || {})])].sort();
      for (const k of keys) if (norm((x.meta || {})[k]) !== norm((y.meta || {})[k])) diffs.push(`meta ${k} "${(x.meta || {})[k] ?? '(none)'}" → "${(y.meta || {})[k] ?? '(none)'}"`);
      if (stable(x.hreflang) !== stable(y.hreflang)) diffs.push('hreflang links changed');
      if (ld(x.jsonld) !== ld(y.jsonld)) diffs.push(`schema (JSON-LD) changed: ${(x.jsonld || []).length} block(s) → ${(y.jsonld || []).length}`);
      if (stable(x.h1) !== stable(y.h1)) diffs.push(`h1 ${JSON.stringify(x.h1)} → ${JSON.stringify(y.h1)}`);
      for (const w of (A.widths || []).filter(w => (B.widths || []).includes(w))) {
        const f1 = path.join(a, 'shots', `${slug(p)}-${w}.png`), f2 = path.join(b, 'shots', `${slug(p)}-${w}.png`);
        if (!fs.existsSync(f1) || !fs.existsSync(f2)) continue;
        const r = await diffPct(f1, f2);
        if (r.pct > pxLimit) diffs.push(`${w}px: ${r.pct.toFixed(2)}% of pixels differ (${r.sizes[0]} → ${r.sizes[1]})`);
      }
    }
    if (diffs.length) problems++;
    rows.push({ p, diffs });
  }
  await browser.close();
  const md = [`# Parity report`, ``, `Before: ${A.base} (${A.captured})  `, `After: ${B.base} (${B.captured})  `, `Pixel limit: ${pxLimit}% per width`, ``,
    problems ? `**${problems} of ${rows.length} pages differ.** Each difference needs an explanation and approval before the site counts as converted (Site Standard rule 20).` : `**All ${rows.length} pages match.**`, ``,
    ...rows.filter(r => r.diffs.length).flatMap(r => [`## ${r.p}`, ...r.diffs.map(d => `- ${d}`), ``]),
    `## Pages that match`, ...rows.filter(r => !r.diffs.length).map(r => `- ${r.p}`), ``].join('\n');
  fs.writeFileSync(path.join(b, 'parity-report.md'), md);
  console.log(md.split('\n').slice(0, 40).join('\n'));
  process.exit(problems ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
