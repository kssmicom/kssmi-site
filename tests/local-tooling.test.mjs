import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import net from 'node:net';
import { spawn, spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { runPush, gitCommand, forbiddenPath, parseStatus } from '../scripts/push-main.mjs';
import { projectRoot, temporaryRoot, ensureTemporaryRoot, createTask, archiveProjectCaches } from '../scripts/local-tools.mjs';
import { readState } from '../scripts/local-dev.mjs';
import { findPhp } from '../scripts/local-php.mjs';
test('concurrent launch clicks share readiness and use the original project during startup', {timeout:90000}, async()=>{
  const {root}=fixture();
  write(root,'src/editor.txt','initial');
  write(root,'node_modules/astro/package.json','{"type":"module"}');
  write(root,'node_modules/astro/dist/core/index.js',"import fs from 'node:fs';import path from 'node:path';import http from 'node:http';export async function dev(c){await new Promise(r=>setTimeout(r,700));const s=http.createServer((q,r)=>r.end(fs.readFileSync(path.join(c.root,'src/editor.txt'))));await new Promise(r=>s.listen(c.server.port,'127.0.0.1',r));return {address:s.address(),stop:()=>new Promise(r=>s.close(r))};}");
  const reserve=net.createServer();
  await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
  const port=reserve.address().port;
  await new Promise(resolve=>reserve.close(resolve));
  const script=path.join(projectRoot,'scripts/local-dev.mjs');
  const launch=()=>{
    const p=spawn(process.execPath,[script,'start','--source',root,'--port',String(port),'--no-browser'],{windowsHide:true,stdio:['ignore','pipe','pipe']});
    let output='';p.stdout.on('data',d=>{output+=d});p.stderr.on('data',d=>{output+=d});
    return new Promise((resolve,reject)=>{p.on('error',reject);p.on('close',code=>resolve({code,output}));});
  };
  try {
    const first=launch();
    let state;
    for(let i=0;i<300;i++){
      state=readState(root);
      if(state?.status==='preparing'&&state.phase==='server')break;
      await new Promise(resolve=>setTimeout(resolve,20));
    }
    assert.equal(state?.phase,'server');
    write(root,'src/editor.txt','edited while preparing');
    const second=launch();
    const [a,b]=await Promise.all([first,second]);
    assert.equal(a.code,0,a.output);assert.equal(b.code,0,b.output);
    assert.equal(readState(root).task,state.task);
    assert.equal(await(await fetch('http://127.0.0.1:'+port+'/product')).text(),'edited while preparing');
    assert.equal(readState(root).workspace,root);
    assert.equal(fs.existsSync(path.join(state.task,'workspace')),false);
  } finally {
    spawnSync(process.execPath,[script,'stop','--source',root],{windowsHide:true,stdio:'ignore'});
  }
});

ensureTemporaryRoot();
const task = createTask('tooling-tests', projectRoot);
const git = (root, ...args) => gitCommand(root, args).trim();
let sequence = 0;
function write(root, name, text) {
  const file = path.join(root, name);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, text);
}
function fixture() {
  const directory = path.join(task, 'case ' + (++sequence) + ' & paths');
  const root = path.join(directory, 'source');
  const remote = path.join(directory, 'remote.git');
  fs.mkdirSync(root, { recursive: true });
  git(root, 'init', '--initial-branch=main');
  git(root, 'config', 'user.name', 'Local tooling test');
  git(root, 'config', 'user.email', 'tooling@example.invalid');
  git(root, 'config', 'core.hooksPath', path.join(task, 'empty-hooks'));
  git(root, 'config', 'commit.gpgSign', 'false');
  write(root, 'package.json', '{"private":true,"type":"module"}');
  write(root, '.gitignore', 'node_modules/\ndist/\n.astro/\n.env\n');
  write(root, 'a.txt', 'first\n');
  write(root, 'b.txt', 'second\n');
  git(root, 'add', '.');
  git(root, 'commit', '-m', 'fixture');
  git(root, 'init', '--bare', '--initial-branch=main', remote);
  git(root, 'remote', 'add', 'origin', remote);
  git(root, 'push', 'origin', 'main');
  return { root, remote };
}
function answers(values, mutate) {
  return async phase => {
    if (mutate) await mutate(phase);
    if (!(phase in values)) throw new Error('Unexpected question: ' + phase);
    return values[phase];
  };
}
const quiet = () => {};
test('status parser preserves renamed paths and rejects temporary/private file selections', () => {
  assert.deepEqual(parseStatus('R  new name\0old name\0?? a & b.txt\0'), [{ code: 'R ', paths: ['new name', 'old name'] }, { code: '??', paths: ['a & b.txt'] }]);
  for (const p of ['.env', '.ENV.production', 'tep/file', 'node_modules/a', 'dist/index.html', 'private_config.php', 'build-output.txt', 'secret.key']) assert.equal(forbiddenPath(p), true, p);
  assert.equal(forbiddenPath('src/pages/index.astro'), false);
});
test('push commits only chosen files and keeps the commit message literal', async () => {
  const { root, remote } = fixture();
  write(root, 'a.txt', 'selected\n');
  write(root, 'b.txt', 'not selected\n');
  write(root, 'new file & spaces.txt', 'untracked\n');
  write(root, '.env', 'synthetic fixture secret\n');
  const message = 'literal "quotes" & echo NO_COMMAND $(not-executed)';
  const result = await runPush({ root, expected: remote, log: quiet, ask: answers({ files: '1', message, confirm: 'PUSH' }) });
  assert.deepEqual(git(root, 'show', '--format=', '--name-only', 'HEAD').split('\n'), ['a.txt']);
  assert.equal(git(root, 'log', '-1', '--format=%B'), message);
  assert.equal(git(root, 'ls-remote', '--heads', 'origin', 'main').split(/\s/)[0], result.pushed);
  assert.match(git(root, 'status', '--porcelain'), /b\.txt/);
  assert.match(git(root, 'status', '--porcelain'), /new file/);
  assert.equal(git(root, 'diff', '--cached', '--name-only'), '');
});
test('wrong branch, wrong remote and existing staged changes are refused without a commit', async () => {
  for (const mode of ['branch', 'remote', 'staged']) {
    const { root, remote } = fixture();
    write(root, 'a.txt', 'pending\n');
    if (mode === 'branch') git(root, 'switch', '-c', 'feature');
    if (mode === 'staged') git(root, 'add', 'a.txt');
    const before = git(root, 'rev-parse', 'HEAD');
    const index = git(root, 'diff', '--cached', '--name-only');
    await assert.rejects(runPush({ root, expected: mode === 'remote' ? 'https://example.invalid/wrong.git' : remote, ask: answers({}), log: quiet }));
    assert.equal(git(root, 'rev-parse', 'HEAD'), before);
    assert.equal(git(root, 'diff', '--cached', '--name-only'), index);
  }
});
test('cancel and files changing during review leave the index and HEAD untouched', async () => {
  for (const mode of ['cancel', 'changed']) {
    const { root, remote } = fixture();
    write(root, 'a.txt', 'pending\n');
    const before = git(root, 'rev-parse', 'HEAD');
    const request = runPush({ root, expected: remote, log: quiet, ask: answers({ files: '1', message: 'review', confirm: mode === 'cancel' ? 'NO' : 'PUSH' }, phase => {
      if (mode === 'changed' && phase === 'confirm') write(root, 'a.txt', 'changed after review\n');
    }) });
    if (mode === 'changed') await assert.rejects(request, /changed/);
    else assert.equal((await request).cancelled, true);
    assert.equal(git(root, 'rev-parse', 'HEAD'), before);
    assert.equal(git(root, 'diff', '--cached', '--name-only'), '');
  }
});
test('a failed push is retried without creating a duplicate commit', async () => {
  const { root, remote } = fixture();
  write(root, 'a.txt', 'pending\n');
  const failPush = (directory, args, allowed) => {
    if (args[0] === 'push') throw new Error('simulated network failure');
    return gitCommand(directory, args, allowed);
  };
  await assert.rejects(runPush({ root, expected: remote, git: failPush, log: quiet, ask: answers({ files: '1', message: 'single commit', confirm: 'PUSH' }) }), /remains LOCAL/);
  const committed = git(root, 'rev-parse', 'HEAD');
  const count = git(root, 'rev-list', '--count', 'HEAD');
  write(root, 'b.txt', 'still uncommitted\n');
  const retry = await runPush({ root, expected: remote, log: quiet, ask: answers({ retry: 'PUSH' }) });
  assert.equal(retry.retried, true);
  assert.equal(git(root, 'rev-parse', 'HEAD'), committed);
  assert.equal(git(root, 'rev-list', '--count', 'HEAD'), count);
  assert.equal(git(root, 'show', 'HEAD:b.txt'), 'second');
});
test('an already aligned clean repository does not create a commit', async () => {
  const { root, remote } = fixture();
  assert.equal((await runPush({ root, expected: remote, ask: answers({}), log: quiet })).empty, true);
  assert.equal(git(root, 'rev-list', '--count', 'HEAD'), '1');
});
test('reviewed deletion of a tracked legacy build log is allowed, but new log content stays excluded', async () => {
  const { root, remote } = fixture();
  write(root, 'build-output.txt', 'legacy generated log');
  git(root, 'add', 'build-output.txt'); git(root, 'commit', '-m', 'fixture legacy log'); git(root, 'push', 'origin', 'main');
  fs.unlinkSync(path.join(root, 'build-output.txt'));
  write(root, '_build.log', 'new temporary log must stay untracked');
  await runPush({ root, expected: remote, log: quiet, ask: answers({files:'ALL',message:'remove legacy log',confirm:'PUSH'}) });
  assert.equal(git(root, 'show', '--format=', '--name-status', 'HEAD'), 'D\tbuild-output.txt');
  assert.equal(fs.existsSync(path.join(root, '_build.log')), true);
  assert.equal(git(root, 'ls-files', '_build.log'), '');
});
test('a remote change during review aborts before staging', async () => {
  const { root, remote } = fixture();
  write(root, 'a.txt', 'pending');
  const before = git(root, 'rev-parse', 'HEAD');
  await assert.rejects(runPush({root,expected:remote,log:quiet,ask:answers({files:'1',message:'review',confirm:'PUSH'},phase=>{
    if(phase==='confirm') {
      // Advance only the disposable bare repository to a new commit object.
      const tree=git(root,'rev-parse','HEAD^{tree}');
      const next=git(root,'commit-tree',tree,'-p',before,'-m','simulated concurrent remote update');
      git(root,'push','origin',next+':refs/heads/main');
    }
  })}),/changed/);
  assert.equal(git(root,'rev-parse','HEAD'),before);
  assert.equal(git(root,'diff','--cached','--name-only'),'');
});
test('launchers cannot be committed without their new or changed supporting scripts', async()=>{
  const {root,remote}=fixture();
  write(root,'push-main.bat','node scripts/push-main.mjs');
  write(root,'scripts/push-main.mjs','import "./local-tools.mjs";');
  write(root,'scripts/local-tools.mjs','export const fixture=true;');
  const before=git(root,'rev-parse','HEAD');
  await assert.rejects(runPush({root,expected:remote,log:quiet,ask:answers({files:'1'})}),/required.*helper/i);
  assert.equal(git(root,'rev-parse','HEAD'),before);
  assert.equal(git(root,'diff','--cached','--name-only'),'');
  await runPush({root,expected:remote,log:quiet,ask:answers({files:'ALL',message:'complete launcher set',confirm:'PUSH'})});
  assert.equal(git(root,'show','--format=','--name-only','HEAD').split('\n').length,3);
  write(root,'push-main.bat','node scripts/push-main.mjs\necho changed');
  write(root,'scripts/local-tools.mjs','export const fixture="changed";');
  await assert.rejects(runPush({root,expected:remote,log:quiet,ask:answers({files:'1'})}),/required.*helper/i);
  assert.equal(git(root,'diff','--cached','--name-only'),'');
});
test('Fresh archives only known untracked caches and preserves normal output', () => {
  const {root}=fixture();
  write(root,'.astro/old','cache');
  write(root,'dist/index.html','keep build');
  write(root,'src/keep.txt','keep source');
  const freshTask=createTask('fresh-test',root);
  archiveProjectCaches(root,freshTask);
  assert.equal(fs.existsSync(path.join(root,'.astro')),false);
  assert.equal(fs.readFileSync(path.join(freshTask,'previous-cache','.astro','old'),'utf8'),'cache');
  assert.equal(fs.readFileSync(path.join(root,'dist/index.html'),'utf8'),'keep build');
  write(root,'.astro/tracked','protected');
  git(root,'add','-f','.astro/tracked');
  assert.throws(()=>archiveProjectCaches(root,createTask('fresh-tracked-test',root)),/tracked|Tracking/i);
  assert.equal(fs.existsSync(path.join(root,'.astro/tracked')),true);
});

