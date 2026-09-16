<?php
declare(strict_types=1);

session_name('KSSMI_SHORT_LINKS_SESSION_TEST');
session_start();
require_once dirname(__DIR__) . '/private/short-links-tool.php';

function expect_short_links_session(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$email = 'colleague@kssmi.com';
$hash = password_hash('correct horse battery staple', PASSWORD_DEFAULT);
$users = [$email => ['hash' => $hash, 'admin' => true]];
$now = 1_800_000_000;

kssmi_short_links_session_revoke();
expect_short_links_session(kssmi_short_links_session_establish($email, $users[$email], $now), 'Valid account should establish a session.');
$principal = kssmi_short_links_session_principal($users, $now + 1);
expect_short_links_session($principal === ['email' => $email, 'admin' => true], 'Current account must produce its live principal.');

// A demotion is live data, not a privilege cached at login.
$users[$email]['admin'] = false;
$principal = kssmi_short_links_session_principal($users, $now + 2);
expect_short_links_session($principal === ['email' => $email, 'admin' => false], 'Role demotion must take effect on the next request.');
expect_short_links_session($_SESSION['short_links_tool_admin'] === false, 'Cached admin flag must be updated after demotion.');

// Password reset/change invalidates all prior sessions without relying on logout.
$users[$email]['hash'] = password_hash('new password for account', PASSWORD_DEFAULT);
expect_short_links_session(kssmi_short_links_session_principal($users, $now + 3) === null, 'Changed password must revoke the old session.');
expect_short_links_session(!isset($_SESSION['short_links_tool_auth'], $_SESSION['short_links_tool_csrf']), 'Revocation must remove authentication and CSRF state.');

// Deleted accounts and legacy sessions without a credential binding are denied.
expect_short_links_session(kssmi_short_links_session_establish($email, ['hash' => $hash, 'admin' => false], $now), 'Test session should establish.');
expect_short_links_session(kssmi_short_links_session_principal([], $now + 1) === null, 'Deleted account must be denied.');
$_SESSION['short_links_tool_auth'] = true;
$_SESSION['short_links_tool_email'] = $email;
$_SESSION['short_links_tool_admin'] = true;
expect_short_links_session(kssmi_short_links_session_principal([$email => ['hash' => $hash, 'admin' => false]], $now + 1) === null, 'Legacy session without a fingerprint must be denied.');

// Expiry is checked before refreshing last-seen time.
expect_short_links_session(kssmi_short_links_session_establish($email, ['hash' => $hash, 'admin' => false], $now), 'Expiry test session should establish.');
expect_short_links_session(
    kssmi_short_links_session_principal([$email => ['hash' => $hash, 'admin' => false]], $now + kssmi_admin_session_inactivity_ttl() + 1) === null,
    'Inactive session must expire.'
);

session_destroy();
echo "Short-links session tests passed.\n";
