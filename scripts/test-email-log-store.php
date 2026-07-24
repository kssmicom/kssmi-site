<?php
require_once dirname(__DIR__) . '/private/email-log-store.php';

if (($argv[1] ?? '') === '--cutover-worker') {
    $workerLogPath = $argv[2] ?? '';
    $readyPath = $argv[3] ?? '';
    touch($readyPath);
    $workerResult = kssmi_email_logs_mutate(
        $workerLogPath,
        function($logs) {
            $logs[] = ['id' => 'must-not-cross-cutover'];
            return $logs;
        }
    );
    $blocked =
        !$workerResult['ok'] &&
        ($workerResult['error'] ?? '') === 'cutover_in_progress';
    echo $blocked ? '1' : '0';
    exit($blocked ? 0 : 3);
}

if (in_array($argv[1] ?? '', ['--append-worker', '--claim-worker'], true)) {
    $mode = $argv[1];
    $workerLogPath = $argv[2] ?? '';
    $gatePath = $argv[3] ?? '';
    $workerId = $argv[4] ?? '';
    $deadline = microtime(true) + 10;
    while (!file_exists($gatePath) && microtime(true) < $deadline) {
        usleep(1000);
    }
    if (!file_exists($gatePath)) exit(2);

    if ($mode === '--append-worker') {
        $workerResult = kssmi_email_logs_mutate(
            $workerLogPath,
            function($logs) use ($workerId) {
                $logs[] = ['id' => $workerId];
                return $logs;
            }
        );
        echo $workerResult['ok'] ? '1' : '0';
        exit($workerResult['ok'] ? 0 : 3);
    }

    $workerResult = kssmi_email_logs_claim_resend($workerLogPath, 'parallel-delivery');
    echo ($workerResult['ok'] && $workerResult['claimed']) ? '1' : '0';
    exit($workerResult['ok'] ? 0 : 3);
}

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Email log store test failed: {$message}\n");
        exit(1);
    }
}

function run_email_log_workers($mode, $logPath, $workerCount) {
    $gatePath = $logPath . '.worker-gate';
    $processes = [];
    for ($index = 0; $index < $workerCount; $index++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__, $mode, $logPath, $gatePath, "worker-{$index}"],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        assert_true(is_resource($process), "unable to start {$mode}");
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }

    touch($gatePath);
    $successes = 0;
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        assert_true($exitCode === 0, "{$mode} failed: {$stderr}");
        $successes += trim($stdout) === '1' ? 1 : 0;
    }
    @unlink($gatePath);
    return $successes;
}

function run_cutover_lock_worker($logPath, $cutoverMarker) {
    $readyPath = $logPath . '.cutover-worker-ready';
    $lock = kssmi_email_logs_lock($logPath, LOCK_EX);
    assert_true($lock['ok'], 'unable to hold email lock for cutover race test');

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--cutover-worker', $logPath, $readyPath],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    assert_true(is_resource($process), 'unable to start cutover race worker');
    fclose($pipes[0]);

    $deadline = microtime(true) + 10;
    while (!file_exists($readyPath) && microtime(true) < $deadline) {
        usleep(1000);
    }
    assert_true(file_exists($readyPath), 'cutover race worker did not become ready');
    // Give the worker time to reach the held lock. With the old pre-lock-only
    // marker check, it has already passed the check at this point.
    usleep(100000);
    file_put_contents($cutoverMarker, (string)(time() + 60));
    kssmi_email_logs_unlock($lock);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($readyPath);

    assert_true($exitCode === 0, "cutover race worker failed: {$stderr}");
    return trim($stdout) === '1';
}

