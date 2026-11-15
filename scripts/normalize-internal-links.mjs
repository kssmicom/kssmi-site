#!/usr/bin/env node
/**
 * 把 dist 里所有站内 <a href> 补上尾斜杠。
 *
 * 背景：站点由 Astro 以 build.format='directory' 输出，真实地址是 /product/，
 * 但模板里写的是 /product。于是每条站内链接都要吃一次 301（GSC 里 1,923 个
 * "Page with redirect"）。构建后统一补斜杠，零源码改动、可重复执行、幂等。
 *
 * 用法：node scripts/normalize-internal-links.mjs dist [--dry]
 */
import fs from 'node:fs';
import path from 'node:path';

const dist = path.resolve(process.argv[2] || 'dist');
const dry = process.argv.includes('--dry');
if (!fs.existsSync(dist)) { console.error('dist 不存在:', dist); process.exit(1); }

const HREF = /href="(\/[^"]*)"/g;
const SKIP_EXT = /\.(xml|json|svg|png|jpe?g|webp|avif|css|js|ico|txt|pdf|zip|webmanifest)$/i;

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.html')) out.push(p);
  }
  return out;
}

const existsCache = new Map();
function targetExists(p) {
  if (existsCache.has(p)) return existsCache.get(p);
  const ok = fs.existsSync(path.join(dist, p.replace(/^\//, ''), 'index.html'));
  existsCache.set(p, ok);
  return ok;
}

let files = 0, changedFiles = 0, rewrites = 0, skipped = 0;
for (const file of walk(dist)) {
  files++;
  const src = fs.readFileSync(file, 'utf8');
  let hit = 0;
  const out = src.replace(HREF, (m, href) => {
    if (!href.startsWith('/') || href.startsWith('//')) return m;
    const q = href.indexOf('?'), h = href.indexOf('#');
    const cut = q === -1 ? h : (h === -1 ? q : Math.min(q, h));
    const p = cut === -1 ? href : href.slice(0, cut);
    const suffix = cut === -1 ? '' : href.slice(cut);
    if (p.endsWith('/') || SKIP_EXT.test(p)) return m;
    if (!targetExists(p)) { skipped++; return m; }
    hit++;
    return 'href="' + p + '/' + suffix + '"';
  });
  if (hit) {
    changedFiles++;
    rewrites += hit;
    if (!dry) fs.writeFileSync(file, out);
  }
}

console.log((dry ? '[dry] ' : '') + 'HTML ' + files + ' 个，改动 ' + changedFiles + ' 个文件，补斜杠 ' + rewrites + ' 处，跳过 ' + skipped + ' 处（目标不存在）');