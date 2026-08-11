<?php
declare(strict_types=1);

/**
 * VJT event-authenticity and canonical-Lead isolation tests (F-01).
 *
 * Verifies server-issued identities, purpose/session-bound one-time
 * capabilities, atomic replay rejection, immutable session ownership, and the
 * separation between public click telemetry and SMTP-verified business Leads.
 *
 * Run: php scripts/test-vjt-event-authenticity.php
 */

function kssmi_vjt_auth_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_vjt_auth_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_vjt_auth_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

function kssmi_vjt_auth_source(string $relativePath): string {
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $source = @file_get_contents($path);
    kssmi_vjt_auth_assert(is_string($source), "read static policy source: {$relativePath}");
    return $source;
}

function kssmi_vjt_auth_assert_order(string $source, array $needles, string $label): void {
    $offset = -1;
    foreach ($needles as $needle) {
        $position = strpos($source, $needle);
        kssmi_vjt_auth_assert($position !== false, "{$label} contains {$needle}");
        kssmi_vjt_auth_assert($position > $offset, "{$label} keeps {$needle} in the security/write order");
        $offset = $position;
    }
}

// Worker mode exercises concurrent consumption against the same SQLite file.
// Only the capability-consumption winner may create the submission row.
if (($argv[1] ?? '') === '--consume-worker') {
    [$script, $mode, $dataDirectory, $gatePath, $secretHex, $token,
        $visitorId, $sessionId, $eventId] = $argv;
    putenv('KSSMI_VJT_DATA_DIR=' . $dataDirectory);
    putenv('KSSMI_VJT_EVENT_AUTH_SECRET=' . $secretHex);
    require_once dirname(__DIR__) . '/public/api/vjt-helpers.php';
    require_once dirname(__DIR__) . '/private/vjt-event-auth.php';

    $deadline = microtime(true) + 15.0;
    while (!file_exists($gatePath)) {
        if (microtime(true) >= $deadline) {
            fwrite(STDERR, "worker barrier timeout\n");
            exit(2);
        }
        usleep(1000);
    }

    try {
        $claims = kssmi_vjt_validate_capability($token, 'submission', [
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
        ]);
        if ($claims === null) throw new RuntimeException('worker capability validation failed');

        $db = vjt_db();
        $db->beginTransaction();
        $consumed = kssmi_vjt_consume_capability($claims);
        $writeResult = 'not_attempted';
        if ($consumed) {
            $writeResult = vjt_add_submission([
                'visitor_id' => $visitorId,
                'session_id' => $sessionId,
                'event_id' => $eventId,
                'form_plugin' => 'kssmi-inquiry',
                'form_name' => 'Capability race fixture',
                'submit_page' => 'https://kssmi.com/contact/',
                'status' => 'attempt',
            ]);
            $db->commit();
        } else {
            $db->rollBack();
        }
        fwrite(STDOUT, json_encode([
            'consumed' => $consumed,
            'write_result' => $writeResult,
        ], JSON_UNESCAPED_SLASHES));
        exit(0);
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
}

kssmi_vjt_auth_assert(function_exists('proc_open'), 'proc_open is required for the capability race test');

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'kssmi-vjt-auth-' . bin2hex(random_bytes(6));
kssmi_vjt_auth_assert(mkdir($testDirectory, 0700, true), 'create isolated VJT auth directory');

$secretHex = str_repeat('42', 32);
putenv('KSSMI_VJT_DATA_DIR=' . $testDirectory);
putenv('KSSMI_VJT_EVENT_AUTH_SECRET=' . $secretHex);
$workers = [];

try {
    require_once dirname(__DIR__) . '/public/api/vjt-helpers.php';
    require_once dirname(__DIR__) . '/private/vjt-event-auth.php';
    vjt_data_init();
    $db = vjt_db();
    $now = time();

    // ── Server-issued identity: caller-supplied tracking IDs never win ──
    $callerVisitorId = 'vjtv_' . str_repeat('c', 16);
    $callerSessionId = 'vjts_' . str_repeat('d', 16);
    $_COOKIE = [
        'vjt_visitor_id' => $callerVisitorId,
        'vjt_session_id' => $callerSessionId,
    ];
    $_POST = ['visitor_id' => $callerVisitorId, 'session_id' => $callerSessionId];
    $_REQUEST = $_POST;

    $identity = kssmi_vjt_bootstrap_identity(false, 1800, $now, false);
    kssmi_vjt_auth_assert($identity['visitor_id'] !== $callerVisitorId, 'bootstrap ignores caller visitor_id');
    kssmi_vjt_auth_assert($identity['session_id'] !== $callerSessionId, 'bootstrap ignores caller session_id');
    kssmi_vjt_auth_assert(
        preg_match('/^vjtv_[A-Za-z0-9_-]{8,60}$/D', $identity['visitor_id']) === 1,
        'bootstrap issues a valid server visitor ID'
    );
    kssmi_vjt_auth_assert(
        preg_match('/^vjts_[A-Za-z0-9_-]{8,60}$/D', $identity['session_id']) === 1,
        'bootstrap issues a valid server session ID'
    );

    $_COOKIE[KSSMI_VJT_IDENTITY_COOKIE] = $identity['token'];
    $continued = kssmi_vjt_bootstrap_identity(false, 1800, $now + 1, false);
    kssmi_vjt_auth_assert($continued['visitor_id'] === $identity['visitor_id'], 'valid identity preserves visitor ID');
    kssmi_vjt_auth_assert($continued['session_id'] === $identity['session_id'], 'active identity preserves session ID');
    $_COOKIE[KSSMI_VJT_IDENTITY_COOKIE] = $continued['token'];
    $rotated = kssmi_vjt_bootstrap_identity(true, 1800, $now + 2, false);
    kssmi_vjt_auth_assert($rotated['visitor_id'] === $identity['visitor_id'], 'session rotation preserves visitor ID');
    kssmi_vjt_auth_assert($rotated['session_id'] !== $identity['session_id'], 'session rotation issues a new session ID');

    $binding = [
        'visitor_id' => $rotated['visitor_id'],
        'session_id' => $rotated['session_id'],
    ];

    // ── Capability validation: presence, signature, purpose, expiry, binding ──
    $pageviewToken = kssmi_vjt_issue_capabilities('pageview', $binding, 1, $now + 3)[0];
    $pageviewClaims = kssmi_vjt_validate_capability($pageviewToken, 'pageview', $binding, $now + 3);
    kssmi_vjt_auth_assert(is_array($pageviewClaims), 'valid pageview capability passes');
    kssmi_vjt_auth_assert(
        kssmi_vjt_validate_capability('', 'pageview', $binding, $now + 3) === null,
        'missing capability fails'
    );
    kssmi_vjt_auth_assert(
        kssmi_vjt_validate_capability($pageviewToken, 'submission', $binding, $now + 3) === null,
        'wrong-purpose capability fails'
    );
    kssmi_vjt_auth_assert(
        kssmi_vjt_validate_capability($pageviewToken, 'pageview', [
            'visitor_id' => $binding['visitor_id'],
            'session_id' => 'vjts_' . str_repeat('x', 16),
        ], $now + 3) === null,
        'capability is bound to the server session'
    );
    kssmi_vjt_auth_assert(
        kssmi_vjt_validate_capability($pageviewToken, 'pageview', [
            'visitor_id' => 'vjtv_' . str_repeat('y', 16),
            'session_id' => $binding['session_id'],
        ], $now + 3) === null,
        'capability is bound to the server visitor'
    );
    $tamperedToken = ($pageviewToken[0] === 'A' ? 'B' : 'A') . substr($pageviewToken, 1);
    kssmi_vjt_auth_assert(
        kssmi_vjt_validate_capability($tamperedToken, 'pageview', $binding, $now + 3) === null,
        'tampered capability fails signature validation'
    );
    $expiredToken = kssmi_vjt_issue_capabilities(
        'pageview',
        $binding,
        1,
        $now - KSSMI_VJT_CAPABILITY_TTL - 1
    )[0];
    kssmi_vjt_auth_assert(
        kssmi_vjt_validate_capability($expiredToken, 'pageview', $binding, $now) === null,
        'expired capability fails'
    );

    $_COOKIE = [];
    $contactSession = kssmi_vjt_bootstrap_contact_session($now + 3, false);
    $contactToken = kssmi_vjt_issue_capabilities('contact_intent', $contactSession, 1, $now + 3)[0];
    kssmi_vjt_auth_assert(
        is_array(kssmi_vjt_validate_capability($contactToken, 'contact_intent', $contactSession, $now + 3)),
        'contact capability matches its server contact session'
    );
    kssmi_vjt_auth_assert(
        kssmi_vjt_validate_capability($contactToken, 'contact_intent', [
            'contact_id' => str_repeat('z', 24),
        ], $now + 3) === null,
        'contact capability cannot cross contact sessions'
    );

    // ── Seed the server-owned Journey used by ownership and race tests ──
    vjt_upsert_visitor([
        'visitor_id' => $binding['visitor_id'],
        'site_language' => 'EN',
    ]);
    vjt_upsert_session([
        'session_id' => $binding['session_id'],
        'visitor_id' => $binding['visitor_id'],
        'landing_url' => 'https://kssmi.com/contact/',
        'referrer' => 'https://www.google.com/search?q=kssmi',
    ]);
    $otherVisitorId = 'vjtv_' . str_repeat('o', 16);
    vjt_upsert_visitor(['visitor_id' => $otherVisitorId, 'site_language' => 'EN']);

    $ownerBefore = $db->query('SELECT * FROM sessions WHERE session_id = ' .
        $db->quote($binding['session_id']))->fetch();
    $helperRejectedRebind = false;
    try {
        vjt_upsert_session([
            'session_id' => $binding['session_id'],
            'visitor_id' => $otherVisitorId,
            'utm_source' => 'attacker-controlled',
        ]);
    } catch (DomainException $e) {
        $helperRejectedRebind = true;
    }
    kssmi_vjt_auth_assert($helperRejectedRebind, 'session helper rejects visitor rebinding');
    $ownerAfter = $db->query('SELECT * FROM sessions WHERE session_id = ' .
        $db->quote($binding['session_id']))->fetch();
    kssmi_vjt_auth_assert($ownerAfter === $ownerBefore, 'failed helper rebind leaves the session unchanged');

    $triggerRejectedRebind = false;
    try {
        $stmt = $db->prepare('UPDATE sessions SET visitor_id = ? WHERE session_id = ?');
        $stmt->execute([$otherVisitorId, $binding['session_id']]);
    } catch (PDOException $e) {
        $triggerRejectedRebind = true;
    }
    kssmi_vjt_auth_assert($triggerRejectedRebind, 'SQLite trigger rejects direct session rebinding');
    kssmi_vjt_auth_assert(
        $db->query('SELECT visitor_id FROM sessions WHERE session_id = ' .
            $db->quote($binding['session_id']))->fetchColumn() === $binding['visitor_id'],
        'session owner remains immutable after direct SQL attack'
    );

    $nullOwnerRejected = false;
    try {
        $stmt = $db->prepare('UPDATE sessions SET visitor_id = NULL WHERE session_id = ?');
        $stmt->execute([$binding['session_id']]);
    } catch (PDOException $e) {
        $nullOwnerRejected = true;
    }
    kssmi_vjt_auth_assert($nullOwnerRejected, 'SQLite ownership trigger is NULL-safe');

    $nullChildRejected = false;
    try {
        $stmt = $db->prepare("INSERT INTO pageviews
            (session_id, visitor_id, url, visited_at, step_order)
            VALUES (?, NULL, 'https://kssmi.com/forged/', ?, 1)");
        $stmt->execute([$binding['session_id'], gmdate('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        $nullChildRejected = true;
    }
    kssmi_vjt_auth_assert($nullChildRejected, 'SQLite child ownership trigger rejects NULL visitor IDs');

    // ── Sequential consume/replay ──
    $singleToken = kssmi_vjt_issue_capabilities('submission', $binding, 1, $now + 4)[0];
    $singleClaims = kssmi_vjt_validate_capability($singleToken, 'submission', $binding, $now + 4);
    kssmi_vjt_auth_assert(is_array($singleClaims), 'submission capability validates before consume');
    $db->beginTransaction();
    $firstConsume = kssmi_vjt_consume_capability($singleClaims);
    $db->commit();
    kssmi_vjt_auth_assert($firstConsume, 'first capability consume succeeds inside a transaction');
    $db->beginTransaction();
    $replayConsume = kssmi_vjt_consume_capability($singleClaims);
    $db->rollBack();
    kssmi_vjt_auth_assert(!$replayConsume, 'sequential capability replay fails');
    vjt_wipe_all_data();
    $db->beginTransaction();
    $replayAfterWipe = kssmi_vjt_consume_capability($singleClaims);
    $db->rollBack();
    kssmi_vjt_auth_assert(!$replayAfterWipe, 'admin data wipe cannot revive a consumed capability');

    // Recreate the owned Journey after the wipe for the concurrent race and
    // canonical-isolation fixtures below.
    vjt_upsert_visitor([
        'visitor_id' => $binding['visitor_id'],
        'site_language' => 'EN',
    ]);
    vjt_upsert_session([
        'session_id' => $binding['session_id'],
        'visitor_id' => $binding['visitor_id'],
        'landing_url' => 'https://kssmi.com/contact/',
    ]);

    // ── Concurrent replay: exactly one consume and one business write ──
    $raceToken = kssmi_vjt_issue_capabilities('submission', $binding, 1, $now + 5)[0];
    $raceEventId = 'vjtev_' . str_repeat('r', 32);
    $gatePath = $testDirectory . DIRECTORY_SEPARATOR . '.capability-race-start';
    $workerCount = 8;
    for ($index = 0; $index < $workerCount; $index++) {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __FILE__,
                '--consume-worker',
                $testDirectory,
                $gatePath,
                $secretHex,
                $raceToken,
                $binding['visitor_id'],
                $binding['session_id'],
                $raceEventId,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );
        kssmi_vjt_auth_assert(is_resource($process), "start capability worker {$index}");
        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }
    kssmi_vjt_auth_assert(file_put_contents($gatePath, 'go') === 2, 'release capability race barrier');

    $raceResults = [];
    foreach ($workers as $index => $worker) {
        $stdout = stream_get_contents($worker['stdout']);
        $stderr = stream_get_contents($worker['stderr']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        $exitCode = proc_close($worker['process']);
        kssmi_vjt_auth_assert($exitCode === 0, "capability worker {$index} exited cleanly: {$stderr}");
        $decoded = json_decode($stdout, true);
        kssmi_vjt_auth_assert(is_array($decoded), "capability worker {$index} returned JSON");
        $raceResults[] = $decoded;
    }
    $workers = [];
    @unlink($gatePath);

    $raceWinners = array_values(array_filter(
        $raceResults,
        static fn(array $result): bool => ($result['consumed'] ?? false) === true
    ));
    kssmi_vjt_auth_assert(count($raceWinners) === 1, 'exactly one concurrent capability consume succeeds');
    kssmi_vjt_auth_assert(
        ($raceWinners[0]['write_result'] ?? '') === 'stored',
        'the sole capability winner performs the business write'
    );
    $raceRowCount = $db->query('SELECT COUNT(*) FROM submissions WHERE event_id = ' .
        $db->quote($raceEventId))->fetchColumn();
    kssmi_vjt_auth_assert((int)$raceRowCount === 1, 'concurrent replay creates exactly one submission row');
    kssmi_vjt_auth_assert(
        (int)$db->query('SELECT COUNT(*) FROM used_event_capabilities')->fetchColumn() === 2,
        'only the sequential token and one race token are persisted as consumed'
    );

    // ── Public telemetry cannot claim server verification or enter Leads ──
    $publicEventId = 'vjtce_' . str_repeat('p', 32);
    $publicResult = vjt_add_contact_event([
        'event_id' => $publicEventId,
        'channel' => 'whatsapp',
        'event_type' => 'open_intent',
        'status' => 'intent',
        'page_path' => '/contact/',
        'vjt_visitor_id' => $binding['visitor_id'],
        'vjt_session_id' => $binding['session_id'],
        'server_verified' => 1,
    ]);
    kssmi_vjt_auth_assert(($publicResult['result'] ?? '') === 'stored', 'public contact intent remains raw telemetry');
    $publicRow = $db->query('SELECT * FROM contact_events WHERE event_id = ' .
        $db->quote($publicEventId))->fetch();
    kssmi_vjt_auth_assert((int)$publicRow['server_verified'] === 0, 'public payload cannot set server_verified');
    kssmi_vjt_auth_assert(
        (int)$db->query('SELECT COUNT(*) FROM canonical_contact_events')->fetchColumn() === 0,
        'public intent is absent from the canonical view'
    );
    $overview = vjt_get_overview('2000-01-01 00:00:00');
    kssmi_vjt_auth_assert((int)$overview['totalCore'] === 0, 'public intent does not increase canonical Overview total');
    $leads = vjt_get_leads_list(['status' => 'contact', 'page' => 1, 'per_page' => 100]);
    kssmi_vjt_auth_assert((int)$leads['total'] === 0, 'public intent does not enter the canonical Leads list');

    $publicOutcomeRejected = false;
    try {
        vjt_add_contact_event([
            'channel' => 'inquiry',
            'event_type' => 'submission_success',
            'status' => 'success',
            'server_verified' => 1,
        ]);
    } catch (InvalidArgumentException $e) {
        $publicOutcomeRejected = true;
    }
    kssmi_vjt_auth_assert($publicOutcomeRejected, 'public helper rejects a claimed Inquiry success');

    // ── Server-verified success is canonical even without a Journey link ──
    $verifiedEventId = 'vjtcv_' . str_repeat('v', 64);
    $verifiedResult = vjt_add_verified_contact_event([
        'event_id' => $verifiedEventId,
        'channel' => 'inquiry',
        'event_type' => 'submission_success',
        'status' => 'success',
        'page_path' => '/contact/',
        'placement' => 'inquiry-form',
        'server_verified' => 0,
    ]);
    kssmi_vjt_auth_assert(($verifiedResult['result'] ?? '') === 'stored', 'verified Inquiry success stores');
    $verifiedRow = $db->query('SELECT * FROM contact_events WHERE event_id = ' .
        $db->quote($verifiedEventId))->fetch();
    kssmi_vjt_auth_assert((int)$verifiedRow['server_verified'] === 1, 'verified writer sets server provenance internally');
    kssmi_vjt_auth_assert($verifiedRow['vjt_session_id'] === '', 'verified success does not require Journey linkage');
    kssmi_vjt_auth_assert(
        (int)$db->query('SELECT COUNT(*) FROM canonical_contact_events')->fetchColumn() === 1,
        'unlinked verified success enters the canonical view'
    );
    $overview = vjt_get_overview('2000-01-01 00:00:00');
    kssmi_vjt_auth_assert((int)$overview['totalCore'] === 1, 'verified success increases canonical Overview total');
    $leads = vjt_get_leads_list(['status' => 'contact', 'page' => 1, 'per_page' => 100]);
    kssmi_vjt_auth_assert((int)$leads['total'] === 1, 'unlinked verified success enters canonical Leads');
    kssmi_vjt_auth_assert(
        ($leads['items'][0]['event_id'] ?? '') === $verifiedEventId,
        'canonical Leads contains the verified server event only'
    );

    // ── Static wiring: endpoint wrappers cannot bypass the tested helpers ──
    $postEndpoints = [
        'public/api/track-pageview.php' => 'vjt_upsert_visitor([',
        'public/api/track-submission.php' => 'vjt_add_submission([',
        'public/api/track-contact-intent.php' => 'vjt_add_contact_event([',
    ];
    foreach ($postEndpoints as $relativePath => $writeNeedle) {
        $source = kssmi_vjt_auth_source($relativePath);
        kssmi_vjt_auth_assert(
            strpos($source, "'/private/vjt-event-auth.php'") !== false,
            "{$relativePath} loads the event-auth module"
        );
        kssmi_vjt_auth_assert_order($source, [
            'kssmi_vjt_validate_capability(',
            'beginTransaction()',
            'kssmi_vjt_consume_capability(',
            $writeNeedle,
            'commit()',
        ], $relativePath);
        kssmi_vjt_auth_assert(
            strpos($source, 'vjt_add_verified_contact_event(') === false,
            "{$relativePath} cannot call the server-verified writer"
        );
    }

    $submissionSource = kssmi_vjt_auth_source('public/api/track-submission.php');
    kssmi_vjt_auth_assert(
        preg_match('/\$requestedStatus\s*!==\s*[\'\"]attempt[\'\"]/', $submissionSource) === 1,
        'track-submission explicitly rejects non-attempt client status'
    );
    kssmi_vjt_auth_assert(
        preg_match('/[\'\"]status[\'\"]\s*=>\s*[\'\"]attempt[\'\"]/', $submissionSource) === 1,
        'track-submission persists only attempt status'
    );

    $contactGetSource = kssmi_vjt_auth_source('public/api/contact-intent.php');
    kssmi_vjt_auth_assert(
        strpos($contactGetSource, 'if ($capability === null) $shouldRecord = false;') !== false,
        'contact GET disables recording when the capability is missing or invalid'
    );
    kssmi_vjt_auth_assert(
        strpos($contactGetSource, 'if ($shouldRecord) vjt_add_contact_event([') !== false,
        'contact GET gates its raw write on validated capability state'
    );
    kssmi_vjt_auth_assert(
        strpos($contactGetSource, "header('Location: ' . \$destination, true, 302);") !== false,
        'contact GET preserves customer navigation when recording is skipped'
    );
    kssmi_vjt_auth_assert(
        strpos($contactGetSource, 'vjt_add_verified_contact_event(') === false,
        'contact GET cannot create a verified Lead'
    );

    $sendMailSource = kssmi_vjt_auth_source('public/send-mail.php');
    kssmi_vjt_auth_assert(
        strpos($sendMailSource, 'vjt_add_verified_contact_event([') !== false,
        'send-mail uses the server-verified Inquiry writer'
    );

    $dashboardSource = kssmi_vjt_auth_source('public/visitor-journey.php');
    kssmi_vjt_auth_assert(
        strpos($dashboardSource, 'vjtcv_[A-Za-z0-9_-]{16,96}') !== false
            && strpos($dashboardSource, 'server_verified = 1') !== false,
        'canonical Lead deletion accepts server IDs and rechecks verified provenance'
    );

    $issuerSource = kssmi_vjt_auth_source('public/api/vjt-capability.php');
    kssmi_vjt_auth_assert(
        strpos($issuerSource, 'kssmi_vjt_bootstrap_identity(') !== false,
        'capability issuer bootstraps server-generated analytics identity'
    );
    kssmi_vjt_auth_assert(
        strpos($issuerSource, "\$data['visitor_id']") === false
            && strpos($issuerSource, "\$data['session_id']") === false,
        'capability issuer never accepts caller-selected analytics IDs'
    );

    $package = json_decode(kssmi_vjt_auth_source('package.json'), true);
    kssmi_vjt_auth_assert(is_array($package), 'package.json is valid JSON');
    kssmi_vjt_auth_assert(
        ($package['scripts']['test:vjt-authenticity'] ?? '') === 'php scripts/test-vjt-event-authenticity.php',
        'package exposes the event-authenticity regression test'
    );
    kssmi_vjt_auth_assert(
        strpos((string)($package['scripts']['test:php'] ?? ''), 'npm run test:vjt-authenticity') !== false,
        'full PHP suite includes event-authenticity regression coverage'
    );
    $deploySource = kssmi_vjt_auth_source('.github/workflows/deploy.yml');
    kssmi_vjt_auth_assert(
        strpos($deploySource, 'php scripts/test-vjt-event-authenticity.php') !== false,
        'deployment validation runs the event-authenticity regression test explicitly'
    );

    fwrite(STDOUT, "VJT event-authenticity tests passed.\n");
} finally {
    foreach ($workers as $worker) {
        foreach (['stdout', 'stderr'] as $stream) {
            if (isset($worker[$stream]) && is_resource($worker[$stream])) fclose($worker[$stream]);
        }
        if (isset($worker['process']) && is_resource($worker['process'])) proc_terminate($worker['process']);
    }
    kssmi_vjt_auth_remove_tree($testDirectory);
}
