#!/usr/bin/env node
/**
 * 构建链最后一道断言：验「最终产物」，不是「中间产物」。
 *
 * 背景：prune-languages.mjs 会改写 sitemap.xml 和 6,000+ 个 HTML。之前所有
 * validate-* 都在它之前跑，导致改坏的产物没人验（2026-11-15 的 sitemap
 * 闭合标签丢失事故）。本脚本必须放在构建链最后一步。
 *
 * 用法：node scripts/validate-final-artifacts.mjs dist
 */
import fs from 'node:fs';
import path from 'node:path';

const dist = path.resolve(process.argv[2] || 'dist');
const KEEP = ['en', 'ru', 'de'];
const PRUNED = ['it', 'es', 'fr', 'pt', 'ja', 'tr', 'ar', 'ko', 'zh', 'hi', 'vi', 'jv', 'ms', 'tg'];
const errors = [];

function fail(msg) { errors.push(msg); }

// 1) sitemap.xml 必须是合法 XML（栈式配对）
const smPath = path.join(dist, 'sitemap.xml');
if (!fs.existsSync(smPath)) {
  fail('缺少 dist/sitemap.xml');
} else {
  const xml = fs.readFileSync(smPath, 'utf8');
  const stack = [];
  const tagRe = /<(\/?)([A-Za-z][\w:.-]*)([^>]*?)(\/?)>/g;
  let m;
  while ((m = tagRe.exec(xml))) {
    const [, close, name, attrs, selfClose] = m;
    if (attrs.startsWith('?') || attrs.startsWith('!')) continue;
    if (selfClose) continue;
    if (close) {
      const top = stack.pop();
      if (top !== name) fail(`sitemap.xml 标签不配对：</${name}> 对上 <${top}>`);
    } else {
      stack.push(name);
    }
  }
  if (stack.length) fail(`sitemap.xml 有未闭合标签：${stack.join(',')}`);

  const entries = [...xml.matchAll(/<sitemap>\s*<loc>([^<]+)<\/loc>/g)].map(x => x[1].trim());
  if (entries.length !== 6) fail(`sitemap 索引应为 6 条（en/ru/de × core/products），实际 ${entries.length}`);
  for (const u of entries) {
    const p = u.replace(/^https?:\/\/[^/]+/, '');
    if (!fs.existsSync(path.join(dist, p.replace(/^\//, '')))) fail(`sitemap 引用了不存在的文件：${p}`);
    for (const lang of PRUNED) if (p.startsWith(`/${lang}/`)) fail(`被收缩语种仍在 sitemap：${p}`);
  }
}

// 2) 抽样：收缩语种必须 noindex，保留语种必须不 noindex
function sampleDir(dir, name) {
  const out = [];
  const walk = (d) => {
    if (!fs.existsSync(d)) return;
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      if (out.length >= 20) return;
      const p = path.join(d, e.name);
      if (e.isDirectory()) walk(p);
      else if (e.name === 'index.html') out.push(p);
    }
  };
  walk(dir);
  if (!out.length) fail(`抽样目录为空：${name}`);
  return out;
}

for (const lang of ['zh', 'ja', 'it']) {
  for (const f of sampleDir(path.join(dist, lang), lang)) {
    if (!fs.readFileSync(f, 'utf8').includes('noindex')) fail(`${f} 缺少 noindex`);
  }
}
// 只查几个确定应当可索引的页面（站内本身就有 by design 的 noindex 页，如 about-us）
for (const rel of ['index.html', 'product/index.html', 'ru/index.html', 'de/index.html']) {
  const f = path.join(dist, rel);
  if (!fs.existsSync(f)) { fail(`缺少保留页面：${rel}`); continue; }
  if (fs.readFileSync(f, 'utf8').includes('content="noindex')) fail(`${rel} 不该有 noindex`);
}

if (errors.length) {
  console.error('最终产物校验失败：');
  for (const e of errors.slice(0, 20)) console.error('  ✗ ' + e);
  process.exit(1);
}
console.log('最终产物校验通过：sitemap XML 合法、6 个子 sitemap、语种 noindex 抽样一致');