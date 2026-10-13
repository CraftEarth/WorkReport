<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/legal-detector.php';

require_admin();
ensure_legal_detection_schema();

$pdo = db();

$stmt = $pdo->query("
    SELECT
        w.id AS worker_id,
        w.full_name,
        w.department,
        w.employer,
        COUNT(DISTINCT f.report_id) AS flagged_reports,
        COUNT(*) AS total_flags,
        SUM(
            CASE
                WHEN f.severity IN ('high', 'critical')
                THEN 1
                ELSE 0
            END
        ) AS high_flags,
        MIN(r.report_date) AS first_flagged_report,
        MAX(r.report_date) AS latest_flagged_report,
        GROUP_CONCAT(DISTINCT t.title) AS issue_titles
    FROM workers w
    INNER JOIN reports r
        ON r.worker_id = w.id
    INNER JOIN report_legal_flags f
        ON f.report_id = r.id
    INNER JOIN legal_issue_types t
        ON t.id = f.issue_type_id
    WHERE f.review_status = 'unreviewed'
      AND r.report_date >= date('now', '-90 days')
    GROUP BY
        w.id,
        w.full_name,
        w.department,
        w.employer
    ORDER BY
        high_flags DESC,
        flagged_reports DESC,
        latest_flagged_report DESC
");

$workers = $stmt->fetchAll();

$pageTitle = 'Legal / Compliance Review';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>Legal / Compliance Review Queue</h1>

        <p>
            Workers with legally or compliance-relevant reports
            requiring administrative review.
        </p>

    </div>

</section>

<section class="panel">

    <h2>How This Queue Works</h2>

    <p class="help-text">
        Only reports that triggered a potential legal or compliance
        issue are counted here. Ordinary workplace complaints do not
        increase a worker's review level.
    </p>

    <p class="help-text">
        Review levels identify documentation patterns only.
        They do not determine that misconduct or a legal violation occurred.
    </p>

</section>

<?php if (!$workers): ?>

<section class="panel empty-state">

    <h3>No Legal / Compliance Reviews Pending</h3>

    <p>
        No workers currently have unreviewed legal or compliance
        flags within the last 90 days.
    </p>

</section>

<?php else: ?>

    <?php foreach ($workers as $item): ?>

        <?php

        $flaggedReports = (int) $item['flagged_reports'];
        $highFlags = (int) $item['high_flags'];

        if ($flaggedReports >= 5 || $highFlags >= 4) {
            $reviewLevel = 'Serious Assistance Review';
        } elseif ($flaggedReports >= 3 || $highFlags >= 2) {
            $reviewLevel = 'Elevated Review';
        } else {
            $reviewLevel = 'Review';
        }

        $issues = array_filter(
            array_map(
                'trim',
                explode(',', $item['issue_titles'] ?? '')
            )
        );

        ?>

        <section class="panel">

            <div class="panel-header">

                <div>

                    <h2>
                        <?= e($item['full_name']) ?>
                    </h2>

                    <p>
                        <?= e($item['employer'] ?: 'Employer not specified') ?>
                        <?php if ($item['department']): ?>
                            &bull;
                            <?= e($item['department']) ?>
                        <?php endif; ?>
                    </p>

                </div>

                <span class="tag">
                    <?= e($reviewLevel) ?>
                </span>

            </div>

            <div class="manage-summary">

                <div>
                    <span>Flagged Reports</span>
                    <strong><?= $flaggedReports ?></strong>
                </div>

                <div>
                    <span>Total Legal Flags</span>
                    <strong><?= (int) $item['total_flags'] ?></strong>
                </div>

                <div>
                    <span>High Severity Flags</span>
                    <strong><?= $highFlags ?></strong>
                </div>

                <div>
                    <span>Latest Flagged Report</span>
                    <strong>
                        <?= e(
                            format_date(
                                $item['latest_flagged_report']
                            )
                        ) ?>
                    </strong>
                </div>

            </div>

            <?php if ($issues): ?>

                <h3>Potential Issues Detected</h3>

                <div class="tag-list">

                    <?php foreach ($issues as $issue): ?>

                        <span class="tag">
                            <?= e($issue) ?>
                        </span>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

            <p class="help-text">
                Documentation period:
                <?= e(format_date($item['first_flagged_report'])) ?>
                through
                <?= e(format_date($item['latest_flagged_report'])) ?>.
            </p>

        </section>

    <?php endforeach; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>