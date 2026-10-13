<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$worker = require_current_worker();
$workerId = (int) $worker['id'];
$pdo = db();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM reports
    WHERE worker_id = :worker_id
");
$stmt->execute([':worker_id' => $workerId]);
$totalReports = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM report_analysis ra
    INNER JOIN reports r
        ON r.id = ra.report_id
    WHERE r.worker_id = :worker_id
");
$stmt->execute([':worker_id' => $workerId]);
$analyzedReports = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM events e
    INNER JOIN reports r
        ON r.id = e.report_id
    WHERE r.worker_id = :worker_id
");
$stmt->execute([':worker_id' => $workerId]);
$totalEvents = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT rp.person_id)
    FROM report_people rp
    INNER JOIN reports r
        ON r.id = rp.report_id
    WHERE r.worker_id = :worker_id
");
$stmt->execute([':worker_id' => $workerId]);
$totalPeople = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        MIN(report_date) AS first_date,
        MAX(report_date) AS last_date
    FROM reports
    WHERE worker_id = :worker_id
");
$stmt->execute([':worker_id' => $workerId]);
$dateRange = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        COUNT(DISTINCT rp.report_id) AS report_count,
        MIN(r.report_date) AS first_seen,
        MAX(r.report_date) AS last_seen
    FROM people p
    INNER JOIN report_people rp
        ON rp.person_id = p.id
    INNER JOIN reports r
        ON r.id = rp.report_id
    WHERE r.worker_id = :worker_id
    GROUP BY p.id, p.name
    ORDER BY report_count DESC, p.name
");
$stmt->execute([':worker_id' => $workerId]);
$peopleStats = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        COUNT(DISTINCT rp.report_id) AS report_count,
        MIN(r.report_date) AS first_seen,
        MAX(r.report_date) AS last_seen
    FROM places p
    INNER JOIN report_places rp
        ON rp.place_id = p.id
    INNER JOIN reports r
        ON r.id = rp.report_id
    WHERE r.worker_id = :worker_id
    GROUP BY p.id, p.name
    ORDER BY report_count DESC, p.name
");
$stmt->execute([':worker_id' => $workerId]);
$placeStats = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        COUNT(DISTINCT rc.report_id) AS report_count,
        MIN(r.report_date) AS first_seen,
        MAX(r.report_date) AS last_seen
    FROM categories c
    INNER JOIN report_categories rc
        ON rc.category_id = c.id
    INNER JOIN reports r
        ON r.id = rc.report_id
    WHERE r.worker_id = :worker_id
    GROUP BY c.id, c.name
    ORDER BY report_count DESC, c.name
");
$stmt->execute([':worker_id' => $workerId]);
$categoryStats = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        e.category,
        COUNT(*) AS event_count
    FROM events e
    INNER JOIN reports r
        ON r.id = e.report_id
    WHERE r.worker_id = :worker_id
    GROUP BY e.category
    ORDER BY event_count DESC, e.category
");
$stmt->execute([':worker_id' => $workerId]);
$eventCategoryStats = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        e.*,
        r.report_date,
        r.title,
        r.machine
    FROM events e
    INNER JOIN reports r
        ON r.id = e.report_id
    WHERE r.worker_id = :worker_id
    ORDER BY
        r.report_date DESC,
        CASE
            WHEN e.event_time IS NULL
              OR e.event_time = ''
            THEN 1
            ELSE 0
        END,
        e.event_time DESC,
        e.id DESC
    LIMIT 50
");
$stmt->execute([':worker_id' => $workerId]);
$recentEvents = $stmt->fetchAll();

$pageTitle = 'Case Analysis';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>Case Analysis</h1>

        <p>
            Patterns and recurring information for the selected worker.
        </p>

    </div>

</section>


<section class="stats-grid">

    <div class="stat-card">

        <span>Total Reports</span>

        <strong>
            <?= $totalReports ?>
        </strong>

    </div>

    <div class="stat-card">

        <span>Analyzed Reports</span>

        <strong>
            <?= $analyzedReports ?>
        </strong>

    </div>

    <div class="stat-card">

        <span>Extracted Events</span>

        <strong>
            <?= $totalEvents ?>
        </strong>

    </div>

    <div class="stat-card">

        <span>People Identified</span>

        <strong>
            <?= $totalPeople ?>
        </strong>

    </div>

</section>


