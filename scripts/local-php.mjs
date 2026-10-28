import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { projectRoot, taskEnvironment } from './local-tools.mjs';

export function findPhp() {
  if (process.env.KSSMI_PHP_BINARY && !fs.existsSync(process.env.KSSMI_PHP_BINARY)) throw new Error('KSSMI_PHP_BINARY does not point to an installed PHP executable.');
  const candidates = [
    process.env.KSSMI_PHP_BINARY,
    path.resolve(projectRoot, '../../../PHP/php.exe'),
    ...(process.env.PATH || '').split(path.delimiter).map(folder => path.join(folder.replace(/^"|"$/g, ''), 'php.exe'))
  ].filter(Boolean);
  const binary = candidates.find(file => fs.existsSync(file) && fs.statSync(file).isFile());
  if (!binary) throw new Error('PHP was not found. Install/configure PHP or set KSSMI_PHP_BINARY; no dependencies were installed automatically.');
  return binary;
}
export function preparePhp(task) {
  const binary = findPhp();
  const home = path.dirname(binary);
  const slash = value => value.replaceAll('\\', '/').replaceAll('"', '');
  const ini = path.join(task, 'php.ini');
  const extensions = ['curl', 'fileinfo', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3'];
  const lines = [
    '[PHP]', 'date.timezone=UTC', 'display_errors=Off', 'log_errors=On',
    'error_log="' + slash(path.join(task, 'php-error.log')) + '"',
    'sys_temp_dir="' + slash(path.join(task, 'tmp')) + '"',
    'upload_tmp_dir="' + slash(path.join(task, 'tmp')) + '"',
    'session.save_path="' + slash(path.join(task, 'tmp')) + '"',
    'extension_dir="' + slash(path.join(home, 'ext')) + '"'
  ];
  for (const extension of extensions) {
    const dll = path.join(home, 'ext', 'php_' + extension + '.dll');
    if (fs.existsSync(dll)) lines.push('extension="' + slash(dll) + '"');
  }
  fs.writeFileSync(ini, lines.join('\n') + '\n');
  const env = { ...taskEnvironment(task), PHP_INI_SCAN_DIR: path.join(task, 'cache') };
  const openssl = path.join(home, 'extras/ssl/openssl.cnf');
  if (fs.existsSync(openssl)) env.OPENSSL_CONF = openssl;
  const probe = spawnSync(binary, ['-c', ini, '-r', 'echo json_encode(["version"=>PHP_VERSION_ID,"missing"=>array_values(array_filter(["curl","fileinfo","mbstring","openssl","pdo_sqlite"], fn($e)=>!extension_loaded($e)))]);'], { encoding: 'utf8', windowsHide: true, env });
  if (probe.error || probe.status !== 0) throw new Error('The isolated PHP configuration failed. See ' + path.join(task, 'php-error.log'));
  let result;
  try { result = JSON.parse(probe.stdout); } catch { throw new Error('PHP returned an unexpected runtime probe response.'); }
  if (result.version < 80300) throw new Error('PHP 8.3 or newer is required for this backend.');
  if (result.missing.length) throw new Error('PHP is missing required extensions: ' + result.missing.join(', '));
  return { binary, ini, env };
}
