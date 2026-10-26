<?php
declare(strict_types=1);

/**
 * Durable outbox for accepted inquiries.
 *
 * An inquiry counts as accepted only once its two rendered messages (the
 * sales notification and the automatic reply) and its attachments are in
 * email_data/outbox/<id>/, written to a temporary directory, fsynced and
 * renamed into place. send-mail.php answers the browser after that and sends
 * the mail afterwards, so the visitor never waits for SMTP.
 *
 * Every step is sent at least once: a crash, a deploy or an SMTP failure
 * leaves the job on disk, and it is retried with backoff for 72 hours by the
 * next inquiry request or by scripts/inquiry-outbox-worker.php. A send whose
 * outcome is uncertain (the connection broke after DATA started) is retried
 * as well, so a duplicate is possible; a silent loss is not. After 72 hours a
 * step is marked dead, logged as an alert and kept on disk with its files.
 *
 * Identical submissions (same mailbox, name, text, product and attachment
 * names and sizes) within two minutes are one inquiry retried, not two.
 */

const KSSMI_OUTBOX_SCHEMA = 1;
const KSSMI_OUTBOX_STEPS = ['sales', 'autoreply'];
const KSSMI_OUTBOX_RETRY_DELAYS = [60, 300, 900, 1800, 3600];
const KSSMI_OUTBOX_GIVE_UP_AFTER = 72 * 3600;
const KSSMI_OUTBOX_DUPLICATE_WINDOW = 120;
// Recovery window for failed/pending delivery copies, not for contract records.
const KSSMI_OUTBOX_RETENTION_SECONDS = 30 * 86400;

function kssmi_outbox_dir(): string {
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
        $override = getenv('KSSMI_OUTBOX_DIR');
        if (is_string($override) && $override !== '') return $override;
    }
    return dirname(__DIR__) . '/email_data/outbox';
}

function kssmi_outbox_ensure_dir(string $dir): bool {
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    return is_writable($dir);
}

/** Write a file completely and fsync it before it is renamed into place. */
function kssmi_outbox_write_file(string $path, string $contents): bool {
    $handle = @fopen($path, 'xb');
    if ($handle === false) return false;
    @chmod($path, 0600);
    $offset = 0;
    $length = strlen($contents);
    while ($offset < $length) {
        $written = fwrite($handle, substr($contents, $offset));
        if ($written === false || $written === 0) {
            fclose($handle);
            return false;
        }
        $offset += $written;
    }
    $ok = fflush($handle) && (!function_exists('fsync') || @fsync($handle));
    fclose($handle);
    return $ok;
}

function kssmi_outbox_save_job(string $jobDir, array $job): bool {
    try {
        $encoded = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temp = $jobDir . '/.job-' . bin2hex(random_bytes(6));
    } catch (Throwable $error) {
        return false;
    }
    if (!kssmi_outbox_write_file($temp, $encoded)) {
        @unlink($temp);
        return false;
    }
    if (!@rename($temp, $jobDir . '/job.json')) {
        @unlink($temp);
        return false;
    }
    return true;
}

function kssmi_outbox_load_job(string $jobDir): ?array {
    $raw = @file_get_contents($jobDir . '/job.json');
    if (!is_string($raw)) return null;
    $job = json_decode($raw, true);
    return is_array($job) && ($job['schema'] ?? null) === KSSMI_OUTBOX_SCHEMA ? $job : null;
}

function kssmi_outbox_remove_tree(string $path): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $entry = $path . '/' . $name;
        is_dir($entry) && !is_link($entry) ? kssmi_outbox_remove_tree($entry) : @unlink($entry);
    }
    @rmdir($path);
}

