<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/effective-metadata.php';


/*
 * =========================================================
 * CASE PATTERN MEMORY
 * =========================================================
 *
 * Patterns are DERIVED intelligence.
 *
 * Source reports remain untouched.
 *
 * A pattern records recurrence only:
 *
 *      same person
 *      same place/context
 *      same category
 *      same operational issue
 *      same semantic fact type
 *      same effective primary work area
 *
 * The engine does NOT infer:
 *
 *      motive
 *      liability
 *      retaliation
 *      discrimination
 *      truth/falsity
 *      causation
 *
 * Every pattern must remain traceable to the reports and
 * derived facts that produced it.
 */


function ensure_case_pattern_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_patterns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            worker_id INTEGER NOT NULL,

            pattern_dimension TEXT NOT NULL,
            pattern_key TEXT NOT NULL,
            pattern_value TEXT NOT NULL,

            first_seen TEXT,
            last_seen TEXT,

            report_count INTEGER NOT NULL DEFAULT 0,
            fact_count INTEGER NOT NULL DEFAULT 0,

            frequency TEXT NOT NULL DEFAULT 'repeated',
            corroboration TEXT NOT NULL DEFAULT 'source_only',
            confidence TEXT NOT NULL DEFAULT 'medium',

            summary_eligible INTEGER NOT NULL DEFAULT 1,

            reasoning TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            refreshed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                worker_id,
                pattern_dimension,
                pattern_key
            )
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_pattern_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            pattern_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,
            report_id INTEGER NOT NULL,

            report_date TEXT,

            source_kind TEXT NOT NULL,
            source_row_id INTEGER,
            source_excerpt TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                pattern_id,
                report_id,
                source_kind,
                source_row_id
            )
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_pattern_facts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            pattern_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,
            report_id INTEGER NOT NULL,
            fact_id INTEGER NOT NULL,

            fact_type TEXT,
            fact_text TEXT,
            importance TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                pattern_id,
                fact_id
            )
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_patterns_worker
        ON case_patterns (
            worker_id,
            pattern_dimension,
            report_count
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_pattern_reports_pattern
        ON case_pattern_reports (
            pattern_id,
            report_id
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_pattern_facts_pattern
        ON case_pattern_facts (
            pattern_id,
            fact_id
        )
    ");
}


function case_pattern_normalize(
    string $value
): string {

    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace(
        '/\s+/u',
        ' ',
        $value
    );

    return mb_strtolower(
        trim($value)
    );
}


function case_pattern_frequency(
    int $reportCount
): string {

    if ($reportCount >= 5) {
        return 'persistent';
    }

    if ($reportCount >= 2) {
        return 'repeated';
    }

    return 'single';
}


function case_pattern_confidence(
    int $reportCount,
    int $sourceCount
): string {

    if (
        $reportCount >= 3
        && $sourceCount >= 3
    ) {
        return 'high';
    }

    if ($reportCount >= 2) {
        return 'medium';
    }

    return 'low';
}


function case_pattern_insert(
    int $workerId,
    string $dimension,
    string $value,
    array $occurrences,
    int $factCount = 0,
    string $corroboration = 'source_only'
): ?int {

    $value = trim($value);

    if ($value === '') {
        return null;
    }

    /*
     * A recurrence pattern requires at least two DISTINCT
     * reports.
     */
    $reportIds = [];

    foreach ($occurrences as $occurrence) {

        $reportId =
            (int) (
                $occurrence['report_id']
                ?? 0
            );

        if ($reportId > 0) {
            $reportIds[$reportId] = true;
        }
    }

    $reportCount =
        count($reportIds);

    if ($reportCount < 2) {
        return null;
    }

    $patternKey =
        case_pattern_normalize(
            $value
        );

    if ($patternKey === '') {
        return null;
    }

    $dates = [];

    foreach ($occurrences as $occurrence) {

        $date = trim(
            (string) (
                $occurrence['report_date']
                ?? ''
            )
        );

        if ($date !== '') {
            $dates[] = $date;
        }
    }

    sort($dates);

    $firstSeen =
        $dates
            ? $dates[0]
            : null;

    $lastSeen =
        $dates
            ? $dates[count($dates) - 1]
            : null;


    $sourceCount =
        count($occurrences);

    $frequency =
        case_pattern_frequency(
            $reportCount
        );

    $confidence =
        case_pattern_confidence(
            $reportCount,
            $sourceCount
        );


    $reasoning =
        "Documented {$dimension} recurred across "
        . "{$reportCount} distinct reports. "
        . "This records recurrence only and does not establish causation or wrongdoing.";


    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO case_patterns (
            worker_id,
            pattern_dimension,
            pattern_key,
            pattern_value,
            first_seen,
            last_seen,
            report_count,
            fact_count,
            frequency,
            corroboration,
            confidence,
            summary_eligible,
            reasoning,
            refreshed_at
        )
        VALUES (
            :worker_id,
            :pattern_dimension,
            :pattern_key,
            :pattern_value,
            :first_seen,
            :last_seen,
            :report_count,
            :fact_count,
            :frequency,
            :corroboration,
            :confidence,
            1,
            :reasoning,
            CURRENT_TIMESTAMP
        )

        ON CONFLICT (
            worker_id,
            pattern_dimension,
            pattern_key
        )
        DO UPDATE SET
            pattern_value =
                excluded.pattern_value,

            first_seen =
                excluded.first_seen,

            last_seen =
                excluded.last_seen,

            report_count =
                excluded.report_count,

            fact_count =
                excluded.fact_count,

            frequency =
                excluded.frequency,

            corroboration =
                excluded.corroboration,

            confidence =
                excluded.confidence,

            summary_eligible =
                excluded.summary_eligible,

            reasoning =
                excluded.reasoning,

            refreshed_at =
                CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':worker_id' =>
            $workerId,

        ':pattern_dimension' =>
            $dimension,

        ':pattern_key' =>
            $patternKey,

        ':pattern_value' =>
            $value,

        ':first_seen' =>
            $firstSeen,

        ':last_seen' =>
            $lastSeen,

        ':report_count' =>
            $reportCount,

        ':fact_count' =>
            $factCount,

        ':frequency' =>
            $frequency,

        ':corroboration' =>
            $corroboration,

        ':confidence' =>
            $confidence,

        ':reasoning' =>
            $reasoning,
    ]);


    $find = $pdo->prepare("
        SELECT id
        FROM case_patterns
        WHERE worker_id = ?
          AND pattern_dimension = ?
          AND pattern_key = ?
        LIMIT 1
    ");

    $find->execute([
        $workerId,
        $dimension,
        $patternKey
    ]);

    $patternId =
        (int) $find->fetchColumn();

    if ($patternId < 1) {
        return null;
    }


    foreach ($occurrences as $occurrence) {

        $reportId =
            (int) (
                $occurrence['report_id']
                ?? 0
            );

        if ($reportId < 1) {
            continue;
        }

        $save = $pdo->prepare("
            INSERT OR IGNORE INTO case_pattern_reports (
                pattern_id,
                worker_id,
                report_id,
                report_date,
                source_kind,
                source_row_id,
                source_excerpt
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $save->execute([
            $patternId,
            $workerId,
            $reportId,

            $occurrence['report_date']
                ?? null,

            $occurrence['source_kind']
                ?? $dimension,

            $occurrence['source_row_id']
                ?? null,

            $occurrence['source_excerpt']
                ?? null,
        ]);
    }

    return $patternId;
}


function case_pattern_collect_people(
    int $workerId
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            p.id AS entity_id,
            p.name AS value,
            r.id AS report_id,
            r.report_date
        FROM report_people rp

        INNER JOIN reports r
            ON r.id = rp.report_id

        INNER JOIN people p
            ON p.id = rp.person_id

        WHERE r.worker_id = ?

        ORDER BY
            p.name,
            r.report_date,
            r.id
    ");

    $stmt->execute([
        $workerId
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


function case_pattern_collect_places(
    int $workerId
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            p.id AS entity_id,
            p.name AS value,
            r.id AS report_id,
            r.report_date,
            r.original_text,
            r.polished_text
        FROM report_places rp

        INNER JOIN reports r
            ON r.id = rp.report_id

        INNER JOIN places p
            ON p.id = rp.place_id

        WHERE r.worker_id = ?

        ORDER BY
            p.name,
            r.report_date,
            r.id
    ");

    $stmt->execute([
        $workerId
    ]);

    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

    $verified = [];

    foreach ($rows as $row) {

        $place = trim(
            (string) (
                $row['value']
                ?? ''
            )
        );

        if ($place === '') {
            continue;
        }

        /*
         * Generic PLACE recurrence must be supported by the
         * preserved report narrative itself.
         *
         * Structured metadata may legitimately contain a
         * machine/workstation value that is wrong or broader
         * than the written narrative. That value remains
         * preserved elsewhere but must not manufacture a
         * repeated-place pattern.
         */
        $sourceText = trim(
            (string) (
                $row['original_text']
                ?? ''
            )
            . "\n"
            . (string) (
                $row['polished_text']
                ?? ''
            )
        );

        if (
            !effective_metadata_phrase_present(
                $sourceText,
                $place
            )
        ) {
            continue;
        }

        unset(
            $row['original_text'],
            $row['polished_text']
        );

        $verified[] = $row;
    }

    return $verified;
}

function case_pattern_collect_categories(
    int $workerId
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            c.id AS entity_id,
            c.name AS value,
            r.id AS report_id,
            r.report_date
        FROM report_categories rc

        INNER JOIN reports r
            ON r.id = rc.report_id

        INNER JOIN categories c
            ON c.id = rc.category_id

        WHERE r.worker_id = ?

        ORDER BY
            c.name,
            r.report_date,
            r.id
    ");

    $stmt->execute([
        $workerId
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


function case_pattern_collect_operational(
    int $workerId
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            of.id AS entity_id,
            of.finding_type AS value,
            of.observed_fact AS source_excerpt,
            r.id AS report_id,
            r.report_date
        FROM report_operational_findings of

        INNER JOIN reports r
            ON r.id = of.report_id

        WHERE r.worker_id = ?
          AND of.finding_type IS NOT NULL
          AND TRIM(of.finding_type) <> ''

        ORDER BY
            of.finding_type,
            r.report_date,
            r.id
    ");

    $stmt->execute([
        $workerId
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


function case_pattern_collect_fact_types(
    int $workerId
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            f.id AS entity_id,
            f.fact_type AS value,
            f.fact_text AS source_excerpt,
            f.importance,
            r.id AS report_id,
            r.report_date
        FROM case_material_facts f

        INNER JOIN reports r
            ON r.id = f.report_id
           AND r.worker_id = f.worker_id

        WHERE f.worker_id = ?
          AND f.fact_type IS NOT NULL
          AND TRIM(f.fact_type) <> ''
          AND LOWER(TRIM(f.fact_type)) NOT IN (
              'general',
              'context'
          )
          AND f.importance <> 'context'

        ORDER BY
            f.fact_type,
            r.report_date,
            r.id,
            f.id
    ");

    $stmt->execute([
        $workerId
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


function case_pattern_collect_primary_work_areas(
    int $workerId
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            m.id AS entity_id,
            m.effective_value AS value,
            m.interpretation_status,
            m.confidence,
            m.reason AS source_excerpt,
            r.id AS report_id,
            r.report_date
        FROM case_effective_metadata m

        INNER JOIN reports r
            ON r.id = m.report_id
           AND r.worker_id = m.worker_id

        WHERE m.worker_id = ?
          AND m.field_name = 'primary_work_area'
          AND m.effective_value IS NOT NULL
          AND TRIM(m.effective_value) <> ''
          AND m.interpretation_status NOT IN (
              'unresolved',
              'ambiguous'
          )

        ORDER BY
            m.effective_value,
            r.report_date,
            r.id
    ");

    $stmt->execute([
        $workerId
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


function case_pattern_group_rows(
    array $rows
): array {

    $groups = [];

    foreach ($rows as $row) {

        $value = trim(
            (string) (
                $row['value']
                ?? ''
            )
        );

        if ($value === '') {
            continue;
        }

        $key =
            case_pattern_normalize(
                $value
            );

        if ($key === '') {
            continue;
        }

        if (!isset($groups[$key])) {

            $groups[$key] = [
                'value' =>
                    $value,

                'rows' =>
                    [],
            ];
        }

        $groups[$key]['rows'][] =
            $row;
    }

    return $groups;
}


function rebuild_case_patterns_for_worker(
    int $workerId
): int {

    ensure_case_pattern_schema();

    $pdo = db();

    /*
     * Delete only DERIVED pattern memory for this worker.
     *
     * Source reports, facts, entities and evidence remain
     * untouched.
     */
    $patternIds = $pdo->prepare("
        SELECT id
        FROM case_patterns
        WHERE worker_id = ?
    ");

    $patternIds->execute([
        $workerId
    ]);

    $ids = array_map(
        'intval',
        $patternIds->fetchAll(
            PDO::FETCH_COLUMN
        )
    );

    if ($ids) {

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($ids),
                    '?'
                )
            );

        $pdo->prepare("
            DELETE FROM case_pattern_facts
            WHERE pattern_id IN ({$placeholders})
        ")->execute($ids);

        $pdo->prepare("
            DELETE FROM case_pattern_reports
            WHERE pattern_id IN ({$placeholders})
        ")->execute($ids);
    }

    $delete = $pdo->prepare("
        DELETE FROM case_patterns
        WHERE worker_id = ?
    ");

    $delete->execute([
        $workerId
    ]);


    $dimensions = [

        'person' =>
            case_pattern_collect_people(
                $workerId
            ),

        'place' =>
            case_pattern_collect_places(
                $workerId
            ),

        'category' =>
            case_pattern_collect_categories(
                $workerId
            ),

        'operational_issue' =>
            case_pattern_collect_operational(
                $workerId
            ),

        'fact_type' =>
            case_pattern_collect_fact_types(
                $workerId
            ),

        'primary_work_area' =>
            case_pattern_collect_primary_work_areas(
                $workerId
            ),
    ];


    $created = 0;

    foreach (
        $dimensions
        as $dimension => $rows
    ) {

        $groups =
            case_pattern_group_rows(
                $rows
            );

        foreach ($groups as $group) {

            $occurrences = [];

            foreach (
                $group['rows']
                as $row
            ) {

                $occurrences[] = [
                    'report_id' =>
                        (int) $row['report_id'],

                    'report_date' =>
                        $row['report_date']
                        ?? null,

                    'source_kind' =>
                        $dimension,

                    'source_row_id' =>
                        isset($row['entity_id'])
                            ? (int) $row['entity_id']
                            : null,

                    'source_excerpt' =>
                        $row['source_excerpt']
                        ?? null,
                ];
            }


            $factCount = 0;

            if (
                $dimension === 'fact_type'
            ) {
                $factCount =
                    count(
                        $group['rows']
                    );
            }


            $patternId =
                case_pattern_insert(
                    $workerId,
                    $dimension,
                    (string) $group['value'],
                    $occurrences,
                    $factCount
                );

            if (!$patternId) {
                continue;
            }


            /*
             * Fact-type patterns retain exact fact provenance.
             */
            if (
                $dimension
                === 'fact_type'
            ) {

                foreach (
                    $group['rows']
                    as $row
                ) {

                    $factId =
                        (int) (
                            $row['entity_id']
                            ?? 0
                        );

                    if ($factId < 1) {
                        continue;
                    }

                    $save =
                        $pdo->prepare("
                            INSERT OR IGNORE INTO case_pattern_facts (
                                pattern_id,
                                worker_id,
                                report_id,
                                fact_id,
                                fact_type,
                                fact_text,
                                importance
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");

                    $save->execute([
                        $patternId,
                        $workerId,
                        (int) $row['report_id'],
                        $factId,
                        $row['value']
                            ?? null,
                        $row['source_excerpt']
                            ?? null,
                        $row['importance']
                            ?? null,
                    ]);
                }
            }

            $created++;
        }
    }

    return $created;
}


function rebuild_all_case_patterns(): array
{
    ensure_case_pattern_schema();

    $pdo = db();

    $workerIds =
        $pdo->query("
            SELECT DISTINCT worker_id
            FROM reports
            WHERE worker_id IS NOT NULL
            ORDER BY worker_id
        ")
        ->fetchAll(
            PDO::FETCH_COLUMN
        );

    $result = [];

    foreach ($workerIds as $workerId) {

        $workerId =
            (int) $workerId;

        $result[$workerId] =
            rebuild_case_patterns_for_worker(
                $workerId
            );
    }

    return $result;
}
