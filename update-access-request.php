<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/access-requests.php';

require_super_admin();
ensure_access_request_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('access-requests.php');
}


verify_csrf(
    $_POST['_csrf'] ?? null
);

$id =
    (int) ($_POST['id'] ?? 0);

$status =
    trim((string) ($_POST['status'] ?? ''));

$notes =
    trim((string) ($_POST['admin_notes'] ?? ''));

if (
    $id <= 0
    || !in_array($status, access_request_statuses(), true)
) {
    flash(
        'error',
        'Invalid access request update.'
    );

    redirect('access-requests.php');
}

$user = current_user();

$stmt = db()->prepare("
    UPDATE access_requests
    SET
        status = ?,
        admin_notes = ?,
        reviewed_at = datetime('now', 'localtime'),
        reviewed_by_user_id = ?
    WHERE id = ?
");

$stmt->execute([
    $status,
    $notes !== '' ? $notes : null,
    (int) $user['id'],
    $id
]);

flash(
    'success',
    'Access request review updated.'
);

redirect(
    'access-requests.php?status='
    . rawurlencode($status)
);