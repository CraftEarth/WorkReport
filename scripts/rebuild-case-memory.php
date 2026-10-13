<?php

declare(strict_types=1);

require_once __DIR__
    . '/../includes/case-memory.php';

ensure_case_memory_schema();

$pdo = db();

$stmt = $pdo->query("
    SELECT
        r.id,
        r.worker_id,
        r.report_date,
        r.title
    FROM reports r
    INNER JOIN report_analysis ra
        ON ra.report_id = r.id
    ORDER BY
        r.worker_id,
        r.report_date,
        r.id
");

$reports = $stmt->fetchAll();

echo "Case memory rebuild\n";
echo "===================\n\n";

$totalFacts = 0;

foreach ($reports as $report) {

    $result =
        refresh_case_memory_for_report(
            (int) $report['id'],
            (int) $report['worker_id']
        );

    $totalFacts +=
        (int) $result['fact_count'];

    echo
        'Worker '
        . (int) $report['worker_id']
        . ' | Report '
        . (int) $report['id']
        . ' | '
        . $report['report_date']
        . ' | facts='
        . (int) $result['fact_count']
        . ' | source='
        . (int) $result['source_unit_count']
        . ' | events='
        . (int) $result['event_count']
        . ' | findings='
        . (int) $result['operational_finding_count']
        . ' | evidence='
        . (int) $result['evidence_count']
        . PHP_EOL;
}

echo "\n";
echo 'Analyzed reports indexed: '
    . count($reports)
    . PHP_EOL;

echo 'Total memory facts: '
    . $totalFacts
    . PHP_EOL;
