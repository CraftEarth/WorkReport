<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function ensure_work_journal_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_journal_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            created_by_user_id INTEGER,
            journal_date TEXT NOT NULL,
            title TEXT,
            status TEXT NOT NULL DEFAULT 'open',
            started_at TEXT NOT NULL,
            closed_at TEXT,
            final_chain_hash TEXT,
            organization_id INTEGER,
            employment_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_work_journal_sessions_worker
        ON work_journal_sessions(worker_id, journal_date, id);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_journal_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            user_id INTEGER,
            sequence_number INTEGER NOT NULL,
            speaker TEXT NOT NULL,
            message_text TEXT NOT NULL,
            created_at TEXT NOT NULL,
            content_hash TEXT NOT NULL,
            previous_chain_hash TEXT,
            chain_hash TEXT NOT NULL,
            created_at_unix INTEGER NOT NULL,
            UNIQUE(session_id, sequence_number)
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_work_journal_messages_session
        ON work_journal_messages(session_id, sequence_number);
    ");
}

function journal_session_for_worker(
    int $sessionId,
    int $workerId
): ?array {
    $stmt = db()->prepare("
        SELECT *
        FROM work_journal_sessions
        WHERE id = ?
          AND worker_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $sessionId,
        $workerId
    ]);

    $row = $stmt->fetch();

    return $row ?: null;
}

function open_journal_session_for_date(
    int $workerId,
    string $journalDate
): ?array {
    $stmt = db()->prepare("
        SELECT *
        FROM work_journal_sessions
        WHERE worker_id = ?
          AND journal_date = ?
          AND status = 'open'
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $workerId,
        $journalDate
    ]);

    $row = $stmt->fetch();

    return $row ?: null;
}