$legacyValidation = ['status' => 'failed', 'message' => 'Validation failed'];
$legacySecurity = [
    'status' => 'failed',
    'message' => 'Security check failed',
    'error' => 'Security verification failed.',
];
$legacyDelivery = ['status' => 'failed', 'message' => 'PHPMailer error'];
$legacyUnknown = ['status' => 'failed', 'message' => 'Unexpected failure'];
$verifiedDelivery = [
    'status' => 'failed',
    'security_state' => 'verified',
    'security_verified' => true,
    'failure_type' => 'delivery',
];
$debugDelivery = [
    'status' => 'failed',
    'security_state' => 'debug_bypass',
    'security_verified' => false,
    'failure_type' => 'delivery',
];
$missingFailureType = [
    'status' => 'failed',
    'security_state' => 'verified',
    'security_verified' => true,
];
$inconsistentSecurity = [
    'status' => 'failed',
    'security_state' => 'verified',
    'security_verified' => false,
    'failure_type' => 'delivery',
];
$invalidModernStatus = [
    'status' => 'queued',
    'security_state' => 'verified',
    'security_verified' => true,
    'failure_type' => null,
];
$missingModernStatus = [
    'security_state' => 'verified',
    'security_verified' => true,
    'failure_type' => null,
];
$nonScalarModernStatus = [
    'status' => ['failed'],
    'security_state' => 'verified',
    'security_verified' => true,
    'failure_type' => 'delivery',
];

assert_true(!kssmi_email_log_is_accepted($legacyValidation), 'legacy validation rejection accepted');
assert_true(!kssmi_email_log_is_accepted($legacySecurity), 'legacy security rejection accepted');
assert_true(kssmi_email_log_is_accepted($legacyDelivery), 'legacy delivery failure rejected');
assert_true(kssmi_email_log_is_resend_eligible($legacyDelivery), 'legacy delivery failure not resendable');
assert_true(!kssmi_email_log_is_accepted($legacyUnknown), 'unknown legacy failure must fail closed');
assert_true(kssmi_email_log_is_resend_eligible($verifiedDelivery), 'verified delivery failure not resendable');
assert_true(!kssmi_email_log_is_accepted($debugDelivery), 'debug bypass must not be accepted');
assert_true(!kssmi_email_log_is_resend_eligible($debugDelivery), 'debug bypass must not be resendable');
assert_true(
    !kssmi_email_log_is_resend_eligible($missingFailureType),
    'modern row without delivery failure type must fail closed'
);
assert_true(
    !kssmi_email_log_is_accepted($inconsistentSecurity),
    'inconsistent modern security markers must fail closed'
);
assert_true(
    !kssmi_email_log_is_accepted($invalidModernStatus),
    'modern row with an unknown status must fail closed'
);
assert_true(
    !kssmi_email_log_is_accepted($missingModernStatus),
    'modern row with a missing status must fail closed'
);
assert_true(
    !kssmi_email_log_is_accepted($nonScalarModernStatus),
    'modern row with a non-scalar status must fail closed'
);

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-email-log-' . bin2hex(random_bytes(8));
assert_true(mkdir($testRoot, 0700), 'unable to create temporary directory');
$logPath = $testRoot . DIRECTORY_SEPARATOR . 'email-logs.json';

