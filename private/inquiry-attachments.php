<?php
/**
 * Validates optional files attached to a public inquiry.
 *
 * Files remain in PHP's upload directory. Callers must never copy them into a
 * public or persistent location; PHPMailer can attach the validated temp path
 * directly during the current request.
 */

// PHP's configured max_file_uploads is currently 20. The business limit is
// deliberately the same so the 8 MB total cap, not an arbitrary small count,
// is the practical customer constraint.
const KSSMI_INQUIRY_MAX_ATTACHMENTS = 20;
const KSSMI_INQUIRY_MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;
const KSSMI_INQUIRY_MAX_ATTACHMENT_TOTAL_BYTES = 8 * 1024 * 1024;

function kssmi_inquiry_attachment_error($reason, $message) {
    return ['ok' => false, 'reason' => $reason, 'message' => $message, 'files' => []];
}

function kssmi_inquiry_safe_attachment_name($name, $extension) {
    $name = basename(str_replace('\\', '/', (string)$name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
    $name = trim((string)$name, ". \t\r\n");
    if ($name === '') $name = 'reference-file.' . $extension;
    if (!str_ends_with(strtolower($name), '.' . $extension)) $name .= '.' . $extension;
    return function_exists('mb_substr')
        ? mb_substr($name, 0, 180, 'UTF-8')
        : substr($name, 0, 180);
}

function kssmi_inquiry_has_signature($path, $extension) {
    $handle = @fopen($path, 'rb');
    if ($handle === false) return false;
    $head = fread($handle, 512);
    fclose($handle);
    if (!is_string($head)) return false;

    return match ($extension) {
        'pdf' => str_starts_with($head, '%PDF-'),
        'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
        'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
        'webp' => strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP',
        'docx', 'xlsx' => str_starts_with($head, "PK\x03\x04"),
        'dwg' => str_starts_with($head, 'AC10'),
        'dxf' => preg_match('/^\s*0\r?\nSECTION\b/', $head) === 1,
        'stp', 'step' => stripos($head, 'ISO-10303-21') !== false,
        'igs', 'iges' => strlen($head) >= 73 && substr($head, 72, 1) === 'S',
        'stl' => preg_match('/^\s*solid\b/i', $head) === 1,
        'ai', 'eps' => str_starts_with($head, '%!PS') || str_starts_with($head, '%PDF-'),
        'psd' => str_starts_with($head, '8BPS'),
        'cdr' => strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 3) === 'CDR',
        '3dm' => str_starts_with($head, '3D Geometry File Format'),
        'sldprt', 'sldasm', 'ipt', 'iam' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        'x_t' => stripos($head, 'PARASOLID') !== false,
        default => false,
    };
}

function kssmi_inquiry_is_expected_ooxml($path, $extension) {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) return false;
    $expectedPart = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
    $valid = $zip->locateName('[Content_Types].xml') !== false
        && $zip->locateName($expectedPart) !== false;
    $zip->close();
    return $valid;
}

function kssmi_inquiry_normalize_attachments($upload) {
    if (!is_array($upload) || !isset($upload['name'], $upload['tmp_name'], $upload['error'], $upload['size'])) {
        return null;
    }
    $keys = ['name', 'tmp_name', 'error', 'size'];
    $isMultiple = is_array($upload['name']);
    foreach ($keys as $key) {
        if (is_array($upload[$key]) !== $isMultiple) return null;
    }
    if (!$isMultiple) {
        return [[
            'name' => $upload['name'], 'tmp_name' => $upload['tmp_name'],
            'error' => $upload['error'], 'size' => $upload['size'],
        ]];
    }
    $count = count($upload['name']);
    foreach ($keys as $key) if (count($upload[$key]) !== $count) return null;
    $files = [];
    for ($i = 0; $i < $count; $i++) {
        $files[] = [
            'name' => $upload['name'][$i], 'tmp_name' => $upload['tmp_name'][$i],
            'error' => $upload['error'][$i], 'size' => $upload['size'][$i],
        ];
    }
    return $files;
}

