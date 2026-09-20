<?php
require_once dirname(__DIR__) . '/private/inquiry-attachments.php';

function fail($message) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
function assert_true($condition, $message) { if (!$condition) fail($message); }
function fixture($suffix, $contents) {
    $path = tempnam(sys_get_temp_dir(), 'kssmi-attachment-');
    file_put_contents($path, $contents);
    return ['name' => 'reference.' . $suffix, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}
function office_fixture($suffix, $requiredPart) {
    if (!class_exists('ZipArchive')) fail('ZipArchive is required to test Office attachment validation');
    $path = tempnam(sys_get_temp_dir(), 'kssmi-office-');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) fail('could not create Office fixture');
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString($requiredPart, '<xml/>');
    $zip->close();
    return ['name' => 'reference.' . $suffix, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}
function test_upload($files) {
    return kssmi_inquiry_validate_attachments([
        'name' => array_column($files, 'name'), 'tmp_name' => array_column($files, 'tmp_name'),
        'error' => array_column($files, 'error'), 'size' => array_column($files, 'size'),
    ], false);
}

$paths = [];
try {
    $pdf = fixture('pdf', "%PDF-1.7\nTest document\n"); $paths[] = $pdf['tmp_name'];
    assert_true(test_upload([$pdf])['ok'] === true, 'valid PDF should pass');

    $jpeg = fixture('jpeg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9"); $paths[] = $jpeg['tmp_name'];
    assert_true(test_upload([$jpeg])['ok'] === true, 'valid JPEG should pass');

    $docx = office_fixture('docx', 'word/document.xml'); $paths[] = $docx['tmp_name'];
    assert_true(test_upload([$docx])['ok'] === true, 'valid DOCX structure should pass');

    $xlsx = office_fixture('xlsx', 'xl/workbook.xml'); $paths[] = $xlsx['tmp_name'];
    assert_true(test_upload([$xlsx])['ok'] === true, 'valid XLSX structure should pass');

    $dwg = fixture('dwg', "AC1032\x00CAD drawing"); $paths[] = $dwg['tmp_name'];
    assert_true(test_upload([$dwg])['ok'] === true, 'valid DWG signature should pass');

    $spoofed = fixture('pdf', "#!/bin/sh\necho unsafe\n"); $paths[] = $spoofed['tmp_name'];
    assert_true(test_upload([$spoofed])['reason'] === 'type', 'spoofed PDF must fail content checks');

    $executable = fixture('exe', "MZ\x90\x00"); $paths[] = $executable['tmp_name'];
    assert_true(test_upload([$executable])['reason'] === 'extension', 'executable must fail extension checks');

    $archive = fixture('zip', "PK\x03\x04not accepted"); $paths[] = $archive['tmp_name'];
    assert_true(test_upload([$archive])['reason'] === 'extension', 'general ZIP archive must fail');

    $tooMany = [$pdf, $pdf, $pdf, $pdf];
    assert_true(test_upload($tooMany)['reason'] === 'too_many', 'four files must fail');

    $largePath = tempnam(sys_get_temp_dir(), 'kssmi-attachment-large-');
    file_put_contents($largePath, '%PDF-' . str_repeat('A', KSSMI_INQUIRY_MAX_ATTACHMENT_BYTES));
    $paths[] = $largePath;
    $large = ['name' => 'large.pdf', 'tmp_name' => $largePath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($largePath)];
    assert_true(test_upload([$large])['reason'] === 'file_too_large', 'file over 5 MB must fail');

    $mediumPath = tempnam(sys_get_temp_dir(), 'kssmi-attachment-medium-');
    file_put_contents($mediumPath, '%PDF-' . str_repeat('B', 3 * 1024 * 1024));
    $paths[] = $mediumPath;
    $medium = ['name' => 'medium.pdf', 'tmp_name' => $mediumPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($mediumPath)];
    assert_true(test_upload([$medium, $medium, $medium])['reason'] === 'total_too_large', 'files over 8 MB in total must fail');

    echo "PASS: inquiry attachment validation\n";
} finally {
    foreach ($paths as $path) if (is_file($path)) unlink($path);
}
