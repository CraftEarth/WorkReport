<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$worker = require_current_worker();
$workerId = (int) $worker['id'];

$pageTitle = 'New Daily Report';

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

    if ($reportDate === '' || $originalText === '') {

        $error = 'Report date and report text are required.';

    } else {

        $stmt = db()->prepare("
            INSERT INTO reports (
                worker_id,
                report_date,
                shift,
                department,
                machine,
                title,
                original_text,
                status,
                created_at,
                updated_at
            )
            VALUES (
                :worker_id,
                :report_date,
                :shift,
                :department,
                :machine,
                :title,
                :original_text,
                'draft',
                datetime('now', 'localtime'),
                datetime('now', 'localtime')
            )
        ");

        $stmt->execute([
            ':worker_id' => $workerId,
            ':report_date' => $reportDate,
            ':shift' => $shift,
            ':department' => $department,
            ':machine' => $machine,
            ':title' => $title,
            ':original_text' => $originalText
        ]);

        $id = (int) db()->lastInsertId();

        flash(
            'success',
            'Daily report saved as a draft.'
        );

        redirect('report.php?id=' . $id);
    }
}

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>New Daily Report</h1>

        <p>
            Write what happened in your own words.
            Your original entry will remain preserved.
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

    <section class="panel">

        <h2>Report Details</h2>

        <div class="form-grid">

            <div class="field">

                <label for="report_date">
                    Date *
                </label>

                <input
                    type="date"
                    id="report_date"
                    name="report_date"
                    value="<?= e(
                        $_POST['report_date']
                        ?? date('Y-m-d')
                    ) ?>"
                    required
                >

            </div>

            <div class="field">

                <label for="shift">
                    Shift
                </label>

                <input
                    type="text"
                    id="shift"
                    name="shift"
                    placeholder="Example: Day Shift"
                    value="<?= e($_POST['shift'] ?? '') ?>"
                >

            </div>

            <div class="field">

                <label for="department">
                    Department / Area
                </label>

                <input
                    type="text"
                    id="department"
                    name="department"
                    placeholder="Example: Production"
                    value="<?= e($_POST['department'] ?? '') ?>"
                >

            </div>

            <div class="field">

                <label for="machine">
                    Machine / Workstation
                </label>

                <input
                    type="text"
                    id="machine"
                    name="machine"
                    placeholder="Example: Ford Service"
                    value="<?= e($_POST['machine'] ?? '') ?>"
                >

            </div>

        </div>

        <div class="field">

            <label for="title">
                Report Title
            </label>

            <input
                type="text"
                id="title"
                name="title"
                placeholder="Optional short description"
                value="<?= e($_POST['title'] ?? '') ?>"
            >

        </div>

    </section>


    <section class="panel">

        <h2>What Happened Today?</h2>

        <p class="help-text">
            Enter the facts naturally. Include names,
            approximate times, conversations, actions,
            witnesses and anything you believe may matter.
        </p>

        <div class="field">

            <textarea
                id="original_text"
                name="original_text"
                rows="18"
                placeholder="Start writing your daily report here..."
                required
            ><?= e($_POST['original_text'] ?? '') ?></textarea>

        </div>

        <div class="word-counter">

            <span id="wordCount">0 words</span>

            <span id="charCount">0 characters</span>

        </div>

    </section>


    <div class="form-actions">

        <a
            href="index.php"
            class="button button-secondary"
        >
            Cancel
        </a>

        <button
            type="submit"
            class="button button-primary"
        >
            Save Draft
        </button>

    </div>

</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
