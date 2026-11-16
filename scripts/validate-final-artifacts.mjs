#!/usr/bin/env node
/**
 * 构建链最后一道断言：验「最终产物」，不是「中间产物」。
 *
 * 在所有构建后处理结束后检查 XML、全部语种的 sitemap 和核心页面收录资格。
 * 2026-11-16 按站主要求恢复全部 17 语种，保留页面原有的独立 noindex。
 *
 * 用法：node scripts/validate-final-artifacts.mjs dist
 */
import fs from 'node:fs';
import path from 'node:path';

const dist = path.resolve(process.argv[2] || 'dist');
const KEEP = ['en','it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg'];
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
  if (entries.length !== KEEP.length * 2) fail(`sitemap 索引应为 ${KEEP.length * 2} 条（17 语种 × core/products），实际 ${entries.length}`);
  for (const lang of KEEP) {
    for (const kind of ['core', 'products']) {
      const expected = `/${lang}/sitemap-${kind}.xml`;
      if (!entries.some(u => new URL(u).pathname === expected)) fail(`sitemap 缺少语种文件：${expected}`);
    }
  }
  for (const u of entries) {
    const p = u.replace(/^https?:\/\/[^/]+/, '');
    if (!fs.existsSync(path.join(dist, p.replace(/^\//, '')))) fail(`sitemap 引用了不存在的文件：${p}`);
  }
}

// 2) 全部语种的首页、产品列表必须可索引；其他页面保留各自的收录策略。
for (const rel of KEEP.flatMap(lang => {
  const prefix = lang === 'en' ? '' : `${lang}/`;
  return [`${prefix}index.html`, `${prefix}product/index.html`];
})) {
  const f = path.join(dist, rel);
  if (!fs.existsSync(f)) { fail(`缺少保留页面：${rel}`); continue; }
  if (fs.readFileSync(f, 'utf8').includes('content="noindex')) fail(`${rel} 不该有 noindex`);
}

// Retained sections must remain reachable in every language, including the
// collection placeholder linked by its own language selector.
for (const lang of KEEP) {
  const prefix = lang === 'en' ? '' : `${lang}/`;
  for (const section of ['collection', 'blog', 'eyewear']) {
    const rel = `${prefix}${section}/index.html`;
    if (!fs.existsSync(path.join(dist, rel))) fail(`缺少保留栏目：${rel}`);
  }
}

if (errors.length) {
  console.error('最终产物校验失败：');
  for (const e of errors.slice(0, 20)) console.error('  ✗ ' + e);
  process.exit(1);
}
console.log('最终产物校验通过：sitemap XML 合法、34 个子 sitemap、17 语种核心页面可索引');