/** Delete expired valid jobs under the enqueue and job locks, never follow links. */
function kssmi_outbox_prune(?int $now = null): array {
    $now ??= time();
    $result = ['deleted' => 0, 'busy' => 0, 'invalid' => 0];
    $dir = kssmi_outbox_dir();
    if (!is_dir($dir) || is_link($dir)) return $result;
    $root = realpath($dir);
    if ($root === false) return $result;
    $rootLock = @fopen($root . '/.lock', 'c');
    if ($rootLock === false || !flock($rootLock, LOCK_EX | LOCK_NB)) {
        if (is_resource($rootLock)) fclose($rootLock);
        $result['busy']++;
        return $result;
    }
    try {
        foreach (kssmi_outbox_job_ids() as $id) {
            $path = $root . DIRECTORY_SEPARATOR . $id;
            if (is_link($path) || realpath(dirname($path)) !== $root) continue;
            $resolved = realpath($path);
            if ($resolved === false || dirname($resolved) !== $root || !is_dir($path)) continue;
            $jobLock = @fopen($path . '/.lock', 'c');
            if ($jobLock === false || !flock($jobLock, LOCK_EX | LOCK_NB)) {
                if (is_resource($jobLock)) fclose($jobLock);
                $result['busy']++;
                continue;
            }
            $remove = false;
            try {
                $job = kssmi_outbox_load_job($path);
                $created = $job['created_at'] ?? null;
                if (!is_int($created) || $created <= 0 || $created > $now) {
                    $result['invalid']++;
                    continue;
                }
                if ($created >= $now - KSSMI_OUTBOX_RETENTION_SECONDS) continue;
                // Jobs contain only flat files. Unknown subdirectories or links
                // need manual investigation, not recursive automatic deletion.
                foreach (scandir($path) ?: [] as $name) {
                    if ($name === '.' || $name === '..') continue;
                    if (is_link($path . '/' . $name) || !is_file($path . '/' . $name)) {
                        $result['invalid']++;
                        continue 2;
                    }
                }
                foreach (scandir($path) ?: [] as $name) {
                    if ($name === '.' || $name === '..' || $name === '.lock') continue;
                    if (!@unlink($path . '/' . $name)) continue 2;
                }
                $remove = true;
            } finally {
                flock($jobLock, LOCK_UN);
                fclose($jobLock);
            }
            if ($remove) {
                @unlink($path . '/.lock');
                if (@rmdir($path)) $result['deleted']++;
            }
        }
    } finally {
        flock($rootLock, LOCK_UN);
        fclose($rootLock);
    }
    if ($result['invalid'] > 0) error_log('KSSMI retention: invalid outbox entries require manual review: ' . $result['invalid']);
    return $result;
}

function kssmi_outbox_fingerprint(array $form, array $attachments): string {
    $files = array_map(static fn(array $file): string => ($file['name'] ?? '') . ':' . (int)($file['size'] ?? 0), $attachments);
    sort($files, SORT_STRING);
    return hash('sha256', json_encode([
        strtolower(trim((string)($form['email'] ?? ''))),
        (string)($form['name'] ?? ''),
        (string)($form['details'] ?? ''),
        (string)($form['product_url'] ?? ''),
        (string)($form['product_sku'] ?? ''),
        $files,
    ]));
}

/**
 * Persist one accepted inquiry.
 *
 * @param array $messages ['sales' => [...], 'autoreply' => [...]] rendered messages.
 * @param array $attachments [['path' => upload temp path, 'name' => ..., 'size' => ...], ...]
 * @return array{ok: bool, duplicate: bool, id: ?string, inquiry_id: ?string}
 */
