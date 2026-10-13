<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}


verify_csrf(
    $_POST['_csrf'] ?? null
);

$reportId = (int) ($_POST['report_id'] ?? 0);

if ($reportId <= 0) {
    flash('error', 'Invalid report.');
    redirect('index.php');
}

$report = get_report_for_current_worker($reportId);

if (!$report) {
    http_response_code(404);
    exit('Report not found.');
}

/*
 * WORKREPORT PRESERVATION POLICY
 *
 * Reports and their source evidence may not be permanently deleted
 * through the application.
 *
 * A formal Void / Archive workflow with permanent audit history
 * will replace destructive deletion.
 */

flash(
    'error',
    'Permanent report deletion is disabled. WorkReport preserves source records and audit history. A formal Void / Archive workflow will be used instead.'
);

redirect('manage-report.php?id=' . $reportId);
