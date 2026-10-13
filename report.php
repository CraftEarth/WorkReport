<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/legal-detector.php';

$id = (int) ($_GET['id'] ?? 0);

$report = get_report_for_current_worker($id);

if (!$report) {
    http_response_code(404);
    exit('Report not found.');
}

$pdo = db();

$worker = require_current_worker();

$stateCode = strtoupper(
    trim(
        $worker['state_code']
        ?? 'KY'
    )
);

$legalFlags = legal_flags_for_report($id);

$legalResources = [];

foreach ($legalFlags as $flag) {

    $resources = legal_resources_for_issue(
        $stateCode,
        $flag['resource_topic']
    );

    foreach ($resources as $resource) {
        $legalResources[
            (int) $resource['id']
        ] = $resource;
    }
}

$stmt = $pdo->prepare("
    SELECT p.*
    FROM people p
    INNER JOIN report_people rp
        ON rp.person_id = p.id
    WHERE rp.report_id = ?
    ORDER BY p.name
");

$stmt->execute([$id]);

$people = $stmt->fetchAll();


$stmt = $pdo->prepare("
    SELECT p.*
    FROM places p
    INNER JOIN report_places rp
        ON rp.place_id = p.id
    WHERE rp.report_id = ?
    ORDER BY p.name
");

$stmt->execute([$id]);

$places = $stmt->fetchAll();


$stmt = $pdo->prepare("
    SELECT c.*
    FROM categories c
    INNER JOIN report_categories rc
        ON rc.category_id = c.id
    WHERE rc.report_id = ?
    ORDER BY c.name
");

$stmt->execute([$id]);

$categories = $stmt->fetchAll();


$stmt = $pdo->prepare("
    SELECT *
    FROM events
    WHERE report_id = ?
    ORDER BY
        CASE
            WHEN event_time IS NULL
              OR event_time = ''
            THEN 1
            ELSE 0
        END,
        event_time,
        id
");

$stmt->execute([$id]);

$events = $stmt->fetchAll();


$stmt = $pdo->prepare("
    SELECT analyzed_at
    FROM report_analysis
    WHERE report_id = ?
");

$stmt->execute([$id]);

$analyzedAt = $stmt->fetchColumn();


$pageTitle =
    $report['title']
    ?: 'Daily Report';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>
            <?= e(
                $report['title']
                ?: 'Daily Work Report'
            ) ?>
        </h1>

        <p>
            <?= e(
                format_date(
                    $report['report_date']
                )
            ) ?>
        </p>

    </div>

    <div class="heading-actions">

        <a
            href="manage-report.php?id=<?= $id ?>"
            class="button button-secondary"
        >
            Manage
        </a>

        <a
            href="evidence.php?id=<?= $id ?>"
            class="button button-secondary"
        >
            Evidence
        </a>

        <a
            href="analyze-report.php?id=<?= $id ?>"
            class="button button-primary"
        >
            <?= $analyzedAt
                ? 'Re-Analyze Report'
                : 'Analyze Report' ?>
        </a>

        <?php if ($report['status'] === 'draft'): ?>

            <a
                href="edit-report.php?id=<?= $id ?>"
                class="button button-secondary"
            >
                Edit Draft
            </a>

            <form
                method="post"
                action="finalize-report.php"
                class="inline-form"
            >
            <?= csrf_field() ?>

                <input
                    type="hidden"
                    name="id"
                    value="<?= $id ?>"
                >

                <button
                    type="submit"
                    class="button button-secondary"
                    onclick="return confirm(
                        'Finalize this report? ' +
                        'The original entry will become read-only.'
                    );"
                >
                    Finalize
                </button>

            </form>

        <?php endif; ?>

    </div>

</section>


<section class="report-meta">

    <div>
        <span>Status</span>

        <strong
            class="status status-<?= e($report['status']) ?>"
        >
            <?= e(
                ucfirst(
                    $report['status']
                )
            ) ?>
        </strong>
    </div>

    <div>
        <span>Shift</span>

        <strong>
            <?= e(
                $report['shift']
                ?: '-'
            ) ?>
        </strong>
    </div>

    <div>
        <span>Department</span>

        <strong>
            <?= e(
                $report['department']
                ?: '-'
            ) ?>
        </strong>
    </div>

    <div>
        <span>Machine / Area</span>

        <strong>
            <?= e(
                $report['machine']
                ?: '-'
            ) ?>
        </strong>
    </div>

</section>


<!-- WORKREPORT_REPORT_SIDEBAR_V1 -->
<div class="report-review-layout">

    <div class="report-review-main">
<!-- WORKREPORT_PRO_REPORT_SOURCE_V1 -->
<section class="panel report-source-panel">

    <div class="report-source-heading">

        <div>
            <span class="report-source-eyebrow">
                Preserved Source Record
            </span>

            <h2>Worker Statement</h2>

            <p>
                Original wording preserved exactly as submitted.
            </p>
        </div>

        <div class="report-source-status">
            <span>
                <?= str_word_count((string) $report['original_text']) ?> words
            </span>

            <span>
                Report #<?= (int) $report['id'] ?>
            </span>
        </div>

    </div>

    <div class="report-source-meta">

        <div>
            <span>Date</span>
            <strong>
                <?= e(format_date($report['report_date'])) ?>
            </strong>
        </div>

        <div>
            <span>Shift</span>
            <strong>
                <?= e($report['shift'] ?: 'Not stated') ?>
            </strong>
        </div>

        <div>
            <span>Department</span>
            <strong>
                <?= e($report['department'] ?: 'Not stated') ?>
            </strong>
        </div>

        <div>
            <span>Machine / Area</span>
            <strong>
                <?= e($report['machine'] ?: 'Not stated') ?>
            </strong>
        </div>

    </div>

    <div class="report-document">

        <div class="report-document-label">
            Statement
        </div>

        <div class="report-text report-document-body">
            <?= nl2br(
                e($report['original_text'])
            ) ?>
        </div>

    </div>

</section>


<?php if ($legalFlags): ?>

<section class="panel legal-alert-panel">

    <div class="panel-header">

        <div>

            <h2>
                Potential Legal / Compliance Concern
            </h2>

            <p>
                Automated review found information that may
                warrant additional review or assistance.
            </p>

        </div>

    </div>


    <div class="legal-flag-list">

        <?php foreach ($legalFlags as $flag): ?>

            <article class="legal-flag">

                <div class="legal-flag-heading">

                    <strong>
                        <?= e($flag['title']) ?>
                    </strong>

                    <span class="tag">
                        <?= e(
                            ucfirst(
                                $flag['severity']
                            )
                        ) ?>
                    </span>

                </div>


                <p>
                    <?= e($flag['reason']) ?>
                </p>


                <?php if ($flag['matched_excerpt']): ?>

                    <div class="legal-match">

                        <span>
                            Text that triggered review:
                        </span>

                        <blockquote>
                            <?= e(
                                $flag['matched_excerpt']
                            ) ?>
                        </blockquote>

                    </div>

                <?php endif; ?>

            </article>

        <?php endforeach; ?>

    </div>


    <?php if ($legalResources): ?>

        <div class="legal-resources">

            <h3>
                Official Information & Assistance
            </h3>

            <p class="help-text">
                These resources may help you understand your
                rights and available options.
            </p>


            <?php foreach ($legalResources as $resource): ?>

                <div class="legal-resource">

                    <a
                        href="<?= e($resource['source_url']) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <strong>
                            <?= e($resource['title']) ?>
                        </strong>
                    </a>

                    <span>
                        <?= e($resource['source_name']) ?>
                    </span>

                    <?php if ($resource['summary']): ?>

                        <p>
                            <?= e($resource['summary']) ?>
                        </p>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <div class="help-text">

        <strong>Important:</strong>

        This system has identified a possible issue for review.
        It has not determined that a law or workplace policy
        was violated.

    </div>

</section>

<?php endif; ?>





        <?php if ($analyzedAt): ?>

<section class="panel">

    <h2>Extracted Events</h2>

    <?php if (!$events): ?>

        <p>No events saved.</p>

    <?php else: ?>

        <div class="event-list">

        <?php foreach ($events as $event): ?>

            <div class="event-item">

                <div class="event-item-time">

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

                </div>

                <div class="event-item-body">

                    <span class="tag">
                        <?= e($event['category']) ?>
                    </span>

                    <p>
                        <?= e(
                            $event['description']
                        ) ?>
                    </p>

                </div>

            </div>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

        <?php endif; ?>

    </div>

    <?php if ($analyzedAt): ?>

    <aside class="report-review-sidebar" aria-label="Structured analysis">

<section class="panel">

    <div class="panel-header">

        <div>
            <h2>Structured Analysis</h2>

            <p>
                Last analyzed:
                <?= e(
                    format_datetime(
                        $analyzedAt
                    )
                ) ?>
            </p>
        </div>

    </div>


    <div class="analysis-columns">

        <div>

            <h3>People</h3>

            <?php if ($people): ?>

                <div class="tag-list">

                    <?php foreach ($people as $person): ?>

                        <span class="tag">
                            <?= e($person['name']) ?>
                        </span>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <p class="muted">
                    None recorded.
                </p>

            <?php endif; ?>

        </div>


        <div>

            <h3>Places / Machines</h3>

            <?php if ($places): ?>

                <div class="tag-list">

                    <?php foreach ($places as $place): ?>

                        <span class="tag">
                            <?= e($place['name']) ?>
                        </span>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <p class="muted">
                    None recorded.
                </p>

            <?php endif; ?>

        </div>


        <div>

            <h3>Categories</h3>

            <?php if ($categories): ?>

                <div class="tag-list">

                    <?php foreach ($categories as $category): ?>

                        <span class="tag">
                            <?= e($category['name']) ?>
                        </span>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <p class="muted">
                    None recorded.
                </p>

            <?php endif; ?>

        </div>

    </div>

</section>

    </aside>

    <?php endif; ?>

</div>

<?php if ($report['status'] === 'finalized'): ?>

<section class="panel integrity-panel">

    <h2>Document Integrity</h2>

    <div class="integrity-grid">

        <div>
            <span>Finalized</span>

            <strong>
                <?= e(
                    format_datetime(
                        $report['finalized_at']
                    )
                ) ?>
            </strong>
        </div>

        <div>
            <span>SHA-256</span>

            <code>
                <?= e(
                    $report['report_hash']
                ) ?>
            </code>
        </div>

    </div>

</section>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>