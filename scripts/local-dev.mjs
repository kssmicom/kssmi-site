import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import net from 'node:net';
import { randomBytes, createHash, timingSafeEqual } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { spawn } from 'node:child_process';
import { projectRoot, temporaryRoot, ensureTemporaryRoot, assertSafePath, createTask, taskEnvironment, archiveProjectCaches, writeJson } from './local-tools.mjs';
import { findPhp, preparePhp } from './local-php.mjs';

const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
function options(args) {
  const value = name => args.includes(name) ? args[args.indexOf(name) + 1] : undefined;
  const source = fs.realpathSync(value('--source') || projectRoot);
  return { source, port: Number(value('--port') || 4321), php: args.includes('--php'), noBrowser: args.includes('--no-browser'), fresh: args.includes('--fresh'), task: value('--task') };
}
function statePaths(source) {
  ensureTemporaryRoot();
  const id = createHash('sha256').update(source.toLowerCase()).digest('hex').slice(0, 16);
  const control = assertSafePath(temporaryRoot, path.join(temporaryRoot, 'local-preview-' + id));
  fs.mkdirSync(control, { recursive: true });
  return { control, state: path.join(control, 'state.json'), lock: path.join(control, 'start.lock') };
}
function readState(source) {
  try {
    const value = JSON.parse(fs.readFileSync(statePaths(source).state, 'utf8'));
    assertSafePath(temporaryRoot, value.task);
    return value.source === source && value.schema === 1 ? value : null;
  } catch { return null; }
}
async function control(state, action) {
  if (!state || state.status === 'stopped') return null;
  try {
    const response = await fetch('http://127.0.0.1:' + state.controlPort + '/' + action, {
      method: 'POST', headers: { 'x-kssmi-session': state.token }, signal: AbortSignal.timeout(5000)
    });
    if (!response.ok) return null;
    const result = await response.json();
    return result.source === state.source && result.session === state.session ? result : null;
  } catch { return null; }
}
async function available(port) {
  for (const host of ['127.0.0.1', '::1']) {
    const free = await new Promise(resolve => {
      const server = net.createServer();
      server.once('error', error => resolve(host === '::1' && ['EAFNOSUPPORT', 'EADDRNOTAVAIL'].includes(error.code)));
      server.listen({ port, host, ipv6Only: host === '::1' }, () => server.close(() => resolve(true)));
    });
    if (!free) return false;
  }
  return true;
}
function openBrowser(url) {
  const p = spawn('explorer.exe', [url], { detached: true, windowsHide: true, stdio: 'ignore' });
  p.on('error', () => console.log('Open manually: ' + url));
  p.unref();
}
async function stopOwned(source) {
  const state = readState(source);
  const live = await control(state, 'status');
  if (!live) {
    if (state && !['stopped', 'failed'].includes(state.status)) {
      let exists = false;
      try { process.kill(state.pid, 0); exists = true; } catch {}
      if (exists) throw new Error('This project preview is not responding yet. No process was killed. Retry after startup or inspect ' + path.join(state.task, 'server.log'));
    }
    return false;
  }
  const stopped = await control(state, 'stop');
  if (!stopped) throw new Error('The owned preview did not confirm shutdown; no process-name kill was attempted.');
  for (let i = 0; i < 100; i++) {
    if (!(await control(state, 'status'))) return true;
    await pause(100);
  }
  throw new Error('Preview shutdown is still in progress. See ' + state.task);
}
async function start(opts) {
  const paths = statePaths(opts.source);
  const current = readState(opts.source);
  const live = await control(current, 'status');
  if (current?.status === 'preparing') {
    let running = Boolean(live);
    if (!running) { try { process.kill(current.pid, 0); running = true; } catch {} }
    if (running) {
      if (opts.fresh || current.port !== opts.port || (opts.php && !current.php)) throw new Error('This preview is still preparing. Wait for it to finish or stop it before changing its mode.');
      console.log('The project preview is already preparing; waiting for readiness.');
      const deadline = Date.now() + 300000;
      while (Date.now() < deadline) {
        const latest = readState(opts.source);
        if (!latest || latest.task !== current.task || ['failed', 'stopped'].includes(latest.status)) throw new Error('The pending preview stopped or changed. See ' + path.join(current.task, 'server.log'));
        if ((await control(latest, 'status'))?.status === 'ready') {
          console.log('Ready: http://127.0.0.1:' + latest.port);
          if (!opts.noBrowser) openBrowser('http://127.0.0.1:' + latest.port);
          return;
        }
        await pause(1000);
      }
      throw new Error('The existing preview is still preparing. See ' + path.join(current.task, 'server.log'));
    }
  }
  if (live?.status === 'ready' && current.port === opts.port && !opts.fresh && (!opts.php || current.php)) {
    console.log('Existing project preview: http://127.0.0.1:' + current.port);
    if (!opts.noBrowser) openBrowser('http://127.0.0.1:' + current.port);
    return;
  }
  if (live && current.php) opts.php = true;
  if (opts.php) {
    findPhp();
    if (!fs.existsSync(path.join(opts.source, 'public/vendor/phpmailer/phpmailer/src/PHPMailer.php'))) throw new Error('PHPMailer is missing. No dependencies were installed and the existing preview was left alone.');
  }
  if (live) await stopOwned(opts.source);
  if (!(await available(opts.port)) || (opts.php && !(await available(4325)))) {
    throw new Error('A required port is occupied by an unmanaged server. No process was stopped. Close the old server first.');
  }
  if (fs.existsSync(paths.lock)) {
    const previous = JSON.parse(fs.readFileSync(paths.lock, 'utf8'));
    let running = false;
    try { process.kill(previous.pid, 0); running = true; } catch {}
    if (running) throw new Error('Another launcher is preparing this project. Please wait.');
    fs.renameSync(paths.lock, paths.lock + '.stale-' + Date.now());
  }
  const lock = fs.openSync(paths.lock, 'wx');
  fs.writeFileSync(lock, JSON.stringify({ pid: process.pid }));
  fs.closeSync(lock);
  try {
    const task = createTask('preview', opts.source);
    const fd = fs.openSync(path.join(task, 'server.log'), 'a');
    const args = [path.join(projectRoot, 'scripts/local-dev.mjs'), 'run', '--source', opts.source, '--port', String(opts.port), '--task', task];
    if (opts.php) args.push('--php');
    if (opts.fresh) args.push('--fresh');
    const child = spawn(process.execPath, args, { detached: true, windowsHide: true, stdio: ['ignore', fd, fd], env: taskEnvironment(task) });
    let spawnError;
    child.on('error', e => { spawnError = e; });
    child.unref();
    fs.closeSync(fd);
    console.log('Preparing local preview. Log: ' + path.join(task, 'server.log'));
    const deadline = Date.now() + 300000;
    while (Date.now() < deadline) {
      if (spawnError) throw spawnError;
      if (child.exitCode !== null || child.signalCode !== null) throw new Error('Preview process exited. See ' + path.join(task, 'server.log'));
      const state = readState(opts.source);
      if (state?.task === task) {
        const status = await control(state, 'status');
        if (status?.status === 'ready') {
          console.log('Ready: http://127.0.0.1:' + opts.port + (opts.php ? ' (PHP: 4325)' : ''));
          if (!opts.noBrowser) openBrowser('http://127.0.0.1:' + opts.port);
          return;
        }
        if (state.status === 'failed') throw new Error('Preview failed. See ' + path.join(task, 'server.log'));
      }
      await pause(1000);
    }
    await stopOwned(opts.source);
    throw new Error('Preview did not become ready in time. See ' + path.join(task, 'server.log'));
  } finally {
    // Only the launch lock owned by this process is removed.
    if (fs.existsSync(paths.lock) && JSON.parse(fs.readFileSync(paths.lock, 'utf8')).pid === process.pid) fs.unlinkSync(paths.lock);
  }
}
async function run(opts) {
  const task = assertSafePath(temporaryRoot, path.resolve(opts.task));
  const owner = JSON.parse(fs.readFileSync(path.join(task, '.kssmi-local-task.json'), 'utf8'));
  if (owner.schema !== 1 || owner.kind !== 'preview' || owner.source !== opts.source || owner.state !== 'preparing') throw new Error('The task directory does not belong to this preview launch.');
  const paths = statePaths(opts.source);
  const state = { schema: 1, source: opts.source, task, pid: process.pid, session: randomBytes(16).toString('hex'), token: randomBytes(32).toString('hex'), status: 'preparing', port: opts.port, php: opts.php, controlPort: 0 };
  let astro, php, ending = false;
  function save(status) {
    state.status = status;
    writeJson(paths.state, state);
    writeJson(path.join(task, '.kssmi-local-task.json'), { schema: 1, source: opts.source, kind: 'preview', state: status, createdAt: new Date().toISOString() });
  }
  async function shutdown(status = 'stopped') {
    if (ending) return;
    ending = true;
    try { await astro?.stop(); } catch (error) { console.error('Preview shutdown:', error.message); }
    if (php && php.exitCode === null) php.kill();
    save(status);
    server.close(() => process.exit(status === 'failed' ? 1 : 0));
    setTimeout(() => process.exit(status === 'failed' ? 1 : 0), 3000).unref();
  }
  const server = http.createServer((req, res) => {
    const supplied = Buffer.from(req.headers['x-kssmi-session'] || '');
    const expected = Buffer.from(state.token);
    if (req.method !== 'POST' || supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) {
      res.writeHead(403).end(); return;
    }
    if (!['/status', '/stop'].includes(req.url)) { res.writeHead(404).end(); return; }
    res.setHeader('content-type', 'application/json');
    res.end(JSON.stringify({ source: state.source, session: state.session, status: state.status }));
    if (req.url === '/stop') setImmediate(() => shutdown());
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  state.controlPort = server.address().port;
  save('preparing');
  process.on('SIGINT', () => shutdown());
  process.on('SIGTERM', () => shutdown());
  try {
    const workspace = opts.source;
    process.chdir(workspace);
    Object.assign(process.env, taskEnvironment(task));
    state.mode = 'in-place';
    state.workspace = workspace;
    state.phase = 'server';
    save('preparing');
    if (opts.fresh) archiveProjectCaches(workspace, task);
    const { dev } = await import(pathToFileURL(path.join(workspace, 'node_modules/astro/dist/core/index.js')).href);
    astro = await dev({ root: workspace, server: { host: '127.0.0.1', port: opts.port }, vite: { server: { strictPort: true } } });
    if (astro.address.port !== opts.port) throw new Error('Astro selected an unexpected port.');
    if (opts.php) {
      if (!fs.existsSync(path.join(workspace, 'public/vendor/phpmailer/phpmailer/src/PHPMailer.php'))) throw new Error('PHPMailer is missing. Dependencies are never installed automatically by the launcher.');
      const runtime = preparePhp(task);
      const proof = randomBytes(24).toString('hex');
      const probeName = '.kssmi-php-ready-' + randomBytes(8).toString('hex');
      const router = path.join(task, 'php-router.php');
      fs.writeFileSync(router, '<?php if (parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH) === "/' + probeName + '") { header("Cache-Control: no-store"); echo "' + proof + '"; return true; } return false;');
      php = spawn(runtime.binary, ['-c', runtime.ini, '-S', '127.0.0.1:4325', '-t', path.join(workspace, 'public'), router], { cwd: workspace, windowsHide: true, stdio: 'inherit', env: runtime.env });
      php.on('error', e => { console.error(e); shutdown('failed'); });
      php.on('exit', () => { if (!ending) shutdown('failed'); });
      let ready = false;
      for (let i = 0; i < 100 && !ending && php.exitCode === null; i++) {
        try {
          const response = await fetch('http://127.0.0.1:4325/' + probeName, { redirect: 'error', signal: AbortSignal.timeout(500) });
          if (response.ok && await response.text() === proof) { ready = true; break; }
        } catch {}
        await pause(200);
      }
      if (!ready) throw new Error('PHP did not confirm readiness on port 4325.');
    }
    const response = await fetch('http://127.0.0.1:' + opts.port + '/product', { signal: AbortSignal.timeout(180000) });
    if (!response.ok) throw new Error('Preview readiness check failed: HTTP ' + response.status);
    save('ready');
    console.log('Ready. Astro is serving and watching the original project; standard caches stay in the project.');
  } catch (e) {
    console.error(e);
    await shutdown('failed');
  }
}
async function main() {
  const [command = 'start', ...args] = process.argv.slice(2);
  const opts = options(args);
  if (!Number.isInteger(opts.port) || opts.port < 1024 || opts.port > 65535) throw new Error('Invalid local preview port.');
  if (command === 'stop') {
    console.log(await stopOwned(opts.source) ? 'This project preview was stopped.' : 'No managed preview for this project was found. Other processes were left alone.');
  } else if (command === 'run') await run(opts);
  else if (command === 'start') await start(opts);
  else throw new Error('Unknown local preview command.');
}
if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  main().catch(e => { console.error(e.message); process.exitCode = 1; });
}
export { control, readState, statePaths };
