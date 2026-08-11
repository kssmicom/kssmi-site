<?php
/**
 * Shared Email Logs policy and storage.
 *
 * Every reader and writer locks a stable sidecar file. Writers then replace
 * the JSON file atomically, so readers never observe a truncated document and
 * concurrent admin actions cannot overwrite a newly accepted inquiry.
 */

function kssmi_email_logs_array_is_list($value) {
    if (!is_array($value)) return false;
    if (function_exists('array_is_list')) return array_is_list($value);

    $expectedKey = 0;
    foreach ($value as $key => $_value) {
        if ($key !== $expectedKey) return false;
        $expectedKey++;
    }
    return true;
}

function kssmi_email_logs_cutover_is_active($path, $now = null) {
    $marker = dirname($path) . '/email-log-cutover-until';
    $markerExists = file_exists($marker) || is_link($marker);
    if (!$markerExists) return false;

    // A present but unreadable/malformed deployment barrier is an operational
    // fault, not permission to resume mutations. Fail closed until an operator
    // repairs/removes it or a valid timestamp expires.
    if (!is_readable($marker)) {
        static $reportedUnreadableMarkers = [];
        if (!isset($reportedUnreadableMarkers[$marker])) {
            error_log('KSSMI Email Logs: cutover marker is not readable: ' . $marker);
            $reportedUnreadableMarkers[$marker] = true;
        }
        return true;
    }

    $raw = @file_get_contents($marker);
    if (!is_string($raw) || !ctype_digit(trim($raw))) {
        static $reportedMalformedMarkers = [];
        if (!isset($reportedMalformedMarkers[$marker])) {
            error_log('KSSMI Email Logs: cutover marker is malformed: ' . $marker);
            $reportedMalformedMarkers[$marker] = true;
        }
        return true;
    }
    $currentTime = $now === null ? time() : (int)$now;
    return (int)trim($raw) > $currentTime;
}

function kssmi_email_log_is_accepted($log) {
    if (!is_array($log)) return false;

    $hasModernSecurityMarkers =
        array_key_exists('security_state', $log) ||
        array_key_exists('security_verified', $log) ||
        array_key_exists('failure_type', $log);
    if ($hasModernSecurityMarkers) {
        return
            in_array(($log['status'] ?? null), ['success', 'failed'], true) &&
            ($log['security_state'] ?? null) === 'verified' &&
            ($log['security_verified'] ?? null) === true;
    }

    $status = is_scalar($log['status'] ?? null) ? (string)$log['status'] : '';
    if ($status === 'success') return true;
    if ($status !== 'failed') return false;

    // Legacy failed rows are accepted only when their message is one of the
    // delivery errors emitted by send-mail.php. Unknown failures fail closed.
    $message = is_scalar($log['message'] ?? null) ? (string)$log['message'] : '';
    return in_array($message, [
        'PHPMailer missing',
        'PHPMailer error',
        'General error',
    ], true);
}

function kssmi_email_log_is_resend_eligible($log) {
    if (!kssmi_email_log_is_accepted($log)) return false;

    $status = is_scalar($log['status'] ?? null) ? (string)$log['status'] : '';
    if ($status !== 'failed') return false;

    $hasModernSecurityMarkers =
        array_key_exists('security_state', $log) ||
        array_key_exists('security_verified', $log) ||
        array_key_exists('failure_type', $log);
    if ($hasModernSecurityMarkers) {
        if (
            ($log['security_state'] ?? null) !== 'verified' ||
            ($log['security_verified'] ?? null) !== true ||
            ($log['failure_type'] ?? null) !== 'delivery'
        ) {
            return false;
        }
    }

    // Any persisted claim blocks automatic retry. An active claim is still in
    // progress; an expired claim has an uncertain SMTP outcome and requires
    // manual review rather than risking a duplicate customer email.
    if (kssmi_email_log_has_resend_claim($log)) return false;

    // Legacy rows reach this point only through the delivery-message allowlist.
    return true;
}

