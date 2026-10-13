<?php

require_once __DIR__ . '/includes/media-evidence.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

ensure_media_evidence_schema();

$reportId = (int) (
    $_GET['id']
    ?? 0
);

$report = get_report_for_current_worker($reportId);

if (!$report) {
    http_response_code(404);
    exit('Report not found.');
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT *
    FROM attachments
    WHERE report_id = ?
    ORDER BY uploaded_at DESC, id DESC
");

$stmt->execute([$reportId]);

$attachments = $stmt->fetchAll();

/* WORKREPORT_MANAGE_ACTIVE_EVIDENCE_V3 */
$attachments = array_values(
    array_filter(
        $attachments,
        static function (array $item): bool {
            return ($item['status'] ?? 'active') === 'active';
        }
    )
);


$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM events
    WHERE report_id = ?
");

$stmt->execute([$reportId]);

$eventCount =
    (int) $stmt->fetchColumn();


$stmt = $pdo->prepare("
    SELECT analyzed_at
    FROM report_analysis
    WHERE report_id = ?
");

$stmt->execute([$reportId]);

$analyzedAt =
    $stmt->fetchColumn();


$pageTitle = 'Manage Report';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>Manage Report</h1>

        <p>
            <?= e(
                format_date(
                    $report['report_date']
                )
            ) ?>
            · Report #<?= $reportId ?>
        </p>

    </div>

    <a
        href="report.php?id=<?= $reportId ?>"
        class="button button-secondary"
    >
        Back to Report
    </a>

</section>


<section class="panel">

    <h2>Report Record</h2>

    <div class="manage-summary">

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

            <span>Analysis</span>

            <strong>
                <?= $analyzedAt
                    ? 'Analyzed'
                    : 'Not analyzed' ?>
            </strong>

        </div>

        <div>

            <span>Events</span>

            <strong>
                <?= $eventCount ?>
            </strong>

        </div>

        <div>

            <span>Evidence Files</span>

            <strong>
                <?= count($attachments) ?>
            </strong>

        </div>

    </div>

</section>


<section class="panel">

    <h2>Structured Analysis</h2>

    <p class="help-text">
        Clearing the analysis removes detected people,
        places, categories and events from this report.
        It does not change the original daily statement.
    </p>

    <?php if ($analyzedAt): ?>

        <form
            method="post"
            action="clear-analysis.php"
            onsubmit="return confirm(
                'Clear the structured analysis for this report? ' +
                'The original statement will remain untouched.'
            );"
        >
            <?= csrf_field() ?>

            <input
                type="hidden"
                name="report_id"
                value="<?= $reportId ?>"
            >

            <button
                type="submit"
                class="button button-warning"
            >
                Clear Analysis
            </button>

        </form>

    <?php else: ?>

        <p class="muted">
            There is no saved analysis to clear.
        </p>

    <?php endif; ?>

</section>


<section class="panel">

    <div class="panel-header">

        <div>

            <h2>Evidence</h2>

            <p>
                Files attached to this report.
            </p>

        </div>

        <a
            href="evidence.php?id=<?= $reportId ?>"
            class="button button-secondary"
        >
            Add Evidence
        </a>

    </div>


    <?php if (!$attachments): ?>

        <div class="empty-state">

            <p>
                No evidence is attached to this report.
            </p>

        </div>

    <?php else: ?>

        <div class="manage-evidence-list">

        <?php foreach ($attachments as $item): ?>

            <div class="manage-evidence-row">

                <div class="manage-evidence-info">

                    <div>

                        <span class="tag">
                            <?= e(
                                $item['evidence_type']
                            ) ?>
                        </span>

                    </div>

                    <strong>
                        <?= e(
                            $item['filename']
                        ) ?>
                    </strong>

                    <?php if (
                        $item['description']
                    ): ?>

                        <span>
                            <?= e(
                                $item['description']
                            ) ?>
                        </span>

                    <?php endif; ?>

                </div>


                <div class="manage-evidence-actions">

                    <a
                        href="evidence-file.php?id=<?= (int) $item['id'] ?>"
                        target="_blank"
                        class="button button-secondary"
                    >
                        View
                    </a>


                    <form
                        method="post"
                        action="delete-evidence.php"
                        onsubmit="return confirm(
                            'Permanently delete this evidence file? ' +
                            'This cannot be undone.'
                        );"
                    >
                        <input
                            type="hidden"
                            name="_csrf"
                            value="<?= e(media_csrf_token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int) $item['id'] ?>"
                        >

                        <input
                            type="hidden"
                            name="report_id"
                            value="<?= $reportId ?>"
                        >

                        <button
                            type="submit"
                            class="button button-danger"
                        >
                            Delete
                        </button>

                    </form>

                </div>

            </div>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<section class="panel danger-zone">

    <h2>Danger Zone</h2>

    <?php if (
        $report['status']
        === 'draft'
    ): ?>

        <p>
            Draft reports may be permanently deleted.
            This also deletes their analysis and attached evidence.
        </p>

        <form
            method="post"
            action="delete-report.php"
            onsubmit="return confirm(
                'PERMANENTLY delete this draft report? ' +
                'This cannot be undone.'
            );"
        >
            <?= csrf_field() ?>

            <input
                type="hidden"
                name="report_id"
                value="<?= $reportId ?>"
            >

            <button
                type="submit"
                class="button button-danger"
            >
                Delete Draft Report
            </button>

        </form>

    <?php else: ?>

        <p>
            This report is finalized and cannot be deleted.
            Later we will add a formal Void / Archive process
            that preserves the historical record.
        </p>

    <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>