#!/usr/bin/env node
/**
 * Scout parity check, step 1: capture a site (Site Standard rule 20).
 *
 *   node tools/parity/capture.js https://scoutraleigh.com before/            # pages from the sitemap
 *   node tools/parity/capture.js https://site.test after/ --pages pages.txt  # one path per line
 *   node tools/parity/capture.js https://site.test after/ --limit 40 --no-shots
 *
 * For every page: the status and final address, the head tags (title,
 * description, canonical, robots, Open Graph, Twitter), every JSON-LD block,
 * the h1, and full-page screenshots at 390, 768 and 1280 px. Writes
 * <out>/pages.json and <out>/shots/<page>-<width>.png. Compare two captures
 * with compare.js. Read-only: it only loads pages, like a visitor.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const flag = (name, dflt) => { const i = args.indexOf(name); return i === -1 ? dflt : (args[i + 1] && !args[i + 1].startsWith('--') ? args[i + 1] : true); };
const positional = args.filter((a, i) => !a.startsWith('--') && !(i > 0 && args[i - 1].startsWith('--') && ['--pages', '--limit', '--widths'].includes(args[i - 1])));
const base = (positional[0] || '').replace(/\/+$/, '');
const out = positional[1];
if (!/^https?:\/\//.test(base) || !out) {
  console.error('Usage: node tools/parity/capture.js <site address> <out dir> [--pages file] [--limit N] [--widths 390,768,1280] [--no-shots]');
  process.exit(2);
}
const limit = parseInt(flag('--limit', '60'), 10);
const widths = String(flag('--widths', '390,768,1280')).split(',').map(Number).filter(Boolean);
const shots = !args.includes('--no-shots');
const slug = p => (p.replace(/^\/+|\/+$/g, '').replace(/[^a-z0-9]+/gi, '-') || 'home').slice(0, 80);

async function sitemapPaths(page) {
  // Read the sitemap index, then its child sitemaps with pages first (a conversion
  // touches templates, so every page matters more than every post), then the rest.
  const get = async url => { try { const r = await page.request.get(url, { timeout: 20000 }); return r.ok() ? await r.text() : ''; } catch (e) { return ''; } };
  const locs = body => [...body.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/g)].map(m => m[1].replace(/&amp;/g, '&'));
  let index = '';
  for (const u of [base + '/wp-sitemap.xml', base + '/sitemap_index.xml', base + '/sitemap.xml']) { index = await get(u); if (index) break; }
  let children = locs(index).filter(l => /\.xml(\?|$)/.test(l));
  const direct = locs(index).filter(l => !/\.xml(\?|$)/.test(l));
  const rank = l => (/page/i.test(l) ? 0 : /post(?!s-)/i.test(l) ? 2 : 1);
  children = children.sort((x, y) => rank(x) - rank(y));
  const paths = direct.map(l => { try { const u = new URL(l); return u.pathname + u.search; } catch (e) { return ''; } }).filter(Boolean);
  for (const child of children) {
    if (paths.length >= limit) break;
    for (const l of locs(await get(child))) {
      if (paths.length >= limit) break;
      try { const u = new URL(l); paths.push(u.pathname + u.search); } catch (e) { /* skip */ }
    }
  }
  return [...new Set(paths.length ? paths : ['/'])];
}

(async () => {
  fs.mkdirSync(path.join(out, 'shots'), { recursive: true });
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1, ignoreHTTPSErrors: true });
  const page = await context.newPage();
  let paths;
  const list = flag('--pages', null);
  if (list && list !== true) {
    paths = fs.readFileSync(list, 'utf8').split(/\r?\n/).map(s => s.trim()).filter(s => s && !s.startsWith('#')).map(s => s.startsWith('http') ? new URL(s).pathname : s);
  } else {
    paths = await sitemapPaths(page);
  }
  if (!paths.includes('/')) paths.unshift('/');
  paths = paths.slice(0, limit);
  const pages = {};
  for (const p of paths) {
    const rec = { path: p };
    try {
      const resp = await page.goto(base + p, { waitUntil: 'load', timeout: 45000 });
      await page.waitForTimeout(400);
      rec.status = resp ? resp.status() : 0;
      rec.final = new URL(page.url()).pathname + new URL(page.url()).search;
      Object.assign(rec, await page.evaluate(() => {
        const meta = {};
        for (const m of document.querySelectorAll('head meta[name], head meta[property]')) {
          const k = (m.getAttribute('name') || m.getAttribute('property') || '').toLowerCase();
          if (/^(description|robots|og:|twitter:|article:)/.test(k)) meta[k] = (m.getAttribute('content') || '').trim();
        }
        const link = rel => { const l = document.querySelector(`head link[rel="${rel}"]`); return l ? l.getAttribute('href') : ''; };
        const ld = [];
        for (const s of document.querySelectorAll('script[type="application/ld+json"]')) {
          try { ld.push(JSON.parse(s.textContent)); } catch (e) { ld.push({ unparsable: s.textContent.slice(0, 200) }); }
        }
        const h1 = [...document.querySelectorAll('h1')].map(h => h.textContent.replace(/\s+/g, ' ').trim());
        return { title: document.title.trim(), meta, canonical: link('canonical'), hreflang: [...document.querySelectorAll('head link[rel="alternate"][hreflang]')].map(l => l.getAttribute('hreflang') + ' ' + l.getAttribute('href')), jsonld: ld, h1 };
      }));
      if (shots) {
        for (const w of widths) {
          await page.setViewportSize({ width: w, height: 900 });
          await page.waitForTimeout(300);
          await page.screenshot({ path: path.join(out, 'shots', `${slug(p)}-${w}.png`), fullPage: true, animations: 'disabled', caret: 'hide' });
        }
        await page.setViewportSize({ width: 1280, height: 900 });
      }
    } catch (e) {
      rec.error = String(e.message || e).split('\n')[0];
    }
    pages[p] = rec;
    console.log(`${rec.status || 'ERR'} ${p}${rec.final && rec.final !== p ? ' -> ' + rec.final : ''}${rec.error ? ' (' + rec.error + ')' : ''}`);
  }
  fs.writeFileSync(path.join(out, 'pages.json'), JSON.stringify({ base, captured: new Date().toISOString(), widths: shots ? widths : [], pages }, null, 2));
  await browser.close();
  console.log(`Captured ${Object.keys(pages).length} pages into ${out}`);
})().catch(e => { console.error(e); process.exit(1); });