function kssmi_email_log_has_resend_claim($log) {
    if (!is_array($log)) return false;
    $token = $log['resend_token'] ?? null;
    return is_string($token) && $token !== '';
}

function kssmi_email_log_has_active_resend_claim($log, $now = null, $ttlSeconds = 900) {
    if (!kssmi_email_log_has_resend_claim($log)) return false;
    if (($log['resend_outcome'] ?? null) === 'uncertain') return false;
    $claimedAt = $log['resend_claimed_unix'] ?? null;
    if (!is_numeric($claimedAt)) return false;

    $currentTime = $now === null ? time() : (int)$now;
    return (int)$claimedAt > $currentTime - max(60, (int)$ttlSeconds);
}

function kssmi_email_logs_decode($raw, &$error) {
    $error = null;
    if (!is_string($raw)) {
        $error = 'invalid_json';
        return null;
    }
    if (trim($raw) === '') {
        $error = 'empty_existing_file';
        return null;
    }

    // Decode once into the associative shape consumed by every caller. Root
    // array syntax is checked from the JSON text so a numeric-key object
    // cannot masquerade as a PHP list after associative decoding.
    $rootOffset = strspn($raw, " \t\r\n");
    $logs = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = 'invalid_json';
        return null;
    }

    if ($rootOffset >= strlen($raw)
        || $raw[$rootOffset] !== '['
        || !kssmi_email_logs_is_valid_list($logs)) {
        $error = 'invalid_schema';
        return null;
    }
    return $logs;
}

function kssmi_email_logs_is_valid_list($logs) {
    if (!kssmi_email_logs_array_is_list($logs)) return false;
    foreach ($logs as $log) {
        if (!is_array($log) || kssmi_email_logs_array_is_list($log)) return false;
    }
    return true;
}

function kssmi_email_logs_write_all($handle, $contents) {
    $length = strlen($contents);
    $offset = 0;

    while ($offset < $length) {
        $written = fwrite($handle, substr($contents, $offset));
        if ($written === false || $written === 0) return false;
        $offset += $written;
    }

    return fflush($handle);
}

function kssmi_email_logs_atomic_write($path, $contents, $mode = 0640) {
    $parent = dirname($path);
    if (!is_dir($parent) || !is_writable($parent)) return false;

    $suffix = '';
    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $suffix = str_replace('.', '', uniqid('', true));
    }

    $tempPath = $path . '.tmp-' . $suffix;
    $handle = @fopen($tempPath, 'x+b');
    if ($handle === false) return false;

    // Restrict the file before writing any customer data. A process crash must
    // never leave a partially written, world-readable temporary file.
    if (!@chmod($tempPath, $mode)) {
        fclose($handle);
        @unlink($tempPath);
        return false;
    }

    $written = kssmi_email_logs_write_all($handle, $contents);
    if ($written && function_exists('fsync')) {
        $written = @fsync($handle);
    }
    fclose($handle);

    if (!$written) {
        @unlink($tempPath);
        return false;
    }

    if (!@rename($tempPath, $path)) {
        @unlink($tempPath);
        return false;
    }

    @chmod($path, $mode);
    return true;
}

function kssmi_email_logs_lock($path, $operation) {
    $parent = dirname($path);
    if (!is_dir($parent) || !is_writable($parent)) {
        return ['ok' => false, 'logs' => [], 'error' => 'parent_not_writable'];
    }

    $lockPath = $path . '.lock';
    $handle = @fopen($lockPath, 'c+b');
    if ($handle === false) {
        return ['ok' => false, 'logs' => [], 'error' => 'lock_open_failed'];
    }

    @chmod($lockPath, 0640);
    if (!flock($handle, $operation)) {
        fclose($handle);
        return ['ok' => false, 'logs' => [], 'error' => 'lock_failed'];
    }

    return ['ok' => true, 'handle' => $handle, 'lock_path' => $lockPath];
}

