<?php

require_once __DIR__ . '/includes/media-evidence.php';

ensure_media_evidence_schema();

$pdo = db();

$reportId = (int) (
    $_GET['id']
    ?? $_POST['report_id']
    ?? 0
);

$report = media_require_worker_report($reportId);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    media_verify_csrf($_POST['_csrf'] ?? null);

    $evidenceType = trim((string) ($_POST['evidence_type'] ?? 'Other'));
    $evidenceClass = trim((string) ($_POST['evidence_class'] ?? 'original'));
    $description = trim((string) ($_POST['description'] ?? ''));
    $capturedAt = trim((string) ($_POST['captured_at'] ?? ''));
    $sourceNote = trim((string) ($_POST['source_note'] ?? ''));
    $sourceAttachmentId = (int) ($_POST['source_attachment_id'] ?? 0);

    if (!in_array($evidenceType, media_evidence_types(), true)) {
        $evidenceType = 'Other';
    }

    if (!in_array($evidenceClass, ['original', 'derived'], true)) {
        $evidenceClass = 'original';
    }

    $description = media_limit_text($description, 4000);
    $sourceNote = media_limit_text($sourceNote, 1000);

    if ($evidenceClass === 'original') {
        $sourceAttachmentId = 0;
    }

    if ($sourceAttachmentId > 0) {
        $sourceAttachment = media_require_worker_attachment($sourceAttachmentId);

        if (!media_attachment_belongs_to_report($sourceAttachment, $reportId)) {
            http_response_code(403);
            exit('Derived evidence source does not belong to this report.');
        }
    }

    $upload = $_FILES['evidence_file'] ?? null;

    if (!$upload) {
        $error = 'Please choose a file to upload.';
    } elseif ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = media_upload_error_message((int) $upload['error']);
    } elseif (
        (int) ($upload['size'] ?? 0) < 1
        || (int) $upload['size'] > media_effective_upload_limit()
    ) {
        $error = 'The file exceeds the current WorkReport/PHP upload limit of '
            . media_format_bytes(media_effective_upload_limit())
            . '.';
    } elseif (!is_uploaded_file($upload['tmp_name'])) {
        $error = 'The uploaded file could not be validated as an HTTP upload.';
    } else {
        $detected = media_detect_upload($upload);

        if (!$detected) {
            $error = 'Unsupported file type. WorkReport currently accepts common phone images, audio/voicemail, video, PDF, DOCX, TXT, CSV and JSON files.';
        } else {
            $storage = media_storage_dir();

            if (!is_dir($storage)) {
                mkdir($storage, 0775, true);
            }

            $storedFilename = bin2hex(random_bytes(24))
                . '.'
                . $detected['ext'];

            $destination = $storage
                . DIRECTORY_SEPARATOR
                . $storedFilename;

            if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                $error = 'The evidence file could not be stored.';
            } else {
                $hash = hash_file('sha256', $destination);

                if (!$hash) {
                    @unlink($destination);
                    $error = 'The evidence file was stored but could not be fingerprinted, so WorkReport removed the incomplete upload.';
                } else {
                    $originalName = basename(
                        str_replace('\\', '/', (string) $upload['name'])
                    );

                    $originalName = media_limit_text($originalName, 255);

                    $user = current_user();

                    $stmt = $pdo->prepare("
                        INSERT INTO attachments (
                            report_id,
                            filename,
                            stored_filename,
                            file_hash,
                            uploaded_at,
                            description,
                            evidence_type,
                            mime_type,
                            file_size,
                            captured_at,
                            evidence_class,
                            source_attachment_id,
                            source_note,
                            uploaded_by_user_id,
                            status,
                            last_verified_at,
                            last_verify_status
                        )
                        VALUES (
                            :report_id,
                            :filename,
                            :stored_filename,
                            :file_hash,
                            datetime('now', 'localtime'),
                            :description,
                            :evidence_type,
                            :mime_type,
                            :file_size,
                            :captured_at,
                            :evidence_class,
                            :source_attachment_id,
                            :source_note,
                            :uploaded_by_user_id,
                            'active',
                            datetime('now', 'localtime'),
                            'ok'
                        )
                    ");

                    $stmt->execute([
                        ':report_id' => $reportId,
                        ':filename' => $originalName,
                        ':stored_filename' => $storedFilename,
                        ':file_hash' => $hash,
                        ':description' => $description,
                        ':evidence_type' => $evidenceType,
                        ':mime_type' => $detected['mime'],
                        ':file_size' => (int) $upload['size'],
                        ':captured_at' => $capturedAt !== '' ? $capturedAt : null,
                        ':evidence_class' => $evidenceClass,
                        ':source_attachment_id' => $sourceAttachmentId > 0
                            ? $sourceAttachmentId
                            : null,
                        ':source_note' => $sourceNote !== '' ? $sourceNote : null,
                        ':uploaded_by_user_id' => $user ? (int) $user['id'] : null
                    ]);

                    $attachmentId = (int) $pdo->lastInsertId();

                    media_log_action(
                        $attachmentId,
                        $reportId,
                        'uploaded',
                        json_encode([
                            'class' => $evidenceClass,
                            'mime' => $detected['mime'],
                            'bytes' => (int) $upload['size'],
                            'sha256' => $hash
                        ], JSON_UNESCAPED_SLASHES)
                    );

                    flash(
                        'success',
                        'Evidence preserved and SHA-256 fingerprint created.'
                    );

                    redirect('evidence.php?id=' . $reportId);
                }
            }
        }
    }
}

