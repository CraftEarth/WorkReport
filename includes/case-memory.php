<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';


function ensure_case_memory_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_memory_facts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            report_id INTEGER NOT NULL,
            fact_type TEXT NOT NULL,
            fact_key TEXT NOT NULL,
            fact_text TEXT NOT NULL,
            event_time TEXT,
            confidence TEXT NOT NULL DEFAULT 'source',
            source_excerpt TEXT,
            source_kind TEXT NOT NULL,
            source_row_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE,

            UNIQUE (
                report_id,
                source_kind,
                fact_key
            )
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS
            idx_case_memory_worker
        ON case_memory_facts(worker_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS
            idx_case_memory_report
        ON case_memory_facts(report_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS
            idx_case_memory_type
        ON case_memory_facts(fact_type)
    ");


    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_analysis_coverage (
            report_id INTEGER PRIMARY KEY,
            worker_id INTEGER NOT NULL,
            source_hash TEXT NOT NULL,
            source_unit_count INTEGER NOT NULL DEFAULT 0,
            event_count INTEGER NOT NULL DEFAULT 0,
            operational_finding_count INTEGER NOT NULL DEFAULT 0,
            evidence_count INTEGER NOT NULL DEFAULT 0,
            fact_count INTEGER NOT NULL DEFAULT 0,
            coverage_status TEXT NOT NULL DEFAULT 'indexed',
            analyzed_at TEXT,
            refreshed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS
            idx_case_coverage_worker
        ON case_analysis_coverage(worker_id)
    ");
}


function case_memory_source_units(string $text): array
{
    $text = trim(
        str_replace(
            ["\r\n", "\r"],
            "\n",
            $text
        )
    );

    if ($text === '') {
        return [];
    }

    $parts = preg_split(
        '/(?<=[.!?])\s+|\n+/',
        $text,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $units = [];

    foreach ($parts as $part) {

        $part = trim(
            preg_replace(
                '/\s+/',
                ' ',
                $part
            )
        );

        if ($part === '') {
            continue;
        }

        $units[] = $part;
    }

    return $units;
}


function case_memory_attachment_label(array $attachment): string
{
    foreach (
        [
            'description',
            'title',
            'original_name',
            'filename',
            'file_name',
            'name'
        ]
        as $field
    ) {
        $value = trim(
            (string) (
                $attachment[$field]
                ?? ''
            )
        );

        if ($value !== '') {
            return $value;
        }
    }

    return 'Preserved evidence attachment';
}


function case_memory_insert_fact(
    PDO $pdo,
    int $workerId,
    int $reportId,
    string $factType,
    string $factKey,
    string $factText,
    string $sourceKind,
    ?int $sourceRowId = null,
    ?string $eventTime = null,
    string $confidence = 'source',
    ?string $sourceExcerpt = null
): void {

    $factText = trim($factText);

    if ($factText === '') {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT OR REPLACE INTO case_memory_facts (
            worker_id,
            report_id,
            fact_type,
            fact_key,
            fact_text,
            event_time,
            confidence,
            source_excerpt,
            source_kind,
            source_row_id,
            created_at
        )
        VALUES (
            :worker_id,
            :report_id,
            :fact_type,
            :fact_key,
            :fact_text,
            :event_time,
            :confidence,
            :source_excerpt,
            :source_kind,
            :source_row_id,
            datetime('now', 'localtime')
        )
    ");

    $stmt->execute([
        ':worker_id' => $workerId,
        ':report_id' => $reportId,
        ':fact_type' => $factType,
        ':fact_key' => $factKey,
        ':fact_text' => $factText,
        ':event_time' => $eventTime,
        ':confidence' => $confidence,
        ':source_excerpt' => $sourceExcerpt,
        ':source_kind' => $sourceKind,
        ':source_row_id' => $sourceRowId
    ]);
}


function refresh_case_memory_for_report(
    int $reportId,
    int $workerId
): array {

    ensure_case_memory_schema();

    $pdo = db();

    /*
     * Hard worker boundary.
     */
    $stmt = $pdo->prepare("
        SELECT *
        FROM reports
        WHERE id = ?
          AND worker_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $reportId,
        $workerId
    ]);

    $report = $stmt->fetch();

    if (!$report) {
        throw new RuntimeException(
            'Report does not belong to worker.'
        );
    }

    $sourceText = trim(
        (string) (
            $report['original_text']
            ?? ''
        )
    );

    $sourceHash = hash(
        'sha256',
        $sourceText
    );

    /*
     * Rebuild, do not append.
     *
     * This prevents stale facts from surviving a
     * legitimate re-analysis.
     */
    $pdo->prepare("
        DELETE FROM case_memory_facts
        WHERE report_id = ?
          AND worker_id = ?
    ")->execute([
        $reportId,
        $workerId
    ]);


    /*
     * -----------------------------------------------------
     * SOURCE UNITS
     *
     * Every source sentence is indexed.
     * This is the coverage safety net.
     * -----------------------------------------------------
     */

    $sourceUnits =
        case_memory_source_units(
            $sourceText
        );

    foreach (
        $sourceUnits
        as $index => $unit
    ) {

        $key =
            'source-'
            . ($index + 1)
            . '-'
            . substr(
                hash('sha256', $unit),
                0,
                20
            );

        case_memory_insert_fact(
            $pdo,
            $workerId,
            $reportId,
            'source_unit',
            $key,
            $unit,
            'source_report',
            null,
            null,
            'source',
            $unit
        );
    }


    /*
     * -----------------------------------------------------
     * TIMELINE EVENTS
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT
            id,
            event_time,
            category,
            description
        FROM events
        WHERE report_id = ?
        ORDER BY id
    ");

    $stmt->execute([$reportId]);

    $events = $stmt->fetchAll();

    foreach ($events as $event) {

        $eventId =
            (int) $event['id'];

        $category = trim(
            (string) (
                $event['category']
                ?? 'General'
            )
        );

        case_memory_insert_fact(
            $pdo,
            $workerId,
            $reportId,
            'timeline_event',
            'event-' . $eventId,
            (string) $event['description'],
            'event',
            $eventId,
            !empty($event['event_time'])
                ? (string) $event['event_time']
                : null,
            'analyzed',
            (string) $event['description']
        );
    }


    /*
     * -----------------------------------------------------
     * OPERATIONAL FINDINGS
     * -----------------------------------------------------
     */

    $findings = [];

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM report_operational_findings
            WHERE report_id = ?
            ORDER BY id
        ");

        $stmt->execute([$reportId]);

        $findings = $stmt->fetchAll();

    } catch (Throwable $e) {

        $findings = [];
    }

    foreach ($findings as $finding) {

        $findingId =
            (int) $finding['id'];

        $factText = trim(
            (string) (
                $finding['observed_fact']
                ?? $finding['title']
                ?? ''
            )
        );

        case_memory_insert_fact(
            $pdo,
            $workerId,
            $reportId,
            'operational_finding',
            'finding-' . $findingId,
            $factText,
            'operational_finding',
            $findingId,
            null,
            (string) (
                $finding['confidence']
                ?? 'medium'
            ),
            trim(
                (string) (
                    $finding['source_excerpt']
                    ?? ''
                )
            )
        );
    }


    /*
     * -----------------------------------------------------
     * PRESERVED EVIDENCE
     * -----------------------------------------------------
     */

    $attachments = [];

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM attachments
            WHERE report_id = ?
            ORDER BY id
        ");

        $stmt->execute([$reportId]);

        $attachments =
            $stmt->fetchAll();

    } catch (Throwable $e) {

        $attachments = [];
    }

    foreach ($attachments as $attachment) {

        $attachmentId =
            (int) (
                $attachment['id']
                ?? 0
            );

        if ($attachmentId < 1) {
            continue;
        }

        $label =
            case_memory_attachment_label(
                $attachment
            );

        case_memory_insert_fact(
            $pdo,
            $workerId,
            $reportId,
            'evidence_preserved',
            'evidence-' . $attachmentId,
            $label,
            'attachment',
            $attachmentId,
            null,
            'source',
            $label
        );
    }


    /*
     * -----------------------------------------------------
     * ANALYSIS TIMESTAMP
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT analyzed_at
        FROM report_analysis
        WHERE report_id = ?
        LIMIT 1
    ");

    $stmt->execute([$reportId]);

    $analyzedAt =
        $stmt->fetchColumn();

    $factCount =
        count($sourceUnits)
        + count($events)
        + count($findings)
        + count($attachments);


    /*
     * -----------------------------------------------------
     * COVERAGE RECORD
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        INSERT INTO case_analysis_coverage (
            report_id,
            worker_id,
            source_hash,
            source_unit_count,
            event_count,
            operational_finding_count,
            evidence_count,
            fact_count,
            coverage_status,
            analyzed_at,
            refreshed_at
        )
        VALUES (
            :report_id,
            :worker_id,
            :source_hash,
            :source_unit_count,
            :event_count,
            :operational_finding_count,
            :evidence_count,
            :fact_count,
            'indexed',
            :analyzed_at,
            datetime('now', 'localtime')
        )
        ON CONFLICT(report_id)
        DO UPDATE SET
            worker_id =
                excluded.worker_id,
            source_hash =
                excluded.source_hash,
            source_unit_count =
                excluded.source_unit_count,
            event_count =
                excluded.event_count,
            operational_finding_count =
                excluded.operational_finding_count,
            evidence_count =
                excluded.evidence_count,
            fact_count =
                excluded.fact_count,
            coverage_status =
                excluded.coverage_status,
            analyzed_at =
                excluded.analyzed_at,
            refreshed_at =
                datetime('now', 'localtime')
    ");

    $stmt->execute([
        ':report_id' => $reportId,
        ':worker_id' => $workerId,
        ':source_hash' => $sourceHash,
        ':source_unit_count' =>
            count($sourceUnits),
        ':event_count' =>
            count($events),
        ':operational_finding_count' =>
            count($findings),
        ':evidence_count' =>
            count($attachments),
        ':fact_count' =>
            $factCount,
        ':analyzed_at' =>
            $analyzedAt ?: null
    ]);


    return [
        'report_id' =>
            $reportId,

        'worker_id' =>
            $workerId,

        'source_unit_count' =>
            count($sourceUnits),

        'event_count' =>
            count($events),

        'operational_finding_count' =>
            count($findings),

        'evidence_count' =>
            count($attachments),

        'fact_count' =>
            $factCount,

        'source_hash' =>
            $sourceHash
    ];
}


function case_memory_worker_coverage(
    int $workerId
): array {

    ensure_case_memory_schema();

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.report_date,
            r.title,
            r.original_text,
            ra.analyzed_at,

            c.source_hash,
            c.source_unit_count,
            c.event_count,
            c.operational_finding_count,
            c.evidence_count,
            c.fact_count,
            c.coverage_status,
            c.refreshed_at

        FROM reports r

        LEFT JOIN report_analysis ra
            ON ra.report_id = r.id

        LEFT JOIN case_analysis_coverage c
            ON c.report_id = r.id
           AND c.worker_id = r.worker_id

        WHERE r.worker_id = ?

        ORDER BY
            r.report_date,
            r.id
    ");

    $stmt->execute([$workerId]);

    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {

        $currentHash = hash(
            'sha256',
            trim(
                (string) (
                    $row['original_text']
                    ?? ''
                )
            )
        );

        if (empty($row['analyzed_at'])) {

            $row['memory_status'] =
                'unanalyzed';

        } elseif (
            empty($row['source_hash'])
        ) {

            $row['memory_status'] =
                'not_indexed';

        } elseif (
            !hash_equals(
                (string) $row['source_hash'],
                $currentHash
            )
        ) {

            $row['memory_status'] =
                'stale';

        } else {

            $row['memory_status'] =
                'current';
        }
    }

    unset($row);

    return $rows;
}
