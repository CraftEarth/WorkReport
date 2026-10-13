<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function historical_column_exists(
    string $table,
    string $column
): bool {
    $rows = db()->query(
        "PRAGMA table_info(" . $table . ")"
    )->fetchAll();

    foreach ($rows as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function ensure_historical_intake_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS historical_intake_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            imported_by_user_id INTEGER,
            source_person_name TEXT,
            source_type TEXT NOT NULL,
            source_channel TEXT,
            source_date_start TEXT,
            source_date_end TEXT,
            original_recorded_at TEXT,
            date_precision TEXT NOT NULL DEFAULT 'unknown',
            title TEXT,
            original_text TEXT,
            original_text_sha256 TEXT,
            notes TEXT,
            status TEXT NOT NULL DEFAULT 'active',
            void_reason TEXT,
            voided_at TEXT,
            created_at TEXT NOT NULL,
            created_at_unix INTEGER NOT NULL
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_historical_batches_worker
        ON historical_intake_batches(
            worker_id,
            created_at,
            id
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_historical_batches_dates
        ON historical_intake_batches(
            worker_id,
            source_date_start,
            source_date_end
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS historical_intake_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,
            uploaded_by_user_id INTEGER,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            file_size INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            description TEXT,
            created_at TEXT NOT NULL
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_historical_files_batch
        ON historical_intake_files(batch_id, id);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_historical_files_worker
        ON historical_intake_files(worker_id, id);
    ");
}

function historical_intake_batch_for_worker(
    int $batchId,
    int $workerId
): ?array {
    $stmt = db()->prepare("
        SELECT *
        FROM historical_intake_batches
        WHERE id = ?
          AND worker_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $batchId,
        $workerId
    ]);

    $row = $stmt->fetch();

    return $row ?: null;
}

function historical_intake_files_for_batch(
    int $batchId,
    int $workerId
): array {
    $stmt = db()->prepare("
        SELECT *
        FROM historical_intake_files
        WHERE batch_id = ?
          AND worker_id = ?
        ORDER BY id ASC
    ");

    $stmt->execute([
        $batchId,
        $workerId
    ]);

    return $stmt->fetchAll();
}

function recent_historical_intake_batches(
    int $workerId,
    int $limit = 50
): array {
    $limit = max(1, min($limit, 200));

    $stmt = db()->prepare("
        SELECT
            b.*,
            (
                SELECT COUNT(*)
                FROM historical_intake_files f
                WHERE f.batch_id = b.id
            ) AS file_count
        FROM historical_intake_batches b
        WHERE b.worker_id = ?
        ORDER BY b.id DESC
        LIMIT {$limit}
    ");

    $stmt->execute([$workerId]);

    return $stmt->fetchAll();
}

function create_historical_intake_batch(
    int $workerId,
    ?int $userId,
    array $data
): int {
    $sourceType = trim(
        (string) ($data['source_type'] ?? '')
    );

    $allowedSourceTypes = [
        'worker_message',
        'worker_statement',
        'historical_recollection',
        'document_import',
        'mixed_source'
    ];

    if (!in_array(
        $sourceType,
        $allowedSourceTypes,
        true
    )) {
        throw new InvalidArgumentException(
            'Choose a valid source type.'
        );
    }

    $datePrecision = trim(
        (string) ($data['date_precision'] ?? 'unknown')
    );

    $allowedPrecision = [
        'exact',
        'approximate',
        'range',
        'month_only',
        'unknown'
    ];

    if (!in_array(
        $datePrecision,
        $allowedPrecision,
        true
    )) {
        $datePrecision = 'unknown';
    }

    $originalText =
        (string) ($data['original_text'] ?? '');

    if (mb_strlen($originalText) > 1000000) {
        throw new InvalidArgumentException(
            'This text batch is too large. Keep each batch under 1,000,000 characters.'
        );
    }

    $sourceDateStart = trim(
        (string) ($data['source_date_start'] ?? '')
    );

    $sourceDateEnd = trim(
        (string) ($data['source_date_end'] ?? '')
    );

    foreach (
        [
            'source_date_start' => $sourceDateStart,
            'source_date_end' => $sourceDateEnd
        ] as $label => $value
    ) {
        if ($value === '') {
            continue;
        }

        $date = DateTimeImmutable::createFromFormat(
            'Y-m-d',
            $value
        );

        if (
            !$date
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException(
                'Invalid historical source date.'
            );
        }
    }

    if (
        $sourceDateStart !== ''
        && $sourceDateEnd !== ''
        && $sourceDateEnd < $sourceDateStart
    ) {
        throw new InvalidArgumentException(
            'End date cannot be before start date.'
        );
    }

    $originalRecordedAt = trim(
        (string) ($data['original_recorded_at'] ?? '')
    );

    $textHash = $originalText !== ''
        ? hash('sha256', $originalText)
        : null;

    $createdAt = date('Y-m-d H:i:s');
    $createdAtUnix = time();

    $stmt = db()->prepare("
        INSERT INTO historical_intake_batches (
            worker_id,
            imported_by_user_id,
            source_person_name,
            source_type,
            source_channel,
            source_date_start,
            source_date_end,
            original_recorded_at,
            date_precision,
            title,
            original_text,
            original_text_sha256,
            notes,
            status,
            created_at,
            created_at_unix
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
    ");

    $stmt->execute([
        $workerId,
        $userId,
        null_if_blank($data['source_person_name'] ?? null),
        $sourceType,
        null_if_blank($data['source_channel'] ?? null),
        $sourceDateStart !== '' ? $sourceDateStart : null,
        $sourceDateEnd !== '' ? $sourceDateEnd : null,
        $originalRecordedAt !== ''
            ? $originalRecordedAt
            : null,
        $datePrecision,
        null_if_blank($data['title'] ?? null),
        $originalText !== '' ? $originalText : null,
        $textHash,
        null_if_blank($data['notes'] ?? null),
        $createdAt,
        $createdAtUnix
    ]);

    return (int) db()->lastInsertId();
}

function null_if_blank(mixed $value): ?string
{
    $value = trim((string) $value);

    return $value === '' ? null : $value;
}

function void_historical_intake_batch(
    int $batchId,
    int $workerId,
    string $reason
): void {
    $reason = trim($reason);

    if ($reason === '') {
        throw new InvalidArgumentException(
            'A reason is required to void an import.'
        );
    }

    $batch = historical_intake_batch_for_worker(
        $batchId,
        $workerId
    );

    if (!$batch) {
        throw new RuntimeException(
            'Historical batch not found.'
        );
    }

    if ($batch['status'] === 'void') {
        return;
    }

    $stmt = db()->prepare("
        UPDATE historical_intake_batches
        SET
            status = 'void',
            void_reason = ?,
            voided_at = ?
        WHERE id = ?
          AND worker_id = ?
    ");

    $stmt->execute([
        $reason,
        date('Y-m-d H:i:s'),
        $batchId,
        $workerId
    ]);
}

function historical_intake_allowed_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/json' => 'json'
    ];
}

function save_historical_intake_uploads(
    int $batchId,
    int $workerId,
    ?int $userId,
    array $files,
    string $storageRoot
): int {
    if (
        !isset($files['name'])
        || !is_array($files['name'])
    ) {
        return 0;
    }

    if (
        !is_dir($storageRoot)
        && !mkdir($storageRoot, 0700, true)
        && !is_dir($storageRoot)
    ) {
        throw new RuntimeException(
            'Unable to create historical evidence storage.'
        );
    }

    $allowed = historical_intake_allowed_mimes();
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $saved = 0;
    $count = count($files['name']);

    if ($count > 50) {
        throw new RuntimeException(
            'Upload no more than 50 files in one batch.'
        );
    }

    for ($i = 0; $i < $count; $i++) {
        $error = (int) (
            $files['error'][$i]
            ?? UPLOAD_ERR_NO_FILE
        );

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                'One of the historical files failed to upload.'
            );
        }

        $size = (int) (
            $files['size'][$i]
            ?? 0
        );

        if ($size < 1) {
            throw new RuntimeException(
                'One of the historical files is empty.'
            );
        }

        if ($size > 25 * 1024 * 1024) {
            throw new RuntimeException(
                'Each historical file must be 25 MB or smaller.'
            );
        }

        $tmpName =
            (string) $files['tmp_name'][$i];

        $mime = $finfo->file($tmpName);

        if (!isset($allowed[$mime])) {
            throw new RuntimeException(
                'Allowed historical files: JPG, PNG, WEBP, PDF, TXT, CSV, JSON.'
            );
        }

        $storedName =
            bin2hex(random_bytes(24))
            . '.'
            . $allowed[$mime];

        $destination =
            rtrim(
                $storageRoot,
                DIRECTORY_SEPARATOR
            )
            . DIRECTORY_SEPARATOR
            . $storedName;

        if (!move_uploaded_file(
            $tmpName,
            $destination
        )) {
            throw new RuntimeException(
                'Unable to save one of the historical files.'
            );
        }

        $sha256 = hash_file(
            'sha256',
            $destination
        );

        $originalName = basename(
            (string) $files['name'][$i]
        );

        $stmt = db()->prepare("
            INSERT INTO historical_intake_files (
                batch_id,
                worker_id,
                uploaded_by_user_id,
                original_name,
                stored_name,
                mime_type,
                file_size,
                sha256,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $batchId,
            $workerId,
            $userId,
            $originalName,
            $storedName,
            $mime,
            filesize($destination),
            $sha256,
            date('Y-m-d H:i:s')
        ]);

        $saved++;
    }

    return $saved;
}