/* WORKREPORT_VOIDED_VIEW_V3 */
$showVoided =
    isset($_GET['show_voided'])
    && $_GET['show_voided'] === '1';

$attachments = media_report_attachments(
    $reportId,
    $showVoided
);

$evidenceCounts =
    media_report_attachment_counts($reportId);

$activeEvidenceCount =
    (int) ($evidenceCounts['active'] ?? 0);

$voidedEvidenceCount =
    (int) ($evidenceCounts['voided'] ?? 0);
$sourceAttachments = media_source_attachments($reportId);

$pageTitle = 'Report Evidence';

require __DIR__ . '/includes/header.php';

$effectiveLimit = media_effective_upload_limit();
$phpUploadLimit = (string) ini_get('upload_max_filesize');
$phpPostLimit = (string) ini_get('post_max_size');

?>

<section class="page-heading">
    <div>
        <h1>Report Evidence</h1>
        <p>
            <?= e(format_date($report['report_date'])) ?>
            - Report #<?= $reportId ?>
        </p>
    </div>

    <a
        href="report.php?id=<?= $reportId ?>"
        class="button button-secondary"
    >
        Back to Report
    </a>
</section>

<?php if ($error): ?>
    <div class="flash flash-error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<section class="panel media-evidence-intro">
    <div>
        <h2>Preserve Evidence</h2>
        <p class="help-text">
            Originals are stored unchanged and fingerprinted with SHA-256.
            Edited clips, enhanced copies, redactions and transcripts should
            be uploaded as Derived evidence and linked back to the original.
        </p>
    </div>

    <div class="media-limit-box">
        <strong>Current upload ceiling</strong>
        <span><?= e(media_format_bytes($effectiveLimit)) ?></span>
        <small>
            PHP upload_max_filesize: <?= e($phpUploadLimit) ?>,
            post_max_size: <?= e($phpPostLimit) ?>
        </small>
    </div>
</section>

