<?php

declare(strict_types=1);

require_once __DIR__
    . '/../includes/case-facts.php';

ensure_case_fact_schema();

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

$reports =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Material case fact rebuild\n";
echo "==========================\n\n";

$total = 0;

foreach ($reports as $report) {

    $result =
        rebuild_case_facts_for_report(
            (int) $report['id'],
            (int) $report['worker_id']
        );

    $total +=
        $result['total'];

    echo
        'Worker '
        . $report['worker_id']
        . ' | Report '
        . $report['id']
        . ' | '
        . $report['report_date']
        . ' | total='
        . $result['total']
        . ' | material='
        . $result['material']
        . ' | supporting='
        . $result['supporting']
        . PHP_EOL;
}

echo "\n";
echo 'Reports rebuilt: '
    . count($reports)
    . PHP_EOL;

echo 'Deduplicated case facts: '
    . $total
    . PHP_EOL;
