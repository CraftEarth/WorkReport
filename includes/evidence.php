<?php

require_once __DIR__ . '/functions.php';

function ensure_evidence_schema(): void
{
    $pdo = db();

    $columns = [];

    $result = $pdo->query("PRAGMA table_info(attachments)");

    foreach ($result->fetchAll() as $column) {
        $columns[$column['name']] = true;
    }

    $needed = [
        'description'   => 'TEXT',
        'evidence_type' => "TEXT NOT NULL DEFAULT 'Other'",
        'mime_type'     => 'TEXT',
        'file_size'     => 'INTEGER',
        'captured_at'   => 'TEXT'
    ];

    foreach ($needed as $name => $definition) {

        if (!isset($columns[$name])) {
            $pdo->exec(
                "ALTER TABLE attachments
                 ADD COLUMN {$name} {$definition}"
            );
        }
    }
}

function allowed_evidence_types(): array
{
    return [
        'Photo',
        'Screenshot',
        'Document',
        'Schedule',
        'Write-Up',
        'Message',
        'Quality Record',
        'Attendance Record',
        'Other'
    ];
}

function allowed_evidence_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',

        'application/pdf' => 'pdf',

        'text/plain' => 'txt',

        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            => 'docx'
    ];
}

function evidence_is_image(?string $mime): bool
{
    return $mime !== null
        && str_starts_with($mime, 'image/');
}

function format_file_size(?int $bytes): string
{
    $bytes = (int) $bytes;

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round(
        $bytes / (1024 * 1024),
        1
    ) . ' MB';
}