function kssmi_email_logs_unlock($lock) {
    if (!is_array($lock) || !isset($lock['handle']) || !is_resource($lock['handle'])) return;
    flock($lock['handle'], LOCK_UN);
    fclose($lock['handle']);
}

function kssmi_email_logs_read($path) {
    $lock = kssmi_email_logs_lock($path, LOCK_SH);
    if (!$lock['ok']) return $lock;

    try {
        if (!file_exists($path)) {
            return ['ok' => true, 'logs' => [], 'error' => null];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['ok' => false, 'logs' => [], 'error' => 'read_failed'];
        }

        $decodeError = null;
        $logs = kssmi_email_logs_decode($raw, $decodeError);
        if ($decodeError !== null) {
            return ['ok' => false, 'logs' => [], 'error' => $decodeError];
        }

        return ['ok' => true, 'logs' => $logs, 'error' => null];
    } finally {
        kssmi_email_logs_unlock($lock);
    }
}

function kssmi_email_logs_cleanup_temp_files($path, $maxAgeSeconds = 3600) {
    $cutoff = time() - max(300, (int)$maxAgeSeconds);
    $patterns = [
        $path . '.tmp-*',
        $path . '.corrupt-*.tmp-*',
    ];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $tempPath) {
            $mtime = @filemtime($tempPath);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($tempPath);
            }
        }
    }
}

function kssmi_email_logs_preserve_corrupt($path, $raw, $maxBackups = 5) {
    $hash = substr(hash('sha256', $raw), 0, 20);
    $backupPath = $path . '.corrupt-' . $hash;

    if (file_exists($backupPath)) {
        $existing = @file_get_contents($backupPath);
        if ($existing === $raw) return $backupPath;
        $backupPath .= '-' . bin2hex(random_bytes(4));
    }

    if (!kssmi_email_logs_atomic_write($backupPath, $raw, 0600)) return false;

    $backups = array_values(array_filter(
        glob($path . '.corrupt-*') ?: [],
        fn($candidate) =>
            $candidate !== $backupPath &&
            strpos(basename($candidate), '.tmp-') === false
    ));
    usort($backups, function($left, $right) {
        return ((int)@filemtime($right)) <=> ((int)@filemtime($left));
    });
    $otherBackupsToKeep = max(0, (int)$maxBackups - 1);
    foreach (array_slice($backups, $otherBackupsToKeep) as $oldBackup) {
        @unlink($oldBackup);
    }

    return $backupPath;
}

function kssmi_email_logs_mutate($path, $mutator) {
    $lock = kssmi_email_logs_lock($path, LOCK_EX);
    if (!$lock['ok']) return $lock;

    try {
        // This check must happen while holding the same lock used by every
        // writer. A request that reached mutate() before deployment created the
        // marker cannot pass through the cutover after waiting for this lock.
        if (kssmi_email_logs_cutover_is_active($path)) {
            return ['ok' => false, 'logs' => [], 'error' => 'cutover_in_progress'];
        }

        kssmi_email_logs_cleanup_temp_files($path);
        $pathExists = file_exists($path);
        $raw = $pathExists ? @file_get_contents($path) : '[]';
        if ($raw === false) {
            return ['ok' => false, 'logs' => [], 'error' => 'read_failed'];
        }

        $decodeError = null;
        $logs = kssmi_email_logs_decode($raw, $decodeError);
        if ($decodeError !== null) {
            $backupPath = kssmi_email_logs_preserve_corrupt($path, $raw);
            if ($backupPath === false) {
                return [
                    'ok' => false,
                    'logs' => [],
                    'error' => $decodeError . '_backup_failed',
                ];
            }

            error_log('KSSMI Email Logs: invalid JSON preserved at ' . $backupPath);
            return [
                'ok' => false,
                'logs' => [],
                'error' => $decodeError,
                'backup_path' => $backupPath,
            ];
        }

        try {
            $updatedLogs = $mutator($logs);
        } catch (Throwable $e) {
            error_log('KSSMI Email Logs: mutation failed: ' . $e->getMessage());
            return ['ok' => false, 'logs' => $logs, 'error' => 'mutation_failed'];
        }

        if (!kssmi_email_logs_is_valid_list($updatedLogs)) {
            return ['ok' => false, 'logs' => $logs, 'error' => 'mutation_invalid'];
        }

        $encoded = json_encode($updatedLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return ['ok' => false, 'logs' => $logs, 'error' => 'encode_failed'];
        }

        if (!kssmi_email_logs_atomic_write($path, $encoded, 0640)) {
            return ['ok' => false, 'logs' => $logs, 'error' => 'write_failed'];
        }

        return ['ok' => true, 'logs' => $updatedLogs, 'error' => null];
    } finally {
        kssmi_email_logs_unlock($lock);
    }
}

