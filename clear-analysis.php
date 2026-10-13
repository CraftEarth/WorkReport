<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/operational-analysis.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}


verify_csrf(
    $_POST['_csrf'] ?? null
);

$reportId = (int) (
    $_POST['report_id']
    ?? 0
);

$report = get_report_for_current_worker($reportId);

if (!$report) {

    flash(
        'error',
        'Report not found.'
    );

    redirect('index.php');
}

$pdo = db();

$pdo->beginTransaction();

try {

    $pdo->prepare("
        DELETE FROM report_people
        WHERE report_id = ?
    ")->execute([$reportId]);

    $pdo->prepare("
        DELETE FROM report_places
        WHERE report_id = ?
    ")->execute([$reportId]);

    $pdo->prepare("
        DELETE FROM report_categories
        WHERE report_id = ?
    ")->execute([$reportId]);

    $pdo->prepare("
        DELETE FROM events
        WHERE report_id = ?
    ")->execute([$reportId]);


    clear_report_operational_findings(
        $reportId
    );

    $pdo->prepare("
        DELETE FROM report_legal_flags
        WHERE report_id = ?
    ")->execute([$reportId]);
    $pdo->prepare("
        DELETE FROM report_analysis
        WHERE report_id = ?
    ")->execute([$reportId]);

    $pdo->commit();

    flash(
        'success',
        'Structured analysis cleared. The original report was not changed.'
    );

} catch (Throwable $e) {

    $pdo->rollBack();

    throw $e;
}

redirect(
    'manage-report.php?id=' . $reportId
);