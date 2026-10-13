<?php

require_once __DIR__ . '/includes/media-evidence.php';

ensure_media_evidence_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

media_verify_csrf($_POST['_csrf'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$reportId = (int) ($_POST['report_id'] ?? 0);
$voidReason = trim((string) ($_POST['void_reason'] ?? ''));

$report = media_require_worker_report($reportId);
$attachment = media_require_worker_attachment($id);

if (!media_attachment_belongs_to_report($attachment, $reportId)) {
    http_response_code(403);
    exit('Evidence does not belong to this report.');
}

if (($attachment['status'] ?? 'active') !== 'active') {
    flash('error', 'This evidence record has already been voided.');
    redirect('evidence.php?id=' . $reportId);
}

$user = current_user();

$stmt = db()->prepare("
    UPDATE attachments
    SET
        status = 'voided',
        voided_at = datetime('now', 'localtime'),
        voided_by_user_id = ?,
        void_reason = ?
    WHERE id = ?
      AND report_id = ?
");

$stmt->execute([
    $user ? (int) $user['id'] : null,
    media_limit_text($voidReason, 1000),
    $id,
    $reportId
]);

media_log_action(
    $id,
    $reportId,
    'voided',
    $voidReason !== ''
        ? media_limit_text($voidReason, 1000)
        : 'Voided by authorized user.'
);

flash(
    'success',
    'Evidence record voided. The original file and SHA-256 fingerprint were preserved for audit.'
);

redirect('evidence.php?id=' . $reportId);