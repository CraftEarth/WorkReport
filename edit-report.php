<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$report = get_report_for_current_worker($id);

if (!$report) {
    http_response_code(404);
    exit('Report not found.');
}

if ($report['status'] !== 'draft') {

    flash(
        'error',
        'Finalized reports cannot be directly edited.'
    );

    redirect('report.php?id=' . $id);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    
    verify_csrf(
        $_POST['_csrf'] ?? null
    );

$reportDate = trim($_POST['report_date'] ?? '');
    $shift = trim($_POST['shift'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $machine = trim($_POST['machine'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $originalText = trim($_POST['original_text'] ?? '');

    if (
        $reportDate === ''
        || $originalText === ''
    ) {

        $error = 'Report date and report text are required.';

    } else {

        $stmt = db()->prepare("
            UPDATE reports
            SET
                report_date = :report_date,
                shift = :shift,
                department = :department,
                machine = :machine,
                title = :title,
                original_text = :original_text,
                updated_at = datetime('now', 'localtime')
            WHERE id = :id
              AND worker_id = :worker_id
              AND status = 'draft'
        ");

        $stmt->execute([
            ':report_date' => $reportDate,
            ':shift' => $shift,
            ':department' => $department,
            ':machine' => $machine,
            ':title' => $title,
            ':original_text' => $originalText,
            ':id' => $id,
            ':worker_id' => (int) $report['worker_id']
        ]);

        flash(
            'success',
            'Draft updated successfully.'
        );

        redirect('report.php?id=' . $id);
    }
}

$report = get_report_for_current_worker($id);

$pageTitle = 'Edit Report';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>Edit Draft</h1>

        <p>
            Report #<?= $id ?>
        </p>

    </div>

</section>


<?php if ($error): ?>

<div class="flash flash-error">
    <?= e($error) ?>
</div>

<?php endif; ?>


<form
    method="post"
    class="report-form"
>
    <?= csrf_field() ?>

    <input
        type="hidden"
        name="id"
        value="<?= $id ?>"
    >

    <section class="panel">

        <h2>Report Details</h2>

        <div class="form-grid">

            <div class="field">

                <label>Date *</label>

                <input
                    type="date"
                    name="report_date"
                    value="<?= e($report['report_date']) ?>"
                    required
                >

            </div>

            <div class="field">

                <label>Shift</label>

                <input
                    type="text"
                    name="shift"
                    value="<?= e($report['shift']) ?>"
                >

            </div>

            <div class="field">

                <label>Department / Area</label>

                <input
                    type="text"
                    name="department"
                    value="<?= e($report['department']) ?>"
                >

            </div>

            <div class="field">

                <label>Machine / Workstation</label>

                <input
                    type="text"
                    name="machine"
                    value="<?= e($report['machine']) ?>"
                >

            </div>

        </div>

        <div class="field">

            <label>Report Title</label>

            <input
                type="text"
                name="title"
                value="<?= e($report['title']) ?>"
            >

        </div>

    </section>


    <section class="panel">

        <h2>Original Statement</h2>

        <div class="field">

            <textarea
                id="original_text"
                name="original_text"
                rows="18"
                required
            ><?= e($report['original_text']) ?></textarea>

        </div>

        <div class="word-counter">

            <span id="wordCount">0 words</span>

            <span id="charCount">0 characters</span>

        </div>

    </section>


    <div class="form-actions">

        <a
            href="report.php?id=<?= $id ?>"
            class="button button-secondary"
        >
            Cancel
        </a>

        <button
            type="submit"
            class="button button-primary"
        >
            Save Changes
        </button>

    </div>

</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
