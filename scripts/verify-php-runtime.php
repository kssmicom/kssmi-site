<?php
declare(strict_types=1);

/**
 * PHP runtime verification for Kssmi CI (优化-001 阶段 1).
 *
 * The backend relies on PHP 8.3 with PDO SQLite (WAL mode), curl (GSC / SMTP
 * fallbacks) and mbstring. CI and deploy both gate on this script so a missing
 * extension or wrong PHP version fails fast instead of half-working in prod.
 */

$expectedMajor = 8;
$expectedMinor = 3;
$requiredExtensions = ['pdo_sqlite', 'curl', 'mbstring'];
$failures = [];

if (PHP_MAJOR_VERSION !== $expectedMajor || PHP_MINOR_VERSION !== $expectedMinor) {
    $failures[] = sprintf(
        'Expected PHP %d.%d.x, got %s.',
        $expectedMajor,
        $expectedMinor,
        PHP_VERSION
    );
}

foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $failures[] = sprintf('Required PHP extension is not loaded: %s.', $extension);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $failures[] = 'PDO SQLite driver is unavailable.';
}

if ($failures !== []) {
    fwrite(STDERR, "PHP runtime verification failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

printf(
    "PHP runtime verified: %s; extensions: %s; PDO drivers: %s\n",
    PHP_VERSION,
    implode(', ', $requiredExtensions),
    implode(', ', PDO::getAvailableDrivers())
);
