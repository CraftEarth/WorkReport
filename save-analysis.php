<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/legal-detector.php';
require_once __DIR__ . '/includes/operational-analysis.php';
require_once __DIR__ . '/includes/case-memory.php';
require_once __DIR__ . '/includes/case-facts.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

verify_csrf(
    $_POST['_csrf'] ?? null
);

$reportId = (int) ($_POST['report_id'] ?? 0);

$report = get_report_for_current_worker($reportId);

if (!$report) {
    flash('error', 'Report not found.');
    redirect('index.php');
}

$pdo = db();

ensure_case_memory_schema();

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


    $people = $_POST['people'] ?? [];

    $extraPeople = trim(
        $_POST['extra_people'] ?? ''
    );

    if ($extraPeople !== '') {

        $extras = array_filter(
            array_map(
                'trim',
                explode(',', $extraPeople)
            )
        );

        $people = array_merge(
            $people,
            $extras
        );
    }

    $people = array_unique($people);

    foreach ($people as $name) {

        if ($name === '') {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO people (name)
            VALUES (?)
        ");

        $stmt->execute([$name]);

        $stmt = $pdo->prepare("
            SELECT id
            FROM people
            WHERE name = ?
            LIMIT 1
        ");

        $stmt->execute([$name]);

        $personId = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO report_people (
                report_id,
                person_id,
                role
            )
            VALUES (?, ?, 'mentioned')
        ");

        $stmt->execute([
            $reportId,
            $personId
        ]);
    }


    $places = $_POST['places'] ?? [];

    $extraPlaces = trim(
        $_POST['extra_places'] ?? ''
    );

    if ($extraPlaces !== '') {

        $extras = array_filter(
            array_map(
                'trim',
                explode(',', $extraPlaces)
            )
        );

        $places = array_merge(
            $places,
            $extras
        );
    }

    $places = array_unique($places);

    foreach ($places as $name) {

        if ($name === '') {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO places (
                name,
                place_type
            )
            VALUES (?, 'work area')
        ");

        $stmt->execute([$name]);

        $stmt = $pdo->prepare("
            SELECT id
            FROM places
            WHERE name = ?
            LIMIT 1
        ");

        $stmt->execute([$name]);

        $placeId = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO report_places (
                report_id,
                place_id
            )
            VALUES (?, ?)
        ");

        $stmt->execute([
            $reportId,
            $placeId
        ]);
    }


    $categories =
        array_unique(
            $_POST['categories'] ?? []
        );

    foreach ($categories as $name) {

        if ($name === '') {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO categories (name)
            VALUES (?)
        ");

        $stmt->execute([$name]);

        $stmt = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE name = ?
            LIMIT 1
        ");

        $stmt->execute([$name]);

        $categoryId =
            (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO report_categories (
                report_id,
                category_id
            )
            VALUES (?, ?)
        ");

        $stmt->execute([
            $reportId,
            $categoryId
        ]);
    }


    $events = $_POST['events'] ?? [];

    foreach ($events as $event) {

        if (empty($event['enabled'])) {
            continue;
        }

        $description =
            trim($event['description'] ?? '');

        if ($description === '') {
            continue;
        }

        $eventTime =
            trim($event['time'] ?? '');

        $category =
            trim(
                $event['category']
                ?? 'General'
            );

        $stmt = $pdo->prepare("
            INSERT INTO events (
                report_id,
                event_time,
                category,
                description,
                created_at
            )
            VALUES (
                :report_id,
                :event_time,
                :category,
                :description,
                datetime('now', 'localtime')
            )
        ");

        $stmt->execute([
            ':report_id' => $reportId,
            ':event_time' =>
                $eventTime !== ''
                    ? $eventTime
                    : null,
            ':category' => $category,
            ':description' => $description
        ]);
    }



    $operationalFindings =
        $_POST['operational_findings']
        ?? [];

    save_report_operational_findings(
        $reportId,
        $operationalFindings
    );

    $stmt = $pdo->prepare("
        INSERT INTO report_analysis (
            report_id,
            analyzed_at
        )
        VALUES (
            ?,
            datetime('now', 'localtime')
        )
        ON CONFLICT(report_id)
        DO UPDATE SET
            analyzed_at = datetime('now', 'localtime')
    ");

    $stmt->execute([$reportId]);

    $legalIssues = detect_legal_issues(
        $report['original_text']
    );

    save_report_legal_flags(
        $reportId,
        $legalIssues
    );

    refresh_case_memory_for_report(
        $reportId,
        (int) $report['worker_id']
    );

    rebuild_case_facts_for_report(
        $reportId,
        (int) $report['worker_id']
    );

    $pdo->commit();

    flash(
        'success',
        'Report analysis saved successfully.'
    );

} catch (Throwable $e) {

    $pdo->rollBack();

    throw $e;
}

redirect(
    'report.php?id=' . $reportId
);