import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import net from 'node:net';
import { randomBytes, createHash, timingSafeEqual } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { spawn } from 'node:child_process';
import { projectRoot, temporaryRoot, ensureTemporaryRoot, assertSafePath, createTask, taskEnvironment, archiveProjectCaches, writeJson } from './local-tools.mjs';
import { findPhp, preparePhp } from './local-php.mjs';

export const DEFAULT_ASTRO_PORT = 4322;
export const DEFAULT_PHP_PORT = 4325;

const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
function parsePort(raw, fallback, label) {
  if (raw === undefined) return fallback;
  if (!/^\d+$/.test(raw)) throw new Error('Invalid ' + label + ': ' + raw);
  const port = Number(raw);
  if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('Invalid ' + label + ': ' + raw);
  return port;
}
function options(args) {
  const value = name => {
    const idx = args.indexOf(name);
    if (idx === -1) return undefined;
    const next = args[idx + 1];
    if (next === undefined || next.startsWith('--')) throw new Error('Missing value for ' + name + '.');
    return next;
  };
  const source = fs.realpathSync(value('--source') || projectRoot);
  return {
    source,
    port: parsePort(value('--port'), DEFAULT_ASTRO_PORT, 'local preview port'),
    php: args.includes('--php'),
    phpPort: parsePort(value('--php-port'), DEFAULT_PHP_PORT, 'local PHP port'),
    phpPortProvided: args.includes('--php-port'),
    noBrowser: args.includes('--no-browser'),
    fresh: args.includes('--fresh'),
    task: value('--task')
  };
}
function statePhpPort(state) {
  return state?.php ? (Number.isInteger(state.phpPort) ? state.phpPort : DEFAULT_PHP_PORT) : null;
}
function isPidAlive(pid) {
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try { process.kill(pid, 0); return true; } catch { return false; }
}
function unmanagedPortError(port) {
  return new Error(
    `Port ${port} is occupied by an unmanaged server. No process was stopped. Legacy shell-started servers are not adopted automatically; verify the owning PID and command manually before stopping it (macOS: lsof -nP -iTCP:${port} -sTCP:LISTEN && ps -p <PID> -o pid,lstart,command; Windows: netstat -ano | findstr :${port}).`
  );
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
  return new Promise(resolve => {
    let settled = false;
    const done = value => {
      if (!settled) {
        settled = true;
        resolve(value);
      }
    };
    const req = http.request({
      host: '127.0.0.1',
      port: state.controlPort,
      path: '/' + action,
      method: 'POST',
      agent: false,
      headers: { 'x-kssmi-session': state.token, connection: 'close' },
      timeout: 5000
    }, res => {
      if ((res.statusCode || 0) < 200 || res.statusCode >= 300) {
        res.resume();
        done(null);
        return;
      }
      let body = '';
      res.setEncoding('utf8');
      res.on('data', chunk => { body += chunk; });
      res.on('error', () => done(null));
      res.on('end', () => {
        try {
          const result = JSON.parse(body);
          done(result.source === state.source && result.session === state.session ? result : null);
        } catch {
          done(null);
        }
      });
    });
    req.on('timeout', () => { req.destroy(); done(null); });
    req.on('error', () => done(null));
    req.end();
  });
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
  const command = process.platform === 'win32'
    ? ['explorer.exe', [url], { detached: true, windowsHide: true, stdio: 'ignore' }]
    : process.platform === 'darwin'
      ? ['open', [url], { detached: true, stdio: 'ignore' }]
      : ['xdg-open', [url], { detached: true, stdio: 'ignore' }];
  const p = spawn(command[0], command[1], command[2]);
  p.on('error', () => console.log('Open manually: ' + url));
  p.unref();
}
async function stopOwned(source) {
  const state = readState(source);
  const live = await control(state, 'status');
  if (!live) {
    if (state && !['stopped', 'failed'].includes(state.status)) {
      if (isPidAlive(state.pid)) throw new Error('This project preview is not responding yet. No process was killed. Retry after startup or inspect ' + path.join(state.task, 'server.log'));
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
    const running = Boolean(live) || isPidAlive(current.pid);
    if (running) {
      const phpPortMismatch = opts.php && current.php && statePhpPort(current) !== opts.phpPort;
      if (opts.fresh || current.port !== opts.port || (opts.php && !current.php) || phpPortMismatch) {
        throw new Error('This preview is still preparing. Wait for it to finish or stop it before changing its mode.');
      }
      console.log('The project preview is already preparing; waiting for readiness.');
      const deadline = Date.now() + 300000;
      while (Date.now() < deadline) {
        const latest = readState(opts.source);
        if (!latest || latest.task !== current.task || ['failed', 'stopped'].includes(latest.status)) throw new Error('The pending preview stopped or changed. See ' + path.join(current.task, 'server.log'));
        if ((await control(latest, 'status'))?.status === 'ready') {
          console.log('Ready: http://127.0.0.1:' + latest.port + (latest.php ? ' (PHP: ' + statePhpPort(latest) + ')' : ''));
          if (!opts.noBrowser) openBrowser('http://127.0.0.1:' + latest.port);
          return;
        }
        await pause(1000);
      }
      throw new Error('The existing preview is still preparing. See ' + path.join(current.task, 'server.log'));
    }
  }
  const phpMatches = !opts.php || (Boolean(current?.php) && statePhpPort(current) === opts.phpPort);
  if (live?.status === 'ready' && current?.port === opts.port && !opts.fresh && phpMatches) {
    console.log('Existing project preview: http://127.0.0.1:' + current.port + (current.php ? ' (PHP: ' + statePhpPort(current) + ')' : ''));
    if (!opts.noBrowser) openBrowser('http://127.0.0.1:' + current.port);
    return;
  }
  if (live && current?.php) {
    opts.php = true;
    if (!opts.phpPortProvided) opts.phpPort = statePhpPort(current);
  }
  if (opts.php && opts.port === opts.phpPort) throw new Error('Astro port and PHP port must be different.');
  if (opts.php) {
    findPhp();
    if (!fs.existsSync(path.join(opts.source, 'public/vendor/phpmailer/phpmailer/src/PHPMailer.php'))) throw new Error('PHPMailer is missing. No dependencies were installed and the existing preview was left alone.');
  }
  if (live) {
    if (opts.port !== current.port && !(await available(opts.port))) throw unmanagedPortError(opts.port);
    if (opts.php && (!current?.php || opts.phpPort !== statePhpPort(current)) && !(await available(opts.phpPort))) throw unmanagedPortError(opts.phpPort);
    await stopOwned(opts.source);
  }
  if (!(await available(opts.port))) throw unmanagedPortError(opts.port);
  if (opts.php && !(await available(opts.phpPort))) throw unmanagedPortError(opts.phpPort);
  if (fs.existsSync(paths.lock)) {
    let previous = null;
    try { previous = JSON.parse(fs.readFileSync(paths.lock, 'utf8')); } catch {}
    if (previous && isPidAlive(previous.pid)) throw new Error('Another launcher is preparing this project. Please wait.');
    fs.renameSync(paths.lock, paths.lock + '.stale-' + Date.now());
  }
  const lock = fs.openSync(paths.lock, 'wx');
  fs.writeFileSync(lock, JSON.stringify({ pid: process.pid }));
  fs.closeSync(lock);
  try {
    const task = createTask('preview', opts.source);
    const fd = fs.openSync(path.join(task, 'server.log'), 'a');
    const args = [path.join(projectRoot, 'scripts/local-dev.mjs'), 'run', '--source', opts.source, '--port', String(opts.port), '--task', task];
    if (opts.php) args.push('--php', '--php-port', String(opts.phpPort));
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
          console.log('Ready: http://127.0.0.1:' + opts.port + (opts.php ? ' (PHP: ' + opts.phpPort + ')' : ''));
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
    try {
      if (fs.existsSync(paths.lock) && JSON.parse(fs.readFileSync(paths.lock, 'utf8')).pid === process.pid) fs.unlinkSync(paths.lock);
    } catch {}
  }
}
async function run(opts) {
  const task = assertSafePath(temporaryRoot, path.resolve(opts.task));
  const owner = JSON.parse(fs.readFileSync(path.join(task, '.kssmi-local-task.json'), 'utf8'));
  if (owner.schema !== 1 || owner.kind !== 'preview' || owner.source !== opts.source || owner.state !== 'preparing') throw new Error('The task directory does not belong to this preview launch.');
  const paths = statePaths(opts.source);
  const state = { schema: 1, source: opts.source, task, pid: process.pid, session: randomBytes(16).toString('hex'), token: randomBytes(32).toString('hex'), status: 'preparing', port: opts.port, php: opts.php, phpPort: opts.php ? opts.phpPort : null, controlPort: 0 };
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
    if (php && php.exitCode === null) {
      await new Promise(resolve => {
        const timer = setTimeout(resolve, 2000);
        php.once('exit', () => { clearTimeout(timer); resolve(); });
        try { php.kill(); } catch { clearTimeout(timer); resolve(); }
      });
    }
    save(status);
    server.close(() => process.exit(status === 'failed' ? 1 : 0));
    server.closeAllConnections?.();
    setTimeout(() => process.exit(status === 'failed' ? 1 : 0), 3000).unref();
  }
  const server = http.createServer((req, res) => {
    const supplied = Buffer.from(req.headers['x-kssmi-session'] || '');
    const expected = Buffer.from(state.token);
    if (req.method !== 'POST' || supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) {
      res.writeHead(403).end(); return;
    }
    if (!['/status', '/stop'].includes(req.url)) { res.writeHead(404).end(); return; }
    res.setHeader('connection', 'close');
    res.setHeader('content-type', 'application/json');
    res.end(JSON.stringify({ source: state.source, session: state.session, status: state.status, port: state.port, php: state.php, phpPort: state.phpPort }));
    if (req.url === '/stop') setImmediate(() => shutdown());
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  state.controlPort = server.address().port;
  save('preparing');
  if (process.platform !== 'win32') process.on('SIGHUP', () => {});
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
      php = spawn(runtime.binary, ['-c', runtime.ini, '-S', '127.0.0.1:' + opts.phpPort, '-t', path.join(workspace, 'public'), router], { cwd: workspace, windowsHide: true, stdio: 'inherit', env: runtime.env });
      php.on('error', e => { console.error(e); shutdown('failed'); });
      php.on('exit', () => { if (!ending) shutdown('failed'); });
      let ready = false;
      for (let i = 0; i < 100 && !ending && php.exitCode === null; i++) {
        try {
          const response = await fetch('http://127.0.0.1:' + opts.phpPort + '/' + probeName, { redirect: 'error', signal: AbortSignal.timeout(500) });
          if (response.ok && await response.text() === proof) { ready = true; break; }
        } catch {}
        await pause(200);
      }
      if (!ready) throw new Error('PHP did not confirm readiness on port ' + opts.phpPort + '.');
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
  if (opts.php && opts.port === opts.phpPort) throw new Error('Astro port and PHP port must be different.');
  if (command === 'stop') {
    const stopped = await stopOwned(opts.source);
    console.log(stopped
      ? 'This project preview was stopped.'
      : 'No managed preview for this project was found. Other processes were left alone.\nNote: legacy servers started outside scripts/local-dev.mjs are not adopted or stopped automatically; inspect PID and command manually before stopping any unmanaged listener.');
  } else if (command === 'run') await run(opts);
  else if (command === 'start') await start(opts);
  else throw new Error('Unknown local preview command.');
}
if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  main().catch(e => { console.error(e.message); process.exitCode = 1; });
}
export { control, readState, statePaths };
