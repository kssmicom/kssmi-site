import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createInterface } from 'node:readline/promises';
import { pathToFileURL } from 'node:url';
import { projectRoot, createTask, taskEnvironment } from './local-tools.mjs';

const expectedOrigin = 'https://github.com/kssmicom/kssmi-site.git';
export function gitCommand(root, args, allowed = [0]) {
  const env = { ...process.env, GIT_OPTIONAL_LOCKS: '0' };
  for (const key of ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE']) delete env[key];
  const result = spawnSync('git', ['-C', root, ...args], { encoding: 'utf8', windowsHide: true, env, maxBuffer: 32 * 1024 * 1024 });
  if (result.error || !allowed.includes(result.status)) throw new Error('Git failed (' + args[0] + '): ' + (result.error?.message || result.stderr.trim()));
  return result.stdout;
}
function canonicalRemote(value) {
  return value.trim().replace(/^git@github\.com:/i, 'https://github.com/').replace(/\/$/, '').replace(/\.git$/i, '').toLowerCase();
}
export function parseStatus(text) {
  const fields = text.split('\0');
  const result = [];
  for (let i = 0; i < fields.length && fields[i]; i++) {
    const code = fields[i].slice(0, 2);
    const file = fields[i].slice(3);
    const paths = [file];
    if (/[RC]/.test(code)) paths.push(fields[++i]);
    result.push({ code, paths });
  }
  return result;
}
export function forbiddenPath(file) {
  const parts = file.replaceAll('\\', '/').split('/');
  const top = parts[0].toLowerCase();
  return parts.includes('..') || path.isAbsolute(file) ||
    ['tep', 'dist', '.astro', 'node_modules', '.git', '.agent', '.gstack', 'email_data', 'rate_limit', 'test-results', 'playwright-report'].includes(top) ||
    parts.some(p => /^\.env($|\.)/i.test(p) || /^(private_config\.php|short-links-users\.txt|\.email_|\.admin_)/i.test(p)) ||
    /\.(log|tmp|pem|key|pfx|p12)$/i.test(file) || /^build-output\.txt$/i.test(file);
}
function digest(root, paths) {
  return paths.map(file => {
    const target = path.join(root, file);
    if (!fs.existsSync(target)) return file + ':deleted';
    const stat = fs.lstatSync(target);
    if (!stat.isFile() || stat.isSymbolicLink()) throw new Error('Only regular files may be selected: ' + file);
    return file + ':' + createHash('sha256').update(fs.readFileSync(target)).digest('hex');
  }).join('\n');
}
function literal(paths) { return paths.map(file => ':(literal)' + file); }
export function isGeneratedLogDeletion(change) {
  return change.code.trim() === 'D' && change.paths.length === 1 && ['build-output.txt', '_build.log'].includes(change.paths[0]);
}
const previewDependencies = ['scripts/local-dev.mjs', 'scripts/local-tools.mjs', 'scripts/local-php.mjs'];
const dependencies = {
  'Build-Site.bat': ['scripts/build-local.ps1'],
  'Cleanup-Logs.bat': ['scripts/cleanup-local.ps1'],
  'Local-Start.vbs': previewDependencies,
  'Local-Start.bat': previewDependencies,
  'Local-Start-Fresh.bat': previewDependencies,
  'Local-Stop.bat': previewDependencies,
  'start-servers.bat': previewDependencies,
  'push-main.bat': ['scripts/push-main.mjs', 'scripts/local-tools.mjs'],
  'scripts/local-dev.mjs': ['scripts/local-tools.mjs', 'scripts/local-php.mjs'],
  'scripts/local-php.mjs': ['scripts/local-tools.mjs'],
  'scripts/push-main.mjs': ['scripts/local-tools.mjs'],
  'tests/local-tooling.test.mjs': ['scripts/push-main.mjs', ...previewDependencies]
};
function checkDependencies(root, selected, changes, invoke) {
  const chosen = new Set(selected);
  const changed = new Set(changes.flatMap(change => change.paths));
  const queue = [...selected];
  const visited = new Set();
  for (const file of queue) {
    if (visited.has(file) || !fs.existsSync(path.join(root, file))) continue;
    visited.add(file);
    for (const dependency of dependencies[file] || []) {
      const target = path.join(root, dependency);
      if (!fs.existsSync(target) || !fs.lstatSync(target).isFile()) throw new Error('Required helper is missing: ' + dependency + '. Nothing was staged.');
      if (!chosen.has(dependency) && (changed.has(dependency) || !invoke(['ls-tree', '--name-only', 'HEAD', '--', dependency]).trim())) {
        throw new Error('Include the required new/changed helper in the selection: ' + dependency + '. Nothing was staged.');
      }
      queue.push(dependency);
    }
  }
  for (const file of selected.filter(name => !fs.existsSync(path.join(root, name)))) {
    for (const [caller, required] of Object.entries(dependencies)) {
      if (required.includes(file) && fs.existsSync(path.join(root, caller))) throw new Error('Cannot remove ' + file + ' while its launcher/helper remains: ' + caller);
    }
  }
}

export async function runPush({ root = projectRoot, expected = expectedOrigin, ask, log = console.log, git = gitCommand } = {}) {
  root = fs.realpathSync(root);
  const invoke = (args, allowed) => git(root, args, allowed);
  function guards() {
    const actual = fs.realpathSync(invoke(['rev-parse', '--show-toplevel']).trim());
    if (actual.toLowerCase() !== root.toLowerCase()) throw new Error('Run this launcher from the website repository root.');
    if (invoke(['symbolic-ref', '--quiet', '--short', 'HEAD']).trim() !== 'main') throw new Error('Current branch must be main. No branch was switched.');
    for (const args of [['remote', 'get-url', '--all', 'origin'], ['remote', 'get-url', '--push', '--all', 'origin']]) {
      const urls = invoke(args).trim().split(/\r?\n/);
      if (urls.length !== 1 || canonicalRemote(urls[0]) !== canonicalRemote(expected)) throw new Error('origin does not match the expected website repository.');
    }
    const gitDir = path.resolve(root, invoke(['rev-parse', '--git-dir']).trim());
    if (['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply'].some(name => fs.existsSync(path.join(gitDir, name)))) throw new Error('An unfinished Git operation exists. Nothing was merged or reset.');
    if (invoke(['diff', '--cached', '--name-only', '-z']).length) throw new Error('The Git index already contains staged changes. Review those separately first.');
  }
  const head = () => invoke(['rev-parse', 'HEAD']).trim();
  const remoteHead = () => {
    const rows = invoke(['ls-remote', '--heads', 'origin', 'refs/heads/main']).trim().split(/\r?\n/);
    if (rows.length !== 1 || !/^[0-9a-f]{40}\s+refs\/heads\/main$/.test(rows[0])) throw new Error('Could not verify origin/main.');
    return rows[0].split(/\s+/)[0];
  };
  guards();
  const originalHead = head();
  const originalRemote = remoteHead();
  function beforePush(commit) {
    if (invoke(['symbolic-ref', '--quiet', '--short', 'HEAD']).trim() !== 'main' || head() !== commit) throw new Error('Branch or HEAD changed. Nothing was pushed.');
    for (const args of [['remote', 'get-url', '--all', 'origin'], ['remote', 'get-url', '--push', '--all', 'origin']]) {
      const urls = invoke(args).trim().split(/\r?\n/);
      if (urls.length !== 1 || canonicalRemote(urls[0]) !== canonicalRemote(expected)) throw new Error('origin changed. Nothing was pushed.');
    }
    if (remoteHead() !== originalRemote) throw new Error('origin/main changed during review. Nothing was pushed; no automatic merge or force push was attempted.');
  }
  function pushCommit(commit) {
    beforePush(commit);
    try { log(invoke(['push', '--porcelain', 'origin', commit + ':refs/heads/main']).trim()); }
    catch (e) { throw new Error('Commit ' + commit + ' remains LOCAL. Push was not confirmed. Re-run this launcher to review/retry the existing commits; do not create a duplicate commit.\n' + e.message); }
    if (remoteHead() !== commit) throw new Error('Push returned, but the remote tip no longer matches. Check GitHub before doing anything else.');
    log('GitHub main now points to ' + commit + '. Deployment has been triggered; this is NOT a deployment-success claim.');
    log('Check: https://github.com/kssmicom/kssmi-site/actions');
  }
  if (originalHead !== originalRemote) {
    const ancestor = invoke(['merge-base', originalRemote, originalHead]).trim();
    if (ancestor !== originalRemote) throw new Error('Local and remote main are not aligned. No commit, pull, merge, reset or push was attempted.');
    log('Existing unpushed commits:\n' + invoke(['log', '--oneline', originalRemote + '..' + originalHead]));
    if ((await ask('retry', 'Type PUSH to push ONLY these existing commits (working files will not be committed): ')).trim() !== 'PUSH') return { cancelled: true };
    guards();
    if (head() !== originalHead) throw new Error('HEAD changed while reviewing.');
    pushCommit(originalHead);
    return { pushed: originalHead, retried: true };
  }
  const changes = parseStatus(invoke(['status', '--porcelain=v1', '-z', '--untracked-files=all']));
  if (!changes.length) { log('No changes and no unpushed commits. Nothing to do.'); return { empty: true }; }
  const eligible = changes.filter(change => isGeneratedLogDeletion(change) || change.paths.every(p => !forbiddenPath(p)));
  const blocked = changes.filter(change => !isGeneratedLogDeletion(change) && change.paths.some(forbiddenPath));
  if (blocked.length) log('Excluded generated/private files (not staged):\n' + blocked.map(c => c.paths.map(p => JSON.stringify(p)).join(' -> ')).join('\n'));
  if (!eligible.length) throw new Error('No eligible files. Nothing was staged.');
  log(eligible.map((c, i) => String(i + 1) + '. ' + c.code + ' ' + c.paths.map(p => JSON.stringify(p)).join(' <- ')).join('\n'));
  const choice = (await ask('files', 'Select file numbers separated by commas, or ALL. Empty cancels: ')).trim();
  if (!choice) return { cancelled: true };
  const indexes = choice === 'ALL' ? eligible.map((_, i) => i) : choice.split(',').map(value => {
    if (!/^\s*\d+\s*$/.test(value)) throw new Error('Invalid file selection.');
    return Number(value) - 1;
  });
  if (!indexes.length || indexes.some(i => i < 0 || i >= eligible.length)) throw new Error('File selection is out of range.');
  const chosen = [...new Set(indexes)].map(i => eligible[i]);
  const selected = chosen.flatMap(change => change.paths);
  const permittedDeletions = new Set(chosen.filter(isGeneratedLogDeletion).flatMap(change => change.paths));
  checkDependencies(root, selected, changes, invoke);
  const checksum = digest(root, selected);
  log('Selected paths:\n' + selected.map(p => JSON.stringify(p)).join('\n'));
  log(invoke(['diff', '--no-ext-diff', '--no-textconv', '--stat', 'HEAD', '--', ...literal(selected)]));
  const task = createTask('push-review', root);
  fs.writeFileSync(path.join(task, 'review.patch'), invoke(['diff', '--no-ext-diff', '--no-textconv', 'HEAD', '--', ...literal(selected)]));
  fs.writeFileSync(path.join(task, 'selection.json'), JSON.stringify({ head: originalHead, remote: originalRemote, paths: selected, checksum }, null, 2));
  log('Review details: ' + task + ' (untracked files are listed with hashes; inspect their source before confirming).');
  const message = await ask('message', 'Commit message (empty cancels): ');
  if (!message.trim()) return { cancelled: true };
  if ((await ask('confirm', 'This commits ONLY the selected files, pushes main and triggers deployment. Type PUSH to continue: ')).trim() !== 'PUSH') return { cancelled: true };
  guards();
  if (head() !== originalHead || remoteHead() !== originalRemote || digest(root, selected) !== checksum) throw new Error('The reviewed files or repository changed. Nothing was staged.');
  checkDependencies(root, selected, parseStatus(invoke(['status', '--porcelain=v1', '-z', '--untracked-files=all'])), invoke);
  invoke(['add', '--all', '--', ...literal(selected)]);
  const staged = invoke(['diff', '--cached', '--name-only', '-z', '--no-renames']).split('\0').filter(Boolean);
  const stagedDeletions = new Set(invoke(['diff', '--cached', '--name-only', '-z', '--no-renames', '--diff-filter=D']).split('\0').filter(Boolean));
  if (staged.some(file => !selected.includes(file) || (forbiddenPath(file) && !(permittedDeletions.has(file) && stagedDeletions.has(file))))) throw new Error('Unexpected staged paths appeared. Nothing was committed or pushed. The index is preserved for inspection.');
  if (!staged.length) return { empty: true };
  invoke(['diff', '--cached', '--check']);
  const reviewedTree = invoke(['write-tree']).trim();
  if (head() !== originalHead || digest(root, selected) !== checksum) throw new Error('Files changed after staging. Nothing was committed or pushed; inspect the index.');
  invoke(['commit', '-m', message]);
  const committed = head();
  log('Created local commit: ' + committed);
  const parent = invoke(['rev-parse', committed + '^']).trim();
  const tree = invoke(['rev-parse', committed + '^{tree}']).trim();
  if (parent !== originalHead || tree !== reviewedTree) throw new Error('The created commit differs from the reviewed snapshot (possibly a hook/concurrent operation). It remains LOCAL; nothing was pushed.');
  pushCommit(committed);
  return { pushed: committed, selected };
}
async function main() {
  const task = createTask('push-session', projectRoot);
  Object.assign(process.env, taskEnvironment(task));
  const rl = createInterface({ input: process.stdin, output: process.stdout });
  try { await runPush({ ask: (_phase, message) => rl.question(message) }); }
  finally { rl.close(); }
}
if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  main().catch(e => { console.error(e.message); process.exitCode = 1; });
}
