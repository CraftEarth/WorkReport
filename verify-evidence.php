<?php

require_once __DIR__ . '/includes/media-evidence.php';

ensure_media_evidence_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

media_verify_csrf($_POST['_csrf'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$reportId = (int) ($_POST['report_id'] ?? 0);

$report = media_require_worker_report($reportId);
$attachment = media_require_worker_attachment($id);

if (!media_attachment_belongs_to_report($attachment, $reportId)) {
    http_response_code(403);
    exit('Evidence does not belong to this report.');
}

$result = media_verify_file($attachment);

$stmt = db()->prepare("
    UPDATE attachments
    SET
        last_verified_at = datetime('now', 'localtime'),
        last_verify_status = ?
    WHERE id = ?
      AND report_id = ?
");

$stmt->execute([
    $result['status'],
    $id,
    $reportId
]);

media_log_action(
    $id,
    $reportId,
    'integrity_verified',
    json_encode([
        'status' => $result['status'],
        'expected_sha256' => $attachment['file_hash'] ?? null,
        'actual_sha256' => $result['actual_hash']
    ], JSON_UNESCAPED_SLASHES)
);

if ($result['status'] === 'ok') {
    flash(
        'success',
        'Integrity check passed. The stored file still matches its original SHA-256 fingerprint.'
    );
} elseif ($result['status'] === 'missing') {
    flash(
        'error',
        'Integrity check failed because the stored evidence file is missing.'
    );
} else {
    flash(
        'error',
        'WARNING: SHA-256 mismatch. The stored file no longer matches the fingerprint created when it was uploaded.'
    );
}

redirect('evidence.php?id=' . $reportId);