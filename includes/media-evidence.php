<?php

require_once __DIR__ . '/evidence.php';
require_once __DIR__ . '/auth.php';

function ensure_media_evidence_schema(): void
{
    ensure_evidence_schema();

    $pdo = db();

    $columns = [];
    $result = $pdo->query("PRAGMA table_info(attachments)");

    foreach ($result->fetchAll() as $column) {
        $columns[$column['name']] = true;
    }

    $additions = [
        'evidence_class' => "ALTER TABLE attachments ADD COLUMN evidence_class TEXT NOT NULL DEFAULT 'original'",
        'source_attachment_id' => "ALTER TABLE attachments ADD COLUMN source_attachment_id INTEGER",
        'source_note' => "ALTER TABLE attachments ADD COLUMN source_note TEXT",
        'uploaded_by_user_id' => "ALTER TABLE attachments ADD COLUMN uploaded_by_user_id INTEGER",
        'status' => "ALTER TABLE attachments ADD COLUMN status TEXT NOT NULL DEFAULT 'active'",
        'voided_at' => "ALTER TABLE attachments ADD COLUMN voided_at TEXT",
        'voided_by_user_id' => "ALTER TABLE attachments ADD COLUMN voided_by_user_id INTEGER",
        'void_reason' => "ALTER TABLE attachments ADD COLUMN void_reason TEXT",
        'last_verified_at' => "ALTER TABLE attachments ADD COLUMN last_verified_at TEXT",
        'last_verify_status' => "ALTER TABLE attachments ADD COLUMN last_verify_status TEXT"
    ];

    foreach ($additions as $name => $sql) {
        if (!isset($columns[$name])) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_attachments_report_status
        ON attachments(report_id, status);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_attachments_source
        ON attachments(source_attachment_id);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS evidence_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attachment_id INTEGER,
            report_id INTEGER NOT NULL,
            user_id INTEGER,
            action TEXT NOT NULL,
            details TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_evidence_audit_attachment
        ON evidence_audit_log(attachment_id, created_at);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_evidence_audit_report
        ON evidence_audit_log(report_id, created_at);
    ");
}

function media_require_worker_report(int $reportId): array
{
    if (!function_exists('get_report_for_current_worker')) {
        http_response_code(500);
        exit('Worker-scoped report access is required before evidence can be opened.');
    }

    $report = get_report_for_current_worker($reportId);

    if (!$report) {
        http_response_code(404);
        exit('Report not found.');
    }

    return $report;
}

function media_require_worker_attachment(int $attachmentId): array
{
    if (!function_exists('get_attachment_for_current_worker')) {
        http_response_code(500);
        exit('Worker-scoped evidence access is required before evidence can be opened.');
    }

    $attachment = get_attachment_for_current_worker($attachmentId);

    if (!$attachment) {
        http_response_code(404);
        exit('Evidence not found.');
    }

    return $attachment;
}

function media_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['media_evidence_csrf'])) {
        $_SESSION['media_evidence_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['media_evidence_csrf'];
}

function media_verify_csrf(?string $token): void
{
    $expected = media_csrf_token();

    if (
        !$token
        || !hash_equals($expected, $token)
    ) {
        http_response_code(403);
        exit('Invalid request token. Please return to WorkReport and try again.');
    }
}

function media_evidence_types(): array
{
    return [
        'Photo',
        'Screenshot',
        'Audio',
        'Voicemail',
        'Video',
        'Document',
        'Message / Chat',
        'Quality Record',
        'Attendance / FMLA',
        'Schedule',
        'Write-up',
        'Other'
    ];
}

function media_allowed_extensions(): array
{
    return [
        'jpg'  => ['mime' => 'image/jpeg', 'kind' => 'image'],
        'jpeg' => ['mime' => 'image/jpeg', 'kind' => 'image'],
        'png'  => ['mime' => 'image/png', 'kind' => 'image'],
        'webp' => ['mime' => 'image/webp', 'kind' => 'image'],
        'gif'  => ['mime' => 'image/gif', 'kind' => 'image'],
        'heic' => ['mime' => 'image/heic', 'kind' => 'image'],
        'heif' => ['mime' => 'image/heif', 'kind' => 'image'],

        'm4a'  => ['mime' => 'audio/mp4', 'kind' => 'audio'],
        'mp3'  => ['mime' => 'audio/mpeg', 'kind' => 'audio'],
        'wav'  => ['mime' => 'audio/wav', 'kind' => 'audio'],
        'aac'  => ['mime' => 'audio/aac', 'kind' => 'audio'],

        'mp4'  => ['mime' => 'video/mp4', 'kind' => 'video'],
        'mov'  => ['mime' => 'video/quicktime', 'kind' => 'video'],
        'webm' => ['mime' => 'video/webm', 'kind' => 'video'],
        '3gp'  => ['mime' => 'video/3gpp', 'kind' => 'video'],

        'pdf'  => ['mime' => 'application/pdf', 'kind' => 'document'],
        'txt'  => ['mime' => 'text/plain', 'kind' => 'document'],
        'csv'  => ['mime' => 'text/csv', 'kind' => 'document'],
        'json' => ['mime' => 'application/json', 'kind' => 'document'],
        'docx' => ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'kind' => 'document']
    ];
}

