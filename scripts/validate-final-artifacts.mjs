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

  const hasBlogs = KEEP.some((lang) => fs.existsSync(path.join(dist, lang, 'sitemap-blogs.xml')));
  const expectedKinds = hasBlogs ? ['core', 'products', 'blogs'] : ['core', 'products'];
  const entries = [...xml.matchAll(/<sitemap>\s*<loc>([^<]+)<\/loc>/g)].map(x => x[1].trim());
  if (entries.length !== KEEP.length * expectedKinds.length) fail(`sitemap 索引应为 ${KEEP.length * expectedKinds.length} 条（17 语种 × ${expectedKinds.join('/')}），实际 ${entries.length}`);
  for (const lang of KEEP) {
    for (const kind of expectedKinds) {
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

// Full documents expose one main landmark and one keyboard-focusable skip
// target. HTML fragments and Astro redirect stubs are not full documents.
let landmarkPages = 0;
function verifyLandmarks(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (entry.isDirectory()) { verifyLandmarks(file); continue; }
    if (!entry.name.endsWith('.html')) continue;
    const html = fs.readFileSync(file, 'utf8');
    if (!/<html\b/i.test(html)) {
      const rel = path.relative(dist, file).replaceAll('\\', '/');
      if (/^(?:[a-z]{2}\/)?product\/\d+\/index\.html$/.test(rel)) {
        fail(`${rel} 是越界分页跳转页，不应生成`);
      }
      const refresh = html.match(/<meta\s+http-equiv="refresh"\s+content="\d+;url=([^"]+)"/i);
      if (refresh) {
        const target = new URL(refresh[1], 'https://kssmi.com');
        if (target.origin === 'https://kssmi.com') {
          const targetPath = path.resolve(dist, decodeURIComponent(target.pathname).replace(/^\//, ''), 'index.html');
          if (targetPath === file) fail(`${rel} 发生自跳转`);
          if (!fs.existsSync(targetPath)) fail(`${rel} 的跳转目标不存在：${target.pathname}`);
        }
      }
      continue;
    }
    landmarkPages++;
    const mains = [...html.matchAll(/<main\b[^>]*>/gi)].map(m => m[0]);
    const targets = [...html.matchAll(/\sid=(['"])main-content\1/g)];
    const rel = path.relative(dist, file);
    if (mains.length !== 1) fail(`${rel} 应只有一个 main，实际 ${mains.length}`);
    if (targets.length !== 1) fail(`${rel} 应只有一个 main-content 跳转目标，实际 ${targets.length}`);
    if (mains.length === 1 && (!/\sid="main-content"/.test(mains[0]) || !/\stabindex="-1"/.test(mains[0]))) {
      fail(`${rel} 的正文 main 必须是可聚焦的跳转目标`);
    }
  }
}
verifyLandmarks(dist);

if (errors.length) {
  console.error('最终产物校验失败：');
  for (const e of errors.slice(0, 20)) console.error('  ✗ ' + e);
  process.exit(1);
}
console.log('最终产物校验通过：sitemap XML 合法、34 个子 sitemap、17 语种核心页面可索引');
console.log(`正文结构校验通过：${landmarkPages} 个完整页面的 main 与跳转目标唯一`);