function recent_journal_worker_messages(
    int $workerId,
    int $limit = 3
): array {
    $limit = max(1, min($limit, 20));

    $stmt = db()->prepare("
        SELECT
            m.id,
            m.session_id,
            m.sequence_number,
            m.message_text,
            m.created_at,
            m.created_at_unix,
            s.journal_date
        FROM work_journal_messages m
        INNER JOIN work_journal_sessions s
            ON s.id = m.session_id
        WHERE s.worker_id = ?
          AND m.speaker = 'worker'
        ORDER BY
            m.created_at_unix DESC,
            m.id DESC
        LIMIT {$limit}
    ");

    $stmt->execute([$workerId]);

    return $stmt->fetchAll();
}


function latest_open_journal_session(
    int $workerId
): ?array {
    $stmt = db()->prepare("
        SELECT *
        FROM work_journal_sessions
        WHERE worker_id = ?
          AND status = 'open'
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([$workerId]);

    $row = $stmt->fetch();

    return $row ?: null;
}

function journal_messages(int $sessionId): array
{
    $stmt = db()->prepare("
        SELECT *
        FROM work_journal_messages
        WHERE session_id = ?
        ORDER BY sequence_number ASC
    ");

    $stmt->execute([$sessionId]);

    return $stmt->fetchAll();
}

function journal_last_chain_hash(int $sessionId): string
{
    $stmt = db()->prepare("
        SELECT chain_hash
        FROM work_journal_messages
        WHERE session_id = ?
        ORDER BY sequence_number DESC
        LIMIT 1
    ");

    $stmt->execute([$sessionId]);

    return (string) ($stmt->fetchColumn() ?: '');
}

function journal_next_sequence(int $sessionId): int
{
    $stmt = db()->prepare("
        SELECT COALESCE(MAX(sequence_number), 0)
        FROM work_journal_messages
        WHERE session_id = ?
    ");

    $stmt->execute([$sessionId]);

    return ((int) $stmt->fetchColumn()) + 1;
}

function add_journal_message(
    int $sessionId,
    ?int $userId,
    string $speaker,
    string $messageText
): array {
    $messageText = trim($messageText);

    if ($messageText === '') {
        throw new InvalidArgumentException(
            'Journal message cannot be empty.'
        );
    }

    if (!in_array($speaker, ['worker', 'system'], true)) {
        throw new InvalidArgumentException(
            'Invalid journal speaker.'
        );
    }

    $pdo = db();

    $pdo->beginTransaction();

    try {
        $sequence = journal_next_sequence($sessionId);
        $previousHash = journal_last_chain_hash($sessionId);
        $createdAt = date('Y-m-d H:i:s');
        $createdAtUnix = time();

        $contentHash = hash(
            'sha256',
            implode("\n", [
                $speaker,
                $messageText,
                $createdAt,
                (string) $createdAtUnix
            ])
        );

        $chainHash = hash(
            'sha256',
            implode('|', [
                (string) $sessionId,
                (string) $sequence,
                $contentHash,
                $previousHash
            ])
        );

        $stmt = $pdo->prepare("
            INSERT INTO work_journal_messages (
                session_id,
                user_id,
                sequence_number,
                speaker,
                message_text,
                created_at,
                content_hash,
                previous_chain_hash,
                chain_hash,
                created_at_unix
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $sessionId,
            $userId,
            $sequence,
            $speaker,
            $messageText,
            $createdAt,
            $contentHash,
            $previousHash !== '' ? $previousHash : null,
            $chainHash,
            $createdAtUnix
        ]);

        $pdo->commit();

        return [
            'sequence_number' => $sequence,
            'content_hash' => $contentHash,
            'chain_hash' => $chainHash,
            'created_at' => $createdAt
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function create_journal_session(
    int $workerId,
    ?int $userId,
    ?string $title = null
): array {
    $pdo = db();

    $startedAt = date('Y-m-d H:i:s');
    $journalDate = date('Y-m-d');

    $stmt = $pdo->prepare("
        INSERT INTO work_journal_sessions (
            worker_id,
            created_by_user_id,
            journal_date,
            title,
            status,
            started_at,
            created_at
        )
        VALUES (?, ?, ?, ?, 'open', ?, ?)
    ");

    $stmt->execute([
        $workerId,
        $userId,
        $journalDate,
        $title,
        $startedAt,
        $startedAt
    ]);

    $sessionId = (int) $pdo->lastInsertId();

    add_journal_message(
        $sessionId,
        null,
        'system',
        'How was your workday? Tell me what happened in your own words. I will preserve what you write in order.'
    );

    return journal_session_for_worker(
        $sessionId,
        $workerId
    );
}

function close_journal_session(
    int $sessionId,
    int $workerId
): void {
    $session = journal_session_for_worker(
        $sessionId,
        $workerId
    );

    if (!$session || $session['status'] !== 'open') {
        return;
    }

    $finalHash = journal_last_chain_hash($sessionId);

    $stmt = db()->prepare("
        UPDATE work_journal_sessions
        SET
            status = 'closed',
            closed_at = ?,
            final_chain_hash = ?
        WHERE id = ?
          AND worker_id = ?
          AND status = 'open'
    ");

    $stmt->execute([
        date('Y-m-d H:i:s'),
        $finalHash,
        $sessionId,
        $workerId
    ]);
}

function recent_journal_sessions(
    int $workerId,
    int $limit = 20
): array {
    $limit = max(1, min($limit, 100));

    $stmt = db()->prepare("
        SELECT
            s.*,
            (
                SELECT COUNT(*)
                FROM work_journal_messages m
                WHERE m.session_id = s.id
                  AND m.speaker = 'worker'
            ) AS worker_message_count
        FROM work_journal_sessions s
        WHERE s.worker_id = ?
        ORDER BY
            s.journal_date DESC,
            s.id DESC
        LIMIT {$limit}
    ");

    $stmt->execute([$workerId]);

    return $stmt->fetchAll();
}

function verify_journal_chain(int $sessionId): array
{
    $messages = journal_messages($sessionId);

    $previousHash = '';
    $valid = true;
    $failedSequence = null;

    foreach ($messages as $message) {
        $expectedContentHash = hash(
            'sha256',
            implode("\n", [
                $message['speaker'],
                $message['message_text'],
                $message['created_at'],
                (string) $message['created_at_unix']
            ])
        );

        $expectedChainHash = hash(
            'sha256',
            implode('|', [
                (string) $sessionId,
                (string) $message['sequence_number'],
                $expectedContentHash,
                $previousHash
            ])
        );

        if (
            !hash_equals(
                (string) $message['content_hash'],
                $expectedContentHash
            )
            || !hash_equals(
                (string) $message['chain_hash'],
                $expectedChainHash
            )
        ) {
            $valid = false;
            $failedSequence =
                (int) $message['sequence_number'];
            break;
        }

        $previousHash =
            (string) $message['chain_hash'];
    }

    return [
        'valid' => $valid,
        'failed_sequence' => $failedSequence,
        'final_chain_hash' => $previousHash,
        'message_count' => count($messages)
    ];
}