function media_allowed_mimes(): array
{
    return [
        'image/jpeg' => ['ext' => 'jpg', 'kind' => 'image'],
        'image/png' => ['ext' => 'png', 'kind' => 'image'],
        'image/webp' => ['ext' => 'webp', 'kind' => 'image'],
        'image/gif' => ['ext' => 'gif', 'kind' => 'image'],
        'image/heic' => ['ext' => 'heic', 'kind' => 'image'],
        'image/heif' => ['ext' => 'heif', 'kind' => 'image'],
        'image/heic-sequence' => ['ext' => 'heic', 'kind' => 'image'],
        'image/heif-sequence' => ['ext' => 'heif', 'kind' => 'image'],

        'audio/mp4' => ['ext' => 'm4a', 'kind' => 'audio'],
        'audio/x-m4a' => ['ext' => 'm4a', 'kind' => 'audio'],
        'audio/m4a' => ['ext' => 'm4a', 'kind' => 'audio'],
        'audio/mpeg' => ['ext' => 'mp3', 'kind' => 'audio'],
        'audio/mp3' => ['ext' => 'mp3', 'kind' => 'audio'],
        'audio/wav' => ['ext' => 'wav', 'kind' => 'audio'],
        'audio/x-wav' => ['ext' => 'wav', 'kind' => 'audio'],
        'audio/wave' => ['ext' => 'wav', 'kind' => 'audio'],
        'audio/aac' => ['ext' => 'aac', 'kind' => 'audio'],

        'video/mp4' => ['ext' => 'mp4', 'kind' => 'video'],
        'video/quicktime' => ['ext' => 'mov', 'kind' => 'video'],
        'video/webm' => ['ext' => 'webm', 'kind' => 'video'],
        'video/3gpp' => ['ext' => '3gp', 'kind' => 'video'],

        'application/pdf' => ['ext' => 'pdf', 'kind' => 'document'],
        'text/plain' => ['ext' => 'txt', 'kind' => 'document'],
        'text/csv' => ['ext' => 'csv', 'kind' => 'document'],
        'application/csv' => ['ext' => 'csv', 'kind' => 'document'],
        'application/json' => ['ext' => 'json', 'kind' => 'document'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['ext' => 'docx', 'kind' => 'document']
    ];
}

function media_detect_upload(array $file): ?array
{
    if (
        empty($file['tmp_name'])
        || !is_file($file['tmp_name'])
    ) {
        return null;
    }

    $original = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'evidence')));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string) $finfo->file($file['tmp_name']);

    $byMime = media_allowed_mimes();

    if (isset($byMime[$detectedMime])) {
        $result = $byMime[$detectedMime];

        /*
         * Preserve useful original extensions when one MIME may map to
         * more than one container. The stored file remains non-executable.
         */
        $byExt = media_allowed_extensions();

        if (
            isset($byExt[$ext])
            && $byExt[$ext]['kind'] === $result['kind']
        ) {
            $result['ext'] = $ext;
        }

        $result['mime'] = $detectedMime;
        return $result;
    }

    /*
     * Some phone media and DOCX files are reported as generic binary/ZIP.
     * Extension fallback is intentionally restricted to the explicit
     * allowlist. Files are stored outside normal web access and served
     * through an authenticated endpoint with nosniff.
     */
    if (
        in_array(
            $detectedMime,
            [
                'application/octet-stream',
                'application/zip',
                'application/x-zip-compressed'
            ],
            true
        )
    ) {
        $byExt = media_allowed_extensions();

        if (isset($byExt[$ext])) {
            return [
                'ext' => $ext,
                'kind' => $byExt[$ext]['kind'],
                'mime' => $byExt[$ext]['mime']
            ];
        }
    }

    return null;
}

