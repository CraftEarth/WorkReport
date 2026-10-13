<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/worker-profile.php';
require_once __DIR__ . '/includes/work-journal.php';
require_once __DIR__ . '/includes/journal-intelligence.php';

require_login();
ensure_worker_profile_schema();
ensure_work_journal_schema();

$requestedWorkerId = (int) ($_GET['worker_id'] ?? 0);

if ($requestedWorkerId > 0) {
    if (!worker_accessible($requestedWorkerId)) {
        http_response_code(403);
        exit('Access denied.');
    }

    $_SESSION['worker_id'] = $requestedWorkerId;
}

$worker = require_current_worker();
$workerId = (int) $worker['id'];
$pdo = db();

$user = current_user();
$userId = $user ? (int) $user['id'] : null;

if (empty($_SESSION['journal_csrf'])) {
    $_SESSION['journal_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$journalCsrf = $_SESSION['journal_csrf'];
$quickJournalError = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'quick_journal'
) {
    $postedCsrf =
        (string) ($_POST['csrf_token'] ?? '');

    if (
        $postedCsrf === ''
        || !hash_equals($journalCsrf, $postedCsrf)
    ) {
        http_response_code(400);
        exit('Invalid request token.');
    }

    try {
        $messageText = trim(
            (string) ($_POST['message_text'] ?? '')
        );

        if ($messageText === '') {
            throw new RuntimeException(
                'Write something before saving.'
            );
        }

        if (mb_strlen($messageText) > 20000) {
            throw new RuntimeException(
                'That entry is too long. Keep each message under 20,000 characters.'
            );
        }

        /*
         * Quick Journal uses the same source-record path as the
         * full Journal. Reuse today's open session when possible.
         */
        $journalDate = date('Y-m-d');

        $quickSession =
            open_journal_session_for_date(
                $workerId,
                $journalDate
            );

        if (!$quickSession) {
            $quickSession = create_journal_session(
                $workerId,
                $userId
            );
        }

        add_journal_message(
            (int) $quickSession['id'],
            $userId,
            'worker',
            $messageText
        );

        /*
         * Source capture comes first.
         * Intelligence failure must never prevent preservation
         * of the worker's original statement.
         */
        try {
            journal_intelligence_process_pending(
                $workerId,
                (int) $quickSession['id'],
                true
            );
        } catch (Throwable $intelligenceError) {
            error_log(
                'WorkReport profile quick journal intelligence error: '
                . $intelligenceError->getMessage()
            );
        }

        header(
            'Location: worker-profile.php?worker_id='
            . $workerId
            . '#quick-journal'
        );
        exit;

    } catch (Throwable $e) {
        $quickJournalError = $e->getMessage();
    }
}

$recentJournalMessages =
    recent_journal_worker_messages(
        $workerId,
        3
    );

$age = worker_age($worker['birth_date'] ?? null);
$profileCompletion = worker_profile_completion($worker);

/*
 * Normalized employment is authoritative when available.
 *
 * Legacy worker fields remain as a compatibility fallback only for
 * workers who do not yet have a normalized employment record.
 *
 * Do not fill blank normalized values from legacy fields because
 * those legacy values may eventually be stale historical data.
 */
$currentEmployment = worker_current_employment($workerId);

if ($currentEmployment !== null) {
    $employmentEmployer =
        trim((string) ($currentEmployment['employer'] ?? ''));

    $employmentWorksite =
        trim((string) ($currentEmployment['worksite'] ?? ''));

    $employmentJobTitle =
        trim((string) ($currentEmployment['job_title'] ?? ''));

    $employmentDepartment =
        trim((string) ($currentEmployment['department'] ?? ''));

    $employmentWorkArea =
        trim((string) ($currentEmployment['work_area'] ?? ''));

    $employmentShift =
        trim((string) ($currentEmployment['shift_name'] ?? ''));

    $employmentSupervisor =
        trim((string) ($currentEmployment['supervisor_name'] ?? ''));

    $employmentTraining =
        trim((string) ($currentEmployment['training_status'] ?? ''));

    $employmentStartDate =
        trim((string) ($currentEmployment['start_date'] ?? ''));
} else {
    $employmentEmployer =
        trim((string) ($worker['employer'] ?? ''));

    $employmentWorksite = '';

    $employmentJobTitle =
        trim((string) ($worker['job_title'] ?? ''));

    $employmentDepartment =
        trim((string) ($worker['department'] ?? ''));

    $employmentWorkArea =
        trim((string) ($worker['primary_work_area'] ?? ''));

    $employmentShift =
        trim((string) ($worker['shift_name'] ?? ''));

    $employmentSupervisor =
        trim((string) ($worker['supervisor_name'] ?? ''));

    $employmentTraining =
        trim((string) ($worker['training_status'] ?? ''));

    $employmentStartDate =
        trim((string) ($worker['hire_date'] ?? ''));
}

$tenure = worker_tenure_label(
    $employmentStartDate !== ''
        ? $employmentStartDate
        : null
);

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS report_count,
        SUM(CASE WHEN status = 'finalized' THEN 1 ELSE 0 END)
            AS finalized_count,
        MAX(report_date) AS last_report
    FROM reports
    WHERE worker_id = ?
");

$stmt->execute([$workerId]);
$reportStats = $stmt->fetch() ?: [];

$evidenceCount = 0;

if (worker_profile_table_exists('attachments')) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM attachments a
        INNER JOIN reports r
            ON r.id = a.report_id
        WHERE r.worker_id = ?
    ");

    $stmt->execute([$workerId]);
    $evidenceCount = (int) $stmt->fetchColumn();
}