test('cleanup review keeps tracked logs, credentials, JSON data and source scripts', () => {
  const { root } = fixture();
  write(root, 'build.log', 'tracked fixture log');
  git(root, 'add', '-f', 'build.log'); git(root, 'commit', '-m', 'fixture tracked log');
  for (const name of ['_build.log', 'email-logs.json', 'debug.mjs', '.env']) write(root, name, 'fixture');
  for (const name of ['dist/index.html','.astro/cache','node_modules/.astro/cache','test-results/result.txt']) write(root,name,'normal output');
  const script = path.join(projectRoot, 'scripts/cleanup-local.ps1');
  const result = spawnSync('powershell.exe', ['-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', script, '-SourceRoot', root, '-InspectOnly'], { encoding: 'utf8', windowsHide: true });
  assert.equal(result.status, 0, result.stderr);
  const review = JSON.parse(result.stdout.replace(/^\uFEFF/, ''));
  assert.deepEqual(review.Candidates.map(c => path.basename(c.Path)), ['_build.log']);
  assert.ok(review.Skipped.some(c => c.Path.endsWith('build.log') && /Tracked/.test(c.Reason)));
  const cleaned = spawnSync('powershell.exe', ['-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', script, '-SourceRoot', root], { input: '1\nRECYCLE\n', encoding: 'utf8', windowsHide: true });
  assert.equal(cleaned.status, 0, cleaned.stderr);
  assert.equal(fs.existsSync(path.join(root, '_build.log')), false);
  for (const name of ['build.log', 'email-logs.json', 'debug.mjs', '.env','dist/index.html','.astro/cache','node_modules/.astro/cache','test-results/result.txt']) assert.equal(fs.existsSync(path.join(root, name)), true);
});
test('preview lifecycle serves original source, refreshes caches and stops only its own session', { timeout: 90000 }, async () => {
  const { root } = fixture();
  write(root, 'src/editor.txt', 'initial');
  write(root, 'node_modules/astro/package.json', '{"type":"module"}');
  write(root, 'node_modules/astro/dist/core/index.js', [
    "import fs from 'node:fs'; import path from 'node:path'; import http from 'node:http';",
    "export async function dev(config) {",
    "fs.mkdirSync('.astro',{recursive:true}); fs.writeFileSync('.astro/generated','cache');",
    "const server=http.createServer((req,res)=>res.end(fs.readFileSync(path.join(config.root,'src/editor.txt'))));",
    "await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(config.server.port,'127.0.0.1',resolve)});",
    "return {address:server.address(),stop:()=>new Promise(resolve=>server.close(resolve))}; }"
  ].join('\n'));
  const reservation = net.createServer();
  await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  const sentinel = spawn(process.execPath, ['-e', 'setInterval(()=>{},1000)'], { windowsHide: true, stdio: 'ignore' });
  const script = path.join(projectRoot, 'scripts/local-dev.mjs');
  const call = args => spawnSync(process.execPath, [script, ...args, '--source', root, '--port', String(port), '--no-browser'], { encoding: 'utf8', windowsHide: true, timeout: 60000 });
  try {
    const start = call(['start']);
    assert.equal(start.status, 0, start.stderr + start.stdout);
    const first = readState(root);
    assert.ok(first.task.startsWith(temporaryRoot + path.sep));
    assert.equal(first.workspace,root);
    assert.equal(fs.existsSync(path.join(root, '.astro')), true);
    write(root,'dist/index.html','preserve normal build');
    assert.equal(await (await fetch('http://127.0.0.1:' + port + '/product')).text(), 'initial');
    write(root, 'src/editor.txt', 'edited');
    for (let i=0;i<30;i++) {
      if (await (await fetch('http://127.0.0.1:' + port + '/product')).text() === 'edited') break;
      await new Promise(resolve => setTimeout(resolve,100));
    }
    assert.equal(await (await fetch('http://127.0.0.1:' + port + '/product')).text(), 'edited');
    const fresh = call(['start','--fresh']);
    assert.equal(fresh.status, 0, fresh.stderr + fresh.stdout);
    assert.notEqual(readState(root).task, first.task);
    assert.equal(fs.existsSync(path.join(readState(root).task,'previous-cache','.astro','generated')),true);
    assert.equal(fs.readFileSync(path.join(root,'dist/index.html'),'utf8'),'preserve normal build');
    assert.equal(fs.existsSync(first.task), true);
    const stop = call(['stop']);
    assert.equal(stop.status, 0, stop.stderr);
    assert.equal(sentinel.exitCode, null);
    assert.equal(readState(root).status, 'stopped');
  } finally {
    call(['stop']);
    sentinel.kill();
  }
});
test('PHP serves original public files with task-local configuration and temporary directory', { timeout: 90000 }, async t => {
  try { findPhp(); } catch { t.skip('PHP is not installed on this test host'); return; }
  const phpPort = net.createServer();
  const free = await new Promise(resolve => {
    phpPort.once('error', () => resolve(false));
    phpPort.listen(4325, '127.0.0.1', () => phpPort.close(() => resolve(true)));
  });
  if (!free) { t.skip('An existing PHP service is using 4325; it was left alone'); return; }
  const { root } = fixture();
  write(root,'public/vendor/phpmailer/phpmailer/src/PHPMailer.php','<?php // synthetic dependency marker');
  write(root,'public/probe.php','<?php echo json_encode(["tmp"=>sys_get_temp_dir(),"pdo"=>extension_loaded("pdo_sqlite")]);');
  write(root,'node_modules/astro/package.json','{"type":"module"}');
  write(root,'node_modules/astro/dist/core/index.js',"import http from 'node:http'; export async function dev(config){ const s=http.createServer((q,r)=>r.end('fixture')); await new Promise(resolve=>s.listen(config.server.port,'127.0.0.1',resolve)); return {address:s.address(),stop:()=>new Promise(resolve=>s.close(resolve))}; }");
  const reserve=net.createServer();
  await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
  const port=reserve.address().port;
  await new Promise(resolve=>reserve.close(resolve));
  const script=path.join(projectRoot,'scripts/local-dev.mjs');
  const call=args=>spawnSync(process.execPath,[script,...args,'--source',root,'--port',String(port),'--no-browser'],{encoding:'utf8',windowsHide:true,timeout:60000});
  try {
    const started=call(['start','--php']);
    assert.equal(started.status,0,started.stderr+started.stdout);
    const state=readState(root);
    const result=await (await fetch('http://127.0.0.1:4325/probe.php')).json();
    assert.equal(result.pdo,true);
    assert.ok(path.resolve(result.tmp).toLowerCase().startsWith(path.resolve(state.task).toLowerCase()+path.sep), result.tmp);
    assert.equal(fs.existsSync(path.join(state.task,'php.ini')),true);
    assert.equal(state.workspace,root);
    assert.equal(fs.readdirSync(path.join(root,'public')).some(name=>name.startsWith('.kssmi-php-ready')),false);
    assert.equal(fs.existsSync(path.join(root,'php.ini')),false);
    const fresh=call(['start','--fresh']);
    assert.equal(fresh.status,0,fresh.stderr+fresh.stdout);
    assert.equal(readState(root).php,true);
    assert.equal((await (await fetch('http://127.0.0.1:4325/probe.php')).json()).pdo,true);
  } finally { call(['stop']); }
});
test('an unmanaged IPv6 listener is refused without being stopped', {timeout:45000}, async t=>{
  const server=net.createServer();
  const listening=await new Promise(resolve=>{
    server.once('error',()=>resolve(false));
    server.listen({host:'::1',port:0,ipv6Only:true},()=>resolve(true));
  });
  if(!listening){t.skip('IPv6 loopback is unavailable');return;}
  try {
    const {root}=fixture();
    const port=server.address().port;
    const result=spawnSync(process.execPath,[path.join(projectRoot,'scripts/local-dev.mjs'),'start','--source',root,'--port',String(port),'--no-browser'],{encoding:'utf8',windowsHide:true,timeout:30000});
    assert.equal(result.status,1);
    assert.match(result.stderr,/occupied/);
    assert.equal(server.listening,true);
    assert.equal(readState(root),null);
  } finally {await new Promise(resolve=>server.close(resolve));}
});
