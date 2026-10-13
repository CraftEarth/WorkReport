<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/worker-profile.php';

require_login();
ensure_worker_profile_schema();

$workerId = (int) ($_GET['worker_id'] ?? 0);

if (
    $workerId < 1
    || !worker_accessible($workerId)
) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare("
    SELECT profile_photo_file
    FROM workers
    WHERE id = ?
      AND active = 1
    LIMIT 1
");

$stmt->execute([$workerId]);

$filename = trim(
    (string) $stmt->fetchColumn()
);

if ($filename === '') {
    http_response_code(404);
    exit;
}

$filename = basename($filename);

$path =
    STORAGE_PATH
    . DIRECTORY_SEPARATOR
    . 'profile-photos'
    . DIRECTORY_SEPARATOR
    . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($path);

$allowed = [
    'image/jpeg',
    'image/png',
    'image/webp'
];

if (!in_array($mime, $allowed, true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