<section class="panel">

    <h2>Documentation Period</h2>

    <?php if ($dateRange['first_date']): ?>

        <p class="case-period">

            <?= e(
                format_date(
                    $dateRange['first_date']
                )
            ) ?>

            through

            <?= e(
                format_date(
                    $dateRange['last_date']
                )
            ) ?>

        </p>

    <?php else: ?>

        <p>No reports have been entered.</p>

    <?php endif; ?>

</section>


<div class="analysis-dashboard-grid">

<section class="panel">

    <h2>People Mentioned</h2>

    <?php if (!$peopleStats): ?>

        <p>No people indexed.</p>

    <?php else: ?>

        <div class="stat-table">

            <?php foreach ($peopleStats as $row): ?>

                <div class="stat-table-row">

                    <div>

                        <strong>
                            <?= e($row['name']) ?>
                        </strong>

                        <span>
                            First:
                            <?= e(
                                format_date(
                                    $row['first_seen']
                                )
                            ) ?>

                            · Last:
                            <?= e(
                                format_date(
                                    $row['last_seen']
                                )
                            ) ?>
                        </span>

                    </div>

                    <div class="count-badge">
                        <?= (int) $row['report_count'] ?>
                        reports
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<section class="panel">

    <h2>Locations / Machines</h2>

    <?php if (!$placeStats): ?>

        <p>No places indexed.</p>

    <?php else: ?>

        <div class="stat-table">

            <?php foreach ($placeStats as $row): ?>

                <div class="stat-table-row">

                    <div>

                        <strong>
                            <?= e($row['name']) ?>
                        </strong>

                        <span>
                            First:
                            <?= e(
                                format_date(
                                    $row['first_seen']
                                )
                            ) ?>

                            · Last:
                            <?= e(
                                format_date(
                                    $row['last_seen']
                                )
                            ) ?>
                        </span>

                    </div>

                    <div class="count-badge">
                        <?= (int) $row['report_count'] ?>
                        reports
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<section class="panel">

    <h2>Report Categories</h2>

    <?php if (!$categoryStats): ?>

        <p>No categories indexed.</p>

    <?php else: ?>

        <div class="stat-table">

            <?php foreach ($categoryStats as $row): ?>

                <div class="stat-table-row">

                    <div>

                        <strong>
                            <?= e($row['name']) ?>
                        </strong>

                        <span>
                            First:
                            <?= e(
                                format_date(
                                    $row['first_seen']
                                )
                            ) ?>

                            · Last:
                            <?= e(
                                format_date(
                                    $row['last_seen']
                                )
                            ) ?>
                        </span>

                    </div>

                    <div class="count-badge">
                        <?= (int) $row['report_count'] ?>
                        reports
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<section class="panel">

    <h2>Event Breakdown</h2>

    <?php if (!$eventCategoryStats): ?>

        <p>No events indexed.</p>

    <?php else: ?>

        <div class="stat-table">

            <?php foreach ($eventCategoryStats as $row): ?>

                <div class="stat-table-row">

                    <strong>
                        <?= e($row['category']) ?>
                    </strong>

                    <div class="count-badge">
                        <?= (int) $row['event_count'] ?>
                        events
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

</div>


<section class="panel">

    <div class="panel-header">

        <div>

            <h2>Master Event Timeline</h2>

            <p>
                Extracted events from this worker's analyzed reports.
            </p>

        </div>

    </div>

    <?php if (!$recentEvents): ?>

        <p>No timeline events recorded yet.</p>

    <?php else: ?>

        <div class="case-event-list">

        <?php foreach ($recentEvents as $event): ?>

            <a
                href="report.php?id=<?= (int) $event['report_id'] ?>"
                class="case-event-row"
            >

                <div class="case-event-date">

                    <strong>
                        <?= e(
                            format_date(
                                $event['report_date']
                            )
                        ) ?>
                    </strong>

                    <span>
                        <?= $event['event_time']
                            ? e(
                                date(
                                    'g:i A',
                                    strtotime(
                                        $event['event_time']
                                    )
                                )
                            )
                            : 'Time not stated' ?>
                    </span>

                </div>

                <div class="case-event-body">

                    <div>

                        <span class="tag">
                            <?= e($event['category']) ?>
                        </span>

                        <span class="case-event-source">

                            <?= e(
                                $event['title']
                                ?: $event['machine']
                                ?: 'Daily Report'
                            ) ?>

                        </span>

                    </div>

                    <p>
                        <?= e($event['description']) ?>
                    </p>

                </div>

            </a>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<section class="panel next-stage">

    <h2>Final Report Generator</h2>

    <p>
        The case-wide structure is now in place.
        The next stage can turn this data into a complete
        chronological final report while linking every statement
        back to its original daily report.
    </p>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>