<?php
declare(strict_types=1);

// KS-04: the inquiry auto-reply greeting and recipient. Nothing is sent; the
// reply is queued for every accepted inquiry (scripts/test-inquiry-outbox.php).

require_once dirname(__DIR__) . '/private/inquiry-autoreply.php';

function iar_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// The same escaping send-mail.php's sanitize() applies to form fields.
function iar_form_value(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

// Names that read as names are greeted; anything else gets the generic greeting.
foreach (['Maria Garcia', "O'Brien", 'Jean-Pierre Dupont', '张伟', 'José Álvarez', 'محمد علي', 'Nguyễn Văn An', 'Dr. Smith', 'Anna Maria del Carmen López'] as $name) {
    iar_assert(kssmi_inquiry_autoreply_name(iar_form_value($name)) === $name, "The name {$name} must be accepted as plain text.");
}
foreach ([
    '',
    'Visit evil.com now',
    'http://evil.example',
    'Call 555 0100 today',
    'win@example.com',
    str_repeat('a', 61),
    'one two three four five six seven',
    '-Bob',
    "Bob\r\nBcc: someone@example.com",
    'Bob & Co',
] as $name) {
    iar_assert(kssmi_inquiry_autoreply_name(iar_form_value($name)) === '', 'The name ' . json_encode($name) . ' must be rejected.');
}
iar_assert(kssmi_inquiry_autoreply_name(null) === '' && kssmi_inquiry_autoreply_name(['x']) === '', 'A non-string name must be rejected.');

$languages = ['en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja', 'tr', 'ar', 'ko', 'zh', 'hi', 'vi', 'jv', 'ms', 'tg'];
foreach ($languages as $language) {
    $named = kssmi_inquiry_autoreply_build(['name' => iar_form_value("O'Brien"), 'language' => $language]);
    $greeting = strtok($named['body'], "\n");
    iar_assert(str_contains($greeting, "O'Brien") && $named['recipient_name'] === "O'Brien", "The {$language} greeting must use an accepted name as plain text.");
    $generic = kssmi_inquiry_autoreply_build(['name' => iar_form_value('Visit evil.com now'), 'language' => $language]);
    iar_assert($generic['recipient_name'] === '', "A rejected name must not become the {$language} display name.");
    iar_assert(!str_contains($generic['body'], 'evil') && !str_contains($generic['body'], '{name}') && !str_contains($generic['body'], 'there'), "The {$language} generic greeting must not carry the rejected name or a placeholder.");
}
$fallback = kssmi_inquiry_autoreply_build(['name' => 'Maria', 'language' => 'xx']);
iar_assert(str_starts_with($fallback['body'], "Hello Maria,\n"), 'An unknown language must fall back to English.');
iar_assert(str_starts_with(kssmi_inquiry_autoreply_build(['name' => '', 'language' => 'zh'])['body'], "您好：\n"), 'An empty name must get the generic greeting.');

// Every inquiry from the same mailbox gets the same full reply; nothing in
// this module can skip it.
for ($index = 0; $index < 3; $index++) {
    $reply = kssmi_inquiry_autoreply_build(['name' => 'Test Verify', 'email' => 'jack@kssmi.com', 'language' => 'en']);
    iar_assert($reply['to'] === 'jack@kssmi.com' && str_starts_with($reply['body'], "Hello Test Verify,\n"), 'Every inquiry must get the full reply.');
}
iar_assert(!function_exists('kssmi_inquiry_autoreply_budget'), 'No auto-reply budget may exist after acceptance.');

echo "Inquiry auto-reply greeting tests passed.\n";