function kssmi_inquiry_validate_attachments($upload, $requireUploadedFile = true) {
    if ($upload === null || $upload === []) return ['ok' => true, 'files' => []];
    $files = kssmi_inquiry_normalize_attachments($upload);
    if ($files === null) return kssmi_inquiry_attachment_error('malformed', 'Attachment upload was invalid. Please try again.');

    $files = array_values(array_filter($files, static fn($file) => (int)$file['error'] !== UPLOAD_ERR_NO_FILE));
    if (count($files) === 0) return ['ok' => true, 'files' => []];
    if (count($files) > KSSMI_INQUIRY_MAX_ATTACHMENTS) {
        return kssmi_inquiry_attachment_error('too_many', 'Please attach no more than 20 files.');
    }

    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'dwg' => ['image/vnd.dwg', 'application/octet-stream'],
        'dxf', 'stp', 'step', 'igs', 'iges', 'stl', 'x_t' => ['text/plain', 'application/octet-stream'],
        'ai', 'eps' => ['application/pdf', 'application/postscript'],
        'psd', 'cdr', '3dm', 'sldprt', 'sldasm', 'ipt', 'iam' => ['application/octet-stream'],
    ];
    $totalBytes = 0;
    $validated = [];
    if (!class_exists('finfo')) {
        return kssmi_inquiry_attachment_error('fileinfo_unavailable', 'Attachment validation is temporarily unavailable. Please email the file to us.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($files as $file) {
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            return kssmi_inquiry_attachment_error('upload_error', 'One attachment could not be uploaded. Please try again or email us for larger files.');
        }
        if (!is_string($file['tmp_name']) || $file['tmp_name'] === '' || !is_file($file['tmp_name'])
            || ($requireUploadedFile && !is_uploaded_file($file['tmp_name']))) {
            return kssmi_inquiry_attachment_error('invalid_temp_file', 'Attachment upload was invalid. Please try again.');
        }
        $size = (int)$file['size'];
        if ($size < 1 || $size > KSSMI_INQUIRY_MAX_ATTACHMENT_BYTES) {
            return kssmi_inquiry_attachment_error('file_too_large', 'Each attachment must be 5 MB or smaller.');
        }
        $totalBytes += $size;
        if ($totalBytes > KSSMI_INQUIRY_MAX_ATTACHMENT_TOTAL_BYTES) {
            return kssmi_inquiry_attachment_error('total_too_large', 'Attachments must total 8 MB or less.');
        }
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$extension])) {
            return kssmi_inquiry_attachment_error('extension', 'Use a supported PDF, image, CAD, or design file.');
        }
        $mime = $finfo->file($file['tmp_name']);
        if (!is_string($mime) || !in_array($mime, $allowed[$extension], true)
            || !kssmi_inquiry_has_signature($file['tmp_name'], $extension)) {
            return kssmi_inquiry_attachment_error('type', 'One attachment does not match a supported file type.');
        }
        if ($extension === 'docx' || $extension === 'xlsx') {
            $ooxmlResult = kssmi_inquiry_is_expected_ooxml($file['tmp_name'], $extension);
            if ($ooxmlResult === null) {
                return kssmi_inquiry_attachment_error('zip_unavailable', 'Document attachments are temporarily unavailable. Please email the file to us.');
            }
            if ($ooxmlResult !== true) {
                return kssmi_inquiry_attachment_error('ooxml', 'One attachment does not match a supported file type.');
            }
        }
        $validated[] = [
            'path' => $file['tmp_name'],
            'name' => kssmi_inquiry_safe_attachment_name($file['name'], $extension),
            'size' => $size,
        ];
    }
    return ['ok' => true, 'files' => $validated, 'total_bytes' => $totalBytes];
}