$operationalCount = 0;

if (worker_profile_table_exists('report_operational_findings')) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM report_operational_findings f
        INNER JOIN reports r
            ON r.id = f.report_id
        WHERE r.worker_id = ?
    ");

    $stmt->execute([$workerId]);
    $operationalCount = (int) $stmt->fetchColumn();
}

$legalFlagCount = 0;

if (worker_profile_table_exists('report_legal_flags')) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM report_legal_flags f
        INNER JOIN reports r
            ON r.id = f.report_id
        WHERE r.worker_id = ?
    ");

    $stmt->execute([$workerId]);
    $legalFlagCount = (int) $stmt->fetchColumn();
}

$operationalExpression =
    worker_profile_table_exists('report_operational_findings')
        ? "(
            SELECT COUNT(*)
            FROM report_operational_findings ofn
            WHERE ofn.report_id = r.id
        )"
        : "0";

$legalExpression =
    worker_profile_table_exists('report_legal_flags')
        ? "(
            SELECT COUNT(*)
            FROM report_legal_flags lf
            WHERE lf.report_id = r.id
        )"
        : "0";

$stmt = $pdo->prepare("
    SELECT
        r.*,
        {$operationalExpression} AS operational_count,
        {$legalExpression} AS legal_count
    FROM reports r
    WHERE r.worker_id = ?
    ORDER BY
        r.report_date DESC,
        r.id DESC
    LIMIT 50
");

$stmt->execute([$workerId]);
$reports = $stmt->fetchAll();

$pageTitle = $worker['full_name'] . ' - Worker Workspace';

$workspaceSubtitleParts = array_filter([
    $employmentEmployer,
    $employmentJobTitle,
    $employmentDepartment,
    $employmentWorkArea,
    $employmentShift
]);

$workspaceSubtitle = implode(' • ', $workspaceSubtitleParts);

require __DIR__ . '/includes/header.php';

?>

<?php
$nameParts = array_values(
    array_filter(
        explode(
            ' ',
            trim((string) $worker['full_name'])
        )
    )
);

$workerInitials = '';

foreach (array_slice($nameParts, 0, 2) as $namePart) {
    $workerInitials .= mb_strtoupper(
        mb_substr($namePart, 0, 1)
    );
}

$lastReportDate =
    !empty($reportStats['last_report'])
        ? format_date($reportStats['last_report'])
        : 'None yet';
?>

<div class="wr-workspace">


    <!-- ==================================================
         WORKER IDENTITY
         ================================================== -->

    <section class="wr-profile-hero">

        <div class="wr-profile-avatar">

            <?php if (!empty($worker['profile_photo_file'])): ?>

                <img
                    src="worker-photo.php?worker_id=<?= $workerId ?>"
                    alt="<?= e($worker['full_name']) ?>"
                >

            <?php else: ?>

                <div class="wr-profile-avatar-fallback">
                    <?= e($workerInitials ?: 'WR') ?>
                </div>

            <?php endif; ?>

        </div>


        <div class="wr-profile-identity">

            <div class="wr-profile-title-row">

                <h1>
                    <?= e($worker['full_name']) ?>
                </h1>

                <span class="wr-profile-status">
                    Active Worker File
                </span>

            </div>


            <div class="wr-profile-primary">

                <strong>
                    <?= e(
                        $employmentEmployer !== ''
                            ? $employmentEmployer
                            : 'Employer not recorded'
                    ) ?>
                </strong>

                <?php if ($employmentJobTitle !== ''): ?>
                    <span>
                        <?= e($employmentJobTitle) ?>
                    </span>
                <?php endif; ?>

            </div>


            <div class="wr-profile-meta">

                <?php if ($employmentDepartment !== ''): ?>
                    <span>
                        Department:
                        <strong>
                            <?= e($employmentDepartment) ?>
                        </strong>
                    </span>
                <?php endif; ?>

                <?php if ($employmentWorkArea !== ''): ?>
                    <span>
                        Area:
                        <strong>
                            <?= e($employmentWorkArea) ?>
                        </strong>
                    </span>
                <?php endif; ?>

                <?php if ($employmentShift !== ''): ?>
                    <span>
                        Shift:
                        <strong>
                            <?= e($employmentShift) ?>
                        </strong>
                    </span>
                <?php endif; ?>

                <?php if ($employmentSupervisor !== ''): ?>
                    <span>
                        Supervisor:
                        <strong>
                            <?= e($employmentSupervisor) ?>
                        </strong>
                    </span>
                <?php endif; ?>

            </div>

        </div>


        <div class="wr-profile-edit">

            <a
                href="edit-worker-profile.php"
                class="wr-secondary-button"
            >
                Edit Profile
            </a>

        </div>

    </section>



    <!-- ==================================================
         WORKSPACE
         ================================================== -->

    <div class="wr-workspace-grid">


        <div class="wr-workspace-main">


            <section class="wr-panel">

                <div class="wr-panel-heading">

                    <span class="wr-eyebrow">
                        CASE WORKSPACE
                    </span>

                    <h2>
                        Your WorkReport file
                    </h2>

                    <p>
                        Capture what happened, preserve the record,
                        and review the history of your case.
                    </p>

                </div>


                <div class="wr-action-grid">

                    <a
                        href="journal.php"
                        class="wr-action"
                    >
                        <span class="wr-action-kicker">
                            RECORD
                        </span>

                        <strong>
                            Work Journal
                        </strong>

                        <small>
                            Capture events and notes while they are fresh.
                        </small>
                    </a>


                    <a
                        href="new-report.php"
                        class="wr-action"
                    >
                        <span class="wr-action-kicker">
                            DOCUMENT
                        </span>

                        <strong>
                            New Report
                        </strong>

                        <small>
                            Create a structured workplace report.
                        </small>
                    </a>


                    <a
                        href="timeline.php"
                        class="wr-action"
                    >
                        <span class="wr-action-kicker">
                            HISTORY
                        </span>

                        <strong>
                            Timeline
                        </strong>

                        <small>
                            Review documented events in chronological order.
                        </small>
                    </a>


                    <a
                        href="case-analysis.php"
                        class="wr-action"
                    >
                        <span class="wr-action-kicker">
                            REVIEW
                        </span>

                        <strong>
                            Analysis
                        </strong>

                        <small>
                            Review patterns, signals and structured findings.
                        </small>
                    </a>


                    <a
                        href="final-report.php"
                        class="wr-action"
                    >
                        <span class="wr-action-kicker">
                            PREPARE
                        </span>

                        <strong>
                            Final Report
                        </strong>

                        <small>
                            Assemble the documented record for review.
                        </small>
                    </a>


                    <a
                        href="search.php"
                        class="wr-action"
                    >
                        <span class="wr-action-kicker">
                            FIND
                        </span>

                        <strong>
                            Search
                        </strong>

                        <small>
                            Search reports, records and case information.
                        </small>
                    </a>

                </div>

            </section>



            <!-- ==================================================
                 QUICK JOURNAL
                 ================================================== -->

            <section
                class="wr-panel wr-quick-journal"
                id="quick-journal"
            >

                <div class="wr-panel-heading wr-panel-heading-row">

                    <div>

                        <span class="wr-eyebrow">
                            QUICK JOURNAL
                        </span>

                        <h2>
                            Save it while it is fresh
                        </h2>

                        <p>
                            This writes directly to the same preserved
                            source record used by the full Work Journal.
                        </p>

                    </div>

                    <a
                        href="journal.php"
                        class="wr-text-link"
                    >
                        Open Full Journal →
                    </a>

                </div>


                <?php if ($quickJournalError !== ''): ?>

                    <div class="alert alert-error">
                        <?= e($quickJournalError) ?>
                    </div>

                <?php endif; ?>


                <form method="post">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($journalCsrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="quick_journal"
                    >

                    <textarea
                        name="message_text"
                        rows="5"
                        maxlength="20000"
                        placeholder="Write what happened, save a reminder, or document something before you forget..."
                        required
                    ></textarea>


                    <div class="wr-quick-journal-footer">

                        <small>
                            Preserved in your Work Journal source record.
                        </small>

                        <button
                            type="submit"
                            class="button"
                        >
                            Save Entry
                        </button>

                    </div>

                </form>


                <?php if ($recentJournalMessages): ?>

                    <div class="wr-recent-notes">

                        <h3>
                            Recent journal notes
                        </h3>

                        <?php foreach ($recentJournalMessages as $journalMessage): ?>

                            <article class="wr-recent-note">

                                <time>
                                    <?= e(
                                        format_datetime(
                                            $journalMessage['created_at']
                                        )
                                    ) ?>
                                </time>

                                <p>
                                    <?= nl2br(
                                        e(
                                            $journalMessage['message_text']
                                        )
                                    ) ?>
                                </p>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>



            <!-- ==================================================
                 REPORT HISTORY
                 ================================================== -->

            <section
                class="wr-panel"
                id="reports"
            >

                <div class="wr-panel-heading wr-panel-heading-row">

                    <div>

                        <span class="wr-eyebrow">
                            DOCUMENTATION
                        </span>

                        <h2>
                            Reports
                        </h2>

                        <p>
                            Workplace reports preserved in this worker file.
                        </p>

                    </div>

                    <a
                        href="new-report.php"
                        class="wr-text-link"
                    >
                        + New Report
                    </a>

                </div>


                <?php if (!$reports): ?>

                    <div class="wr-empty">
                        No reports have been created yet.
                    </div>

                <?php else: ?>

                    <div class="wr-report-list">

                        <?php foreach ($reports as $report): ?>

                            <?php
                            $operational =
                                (int) (
                                    $report['operational_count']
                                    ?? 0
                                );

                            $legal =
                                (int) (
                                    $report['legal_count']
                                    ?? 0
                                );
                            ?>

                            <article class="wr-report-row">

                                <div class="wr-report-date">

                                    <span>
                                        DATE
                                    </span>

                                    <strong>
                                        <?= e(
                                            format_date(
                                                $report['report_date']
                                            )
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="wr-report-main">

                                    <a
                                        href="report.php?id=<?= (int) $report['id'] ?>"
                                        class="wr-report-title"
                                    >
                                        <?= e(
                                            $report['title']
                                            ?: 'Daily Work Report'
                                        ) ?>
                                    </a>

                                    <div class="wr-report-meta">

                                        <span>
                                            <?= e(
                                                $report['machine']
                                                ?: $report['department']
                                                ?: 'Area not recorded'
                                            ) ?>
                                        </span>

                                        <span>
                                            <?= e(
                                                ucfirst(
                                                    $report['status']
                                                )
                                            ) ?>
                                        </span>

                                    </div>

                                </div>


                                <div class="wr-report-signal">

                                    <?php if ($legal > 0): ?>

                                        <span class="wr-signal wr-signal-legal">
                                            Legal review <?= $legal ?>
                                        </span>

                                    <?php elseif ($operational > 0): ?>

                                        <span class="wr-signal wr-signal-review">
                                            Review <?= $operational ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="wr-signal wr-signal-clear">
                                            No active signal
                                        </span>

                                    <?php endif; ?>

                                </div>


                                <div class="wr-report-links">

                                    <a
                                        href="report.php?id=<?= (int) $report['id'] ?>"
                                    >
                                        Open
                                    </a>

                                    <a
                                        href="analyze-report.php?id=<?= (int) $report['id'] ?>"
                                    >
                                        Analyze
                                    </a>

                                    <a
                                        href="evidence.php?id=<?= (int) $report['id'] ?>"
                                    >
                                        Evidence
                                    </a>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>


        </div>



        <!-- ==================================================
             SIDEBAR
             ================================================== -->

        <aside class="wr-workspace-side">


            <section class="wr-panel">

                <div class="wr-panel-heading">

                    <span class="wr-eyebrow">
                        CASE SNAPSHOT
                    </span>

                    <h2>
                        Current file
                    </h2>

                </div>


                <div class="wr-snapshot-grid">

                    <div>
                        <span>Reports</span>
                        <strong>
                            <?= (int) ($reportStats['report_count'] ?? 0) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Finalized</span>
                        <strong>
                            <?= (int) ($reportStats['finalized_count'] ?? 0) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Evidence</span>
                        <strong>
                            <?= $evidenceCount ?>
                        </strong>
                    </div>

                    <div>
                        <span>Journal</span>
                        <strong>
                            <?= $openJournal ? 'Open' : 'No open session' ?>
                        </strong>
                    </div>

                </div>

            </section>



            <section class="wr-panel">

                <div class="wr-panel-heading">

                    <span class="wr-eyebrow">
                        WORKER DETAILS
                    </span>

                    <h2>
                        Employment profile
                    </h2>

                </div>


                <dl class="wr-detail-list">

                    <div>
                        <dt>Age</dt>
                        <dd>
                            <?= $age !== null
                                ? (int) $age
                                : 'Not recorded' ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Job title</dt>
                        <dd>
                            <?= e(
                                $employmentJobTitle !== ''
                                    ? $employmentJobTitle
                                    : 'Not recorded'
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Department</dt>
                        <dd>
                            <?= e(
                                $employmentDepartment !== ''
                                    ? $employmentDepartment
                                    : 'Not recorded'
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Primary area</dt>
                        <dd>
                            <?= e(
                                $employmentWorkArea !== ''
                                    ? $employmentWorkArea
                                    : 'Not recorded'
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Shift</dt>
                        <dd>
                            <?= e(
                                $employmentShift !== ''
                                    ? $employmentShift
                                    : 'Not recorded'
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Supervisor</dt>
                        <dd>
                            <?= e(
                                $employmentSupervisor !== ''
                                    ? $employmentSupervisor
                                    : 'Not recorded'
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Training</dt>
                        <dd>
                            <?= e(
                                $employmentTraining !== ''
                                    ? $employmentTraining
                                    : 'Not recorded'
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Tenure</dt>
                        <dd>
                            <?= e(
                                $tenure !== ''
                                    ? $tenure
                                    : 'Not recorded'
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Worksite</dt>
                        <dd>
                            <?= e(
                                $employmentWorksite !== ''
                                    ? $employmentWorksite
                                    : 'Not recorded'
                            ) ?>
                        </dd>
                    </div>

                </dl>

            </section>



            <section class="wr-panel">

                <div class="wr-panel-heading">

                    <span class="wr-eyebrow">
                        REVIEW STATUS
                    </span>

                    <h2>
                        Record indicators
                    </h2>

                </div>


                <dl class="wr-detail-list">

                    <div>
                        <dt>Operational findings</dt>
                        <dd>
                            <?= $operationalCount ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Legal / compliance flags</dt>
                        <dd>
                            <?= $legalFlagCount ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Profile complete</dt>
                        <dd>
                            <?= $profileCompletion ?>%
                        </dd>
                    </div>

                    <div>
                        <dt>Last report</dt>
                        <dd>
                            <?= e($lastReportDate) ?>
                        </dd>
                    </div>

                </dl>

            </section>


        </aside>


    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
