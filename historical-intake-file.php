<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/historical-intake.php';

require_login();
ensure_historical_intake_schema();

$worker = require_current_worker();
$workerId = (int) $worker['id'];

$id = (int) (
    $_GET['id']
    ?? 0
);

$stmt = db()->prepare("
    SELECT *
    FROM historical_intake_files
    WHERE id = ?
      AND worker_id = ?
    LIMIT 1
");

$stmt->execute([
    $id,
    $workerId
]);

$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit;
}

$path =
    STORAGE_PATH
    . DIRECTORY_SEPARATOR
    . 'historical-intake'
    . DIRECTORY_SEPARATOR
    . basename(
        (string) $file['stored_name']
    );
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$currentHash = hash_file(
    'sha256',
    $path
);

if (
    !hash_equals(
        (string) $file['sha256'],
        (string) $currentHash
    )
) {
    http_response_code(409);
    exit(
        'Historical source file integrity check failed.'
    );
}

header(
    'Content-Type: '
    . $file['mime_type']
);

header(
    'Content-Length: '
    . filesize($path)
);

header(
    'Content-Disposition: inline; filename="'
    . rawurlencode(
        $file['original_name']
    )
    . '"'
);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($path);
exit;