function kssmi_email_logs_claim_resend($path, $id, $ttlSeconds = 900) {
    $id = is_scalar($id) ? (string)$id : '';
    if ($id === '') {
        return ['ok' => false, 'claimed' => false, 'error' => 'invalid_id'];
    }

    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return ['ok' => false, 'claimed' => false, 'error' => 'token_failed'];
    }

    $claimedLog = null;
    $found = false;
    $blocked = false;
    $blockReason = null;
    $now = time();
    $mutation = kssmi_email_logs_mutate(
        $path,
        function($logs) use (
            $id,
            $token,
            $ttlSeconds,
            $now,
            &$claimedLog,
            &$found,
            &$blocked,
            &$blockReason
        ) {
            foreach ($logs as $index => $log) {
                if ((string)($log['id'] ?? '') !== $id) continue;
                $found = true;
                if (kssmi_email_log_has_resend_claim($log)) {
                    $blocked = true;
                    $blockReason = kssmi_email_log_has_active_resend_claim(
                        $log,
                        $now,
                        $ttlSeconds
                    )
                        ? 'resend_in_progress'
                        : 'resend_outcome_uncertain';
                    break;
                }
                if (!kssmi_email_log_is_resend_eligible($log)) {
                    $blocked = true;
                    $blockReason = 'not_eligible';
                    break;
                }

                $logs[$index]['resend_token'] = $token;
                $logs[$index]['resend_claimed_unix'] = $now;
                $logs[$index]['resend_claimed_at'] = date('Y-m-d H:i:s T', $now);
                $claimedLog = $logs[$index];
                break;
            }
            return $logs;
        }
    );

    if (!$mutation['ok']) {
        return [
            'ok' => false,
            'claimed' => false,
            'error' => $mutation['error'] ?? 'claim_write_failed',
        ];
    }

    return [
        'ok' => true,
        'claimed' => $claimedLog !== null,
        'found' => $found,
        'blocked' => $blocked,
        'block_reason' => $blockReason,
        'token' => $claimedLog !== null ? $token : null,
        'log' => $claimedLog,
        'error' => null,
    ];
}

