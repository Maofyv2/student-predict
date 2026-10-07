<?php
/**
 * Secure File Download Handler
 *
 * Forces a proper file download (application/octet-stream) so browsers
 * do NOT try to render/display the file as HTML or inline content.
 *
 * Usage:  download.php?file=uploads/activities/xyz.pdf
 *         download.php?file=uploads/submissions/abc.docx
 *
 * Security:
 *  - Requires a logged-in session (student OR advisor role).
 *  - Only allows files inside the allowed prefix directories.
 *  - Resolves realpath to prevent path-traversal attacks.
 */

require_once __DIR__ . '/bootstrap.php';
require_login();   // works for both student session and user session

// ── 1. Validate the requested path ───────────────────────────────────────────
$requested = trim($_GET['file'] ?? '');

if ($requested === '') {
    http_response_code(400);
    exit('No file specified.');
}

// Whitelist of allowed base directories (relative to this script's directory)
$allowed_prefixes = [
    'uploads/activities/',
    'uploads/submissions/',
];

$is_allowed = false;
foreach ($allowed_prefixes as $prefix) {
    if (str_starts_with($requested, $prefix)) {
        $is_allowed = true;
        break;
    }
}

if (!$is_allowed) {
    http_response_code(403);
    exit('Access denied.');
}

// ── 2. Resolve absolute path and verify it exists ────────────────────────────
$base_dir  = realpath(__DIR__) . DIRECTORY_SEPARATOR;
// Normalize slashes for Windows
$rel_path  = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $requested);
$full_path = realpath($base_dir . $rel_path);

// Guard against directory traversal (realpath must start with our base dir)
if ($full_path === false || strpos($full_path, $base_dir) !== 0 || !is_file($full_path)) {
    http_response_code(404);
    exit('File not found.');
}

// ── 3. Detect MIME type ───────────────────────────────────────────────────────
$ext      = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
$mime_map = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'zip'  => 'application/zip',
    'rar'  => 'application/x-rar-compressed',
    'txt'  => 'text/plain',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

// Use a safe original filename for the download dialog
// (strip the timestamp/hash prefix from our stored filenames)
$stored_name   = basename($full_path);
$original_name = preg_replace('/^\d+_[a-f0-9]+_/', '', $stored_name);   // submissions
$original_name = preg_replace('/^activity_\d+_[a-f0-9]+\./', '', $original_name); // activities — keep ext
if ($original_name === '' || $original_name === $stored_name) {
    $original_name = $stored_name; // fallback to stored name
}

$mime = $mime_map[$ext] ?? 'application/octet-stream';

// ── 4. Stream the file with proper headers ────────────────────────────────────
// Disable output buffering to avoid memory issues with large files
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes($original_name) . '"');
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: private, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

readfile($full_path);
exit;