<section class="panel">
    <h2>Add Evidence</h2>

    <form
        method="post"
        enctype="multipart/form-data"
        class="evidence-upload-form"
    >
        <input
            type="hidden"
            name="_csrf"
            value="<?= e(media_csrf_token()) ?>"
        >

        <input
            type="hidden"
            name="report_id"
            value="<?= $reportId ?>"
        >

        <div class="form-grid">
            <div class="field">
                <label>Evidence Type</label>

                <select name="evidence_type" required>
                    <?php foreach (media_evidence_types() as $type): ?>
                        <option value="<?= e($type) ?>">
                            <?= e($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Record Type</label>

                <select name="evidence_class" id="evidenceClass" required>
                    <option value="original">Original - untouched source file</option>
                    <option value="derived">Derived - clip, transcript, crop, redaction or enhanced copy</option>
                </select>
            </div>

            <div class="field">
                <label>Date / Time Captured or Recorded</label>

                <input
                    type="datetime-local"
                    name="captured_at"
                >
            </div>

            <div class="field">
                <label>Source / Device Note</label>

                <input
                    type="text"
                    name="source_note"
                    maxlength="1000"
                    placeholder="Example: Tara's iPhone voice memo; bodycam; text screenshot"
                >
            </div>
        </div>

        <div class="field" id="sourceEvidenceField">
            <label>Derived From</label>

            <select name="source_attachment_id">
                <option value="">No linked original selected</option>

                <?php foreach ($sourceAttachments as $source): ?>
                    <option value="<?= (int) $source['id'] ?>">
                        #<?= (int) $source['id'] ?>
                        - <?= e($source['filename']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <span class="help-text">
                Use this when uploading a clip, transcript, crop,
                redaction or enhanced copy made from an original
                already preserved in WorkReport.
            </span>
        </div>

        <div class="field">
            <label>Description</label>

            <textarea
                name="description"
                rows="3"
                maxlength="4000"
                placeholder="Describe what the file shows or records. Keep conclusions separate from what can actually be seen or heard."
            ></textarea>
        </div>

        <div class="field">
            <label>File</label>

            <input
                type="file"
                name="evidence_file"
                accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.heif,.m4a,.mp3,.wav,.aac,.mp4,.mov,.webm,.3gp,.pdf,.txt,.csv,.json,.docx"
                required
            >

            <span class="help-text">
                Images, phone audio/voicemail, video and common documents are supported.
                WorkReport does not alter the uploaded original.
            </span>
        </div>

        <div class="form-actions">
            <button
                type="submit"
                class="button button-primary"
            >
                Preserve Evidence
            </button>
        </div>
    </form>
</section>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Evidence Record</h2>

            <?php if ($voidedEvidenceCount > 0): ?>
                <p class="help-text">
                    <?= $voidedEvidenceCount ?>
                    voided record<?= $voidedEvidenceCount === 1 ? '' : 's' ?>
                    preserved in audit history.
                    <?php if ($showVoided): ?>
                        <a href="evidence.php?id=<?= $reportId ?>">Hide Voided Records</a>
                    <?php else: ?>
                        <a href="evidence.php?id=<?= $reportId ?>&amp;show_voided=1">Show Voided Records</a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <p>
                <?= $activeEvidenceCount ?>
                record<?= $activeEvidenceCount === 1 ? '' : 's' ?>
                linked to this report.
            </p>
        </div>
    </div>

    <?php if (!$attachments): ?>
        <div class="empty-state">
            <h3>No evidence attached</h3>
            <p>
                Photos, recordings, videos and documents preserved
                for this report will appear here.
            </p>
        </div>
    <?php else: ?>
        <div class="evidence-grid media-evidence-grid">

        <?php foreach ($attachments as $item): ?>
            <?php
                $kind = media_kind_from_mime((string) $item['mime_type']);
                $isVoided = ($item['status'] ?? 'active') !== 'active';
            ?>

            <article class="evidence-card <?= $isVoided ? 'evidence-card-voided' : '' ?>">
                <div class="media-preview">
                    <?php if (media_is_browser_image((string) $item['mime_type'])): ?>

                        <a
                            href="evidence-file.php?id=<?= (int) $item['id'] ?>"
                            target="_blank"
                            rel="noopener"
                            class="evidence-preview"
                        >
                            <img
                                src="evidence-file.php?id=<?= (int) $item['id'] ?>"
                                alt="<?= e($item['description'] ?: $item['filename']) ?>"
                                loading="lazy"
                            >
                        </a>

                    <?php elseif ($kind === 'audio'): ?>

                        <div class="media-player-block">
                            <strong>AUDIO</strong>
                            <audio controls preload="metadata">
                                <source
                                    src="evidence-file.php?id=<?= (int) $item['id'] ?>"
                                    type="<?= e($item['mime_type']) ?>"
                                >
                            </audio>
                        </div>

                    <?php elseif ($kind === 'video'): ?>

                        <div class="media-player-block">
                            <strong>VIDEO</strong>
                            <video controls preload="metadata">
                                <source
                                    src="evidence-file.php?id=<?= (int) $item['id'] ?>"
                                    type="<?= e($item['mime_type']) ?>"
                                >
                            </video>
                        </div>

                    <?php else: ?>

                        <a
                            href="evidence-file.php?id=<?= (int) $item['id'] ?>"
                            target="_blank"
                            rel="noopener"
                            class="evidence-document-preview"
                        >
                            <span class="document-icon">FILE</span>
                            <span>Open Evidence</span>
                        </a>

                    <?php endif; ?>
                </div>

                <div class="evidence-card-body">
                    <div class="evidence-card-top">
                        <span class="tag">
                            <?= e($item['evidence_type']) ?>
                        </span>

                        <span class="tag media-class-tag">
                            <?= e(ucfirst($item['evidence_class'] ?? 'original')) ?>
                        </span>

                        <?php if ($isVoided): ?>
                            <span class="tag media-void-tag">VOIDED</span>
                        <?php endif; ?>

                        <span class="evidence-size">
                            <?= e(media_format_bytes((int) $item['file_size'])) ?>
                        </span>
                    </div>

                    <h3><?= e($item['filename']) ?></h3>

                    <?php if ($item['description']): ?>
                        <p><?= nl2br(e($item['description'])) ?></p>
                    <?php endif; ?>

                    <dl class="evidence-meta">
                        <?php if ($item['captured_at']): ?>
                            <div>
                                <dt>Captured / Recorded</dt>
                                <dd><?= e(format_datetime($item['captured_at'])) ?></dd>
                            </div>
                        <?php endif; ?>

                        <div>
                            <dt>Added to WorkReport</dt>
                            <dd><?= e(format_datetime($item['uploaded_at'])) ?></dd>
                        </div>

                        <div>
                            <dt>MIME Type</dt>
                            <dd><?= e($item['mime_type']) ?></dd>
                        </div>

                        <?php if (!empty($item['source_note'])): ?>
                            <div>
                                <dt>Source / Device</dt>
                                <dd><?= e($item['source_note']) ?></dd>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($item['source_attachment_id'])): ?>
                            <div>
                                <dt>Derived From</dt>
                                <dd>
                                    Evidence #<?= (int) $item['source_attachment_id'] ?>
                                    <?php if (!empty($item['source_filename'])): ?>
                                        - <?= e($item['source_filename']) ?>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        <?php endif; ?>

                        <div>
                            <dt>Integrity</dt>
                            <dd>
                                <?php if (($item['last_verify_status'] ?? '') === 'ok'): ?>
                                    Verified
                                <?php elseif (($item['last_verify_status'] ?? '') === 'mismatch'): ?>
                                    HASH MISMATCH
                                <?php elseif (($item['last_verify_status'] ?? '') === 'missing'): ?>
                                    FILE MISSING
                                <?php else: ?>
                                    Not re-verified
                                <?php endif; ?>

                                <?php if (!empty($item['last_verified_at'])): ?>
                                    - <?= e(format_datetime($item['last_verified_at'])) ?>
                                <?php endif; ?>
                            </dd>
                        </div>

                        <div>
                            <dt>SHA-256</dt>
                            <dd>
                                <code class="media-hash">
                                    <?= e($item['file_hash']) ?>
                                </code>
                            </dd>
                        </div>

                        <?php if ($isVoided): ?>
                            <div>
                                <dt>Void Status</dt>
                                <dd>
                                    Preserved for audit.
                                    <?php if (!empty($item['void_reason'])): ?>
                                        <?= e($item['void_reason']) ?>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        <?php endif; ?>
                    </dl>

                    <div class="media-evidence-actions">
                        <a
                            href="evidence-file.php?id=<?= (int) $item['id'] ?>"
                            target="_blank"
                            rel="noopener"
                            class="button button-secondary"
                        >
                            Open
                        </a>

                        <form
                            method="post"
                            action="verify-evidence.php"
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
                                class="button button-secondary"
                            >
                                Verify SHA-256
                            </button>
                        </form>

                        <?php if (!$isVoided): ?>
                            <form
                                method="post"
                                action="delete-evidence.php"
                                onsubmit="return confirm('Void this evidence record? The original file will remain preserved for audit.');"
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
                                <input
                                    type="hidden"
                                    name="void_reason"
                                    value="Voided by authorized user from report evidence page."
                                >

                                <button
                                    type="submit"
                                    class="button button-danger"
                                >
                                    Void Evidence
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>

        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    const classSelect = document.getElementById('evidenceClass');
    const sourceField = document.getElementById('sourceEvidenceField');

    if (!classSelect || !sourceField) {
        return;
    }

    function updateSourceField() {
        sourceField.style.display =
            classSelect.value === 'derived'
                ? ''
                : 'none';
    }

    classSelect.addEventListener('change', updateSourceField);
    updateSourceField();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>