try {
    $cutoverMarker = $testRoot . DIRECTORY_SEPARATOR . 'email-log-cutover-until';
    file_put_contents($cutoverMarker, (string)(time() + 60));
    $cutoverMutation = kssmi_email_logs_mutate($logPath, fn($logs) => $logs);
    assert_true(
        !$cutoverMutation['ok'] &&
        ($cutoverMutation['error'] ?? '') === 'cutover_in_progress',
        'active deployment cutover did not block log mutation'
    );
    unlink($cutoverMarker);

    file_put_contents($cutoverMarker, '');
    $emptyMarkerMutation = kssmi_email_logs_mutate($logPath, fn($logs) => $logs);
    assert_true(
        !$emptyMarkerMutation['ok'] &&
        ($emptyMarkerMutation['error'] ?? '') === 'cutover_in_progress',
        'zero-byte cutover marker failed open'
    );
    file_put_contents($cutoverMarker, 'not-a-timestamp');
    $malformedMarkerMutation = kssmi_email_logs_mutate($logPath, fn($logs) => $logs);
    assert_true(
        !$malformedMarkerMutation['ok'] &&
        ($malformedMarkerMutation['error'] ?? '') === 'cutover_in_progress',
        'malformed cutover marker failed open'
    );
    if (DIRECTORY_SEPARATOR === '/') {
        file_put_contents($cutoverMarker, (string)(time() - 60));
        chmod($cutoverMarker, 0000);
        clearstatcache(true, $cutoverMarker);
        $unreadableMarkerMutation = kssmi_email_logs_mutate($logPath, fn($logs) => $logs);
        chmod($cutoverMarker, 0600);
        assert_true(
            !$unreadableMarkerMutation['ok'] &&
            ($unreadableMarkerMutation['error'] ?? '') === 'cutover_in_progress',
            'unreadable cutover marker failed open'
        );
    }
    file_put_contents($cutoverMarker, (string)(time() - 60));
    $expiredMarkerMutation = kssmi_email_logs_mutate($logPath, fn($logs) => $logs);
    assert_true($expiredMarkerMutation['ok'], 'expired valid cutover marker stayed active');
    unlink($cutoverMarker);

    assert_true(
        run_cutover_lock_worker($logPath, $cutoverMarker),
        'mutation crossed cutover after waiting on the email lock'
    );
    unlink($cutoverMarker);

    $missingParentResult = kssmi_email_logs_mutate(
        $testRoot . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'email-logs.json',
        fn($logs) => $logs
    );
    assert_true(
        !$missingParentResult['ok'] &&
        ($missingParentResult['error'] ?? '') === 'parent_not_writable',
        'missing/non-writable parent did not fail closed'
    );

    file_put_contents($logPath, '');
    $emptyRead = kssmi_email_logs_read($logPath);
    assert_true(
        !$emptyRead['ok'] &&
        ($emptyRead['error'] ?? '') === 'empty_existing_file',
        'existing zero-byte email log was displayed as an empty log'
    );
    $emptyMutation = kssmi_email_logs_mutate($logPath, fn($logs) => [['id' => 'overwrite']]);
    assert_true(
        !$emptyMutation['ok'] &&
        ($emptyMutation['error'] ?? '') === 'empty_existing_file',
        'existing zero-byte email log mutation failed open'
    );
    assert_true(file_get_contents($logPath) === '', 'zero-byte email log was overwritten');
    assert_true(
        isset($emptyMutation['backup_path']) &&
        file_exists($emptyMutation['backup_path']) &&
        filesize($emptyMutation['backup_path']) === 0,
        'zero-byte email log was not preserved as corruption evidence'
    );
    assert_true(kssmi_email_logs_atomic_write($logPath, '[]'), 'unable to reset zero-byte fixture');

    $first = kssmi_email_logs_mutate($logPath, function($logs) {
        $logs[] = ['id' => 'first'];
        return $logs;
    });
    assert_true($first['ok'], 'initial mutation failed');

    $second = kssmi_email_logs_mutate($logPath, function($logs) {
        $logs[] = ['id' => 'second'];
        return $logs;
    });
    assert_true($second['ok'], 'second mutation failed');

    $read = kssmi_email_logs_read($logPath);
    assert_true($read['ok'], 'locked read failed');
    assert_true(count($read['logs']) === 2, 'mutations did not preserve both rows');
    if (DIRECTORY_SEPARATOR === '/') {
        assert_true(
            (fileperms($logPath) & 0777) === 0640,
            'final log permissions are not 0640'
        );
    }

    assert_true(kssmi_email_logs_atomic_write($logPath, '[]'), 'unable to reset concurrent append fixture');
    $appendSuccesses = run_email_log_workers('--append-worker', $logPath, 8);
    assert_true($appendSuccesses === 8, 'a concurrent append worker failed');
    $parallelAppends = kssmi_email_logs_read($logPath);
    assert_true(
        $parallelAppends['ok'] && count($parallelAppends['logs']) === 8,
        'concurrent appends lost a row'
    );

    $invalidJson = '{"broken":';
    assert_true(kssmi_email_logs_atomic_write($logPath, $invalidJson), 'unable to prepare corrupt fixture');
    $corruptMutation = kssmi_email_logs_mutate($logPath, function($logs) {
        return [];
    });
    assert_true(!$corruptMutation['ok'], 'corrupt JSON mutation unexpectedly succeeded');
    assert_true(($corruptMutation['error'] ?? '') === 'invalid_json', 'wrong corrupt JSON error');
    assert_true(file_get_contents($logPath) === $invalidJson, 'corrupt source was overwritten');
    assert_true(isset($corruptMutation['backup_path']), 'corrupt backup path missing');
    assert_true(file_get_contents($corruptMutation['backup_path']) === $invalidJson, 'corrupt backup differs');

    $repeatCorruptMutation = kssmi_email_logs_mutate($logPath, function($logs) {
        return [];
    });
    assert_true(
        ($repeatCorruptMutation['backup_path'] ?? '') === $corruptMutation['backup_path'],
        'identical corrupt data created a duplicate backup'
    );

    assert_true(kssmi_email_logs_atomic_write($logPath, '{"unexpected":"object"}'), 'unable to write object fixture');
    $objectMutation = kssmi_email_logs_mutate($logPath, fn($logs) => []);
    assert_true(
        !$objectMutation['ok'] && ($objectMutation['error'] ?? '') === 'invalid_schema',
        'JSON root object must be rejected'
    );

    assert_true(kssmi_email_logs_atomic_write($logPath, '[{"id":"ok"},[]]'), 'unable to write mixed fixture');
    $mixedMutation = kssmi_email_logs_mutate($logPath, fn($logs) => []);
    assert_true(
        !$mixedMutation['ok'] && ($mixedMutation['error'] ?? '') === 'invalid_schema',
        'mixed log row schema must be rejected'
    );

    assert_true(kssmi_email_logs_atomic_write($logPath, '[{}]'), 'unable to write empty row fixture');
    $emptyRowMutation = kssmi_email_logs_mutate($logPath, fn($logs) => []);
    assert_true(
        !$emptyRowMutation['ok'] && ($emptyRowMutation['error'] ?? '') === 'invalid_schema',
        'empty log row must be rejected'
    );

    assert_true(
        kssmi_email_logs_atomic_write($logPath, '[{"0":"unexpected"}]'),
        'unable to write numeric-key object fixture'
    );
    $numericObjectRead = kssmi_email_logs_read($logPath);
    assert_true(
        !$numericObjectRead['ok'] && ($numericObjectRead['error'] ?? '') === 'invalid_schema',
        'numeric-key object row must not become an accepted list row'
    );

    $originalUncertain = [
        'id' => 'original-delivery-uncertain',
        'status' => 'failed',
        'security_state' => 'verified',
        'security_verified' => true,
        'failure_type' => 'delivery_uncertain',
        'delivery_outcome' => 'uncertain',
    ];
    assert_true(
        kssmi_email_log_is_accepted($originalUncertain) &&
        !kssmi_email_log_is_resend_eligible($originalUncertain),
        'an original uncertain delivery must remain visible but not resendable'
    );

    $resendFixture = [[
        'id' => 'delivery-1',
        'status' => 'failed',
        'security_state' => 'verified',
        'security_verified' => true,
        'failure_type' => 'delivery',
    ]];
    $parallelResendFixture = $resendFixture;
    $parallelResendFixture[0]['id'] = 'parallel-delivery';
    assert_true(
        kssmi_email_logs_atomic_write($logPath, json_encode($parallelResendFixture)),
        'unable to write parallel resend fixture'
    );
    $parallelClaims = run_email_log_workers('--claim-worker', $logPath, 8);
    assert_true($parallelClaims === 1, 'concurrent resend produced more than one claim');

    assert_true(
        kssmi_email_logs_atomic_write(
            $logPath,
            json_encode($resendFixture, JSON_PRETTY_PRINT)
        ),
        'unable to write resend fixture'
    );
    $claim = kssmi_email_logs_claim_resend($logPath, 'delivery-1');
    assert_true($claim['ok'] && $claim['claimed'], 'first resend claim failed');
    $duplicateClaim = kssmi_email_logs_claim_resend($logPath, 'delivery-1');
    assert_true(
        $duplicateClaim['ok'] &&
        !$duplicateClaim['claimed'] &&
        $duplicateClaim['blocked'] &&
        ($duplicateClaim['block_reason'] ?? '') === 'resend_in_progress',
        'second resend claim was not blocked'
    );
    $activeResolve = kssmi_email_logs_resolve_uncertain_resend(
        $logPath,
        'delivery-1'
    );
    assert_true(
        $activeResolve['ok'] &&
        !$activeResolve['resolved'] &&
        ($activeResolve['blocked_reason'] ?? '') === 'resend_in_progress',
        'active resend claim was incorrectly unlocked'
    );
    $wrongFinish = kssmi_email_logs_finish_resend(
        $logPath,
        'delivery-1',
        'wrong-token',
        ['success' => true]
    );
    assert_true($wrongFinish['ok'] && !$wrongFinish['updated'], 'wrong resend token updated the row');
    $finish = kssmi_email_logs_finish_resend(
        $logPath,
        'delivery-1',
        $claim['token'],
        ['success' => true]
    );
    assert_true($finish['ok'] && $finish['updated'], 'valid resend token did not update the row');
    $afterFinish = kssmi_email_logs_read($logPath);
    assert_true(($afterFinish['logs'][0]['status'] ?? '') === 'success', 'resend success status missing');
    assert_true(!isset($afterFinish['logs'][0]['resend_token']), 'resend claim token was not removed');

    $definiteFixture = [[
        'id' => 'delivery-definite',
        'status' => 'failed',
        'security_state' => 'verified',
        'security_verified' => true,
        'failure_type' => 'delivery',
    ]];
    assert_true(
        kssmi_email_logs_atomic_write($logPath, json_encode($definiteFixture)),
        'unable to write definite failure resend fixture'
    );
    $definiteClaim = kssmi_email_logs_claim_resend($logPath, 'delivery-definite');
    assert_true(
        $definiteClaim['ok'] && $definiteClaim['claimed'],
        'unable to claim definite failure resend fixture'
    );
    $definiteFinish = kssmi_email_logs_finish_resend(
        $logPath,
        'delivery-definite',
        $definiteClaim['token'],
        [
            'success' => false,
            'outcome' => 'definite_failure',
            'error' => 'SMTP rejected the message before acceptance',
        ]
    );
    assert_true(
        $definiteFinish['ok'] && $definiteFinish['updated'],
        'definite resend failure was not persisted'
    );
    $afterDefiniteFinish = kssmi_email_logs_read($logPath);
    $definiteLog = $afterDefiniteFinish['logs'][0];
    assert_true(
        !isset($definiteLog['resend_token']) &&
        ($definiteLog['failure_type'] ?? '') === 'delivery' &&
        ($definiteLog['delivery_outcome'] ?? '') === 'definite_failure' &&
        kssmi_email_log_is_resend_eligible($definiteLog),
        'definite resend failure did not clear its claim and remain resendable'
    );

    $uncertainFixture = [[
        'id' => 'delivery-uncertain',
        'status' => 'failed',
        'security_state' => 'verified',
        'security_verified' => true,
        'failure_type' => 'delivery',
    ]];
    assert_true(
        kssmi_email_logs_atomic_write($logPath, json_encode($uncertainFixture)),
        'unable to write uncertain resend fixture'
    );
    $uncertainClaim = kssmi_email_logs_claim_resend($logPath, 'delivery-uncertain');
    assert_true(
        $uncertainClaim['ok'] && $uncertainClaim['claimed'],
        'unable to claim uncertain resend fixture'
    );
    $uncertainFinish = kssmi_email_logs_finish_resend(
        $logPath,
        'delivery-uncertain',
        $uncertainClaim['token'],
        [
            'success' => false,
            'outcome' => 'uncertain',
            'error' => 'SMTP acknowledgement lost',
        ]
    );
    assert_true(
        $uncertainFinish['ok'] && $uncertainFinish['updated'],
        'uncertain resend result was not persisted'
    );
    $afterUncertainFinish = kssmi_email_logs_read($logPath);
    $uncertainLog = $afterUncertainFinish['logs'][0];
    assert_true(
        ($uncertainLog['resend_token'] ?? '') === $uncertainClaim['token'] &&
        ($uncertainLog['resend_outcome'] ?? '') === 'uncertain' &&
        ($uncertainLog['failure_type'] ?? '') === 'delivery_uncertain',
        'uncertain resend cleared its claim or lost its outcome'
    );
    assert_true(
        !kssmi_email_log_has_active_resend_claim($uncertainLog) &&
        !kssmi_email_log_is_resend_eligible($uncertainLog),
        'uncertain resend did not become immediately review-only'
    );
    $uncertainResolve = kssmi_email_logs_resolve_uncertain_resend(
        $logPath,
        'delivery-uncertain'
    );
    assert_true(
        $uncertainResolve['ok'] && $uncertainResolve['resolved'],
        'explicit review did not unlock an immediate uncertain resend'
    );
    $afterUncertainResolve = kssmi_email_logs_read($logPath);
    assert_true(
        !isset($afterUncertainResolve['logs'][0]['resend_token']) &&
        ($afterUncertainResolve['logs'][0]['failure_type'] ?? '') === 'delivery' &&
        kssmi_email_log_is_resend_eligible($afterUncertainResolve['logs'][0]),
        'reviewed uncertain resend did not return to a definite failure state'
    );

    $staleFixture = [[
        'id' => 'delivery-stale',
        'status' => 'failed',
        'security_state' => 'verified',
        'security_verified' => true,
        'failure_type' => 'delivery',
        'resend_token' => 'stale-token',
        'resend_claimed_unix' => time() - 120,
    ]];
    assert_true(
        kssmi_email_logs_atomic_write($logPath, json_encode($staleFixture)),
        'unable to write stale claim fixture'
    );
    $staleClaim = kssmi_email_logs_claim_resend($logPath, 'delivery-stale', 60);
    assert_true(
        $staleClaim['ok'] &&
        !$staleClaim['claimed'] &&
        ($staleClaim['block_reason'] ?? '') === 'resend_outcome_uncertain',
        'stale resend claim did not fail closed as an uncertain outcome'
    );
    $staleResolve = kssmi_email_logs_resolve_uncertain_resend(
        $logPath,
        'delivery-stale',
        60
    );
    assert_true(
        $staleResolve['ok'] && $staleResolve['resolved'],
        'explicit review did not unlock a stale resend claim'
    );
    $afterResolve = kssmi_email_logs_read($logPath);
    assert_true(
        ($afterResolve['logs'][0]['resend_review_disposition'] ?? '') ===
            'confirmed_not_received' &&
        kssmi_email_log_is_resend_eligible($afterResolve['logs'][0]),
        'resolved stale claim was not recorded or did not become eligible'
    );

    $orphanTemp = $logPath . '.tmp-orphan';
    $orphanCorruptTemp = $logPath . '.corrupt-deadbeef.tmp-orphan';
    file_put_contents($orphanTemp, 'partial');
    file_put_contents($orphanCorruptTemp, 'partial');
    touch($orphanTemp, time() - 7200);
    touch($orphanCorruptTemp, time() - 7200);
    $cleanupMutation = kssmi_email_logs_mutate($logPath, fn($logs) => $logs);
    assert_true($cleanupMutation['ok'], 'cleanup mutation failed');
    assert_true(!file_exists($orphanTemp), 'stale temporary file was not cleaned');
    assert_true(
        !file_exists($orphanCorruptTemp),
        'stale corrupt-backup temporary file was not cleaned'
    );
} finally {
    foreach (glob($testRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($testRoot);
}

echo "Email log store tests passed.\n";