function media_kind_from_mime(string $mime): string
{
    if (str_starts_with($mime, 'image/')) {
        return 'image';
    }

    if (str_starts_with($mime, 'audio/')) {
        return 'audio';
    }

    if (str_starts_with($mime, 'video/')) {
        return 'video';
    }

    return 'document';
}

function media_is_browser_image(string $mime): bool
{
    return in_array(
        $mime,
        [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif'
        ],
        true
    );
}

function media_storage_dir(): string
{
    return STORAGE_PATH . DIRECTORY_SEPARATOR . 'attachments';
}

function media_storage_path(array $attachment): string
{
    return media_storage_dir()
        . DIRECTORY_SEPARATOR
        . basename((string) $attachment['stored_filename']);
}

function media_limit_text(string $value, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    return substr($value, 0, $max);
}

function media_ini_bytes(string $value): int
{
    $value = trim($value);

    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }

    $last = strtolower(substr($value, -1));
    $number = (float) $value;

    switch ($last) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
            break;
    }

    return (int) min($number, PHP_INT_MAX);
}

function media_application_max_bytes(): int
{
    return 2 * 1024 * 1024 * 1024;
}

function media_effective_upload_limit(): int
{
    return min(
        media_application_max_bytes(),
        media_ini_bytes((string) ini_get('upload_max_filesize')),
        media_ini_bytes((string) ini_get('post_max_size'))
    );
}

function media_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;

    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $unit;
        }

        $value /= 1024;
    }

    return $bytes . ' B';
}

function media_upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'The file is larger than the current PHP upload limit. Increase upload_max_filesize and post_max_size before uploading large recordings.';

        case UPLOAD_ERR_PARTIAL:
            return 'The upload was interrupted before the file finished transferring.';

        case UPLOAD_ERR_NO_FILE:
            return 'Please choose a file to upload.';

        case UPLOAD_ERR_NO_TMP_DIR:
            return 'PHP does not have a temporary upload directory available.';

        case UPLOAD_ERR_CANT_WRITE:
            return 'PHP could not write the uploaded file to temporary storage.';

        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the upload.';

        default:
            return 'The evidence upload failed.';
    }
}

