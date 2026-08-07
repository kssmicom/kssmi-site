import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const read = (relativePath) =>
  fs.readFileSync(path.join(root, relativePath), 'utf8').replace(/\r\n/g, '\n');

const sendMail = read('public/send-mail.php');
const emailLogs = read('public/email-logs.php');
const emailLogStore = read('private/email-log-store.php');
const rateLimit = read('private/rate-limit.php');
const emailLogTests = read('scripts/test-email-log-store.php');
const rateLimitTests = read('scripts/test-rate-limit.php');
const migrationTests = read('scripts/test-email-log-migration.sh');
const deployWorkflow = read('.github/workflows/deploy.yml');
const environmentAction = read('.github/actions/deploy-environment/action.yml');
// Phase 2 moved the deployment runtime behavior (cutover barrier, atomic
// install, permission probes, migration) into scripts/deploy-release.sh, which
// the workflow uploads and executes on the server. The policy literals below
// are checked against the union so the contract survives the relocation.
const deployReleaseScript = read('scripts/deploy-release.sh');
const deploySource = deployWorkflow + '\n' + environmentAction + '\n' + deployReleaseScript;
const inquiryForm = read('src/components/InquiryForm.astro');
const notFoundPage = read('src/components/pages/NotFoundPage.astro');

const failures = [];
const requireText = (source, text, label) => {
  if (!source.includes(text)) failures.push(`${label}: missing ${JSON.stringify(text)}`);
};
const forbidText = (source, text, label) => {
  if (source.includes(text)) failures.push(`${label}: forbidden ${JSON.stringify(text)}`);
};

