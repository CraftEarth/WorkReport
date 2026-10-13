<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$worker = require_current_worker();
$workerId = (int) $worker['id'];

$pageTitle = 'Timeline';

$stmt = db()->prepare("
    SELECT *
    FROM reports
    WHERE worker_id = :worker_id
    ORDER BY report_date DESC, id DESC
");

$stmt->execute([
    ':worker_id' => $workerId
]);

$reports = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>Master Timeline</h1>

        <p>
            Every workplace report for the selected worker, organized by date.
        </p>

    </div>

    <a
        href="new-report.php"
        class="button button-primary"
    >
        + New Report
    </a>

</section>


<section class="timeline">

<?php if (!$reports): ?>

    <div class="panel empty-state">

        <h3>No timeline entries yet</h3>

        <p>
            Daily reports will appear here automatically.
        </p>

    </div>

<?php else: ?>

    <?php foreach ($reports as $report): ?>

        <article class="timeline-entry">

            <div class="timeline-marker"></div>

            <div class="timeline-date">

                <?= e(
                    format_date(
                        $report['report_date']
                    )
                ) ?>

            </div>

            <a
                href="report.php?id=<?= (int) $report['id'] ?>"
                class="timeline-card"
            >

                <div class="timeline-card-top">

                    <div>

                        <h2>
                            <?= e(
                                $report['title']
                                ?: $report['machine']
                                ?: 'Daily Work Report'
                            ) ?>
                        </h2>

                        <p>

                            <?php if ($report['department']): ?>

                                <?= e($report['department']) ?>

                            <?php endif; ?>

                            <?php if (
                                $report['department']
                                && $report['machine']
                            ): ?>

                                &bull;

                            <?php endif; ?>

                            <?= e($report['machine']) ?>

                        </p>

                    </div>

                    <span
                        class="status status-<?= e($report['status']) ?>"
                    >
                        <?= e(ucfirst($report['status'])) ?>
                    </span>

                </div>

                <div class="timeline-preview">

                    <?= e(
                        mb_strimwidth(
                            $report['original_text'],
                            0,
                            300,
                            '...'
                        )
                    ) ?>

                </div>

            </a>

        </article>

    <?php endforeach; ?>

<?php endif; ?>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
