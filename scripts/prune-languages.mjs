#!/usr/bin/env node
/**
 * 语言收录策略：2026-11-16 按站主要求撤销整批语种收缩，保留全部 17 语种。
 * 正常构建重新生成 HTML、sitemap 和 hreflang；不移除页面原有的独立 noindex。
 *
 * 用法：node scripts/prune-languages.mjs dist [--apply]
 * 默认 dry-run，只统计。
 */
import fs from 'node:fs';
import path from 'node:path';

const ALL = ['en','it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg'];
const KEEP = new Set(ALL);
const PRUNED = ALL.filter(l => !KEEP.has(l));

const dist = path.resolve(process.argv[2] || 'dist');
const apply = process.argv.includes('--apply');
const META = '<meta name="robots" content="noindex, follow">';

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.html')) out.push(p);
  }
  return out;
}

let touched = 0;
for (const lang of PRUNED) {
  const dir = path.join(dist, lang);
  if (!fs.existsSync(dir)) continue;
  for (const f of walk(dir)) {
    const html = fs.readFileSync(f, 'utf8');
    if (html.includes('noindex')) continue;
    const next = html.replace('</head>', META + '</head>');
    if (next === html) continue;
    touched++;
    if (apply) fs.writeFileSync(f, next);
  }
}

// sitemap：删掉被收缩语种的子 sitemap 与索引项
let smTouched = 0;
for (const lang of PRUNED) {
  for (const name of ['sitemap-core.xml', 'sitemap-products.xml']) {
    const f = path.join(dist, lang, name);
    if (!fs.existsSync(f)) continue;
    smTouched++;
    if (apply) fs.rmSync(f);
  }
}
const idx = path.join(dist, 'sitemap.xml');
if (fs.existsSync(idx)) {
  const xml = fs.readFileSync(idx, 'utf8');
  const next = xml.replace(/<sitemap>\s*[\s\S]*?<\/sitemap>\s*/g, block =>
    PRUNED.some(l => block.includes('/' + l + '/sitemap-')) ? '' : block);
  if (next !== xml) {
    smTouched++;
    if (apply) {
      fs.writeFileSync(idx, next);
    }
  }
}

// 顺手摘掉指向被收缩语种的 hreflang（避免 hreflang → noindex 页）
let hreflangTouched = 0;
if (apply) {
  for (const f of walk(dist)) {
    const html = fs.readFileSync(f, 'utf8');
    let next = html;
    for (const lang of PRUNED) {
      next = next.replace(new RegExp('<link[^>]+hreflang="' + lang + '"[^>]*>', 'g'), '');
    }
    if (next !== html) {
      hreflangTouched++;
      fs.writeFileSync(f, next);
    }
  }
}

console.log((apply ? '' : '[dry] ') + 'hreflang 清理 ' + hreflangTouched + ' 个文件');
console.log((apply ? '' : '[dry] ') + '收缩语种 ' + PRUNED.join(',') + ' → 页面 ' + touched + ' 个，sitemap 条目 ' + smTouched + ' 处');