function media_log_action(
    ?int $attachmentId,
    int $reportId,
    string $action,
    ?string $details = null
): void {
    $user = current_user();

    $stmt = db()->prepare("
        INSERT INTO evidence_audit_log (
            attachment_id,
            report_id,
            user_id,
            action,
            details,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, datetime('now', 'localtime'))
    ");

    $stmt->execute([
        $attachmentId,
        $reportId,
        $user ? (int) $user['id'] : null,
        $action,
        $details
    ]);
}

/* WORKREPORT_VOIDED_VISIBILITY_V3 */
function media_report_attachments(
    int $reportId,
    bool $includeVoided = false
): array {
    $sql = "
        SELECT a.*,
               parent.filename AS source_filename
        FROM attachments a
        LEFT JOIN attachments parent
            ON parent.id = a.source_attachment_id
        WHERE a.report_id = ?
    ";

    if (!$includeVoided) {
        $sql .= " AND a.status = 'active' ";
    }

    $sql .= "
        ORDER BY
            CASE WHEN a.status = 'active' THEN 0 ELSE 1 END,
            a.uploaded_at DESC,
            a.id DESC
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute([$reportId]);

    return $stmt->fetchAll();
}

function media_report_attachment_counts(int $reportId): array
{
    $stmt = db()->prepare("
        SELECT
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS voided_count
        FROM attachments
        WHERE report_id = ?
    ");

    $stmt->execute([$reportId]);
    $row = $stmt->fetch() ?: [];

    return [
        'active' => (int) ($row['active_count'] ?? 0),
        'voided' => (int) ($row['voided_count'] ?? 0)
    ];
}

function media_source_attachments(int $reportId): array
{
    $stmt = db()->prepare("
        SELECT *
        FROM attachments
        WHERE report_id = ?
          AND status = 'active'
          AND evidence_class = 'original'
        ORDER BY uploaded_at DESC, id DESC
    ");

    $stmt->execute([$reportId]);

    return $stmt->fetchAll();
}

function media_attachment_belongs_to_report(array $attachment, int $reportId): bool
{
    return (int) $attachment['report_id'] === $reportId;
}

function media_verify_file(array $attachment): array
{
    $path = media_storage_path($attachment);

    if (!is_file($path)) {
        return [
            'status' => 'missing',
            'actual_hash' => null
        ];
    }

    $actual = hash_file('sha256', $path);
    $expected = strtolower((string) ($attachment['file_hash'] ?? ''));

    if (
        $actual
        && $expected !== ''
        && hash_equals($expected, strtolower($actual))
    ) {
        return [
            'status' => 'ok',
            'actual_hash' => $actual
        ];
    }

    return [
        'status' => 'mismatch',
        'actual_hash' => $actual ?: null
    ];
}

function media_content_disposition_filename(string $filename): array
{
    $filename = str_replace(["\r", "\n", '"'], '', $filename);

    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
    $fallback = trim((string) $fallback, '._');

    if ($fallback === '') {
        $fallback = 'evidence';
    }

    return [
        'fallback' => $fallback,
        'encoded' => rawurlencode($filename)
    ];
}

function media_stream_file(array $attachment): never
{
    $path = media_storage_path($attachment);

    if (!is_file($path)) {
        http_response_code(404);
        exit('Evidence file is missing.');
    }

    $size = filesize($path);

    if ($size === false) {
        http_response_code(500);
        exit('Evidence file size could not be determined.');
    }

    $mime = trim((string) ($attachment['mime_type'] ?? ''));

    if ($mime === '') {
        $mime = 'application/octet-stream';
    }

    $kind = media_kind_from_mime($mime);

    $inline = in_array(
        $kind,
        ['image', 'audio', 'video'],
        true
    ) || in_array(
        $mime,
        ['application/pdf', 'text/plain'],
        true
    );

    $names = media_content_disposition_filename(
        (string) ($attachment['filename'] ?? 'evidence')
    );

    $start = 0;
    $end = max(0, $size - 1);
    $status = 200;

    $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));

    if ($range !== '') {
        if (
            str_contains($range, ',')
            || !preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)
        ) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }

        $rawStart = $matches[1];
        $rawEnd = $matches[2];

        if ($rawStart === '' && $rawEnd === '') {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }

        if ($rawStart === '') {
            $suffixLength = (int) $rawEnd;

            if ($suffixLength <= 0) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }

            $start = max(0, $size - $suffixLength);
            $end = max(0, $size - 1);
        } else {
            $start = (int) $rawStart;

            if ($rawEnd !== '') {
                $end = min((int) $rawEnd, max(0, $size - 1));
            }
        }

        if (
            $size === 0
            || $start < 0
            || $start >= $size
            || $end < $start
        ) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }

        $status = 206;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    set_time_limit(0);

    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mime);
    header(
        'Content-Disposition: '
        . ($inline ? 'inline' : 'attachment')
        . '; filename="' . $names['fallback'] . '"'
        . "; filename*=UTF-8''" . $names['encoded']
    );

    if (!empty($attachment['file_hash'])) {
        header('ETag: "' . $attachment['file_hash'] . '"');
    }

    if ($status === 206) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $length = $size === 0 ? 0 : ($end - $start + 1);
    header('Content-Length: ' . $length);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }

    $handle = fopen($path, 'rb');

    if (!$handle) {
        http_response_code(500);
        exit('Evidence file could not be opened.');
    }

    if ($start > 0) {
        fseek($handle, $start);
    }

    $remaining = $length;
    $chunkSize = 1024 * 1024;

    while (
        !feof($handle)
        && $remaining > 0
        && connection_status() === CONNECTION_NORMAL
    ) {
        $readLength = min($chunkSize, $remaining);
        $buffer = fread($handle, $readLength);

        if ($buffer === false || $buffer === '') {
            break;
        }

        echo $buffer;
        flush();

        $remaining -= strlen($buffer);
    }

    fclose($handle);
    exit;
}