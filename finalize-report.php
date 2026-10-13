<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}


verify_csrf(
    $_POST['_csrf'] ?? null
);

$id = (int) ($_POST['id'] ?? 0);

$report = get_report_for_current_worker($id);

if (!$report) {

    flash(
        'error',
        'Report not found.'
    );

    redirect('index.php');
}

if ($report['status'] === 'finalized') {

    flash(
        'error',
        'This report has already been finalized.'
    );

    redirect('report.php?id=' . $id);
}

$hash = report_hash($report);

$stmt = db()->prepare("
    UPDATE reports
    SET
        status = 'finalized',
        finalized_at = datetime('now', 'localtime'),
        report_hash = :hash,
        updated_at = datetime('now', 'localtime')
    WHERE id = :id
      AND worker_id = :worker_id
");

$stmt->execute([
    ':hash' => $hash,
    ':id' => $id,
    ':worker_id' => (int) $report['worker_id']
]);

flash(
    'success',
    'Report finalized and SHA-256 fingerprint created.'
);

redirect('report.php?id=' . $id);
