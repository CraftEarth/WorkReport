<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/historical-intake.php';

require_login();
ensure_historical_intake_schema();

$worker = require_current_worker();
$workerId = (int) $worker['id'];
$user = current_user();
$userId = $user ? (int) $user['id'] : null;

if (empty($_SESSION['historical_intake_csrf'])) {
    $_SESSION['historical_intake_csrf'] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION['historical_intake_csrf'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedCsrf = (string) (
        $_POST['csrf_token']
        ?? ''
    );

    if (
        $postedCsrf === ''
        || !hash_equals(
            $csrf,
            $postedCsrf
        )
    ) {
        http_response_code(400);
        exit('Invalid request token.');
    }

    $action = (string) (
        $_POST['action']
        ?? ''
    );

    try {

        if ($action === 'create_batch') {

            $hasText =
                trim(
                    (string) (
                        $_POST['original_text']
                        ?? ''
                    )
                ) !== '';

            $hasFiles =
                isset($_FILES['source_files']['name'])
                && is_array(
                    $_FILES['source_files']['name']
                )
                && count(
                    array_filter(
                        $_FILES['source_files']['name'],
                        static fn ($name) =>
                            trim((string) $name) !== ''
                    )
                ) > 0;

            if (!$hasText && !$hasFiles) {
                throw new RuntimeException(
                    'Paste source text or attach at least one historical source file.'
                );
            }

            $batchId =
                create_historical_intake_batch(
                    $workerId,
                    $userId,
                    $_POST
                );

            if ($hasFiles) {
                save_historical_intake_uploads(
    $batchId,
    $workerId,
    $userId,
    $_FILES['source_files'],
    STORAGE_PATH
    . DIRECTORY_SEPARATOR
    . 'historical-intake'
);            }

            flash(
                'success',
                'Historical source batch preserved and fingerprinted.'
            );

            header(
                'Location: historical-intake.php?batch_id='
                . $batchId
            );
            exit;
        }

        if ($action === 'void_batch') {

            $batchId = (int) (
                $_POST['batch_id']
                ?? 0
            );

            void_historical_intake_batch(
                $batchId,
                $workerId,
                (string) (
                    $_POST['void_reason']
                    ?? ''
                )
            );

            flash(
                'success',
                'Historical batch marked void. Original record was preserved.'
            );

            header(
                'Location: historical-intake.php?batch_id='
                . $batchId
            );
            exit;
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$batchId = (int) (
    $_GET['batch_id']
    ?? 0
);

$selectedBatch = null;
$selectedFiles = [];

if ($batchId > 0) {
    $selectedBatch =
        historical_intake_batch_for_worker(
            $batchId,
            $workerId
        );

    if (!$selectedBatch) {
        http_response_code(404);
        exit('Historical intake batch not found.');
    }

    $selectedFiles =
        historical_intake_files_for_batch(
            $batchId,
            $workerId
        );
}

$batches =
    recent_historical_intake_batches(
        $workerId,
        100
    );

$pageTitle = 'Historical Intake';

require __DIR__ . '/includes/header.php';

?>

<div class="historical-page">

    <div class="page-title-row">

        <div>

            <h1>Historical Intake</h1>

            <p>
                Preserve older messages, notes, documents,
                screenshots and evidence before analysis.
            </p>

        </div>

        <a
            href="journal.php"
            class="button button-secondary"
        >
            Daily Journal
        </a>

    </div>


    <?php if ($error): ?>

        <div class="flash flash-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <section class="historical-principle">

        <strong>
            Original first. Analysis later.
        </strong>

        <span>
            WorkReport records when the event reportedly happened,
            when the source was originally created if known,
            and when it was imported here.
        </span>

    </section>


    <div class="historical-grid">

        <section class="panel historical-import-panel">

            <div class="section-title">
                <h2>Import Historical Material</h2>
                <span>
                    Current worker:
                    <?= e($worker['full_name']) ?>
                </span>
            </div>

            <form
                method="post"
                enctype="multipart/form-data"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="create_batch"
                >

                <div class="form-grid">

                    <div class="field">

                        <label>Source Person</label>

                        <input
                            type="text"
                            name="source_person_name"
                            value="<?= e($worker['full_name']) ?>"
                            placeholder="Who originally supplied this information?"
                        >

                    </div>

                    <div class="field">

                        <label>Source Type *</label>

                        <select
                            name="source_type"
                            required
                        >
                            <option value="worker_message">
                                Worker message copied by helper
                            </option>
                            <option value="worker_statement">
                                Worker statement
                            </option>
                            <option value="historical_recollection">
                                Historical recollection
                            </option>
                            <option value="document_import">
                                Document import
                            </option>
                            <option value="mixed_source">
                                Mixed source batch
                            </option>
                        </select>

                    </div>

                    <div class="field">

                        <label>Source Channel</label>

                        <input
                            type="text"
                            name="source_channel"
                            placeholder="Text message, Messenger, email, paper note..."
                        >

                    </div>

                    <div class="field">

                        <label>Date Precision</label>

                        <select name="date_precision">
                            <option value="exact">Exact</option>
                            <option value="approximate">Approximate</option>
                            <option value="range" selected>Range</option>
                            <option value="month_only">Month only</option>
                            <option value="unknown">Unknown</option>
                        </select>

                    </div>

                    <div class="field">

                        <label>
                            Earliest Event / Source Date
                        </label>

                        <input
                            type="date"
                            name="source_date_start"
                        >

                    </div>

                    <div class="field">

                        <label>
                            Latest Event / Source Date
                        </label>

                        <input
                            type="date"
                            name="source_date_end"
                        >

                    </div>

                    <div class="field">

                        <label>
                            Original Message / Record Time
                        </label>

                        <input
                            type="text"
                            name="original_recorded_at"
                            placeholder="Example: 2026-09-14 23:36 or unknown"
                        >

                        <small class="help-text">
                            Use this when the original text, screenshot
                            or message has its own timestamp.
                        </small>

                    </div>

                    <div class="field">

                        <label>Batch Title</label>

                        <input
                            type="text"
                            name="title"
                            placeholder="Example: Tara messages - September week 2"
                        >

                    </div>

                </div>


                <div class="field">

                    <label>
                        Original Text / Messages
                    </label>

                    <textarea
                        name="original_text"
                        rows="18"
                        maxlength="1000000"
                        placeholder="Paste Tara's messages exactly as you have them. Do not rewrite them for legal language. Large batches are okay."
                    ></textarea>

                    <small class="help-text">
                        Up to 1,000,000 characters per batch.
                        WorkReport stores the text received here and
                        creates a SHA-256 fingerprint before any later analysis.
                    </small>

                </div>


                <div class="field">

                    <label>
                        Source Files
                    </label>

                    <input
                        type="file"
                        name="source_files[]"
                        multiple
                        accept="image/jpeg,image/png,image/webp,application/pdf,text/plain,text/csv,application/json"
                    >

                    <small class="help-text">
                        Up to 50 files per batch, 25 MB each.
                        JPG, PNG, WEBP, PDF, TXT, CSV and JSON.
                        Files are stored privately and fingerprinted.
                    </small>

                </div>


                <div class="field">

                    <label>
                        Provenance Notes
                    </label>

                    <textarea
                        name="notes"
                        rows="4"
                        placeholder="Example: Tara sent these messages to me after each shift. I copied them here on October 10 without rewriting."
                    ></textarea>

                </div>


                <div class="form-actions">

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Preserve Historical Batch
                    </button>

                </div>

            </form>

        </section>


        <aside class="panel historical-batch-list">

            <div class="section-title">
                <h2>Imported Batches</h2>
                <span><?= count($batches) ?></span>
            </div>

            <?php if (!$batches): ?>

                <p class="help-text">
                    No historical material imported yet.
                </p>

            <?php else: ?>

                <?php foreach ($batches as $item): ?>

                    <a
                        href="historical-intake.php?batch_id=<?= (int) $item['id'] ?>"
                        class="historical-batch-row <?= $selectedBatch && (int) $selectedBatch['id'] === (int) $item['id']
                            ? 'historical-batch-row-active'
                            : '' ?>"
                    >

                        <strong>
                            <?= e(
                                $item['title']
                                ?: 'Historical Batch #'
                                    . (int) $item['id']
                            ) ?>
                        </strong>

                        <span>
                            <?= e($item['source_type']) ?>
                        </span>

                        <span>
                            Source dates:
                            <?= e(
                                $item['source_date_start']
                                ?: 'unknown'
                            ) ?>
                            -
                            <?= e(
                                $item['source_date_end']
                                ?: $item['source_date_start']
                                ?: 'unknown'
                            ) ?>
                        </span>

                        <span>
                            Imported:
                            <?= e($item['created_at']) ?>
                        </span>

                        <span>
                            <?= (int) $item['file_count'] ?>
                            files
                            |
                            <?= e(strtoupper($item['status'])) ?>
                        </span>

                    </a>

                <?php endforeach; ?>

            <?php endif; ?>

        </aside>

    </div>


    <?php if ($selectedBatch): ?>

        <section class="panel historical-record-view">

            <div class="section-title">

                <div>
                    <h2>
                        Source Batch #<?= (int) $selectedBatch['id'] ?>
                    </h2>
                    <span>
                        Preserved source record
                    </span>
                </div>

                <span class="status">
                    <?= e(strtoupper($selectedBatch['status'])) ?>
                </span>

            </div>


            <div class="historical-meta-grid">

                <div>
                    <span>Source person</span>
                    <strong>
                        <?= e(
                            $selectedBatch['source_person_name']
                            ?: 'Not recorded'
                        ) ?>
                    </strong>
                </div>

                <div>
                    <span>Source type</span>
                    <strong>
                        <?= e($selectedBatch['source_type']) ?>
                    </strong>
                </div>

                <div>
                    <span>Channel</span>
                    <strong>
                        <?= e(
                            $selectedBatch['source_channel']
                            ?: 'Not recorded'
                        ) ?>
                    </strong>
                </div>

                <div>
                    <span>Date precision</span>
                    <strong>
                        <?= e($selectedBatch['date_precision']) ?>
                    </strong>
                </div>

                <div>
                    <span>Source range</span>
                    <strong>
                        <?= e(
                            $selectedBatch['source_date_start']
                            ?: 'Unknown'
                        ) ?>
                        -
                        <?= e(
                            $selectedBatch['source_date_end']
                            ?: $selectedBatch['source_date_start']
                            ?: 'Unknown'
                        ) ?>
                    </strong>
                </div>

                <div>
                    <span>Imported into WorkReport</span>
                    <strong>
                        <?= e($selectedBatch['created_at']) ?>
                    </strong>
                </div>

            </div>


            <?php if ($selectedBatch['original_text'] !== null): ?>

                <div class="historical-source-text">

                    <div class="historical-source-heading">
                        <strong>Original imported text</strong>
                        <span>
                            SHA-256:
                            <?= e(
                                substr(
                                    (string) $selectedBatch['original_text_sha256'],
                                    0,
                                    24
                                )
                            ) ?>...
                        </span>
                    </div>

                    <pre><?= e($selectedBatch['original_text']) ?></pre>

                </div>

            <?php endif; ?>


            <?php if ($selectedFiles): ?>

                <div class="historical-file-list">

                    <h3>Source Files</h3>

                    <?php foreach ($selectedFiles as $file): ?>

                        <div class="historical-file-row">

                            <a
                                href="historical-intake-file.php?id=<?= (int) $file['id'] ?>"
                                target="_blank"
                                rel="noopener"
                            >
                                <?= e($file['original_name']) ?>
                            </a>

                            <span>
                                <?= e($file['mime_type']) ?>
                                |
                                <?= number_format(
                                    ((int) $file['file_size']) / 1024,
                                    1
                                ) ?> KB
                            </span>

                            <small>
                                SHA-256:
                                <?= e(
                                    substr(
                                        $file['sha256'],
                                        0,
                                        24
                                    )
                                ) ?>...
                            </small>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>


            <?php if ($selectedBatch['notes']): ?>

                <div class="historical-notes">
                    <strong>Provenance notes</strong>
                    <p>
                        <?= nl2br(
                            e($selectedBatch['notes'])
                        ) ?>
                    </p>
                </div>

            <?php endif; ?>


            <?php if ($selectedBatch['status'] === 'void'): ?>

                <div class="historical-void-note">
                    <strong>
                        This batch was voided, not erased.
                    </strong>
                    <p>
                        <?= e(
                            $selectedBatch['void_reason']
                            ?: 'No reason recorded.'
                        ) ?>
                    </p>
                </div>

            <?php else: ?>

                <details class="historical-void-control">

                    <summary>
                        Imported something incorrectly?
                    </summary>

                    <form method="post">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrf) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="void_batch"
                        >

                        <input
                            type="hidden"
                            name="batch_id"
                            value="<?= (int) $selectedBatch['id'] ?>"
                        >

                        <div class="field">

                            <label>
                                Reason for voiding
                            </label>

                            <input
                                type="text"
                                name="void_reason"
                                required
                                placeholder="Wrong worker, duplicate import, wrong source range..."
                            >

                        </div>

                        <button
                            type="submit"
                            class="button button-secondary"
                            onclick="return confirm('Mark this batch void while preserving the original source record?');"
                        >
                            Mark Batch Void
                        </button>

                    </form>

                </details>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
