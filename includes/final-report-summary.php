<?php

declare(strict_types=1);

require_once __DIR__ . '/legal-detector.php';

function build_final_report_case_summary(
    PDO $pdo,
    array $reports
): array {

    ensure_legal_detection_schema();

    if (!$reports) {
        return [
            'narrative' => '',
            'report_count' => 0,
            'finalized_count' => 0,
            'draft_count' => 0,
            'evidence_count' => 0,
            'legal_flag_count' => 0,
            'categories' => [],
            'people' => [],
            'places' => [],
            'legal_issues' => []
        ];
    }

    $reportIds = array_map(
        static fn(array $report): int => (int) $report['id'],
        $reports
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($reportIds), '?')
    );

    $finalizedCount = 0;
    $draftCount = 0;
    $dates = [];

    foreach ($reports as $report) {

        $dates[] = $report['report_date'];

        if (($report['status'] ?? '') === 'finalized') {
            $finalizedCount++;
        } else {
            $draftCount++;
        }
    }

    sort($dates);

    $stmt = $pdo->prepare("
        SELECT
            c.name,
            COUNT(DISTINCT rc.report_id) AS report_count
        FROM report_categories rc
        INNER JOIN categories c
            ON c.id = rc.category_id
        WHERE rc.report_id IN ($placeholders)
        GROUP BY c.id, c.name
        ORDER BY report_count DESC, c.name
    ");

    $stmt->execute($reportIds);
    $categories = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT
            p.name,
            COUNT(DISTINCT rp.report_id) AS report_count
        FROM report_people rp
        INNER JOIN people p
            ON p.id = rp.person_id
        WHERE rp.report_id IN ($placeholders)
        GROUP BY p.id, p.name
        ORDER BY report_count DESC, p.name
    ");

    $stmt->execute($reportIds);
    $people = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT
            p.name,
            COUNT(DISTINCT rp.report_id) AS report_count
        FROM report_places rp
        INNER JOIN places p
            ON p.id = rp.place_id
        WHERE rp.report_id IN ($placeholders)
        GROUP BY p.id, p.name
        ORDER BY report_count DESC, p.name
    ");

    $stmt->execute($reportIds);
    $places = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM attachments
        WHERE report_id IN ($placeholders)
    ");

    $stmt->execute($reportIds);
    $evidenceCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            t.title,
            t.topic,
            COUNT(*) AS flag_count,
            COUNT(DISTINCT f.report_id) AS report_count
        FROM report_legal_flags f
        INNER JOIN legal_issue_types t
            ON t.id = f.issue_type_id
        WHERE f.report_id IN ($placeholders)
        GROUP BY t.id, t.title, t.topic
        ORDER BY flag_count DESC, t.title
    ");

    $stmt->execute($reportIds);
    $legalIssues = $stmt->fetchAll();

    $legalFlagCount = 0;

    foreach ($legalIssues as $issue) {
        $legalFlagCount += (int) $issue['flag_count'];
    }

    $reportCount = count($reports);

    $narrative = $reportCount . ' workplace report'
        . ($reportCount === 1 ? '' : 's')
        . ' were documented between '
        . format_date($dates[0])
        . ' and '
        . format_date($dates[count($dates) - 1])
        . '.';

    if ($categories) {

        $topCategories = array_slice($categories, 0, 3);
        $labels = [];

        foreach ($topCategories as $category) {
            $labels[] = $category['name']
                . ' ('
                . (int) $category['report_count']
                . ')';
        }

        $narrative .= ' The most frequently documented categories were '
            . implode(', ', $labels)
            . '.';
    }

    $repeatedPeople = array_values(
        array_filter(
            $people,
            static fn(array $person): bool =>
                (int) $person['report_count'] >= 2
        )
    );

    if ($repeatedPeople) {

        $topPeople = array_slice($repeatedPeople, 0, 3);
        $labels = [];

        foreach ($topPeople as $person) {
            $labels[] = $person['name']
                . ' ('
                . (int) $person['report_count']
                . ' reports)';
        }

        $narrative .= ' Recurring people referenced in the selected records include '
            . implode(', ', $labels)
            . '.';
    }

    if ($legalIssues) {

        $titles = array_slice(
            array_column($legalIssues, 'title'),
            0,
            4
        );

        $narrative .= ' Automated compliance review identified potential concerns including '
            . implode(', ', $titles)
            . '. These flags indicate issues that may warrant human review and do not determine that a law or workplace policy was violated.';
    }

    if ($evidenceCount > 0) {
        $narrative .= ' The selected records include '
            . $evidenceCount
            . ' evidence attachment'
            . ($evidenceCount === 1 ? '' : 's')
            . '.';
    }

    return [
        'narrative' => $narrative,
        'report_count' => $reportCount,
        'finalized_count' => $finalizedCount,
        'draft_count' => $draftCount,
        'evidence_count' => $evidenceCount,
        'legal_flag_count' => $legalFlagCount,
        'categories' => $categories,
        'people' => $people,
        'places' => $places,
        'legal_issues' => $legalIssues
    ];
}