requireText(sendMail, 'function verifyTurnstileDetailed(', 'Turnstile result classification');
requireText(sendMail, "'service_error' => true", 'Turnstile service errors');
requireText(sendMail, 'http_response_code($isServiceError ? 503 : 403);', 'Turnstile HTTP response');
requireText(sendMail, 'http_response_code(422);', 'Business validation response');
requireText(sendMail, "'debug_mode' => false", 'Production debug mode');
requireText(sendMail, "$securityState = 'verified';", 'Verified security state');
requireText(sendMail, "$securityState = 'debug_bypass';", 'Debug bypass security state');
requireText(sendMail, "'security_state' => $securityState", 'Security-state marker');
requireText(sendMail, "'security_verified' => $securityState === 'verified'", 'Accepted-inquiry marker');
requireText(sendMail, "'failure_type' => is_string($failureType)", 'Delivery-failure marker');
requireText(sendMail, '$logId = bin2hex(random_bytes(16));', 'Cryptographically random log ID');
requireText(sendMail, "'delivery_outcome' => $deliveryOutcome", 'Three-state delivery outcome');
requireText(sendMail, "'delivery_uncertain'", 'Uncertain original SMTP outcome');
requireText(sendMail, '$smtpSendStarted = true;', 'SMTP ambiguity boundary');
requireText(sendMail, "'/email_data/email-logs.json'", 'Private email data directory');
requireText(sendMail, 'kssmi_email_logs_cutover_is_active($emailLogPath)', 'Submission cutover barrier');
requireText(sendMail, "header('Retry-After: 60');", 'Cutover retry response');
requireText(sendMail, "$securityState,\n        'delivery'", 'Delivery failure logging');
requireText(
  sendMail,
  "require_once dirname(__DIR__) . '/private/email-log-store.php';",
  'Shared email log storage',
);
requireText(
  sendMail,
  "if ($isServiceError) {\n            error_log(",
  'Service-only Turnstile diagnostics',
);
forbidText(sendMail, "'; ip=' . getRealIP()", 'Turnstile rejection IP logging');
forbidText(
  sendMail,
  "logEmail($config, $formData, 'failed', 'Validation failed'",
  'Rejected requests must not enter Email Logs',
);
const debugModeAssignments = [
  ...sendMail.matchAll(/['"]debug_mode['"]\s*=>\s*(true|false)/g),
].map((match) => match[1]);
if (debugModeAssignments.length !== 1 || debugModeAssignments[0] !== 'false') {
  failures.push('Production debug mode: expected exactly one false assignment');
}

const securityGateStart = sendMail.indexOf('// Verify Turnstile (skip in debug mode)');
const businessValidationStart = sendMail.indexOf(
  '// Validate required business fields only after the security gate has passed.',
);
const visitorMetadataStart = sendMail.indexOf('// Get visitor metadata');
if (
  securityGateStart < 0 ||
  businessValidationStart < 0 ||
  visitorMetadataStart < 0 ||
  !(securityGateStart < businessValidationStart && businessValidationStart < visitorMetadataStart)
) {
  failures.push('Request processing order: security gate must precede business validation and metadata');
} else {
  const rejectedRequestSection = sendMail.slice(securityGateStart, visitorMetadataStart);
  forbidText(rejectedRequestSection, 'logEmail(', 'Pre-acceptance request handling');
}

requireText(emailLogs, 'function isAcceptedInquiryLog($log)', 'Accepted inquiry classifier');
requireText(emailLogs, 'function isResendEligibleLog($log)', 'Resend classifier');
requireText(
  emailLogs,
  "require_once dirname(__DIR__) . '/private/email-log-store.php';",
  'Admin shared email log storage',
);
requireText(
  emailLogs,
  'kssmi_email_logs_claim_resend(LOGS_FILE, $resendId)',
  'Atomic resend claim',
);
requireText(
  emailLogs,
  'kssmi_email_logs_finish_resend(',
  'Token-matched resend completion',
);
requireText(
  emailLogs,
  'kssmi_email_logs_resolve_uncertain_resend(LOGS_FILE, $resolveId)',
  'Explicit uncertain-resend recovery',
);
requireText(
  emailLogs,
  'is already being resent.',
  'Blocked resend response',
);
requireText(emailLogs, 'confirm_mailbox_checked', 'Mailbox-review confirmation');
requireText(emailLogs, '$mail->MessageID = $messageId;', 'Stable SMTP Message-ID');
requireText(emailLogs, "'Message-ID: ' . $messageId", 'Stable mail fallback Message-ID');
requireText(emailLogs, "'outcome' => 'uncertain'", 'Uncertain resend transport result');
requireText(
  emailLogs,
  'PHPMailer/SMTP was unavailable and therefore never attempted.',
  'Fallback restricted to pre-SMTP state',
);
requireText(emailLogs, '<?php if ($resendEligible): ?>', 'Resend button authorization');
requireText(emailLogs, '<?php if ($acceptedInquiry): ?>', 'Reply action authorization');
requireText(emailLogs, '<h3>Accepted Inquiries</h3>', 'Accepted inquiry statistics');
requireText(emailLogs, '<h3>Delivery Failed</h3>', 'Delivery failure statistics');
forbidText(emailLogs, '$totalEmails = count($logs);', 'Email statistics');
forbidText(emailLogs, 'file_get_contents(LOGS_FILE)', 'Unlocked email log read');
forbidText(emailLogs, 'file_put_contents(LOGS_FILE', 'Unlocked email log write');

requireText(emailLogStore, 'flock($handle, $operation)', 'Stable email log lock');
requireText(
  emailLogStore,
  'function kssmi_email_logs_cutover_is_active(',
  'Shared deployment cutover barrier',
);
requireText(emailLogStore, "'cutover_in_progress'", 'Mutation cutover rejection');
requireText(emailLogStore, 'function kssmi_email_logs_atomic_write(', 'Atomic email log write');
requireText(emailLogStore, 'while ($offset < $length)', 'Complete email log write loop');
requireText(emailLogStore, "'.corrupt-'", 'Corrupt JSON backup');
requireText(emailLogStore, "'invalid_json'", 'Corrupt JSON rejection');
requireText(emailLogStore, "'empty_existing_file'", 'Zero-byte email log rejection');
requireText(emailLogStore, "'invalid_schema'", 'Invalid log schema rejection');
requireText(emailLogStore, 'json_decode($raw);', 'Root JSON type validation');
requireText(emailLogStore, "function_exists('array_is_list')", 'PHP list-check compatibility');
requireText(
  emailLogStore,
  "($log['failure_type'] ?? null) !== 'delivery'",
  'Modern resend fail-closed policy',
);
requireText(
  emailLogStore,
  "in_array(($log['status'] ?? null), ['success', 'failed'], true)",
  'Modern accepted-status allowlist',
);
requireText(emailLogStore, 'function kssmi_email_logs_claim_resend(', 'Shared resend claim');
requireText(
  emailLogStore,
  'function kssmi_email_logs_resolve_uncertain_resend(',
  'Shared uncertain resend recovery',
);
requireText(emailLogStore, 'function kssmi_email_logs_finish_resend(', 'Shared resend completion');
requireText(emailLogStore, 'resend_token', 'Resend claim token');
requireText(emailLogStore, "'resend_outcome_uncertain'", 'Uncertain resend outcome lockout');
requireText(emailLogStore, "$logs[$index]['resend_outcome'] = 'uncertain';", 'Persisted uncertain resend claim');
requireText(emailLogStore, "hash('sha256', $raw)", 'Corrupt backup deduplication');
requireText(emailLogStore, '$otherBackupsToKeep = max(0, (int)$maxBackups - 1);', 'Corrupt backup cap');
requireText(emailLogStore, 'kssmi_email_logs_is_valid_list($updatedLogs)', 'Mutation schema validation');
requireText(emailLogStore, 'kssmi_email_logs_cleanup_temp_files($path);', 'Orphan temp cleanup');
requireText(emailLogStore, "$path . '.corrupt-*.tmp-*'", 'Corrupt-backup temp cleanup');
requireText(emailLogStore, "'PHPMailer missing'", 'Legacy delivery allowlist');
requireText(emailLogStore, "'PHPMailer error'", 'Legacy delivery allowlist');
requireText(emailLogStore, "'General error'", 'Legacy delivery allowlist');
const tempOpenIndex = emailLogStore.indexOf("$handle = @fopen($tempPath, 'x+b');");
const tempChmodIndex = emailLogStore.indexOf('if (!@chmod($tempPath, $mode))', tempOpenIndex);
const tempWriteIndex = emailLogStore.indexOf(
  '$written = kssmi_email_logs_write_all($handle, $contents);',
  tempOpenIndex,
);
if (
  tempOpenIndex < 0 ||
  tempChmodIndex < tempOpenIndex ||
  tempWriteIndex < tempChmodIndex
) {
  failures.push('Temporary file permissions: chmod must succeed before customer data is written');
}

requireText(rateLimit, "flock($lockHandle, LOCK_EX)", 'File rate-limit transaction lock');
requireText(rateLimit, "$lockFile = $file . '.lock';", 'Stable rate-limit sidecar lock');
requireText(rateLimit, "'/bucket-' . substr($identity, 0, 2)", 'Bounded rate-limit buckets');
requireText(rateLimit, '$maxEntriesPerBucket = 512;', 'Rate-limit bucket identity cap');
requireText(rateLimit, 'function kssmi_rate_limit_atomic_write(', 'Atomic rate-limit bucket write');
requireText(rateLimit, "if (!@chmod($tempPath, 0600))", 'Rate-limit temporary permissions');
requireText(rateLimit, '!@rename($tempPath, $file)', 'Atomic rate-limit replacement');
requireText(rateLimit, 'invalid bucket preserved at', 'Corrupt rate-limit fail-closed handling');
requireText(rateLimit, "$raw = $fileExists ? @file_get_contents($file) : '{}';", 'Missing/zero-byte bucket distinction');
forbidText(rateLimit, 'ftruncate(', 'In-place rate-limit truncation');
forbidText(rateLimit, "md5(\"{$key}:{$ip}\") . '.json'", 'One-file-per-IP rate limit storage');
const strictTypesIndex = rateLimit.indexOf('declare(strict_types=1);');
const firstRateFunctionIndex = rateLimit.indexOf('function ');
if (strictTypesIndex < 0 || firstRateFunctionIndex < strictTypesIndex) {
  failures.push('Rate-limit PHP syntax: strict_types declaration must precede functions');
}

requireText(
  deployWorkflow,
  'php scripts/test-email-log-store.php',
  'PHP behavior regression test',
);
requireText(
  deployWorkflow,
  'bash scripts/test-email-log-migration.sh',
  'Email-log migration behavior test',
);
requireText(
  deployWorkflow,
  'php scripts/test-rate-limit.php',
  'Rate-limit behavior regression test',
);
requireText(
  deploySource,
  'scripts/deploy-release.sh',
  'Release script upload',
);
requireText(
  environmentAction,
  'source: "dist,private,scripts/deploy-release.sh,scripts/permission-policy.sh,scripts/runtime-capability-probe.php,scripts/prune-old-releases.sh"',
  'Shared private directory deployment',
);
requireText(
  deployReleaseScript,
  'PRIVATE_MODULES="email-log-store.php cloudflare-ip-ranges.json rate-limit.php http-security.php"',
  'Shared module list matches production modules',
);
requireText(
  deploySource,
  'EMAIL_LOCK="$EMAIL_LOG.lock"',
  'Stable lock-file permissions',
);
requireText(deploySource, 'exec 8>"$EMAIL_LOCK"', 'Cutover new-lock serialization');
requireText(
  deploySource,
  'ln -s "$EMAIL_LOG" "$LEGACY_EMAIL_LOG"',
  'Legacy email-log compatibility path',
);
requireText(
  deploySource,
  'run_root chown "$SITE_USER:$SITE_GROUP" "$EMAIL_LOCK" "$LEGACY_EMAIL_LOCK"',
  'Legacy lock compatibility permissions',
);
requireText(
  deploySource,
  'refusing an unsafe merge',
  'Divergent email-log migration guard',
);
requireText(
  deploySource,
  'email-log-cutover-until',
  'Email-log cutover barrier marker',
);
requireText(
  deploySource,
  'Email log cutover barrier released: OK',
  'Email-log cutover barrier release',
);
requireText(deploySource, 'EMAIL_DATA_DIR="$PRIVATE_ROOT/email_data"', 'Dedicated email data directory');
requireText(deploySource, 'run_as_site test -r "$RATE_LIMIT_MODULE"', 'Rate-limit module readability');
requireText(deploySource, 'run_as_site test -r "$EMAIL_LOG_MODULE"', 'Email-log module readability');
requireText(deploySource, 'Email data atomic-write permissions: OK', 'Deployment write/rename smoke test');
requireText(deploySource, 'Rate-limit storage permissions: OK', 'Rate-limit directory smoke test');
requireText(deploySource, 'sudo -u "$SITE_USER"', 'PHP-account permission verification');
requireText(emailLogTests, "run_email_log_workers('--append-worker'", 'Concurrent append regression');
requireText(emailLogTests, "run_email_log_workers('--claim-worker'", 'Concurrent resend regression');
requireText(emailLogTests, "'parent_not_writable'", 'Parent-directory failure regression');
requireText(emailLogTests, "'active deployment cutover did not block log mutation'", 'Cutover mutation regression');
requireText(emailLogTests, "'zero-byte cutover marker failed open'", 'Malformed cutover regression');
requireText(emailLogTests, "'mutation crossed cutover after waiting on the email lock'", 'Cutover lock race regression');
requireText(emailLogTests, "'existing zero-byte email log mutation failed open'", 'Zero-byte email log regression');
requireText(emailLogTests, "'numeric-key object row must not become an accepted list row'", 'Row schema regression');
requireText(emailLogTests, "'resend_outcome_uncertain'", 'Uncertain resend regression');
requireText(emailLogTests, "'active resend claim was incorrectly unlocked'", 'Active claim recovery guard');
requireText(emailLogTests, "'explicit review did not unlock a stale resend claim'", 'Reviewed claim recovery');
requireText(emailLogTests, "'uncertain resend cleared its claim or lost its outcome'", 'Immediate uncertain resend guard');
requireText(emailLogTests, "'.corrupt-deadbeef.tmp-orphan'", 'Corrupt temp cleanup regression');
requireText(rateLimitTests, 'run_rate_limit_workers($testRoot, 8)', 'Concurrent rate-limit regression');
requireText(rateLimitTests, "'expired bucket identities were not cleaned'", 'Rate-limit expiry regression');
requireText(rateLimitTests, "'corrupt bucket failed open'", 'Corrupt rate-limit regression');
requireText(rateLimitTests, "'existing zero-byte bucket reset the quota'", 'Zero-byte rate-limit regression');
requireText(rateLimitTests, 'count($capIdentities) < 513', 'Rate-limit capacity regression');
requireText(rateLimitTests, "'513th identity bypassed the bucket capacity'", 'Rate-limit cap enforcement');
requireText(migrationTests, 'legacy-only', 'Legacy-only migration regression');
requireText(migrationTests, 'identical', 'Identical dual-file migration regression');
requireText(migrationTests, 'divergent', 'Divergent dual-file migration regression');
requireText(migrationTests, 'opened-before', 'In-flight legacy writer migration regression');
requireText(migrationTests, 'exec 8>"$email_log.lock"', 'Migration new-lock serialization');

for (const [label, source] of [
  ['Inquiry form', inquiryForm],
  ['404 rescue form', notFoundPage],
]) {
  const resultDeclaration =
    label === '404 rescue form'
      ? 'var result = await response.json();'
      : 'const result = await response.json();';
  requireText(source, resultDeclaration, `${label} JSON error handling`);
  requireText(source, 'if (!response.ok) {', `${label} non-2xx handling`);
  requireText(source, 'window.turnstile.reset(window.turnstileWidgetId);', `${label} token reset`);

  const resultIndex = source.indexOf(resultDeclaration);
  const responseCheckIndex = source.indexOf('if (!response.ok) {', resultIndex);
  if (resultIndex < 0 || responseCheckIndex < resultIndex) {
    failures.push(`${label}: JSON error body must be parsed before non-2xx handling`);
  }
}

if (failures.length > 0) {
  console.error('Email log policy validation failed:');
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Email log policy validation passed.');