function kssmi_email_logs_resolve_uncertain_resend($path, $id, $ttlSeconds = 900) {
    $id = is_scalar($id) ? (string)$id : '';
    if ($id === '') {
        return ['ok' => false, 'resolved' => false, 'error' => 'invalid_id'];
    }

    $resolved = false;
    $found = false;
    $blockedReason = null;
    $now = time();
    $mutation = kssmi_email_logs_mutate(
        $path,
        function($logs) use (
            $id,
            $ttlSeconds,
            $now,
            &$resolved,
            &$found,
            &$blockedReason
        ) {
            foreach ($logs as $index => $log) {
                if ((string)($log['id'] ?? '') !== $id) continue;
                $found = true;

                if (!kssmi_email_log_has_resend_claim($log)) {
                    $blockedReason = 'no_claim';
                    break;
                }
                if (kssmi_email_log_has_active_resend_claim($log, $now, $ttlSeconds)) {
                    $blockedReason = 'resend_in_progress';
                    break;
                }

                // Validate eligibility without the stale claim itself. Only an
                // authenticated admin action on a verified delivery failure can
                // unlock the row after the mailbox has been checked.
                $candidate = $log;
                unset(
                    $candidate['resend_token'],
                    $candidate['resend_claimed_unix'],
                    $candidate['resend_claimed_at'],
                    $candidate['resend_outcome']
                );
                // An explicit uncertain resend is a verified delivery failure
                // once the admin confirms it was not received.
                if (($log['resend_outcome'] ?? null) === 'uncertain') {
                    $candidate['failure_type'] = 'delivery';
                }
                if (!kssmi_email_log_is_resend_eligible($candidate)) {
                    $blockedReason = 'not_eligible';
                    break;
                }

                unset(
                    $logs[$index]['resend_token'],
                    $logs[$index]['resend_claimed_unix'],
                    $logs[$index]['resend_claimed_at'],
                    $logs[$index]['resend_outcome']
                );
                $logs[$index]['failure_type'] = 'delivery';
                $logs[$index]['delivery_outcome'] = 'definite_failure';
                $logs[$index]['resend_outcome_reviewed_at'] = date('Y-m-d H:i:s T', $now);
                $logs[$index]['resend_review_disposition'] = 'confirmed_not_received';
                $resolved = true;
                break;
            }
            return $logs;
        }
    );

    if (!$mutation['ok']) {
        return [
            'ok' => false,
            'resolved' => false,
            'error' => $mutation['error'] ?? 'resolve_write_failed',
        ];
    }

    return [
        'ok' => true,
        'resolved' => $resolved,
        'found' => $found,
        'blocked_reason' => $blockedReason,
        'error' => null,
    ];
}

function kssmi_email_logs_finish_resend($path, $id, $token, $result) {
    $updated = false;
    $mutation = kssmi_email_logs_mutate(
        $path,
        function($logs) use ($id, $token, $result, &$updated) {
            foreach ($logs as $index => $log) {
                if ((string)($log['id'] ?? '') !== (string)$id) continue;
                if (!is_string($token) || $token === '' || ($log['resend_token'] ?? null) !== $token) {
                    break;
                }

                $outcome = is_scalar($result['outcome'] ?? null)
                    ? (string)$result['outcome']
                    : (($result['success'] ?? false) === true ? 'success' : 'definite_failure');
                if (!in_array($outcome, ['success', 'definite_failure', 'uncertain'], true)) {
                    $outcome = 'uncertain';
                }
                $success = $outcome === 'success';
                $logs[$index]['status'] = $success ? 'success' : 'failed';
                $logs[$index]['security_state'] = 'verified';
                $logs[$index]['security_verified'] = true;
                $logs[$index]['failure_type'] =
                    $outcome === 'uncertain' ? 'delivery_uncertain' : ($success ? null : 'delivery');
                $logs[$index]['delivery_outcome'] = $outcome;
                $logs[$index]['resent_at'] = date('Y-m-d H:i:s T');
                if ($success) {
                    $logs[$index]['message'] = 'Email resent successfully';
                    $logs[$index]['error'] = '';
                } elseif ($outcome === 'uncertain') {
                    $logs[$index]['message'] = 'Resend delivery outcome uncertain';
                    $logs[$index]['error'] = is_scalar($result['error'] ?? null)
                        ? (string)$result['error']
                        : 'SMTP outcome uncertain';
                } else {
                    $logs[$index]['error'] = is_scalar($result['error'] ?? null)
                        ? (string)$result['error']
                        : 'Unknown error';
                }
                if ($outcome === 'uncertain') {
                    // Keep the claim and expose the uncertainty immediately.
                    // Only explicit mailbox review may unlock another attempt.
                    $logs[$index]['resend_outcome'] = 'uncertain';
                } else {
                    unset(
                        $logs[$index]['resend_token'],
                        $logs[$index]['resend_claimed_unix'],
                        $logs[$index]['resend_claimed_at'],
                        $logs[$index]['resend_outcome']
                    );
                }
                $updated = true;
                break;
            }
            return $logs;
        }
    );

    $mutation['updated'] = $updated;
    return $mutation;
}