function kssmi_outbox_enqueue(array $messages, array $record, array $attachments, string $fingerprint, ?int $now = null): array {
    $now ??= time();
    $failed = ['ok' => false, 'duplicate' => false, 'id' => null, 'inquiry_id' => null];
    $dir = kssmi_outbox_dir();
    if (!kssmi_outbox_ensure_dir($dir) || !kssmi_outbox_ensure_dir($dir . '/.keys')) return $failed;
    kssmi_outbox_prune($now);

    $lock = @fopen($dir . '/.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) return $failed;
    try {
        // A retried request (lost response, second click with a new
        // Turnstile token) must not become a second inquiry.
        $keyPath = $dir . '/.keys/' . $fingerprint;
        $known = json_decode((string)@file_get_contents($keyPath), true);
        if (is_array($known) && $now - (int)($known['at'] ?? 0) <= KSSMI_OUTBOX_DUPLICATE_WINDOW) {
            return ['ok' => true, 'duplicate' => true, 'id' => $known['id'] ?? null, 'inquiry_id' => $known['inquiry_id'] ?? null];
        }
        foreach (glob($dir . '/.keys/*') ?: [] as $stale) {
            $entry = json_decode((string)@file_get_contents($stale), true);
            if (!is_array($entry) || $now - (int)($entry['at'] ?? 0) > KSSMI_OUTBOX_DUPLICATE_WINDOW) @unlink($stale);
        }

        $id = gmdate('YmdHis', $now) . '-' . bin2hex(random_bytes(8));
        if (isset($record['form']['inquiry_consent'])) {
            require_once __DIR__ . '/inquiry-privacy.php';
            kssmi_inquiry_prune_consent_receipts($dir, $now);
            if (!kssmi_inquiry_persist_consent_receipt($dir, $id, $record, $now)) return $failed;
        }
        $temp = $dir . '/.tmp-' . $id;
        if (!@mkdir($temp, 0700)) return $failed;
        $files = [];
        foreach (array_values($attachments) as $index => $file) {
            $stored = 'att-' . $index;
            $contents = @file_get_contents((string)($file['path'] ?? ''));
            if (!is_string($contents) || !kssmi_outbox_write_file($temp . '/' . $stored, $contents)) {
                kssmi_outbox_remove_tree($temp);
                return $failed;
            }
            $files[] = ['file' => $stored, 'name' => (string)($file['name'] ?? $stored)];
        }
        $steps = [];
        foreach (KSSMI_OUTBOX_STEPS as $step) {
            $steps[$step] = ['state' => 'pending', 'attempts' => 0, 'last_error' => null, 'uncertain' => false, 'sent_at' => null, 'message' => $messages[$step]];
        }
        $steps['sales']['message']['attachments'] = $files;
        $job = [
            'schema' => KSSMI_OUTBOX_SCHEMA,
            'id' => $id,
            'inquiry_id' => (string)($record['inquiry_id'] ?? ''),
            'created_at' => $now,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'record' => $record,
            'steps' => $steps,
        ];
        if (!kssmi_outbox_save_job($temp, $job) || !@rename($temp, $dir . '/' . $id)) {
            kssmi_outbox_remove_tree($temp);
            return $failed;
        }
        $keyTemp = $keyPath . '.tmp-' . bin2hex(random_bytes(4));
        if (kssmi_outbox_write_file($keyTemp, json_encode(['id' => $id, 'inquiry_id' => $job['inquiry_id'], 'at' => $now]))) {
            @rename($keyTemp, $keyPath) || @unlink($keyTemp);
        }
        return ['ok' => true, 'duplicate' => false, 'id' => $id, 'inquiry_id' => $job['inquiry_id']];
    } catch (Throwable $error) {
        error_log('KSSMI outbox enqueue failed: ' . $error->getMessage());
        return $failed;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function kssmi_outbox_retry_delay(int $attempts): int {
    $delays = KSSMI_OUTBOX_RETRY_DELAYS;
    return $delays[min(max($attempts, 1), count($delays)) - 1];
}

/**
 * Send whatever is still unsent for one job, under that job's lock.
 *
 * $send(string $step, array $message, string $jobDir, array $job) returns
 * ['state' => 'sent'|'failed'|'uncertain', 'error' => ?string, 'ms' => int].
 * $onFinal(string $step, string $state, array $job) runs once per step when it
 * is sent or given up (logging, counters).
 *
 * @return 'done'|'retry'|'dead'|'busy'|'not_due'|'missing'
 */
function kssmi_outbox_process(string $id, callable $send, callable $onFinal, ?int $now = null): string {
    $now ??= time();
    if (preg_match('/^[0-9]{14}-[a-f0-9]{16}$/D', $id) !== 1) return 'missing';
    $jobDir = kssmi_outbox_dir() . '/' . $id;
    if (!is_dir($jobDir) || is_link($jobDir)) return 'missing';
    $lock = @fopen($jobDir . '/.lock', 'c');
    if ($lock === false) return 'busy';
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return 'busy';
    }
    $completed = false;
    try {
        $job = kssmi_outbox_load_job($jobDir);
        if ($job === null) {
            error_log('KSSMI outbox ALERT: unreadable job ' . $id);
            return 'missing';
        }
        if ((int)$job['next_attempt_at'] > $now) return 'not_due';
        foreach (KSSMI_OUTBOX_STEPS as $step) {
            if ($job['steps'][$step]['state'] === 'dead') return 'dead';
        }

        $remaining = false;
        $dead = false;
        foreach (KSSMI_OUTBOX_STEPS as $step) {
            $state = $job['steps'][$step]['state'];
            if ($state === 'sent' || $state === 'dead') {
                $dead = $dead || $state === 'dead';
                continue;
            }
            try {
                $result = $send($step, $job['steps'][$step]['message'], $jobDir, $job);
            } catch (Throwable $error) {
                $result = ['state' => 'failed', 'error' => $error->getMessage(), 'ms' => 0];
            }
            $job['steps'][$step]['attempts']++;
            if (($result['state'] ?? '') === 'sent') {
                $job['steps'][$step]['state'] = 'sent';
                $job['steps'][$step]['sent_at'] = $now;
                $job['steps'][$step]['last_error'] = null;
                kssmi_outbox_save_job($jobDir, $job);
                error_log(sprintf('KSSMI outbox %s %s sent in %d ms (attempt %d)', $job['inquiry_id'], $step, (int)($result['ms'] ?? 0), $job['steps'][$step]['attempts']));
                $onFinal($step, 'sent', $job);
                continue;
            }
            $job['steps'][$step]['last_error'] = substr((string)($result['error'] ?? 'unknown error'), 0, 500);
            if (($result['state'] ?? '') === 'uncertain') $job['steps'][$step]['uncertain'] = true;
            $remaining = true;
            error_log(sprintf('KSSMI outbox %s %s %s (attempt %d): %s', $job['inquiry_id'], $step, $result['state'] ?? 'failed', $job['steps'][$step]['attempts'], $job['steps'][$step]['last_error']));
        }

        if (!$remaining) {
            if ($dead) {
                kssmi_outbox_save_job($jobDir, $job);
                return 'dead';
            }
            // Removed in finally, once the lock file is closed.
            $completed = true;
            return 'done';
        }
        $job['attempts']++;
        if ($now - (int)$job['created_at'] >= KSSMI_OUTBOX_GIVE_UP_AFTER) {
            foreach (KSSMI_OUTBOX_STEPS as $step) {
                if ($job['steps'][$step]['state'] !== 'sent') {
                    $job['steps'][$step]['state'] = 'dead';
                    error_log(sprintf('KSSMI outbox ALERT: %s %s not delivered after 72 hours; kept in %s', $job['inquiry_id'], $step, $jobDir));
                    $onFinal($step, 'dead', $job);
                }
            }
            kssmi_outbox_save_job($jobDir, $job);
            return 'dead';
        }
        $job['next_attempt_at'] = $now + kssmi_outbox_retry_delay($job['attempts']);
        kssmi_outbox_save_job($jobDir, $job);
        return 'retry';
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        if ($completed) kssmi_outbox_remove_tree($jobDir);
    }
}

/** Ids of jobs on disk, oldest first. */
function kssmi_outbox_job_ids(): array {
    $ids = [];
    foreach (scandir(kssmi_outbox_dir()) ?: [] as $name) {
        if (preg_match('/^[0-9]{14}-[a-f0-9]{16}$/D', $name) === 1) $ids[] = $name;
    }
    sort($ids, SORT_STRING);
    return $ids;
}

/**
 * Process due jobs, oldest first, within a count and time budget.
 *
 * @return array<string, string> job id => result
 */
function kssmi_outbox_drain(callable $send, callable $onFinal, int $limit = 5, int $budgetSeconds = 45, ?string $skip = null, ?int $now = null): array {
    $started = time();
    $results = [];
    foreach (kssmi_outbox_job_ids() as $id) {
        if ($id === $skip) continue;
        if (count($results) >= $limit || time() - $started >= $budgetSeconds) break;
        $result = kssmi_outbox_process($id, $send, $onFinal, $now);
        // Jobs that are not due, or already given up, do not use the budget.
        if ($result !== 'not_due' && $result !== 'dead') $results[$id] = $result;
    }
    return $results;
}

/** @return array{pending: int, dead: int, oldest: ?int} */
function kssmi_outbox_summary(): array {
    $summary = ['pending' => 0, 'dead' => 0, 'oldest' => null];
    if (!is_dir(kssmi_outbox_dir())) return $summary;
    foreach (kssmi_outbox_job_ids() as $id) {
        $job = kssmi_outbox_load_job(kssmi_outbox_dir() . '/' . $id);
        if ($job === null) {
            $summary['dead']++;
            continue;
        }
        $isDead = false;
        foreach (KSSMI_OUTBOX_STEPS as $step) $isDead = $isDead || $job['steps'][$step]['state'] === 'dead';
        $isDead ? $summary['dead']++ : $summary['pending']++;
        $summary['oldest'] = $summary['oldest'] === null ? (int)$job['created_at'] : min($summary['oldest'], (int)$job['created_at']);
    }
    return $summary;
}

/** Complete the HTTP response now and keep running, where the SAPI allows it. */
function kssmi_outbox_finish_response(): bool {
    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('litespeed_finish_request')) return litespeed_finish_request();
    if (function_exists('fastcgi_finish_request')) return fastcgi_finish_request();
    return false;
}
