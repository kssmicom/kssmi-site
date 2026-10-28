import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import { spawnSync } from 'node:child_process';

export const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const temporaryRoot = path.resolve(projectRoot, '..', 'tep');

export function within(root, target) {
  const relative = path.relative(root, target);
  return relative !== '' && relative !== '..' && !relative.startsWith('..' + path.sep) && !path.isAbsolute(relative);
}
export function assertSafePath(root, target) {
  if (!within(root, target)) throw new Error('Path is outside the managed directory: ' + target);
  for (let p = target; ; p = path.dirname(p)) {
    if (fs.existsSync(p) && fs.lstatSync(p).isSymbolicLink()) throw new Error('Links and junctions are not allowed: ' + p);
    if (p === root) break;
  }
  return target;
}
export function ensureTemporaryRoot() {
  if (fs.existsSync(temporaryRoot) && fs.lstatSync(temporaryRoot).isSymbolicLink()) throw new Error('tep must not be a link or junction.');
  fs.mkdirSync(temporaryRoot, { recursive: true });
  return temporaryRoot;
}
export function writeJson(file, data) {
  const next = file + '.new-' + process.pid;
  fs.writeFileSync(next, JSON.stringify(data, null, 2));
  fs.renameSync(next, file);
}
export function createTask(kind, source) {
  ensureTemporaryRoot();
  const task = assertSafePath(temporaryRoot, path.join(temporaryRoot, kind + '-' + new Date().toISOString().replace(/[:.]/g, '-') + '-' + randomUUID().slice(0, 8)));
  fs.mkdirSync(task);
  for (const folder of ['tmp', 'npm-cache', 'cache']) fs.mkdirSync(path.join(task, folder));
  writeJson(path.join(task, '.kssmi-local-task.json'), { schema: 1, source, kind, state: 'preparing', createdAt: new Date().toISOString() });
  return task;
}
export function taskEnvironment(task) {
  return { ...process.env, TEMP: path.join(task, 'tmp'), TMP: path.join(task, 'tmp'), TMPDIR: path.join(task, 'tmp'), npm_config_cache: path.join(task, 'npm-cache'), ASTRO_TELEMETRY_DISABLED: '1' };
}
export function assertNoLinks(root) {
  for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
    const file = path.join(root, entry.name);
    if (entry.isSymbolicLink()) throw new Error('A link or junction was found: ' + file);
    if (entry.isDirectory()) assertNoLinks(file);
  }
}

export const standardCacheDirectories = ['.astro', 'node_modules/.astro', 'node_modules/.vite', 'node_modules/.vite-temp'];
export function archiveProjectCaches(source, task) {
  const archive = assertSafePath(temporaryRoot, path.join(task, 'previous-cache'));
  fs.mkdirSync(archive, { recursive: true });
  for (const relative of standardCacheDirectories) {
    const from = assertSafePath(source, path.join(source, relative));
    if (!fs.existsSync(from)) continue;
    if (!fs.lstatSync(from).isDirectory()) throw new Error('Expected a generated cache directory: ' + from);
    assertNoLinks(from);
    const tracked = spawnSync('git', ['-C', source, 'ls-files', '--', relative], { encoding: 'utf8', windowsHide: true, env: { ...process.env, GIT_OPTIONAL_LOCKS: '0' } });
    if (tracked.error || tracked.status !== 0 || tracked.stdout.trim()) throw new Error('Cache cleanup requires an untracked, verified project cache: ' + relative);
    const to = assertSafePath(archive, path.join(archive, relative.replaceAll('/', '-')));
    fs.renameSync(from, to);
  }
}
