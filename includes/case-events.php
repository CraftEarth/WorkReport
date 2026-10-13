<?php

require_once __DIR__ . '/functions.php';


/*
 * ============================================================
 * CASE EVENT KNOWLEDGE FOUNDATION
 * ============================================================
 *
 * Goals:
 *
 * 1. Preserve every meaningful source unit.
 * 2. Never require a known profession, vocabulary, or language.
 * 3. Build atomic event records where current intelligence can.
 * 4. Preserve unresolved units instead of discarding them.
 * 5. Keep all derived interpretation separate from source text.
 * 6. Scope every record by worker_id + report_id.
 *
 * Source authority:
 *
 *   reports.original_text
 *
 * polished_text is used only if no original source exists.
 *
 * No source text is rewritten.
 */


function ensure_case_event_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_source_units (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            worker_id INTEGER NOT NULL,
            report_id INTEGER NOT NULL,

            source_basis TEXT NOT NULL DEFAULT 'original_text',
            sequence_no INTEGER NOT NULL,

            raw_text TEXT NOT NULL,
            normalized_text TEXT NOT NULL,

            source_hash TEXT NOT NULL,

            language_hint TEXT NOT NULL DEFAULT 'und',

            unit_type TEXT NOT NULL DEFAULT 'unknown',

            coverage_status TEXT NOT NULL DEFAULT 'unresolved',

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            refreshed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                worker_id,
                report_id,
                source_basis,
                sequence_no
            )
        )
    ");


    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_atomic_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            worker_id INTEGER NOT NULL,
            report_id INTEGER NOT NULL,
            source_unit_id INTEGER NOT NULL,

            event_index INTEGER NOT NULL DEFAULT 1,

            event_date TEXT,
            event_time TEXT,

            event_type TEXT NOT NULL DEFAULT 'unresolved_event',

            fact_status TEXT NOT NULL DEFAULT 'reported',

            normalized_summary TEXT,

            interpretation_status TEXT NOT NULL DEFAULT 'unresolved',

            confidence REAL NOT NULL DEFAULT 0.25,

            source_excerpt TEXT NOT NULL,

            derived_from_kind TEXT,
            derived_from_id INTEGER,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            refreshed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                source_unit_id,
                event_index
            )
        )
    ");


    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_event_coverage (
            report_id INTEGER PRIMARY KEY,

            worker_id INTEGER NOT NULL,

            source_basis TEXT NOT NULL,
            source_hash TEXT NOT NULL,

            source_unit_count INTEGER NOT NULL DEFAULT 0,

            eventized_unit_count INTEGER NOT NULL DEFAULT 0,
            context_unit_count INTEGER NOT NULL DEFAULT 0,
            unresolved_unit_count INTEGER NOT NULL DEFAULT 0,

            atomic_event_count INTEGER NOT NULL DEFAULT 0,

            coverage_status TEXT NOT NULL DEFAULT 'unresolved',

            refreshed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");


    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_source_units_worker
        ON case_source_units (
            worker_id,
            report_id,
            sequence_no
        )
    ");


    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_source_units_hash
        ON case_source_units (
            worker_id,
            source_hash
        )
    ");


    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_atomic_events_worker
        ON case_atomic_events (
            worker_id,
            report_id,
            source_unit_id
        )
    ");


    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_atomic_events_type
        ON case_atomic_events (
            worker_id,
            event_type
        )
    ");
}


/*
 * Unicode-safe normalization.
 *
 * This is only for matching/search.
 * raw_text remains untouched.
 */
function case_event_normalize_text(
    string $text
): string {

    $text = trim($text);

    if ($text === '') {
        return '';
    }

    $text = preg_replace(
        '/\s+/u',
        ' ',
        $text
    );

    return mb_strtolower(
        trim($text)
    );
}


function case_event_source_hash(
    string $text
): string {

    return hash(
        'sha256',
        $text
    );
}


/*
 * Source segmentation.
 *
 * Intentionally language-tolerant:
 *
 * . ! ?
 * 。！？
 * line breaks
 *
 * Unknown punctuation/languages still remain preserved,
 * even if they remain as larger units.
 */
function case_event_split_source_units(
    string $text
): array {

    $text = trim($text);

    if ($text === '') {
        return [];
    }

    $parts = preg_split(
        '/(?<=[.!?。！？])\s+|\R+/u',
        $text,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $units = [];

    foreach ($parts as $part) {

        $raw = trim($part);

        if ($raw === '') {
            continue;
        }

        $units[] = $raw;
    }

    return $units;
}


function case_event_report_source(
    array $report
): array {

    $original =
        trim(
            (string) (
                $report['original_text']
                ?? ''
            )
        );

    if ($original !== '') {

        return [
            'basis' => 'original_text',
            'text' => $original,
        ];
    }


    $polished =
        trim(
            (string) (
                $report['polished_text']
                ?? ''
            )
        );

    if ($polished !== '') {

        return [
            'basis' => 'polished_text',
            'text' => $polished,
        ];
    }


    return [
        'basis' => 'none',
        'text' => '',
    ];
}


/*
 * Load current semantic facts for a report.
 *
 * These facts DO NOT replace the source.
 * They are merely used as current interpretations.
 */
function case_event_report_material_facts(
    int $workerId,
    int $reportId
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            fact_type,
            fact_text,
            normalized_text,
            confidence,
            source_excerpt,
            event_time,
            importance
        FROM case_material_facts
        WHERE worker_id = ?
          AND report_id = ?
        ORDER BY id
    ");

    $stmt->execute([
        $workerId,
        $reportId
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/*
 * Match known derived facts back to one preserved source unit.
 *
 * Exact normalized match is preferred.
 * Containment is a fallback.
 */
function case_event_match_facts(
    string $unitText,
    array $facts
): array {

    $normalizedUnit =
        case_event_normalize_text(
            $unitText
        );

    if ($normalizedUnit === '') {
        return [];
    }


    /*
     * Matching priority:
     *
     * 1. Exact source_excerpt
     * 2. Exact fact_text / normalized_text
     * 3. Strong containment against source_excerpt
     * 4. Strong containment against fact interpretation
     *
     * source_excerpt receives priority because it represents
     * provenance from the preserved report rather than a
     * transformed semantic interpretation.
     */

    $exactSource = [];
    $exactFact = [];
    $containedSource = [];
    $containedFact = [];


    foreach ($facts as $fact) {

        $sourceExcerpt =
            case_event_normalize_text(
                (string) (
                    $fact['source_excerpt']
                    ?? ''
                )
            );


        $factText =
            case_event_normalize_text(
                (string) (
                    $fact['fact_text']
                    ?? ''
                )
            );


        $normalizedFact =
            case_event_normalize_text(
                (string) (
                    $fact['normalized_text']
                    ?? ''
                )
            );


        /*
         * Exact provenance match.
         */
        if (
            $sourceExcerpt !== ''
            &&
            $sourceExcerpt === $normalizedUnit
        ) {

            $exactSource[] = $fact;
            continue;
        }


        /*
         * Exact semantic match.
         */
        if (
            (
                $factText !== ''
                &&
                $factText === $normalizedUnit
            )
            ||
            (
                $normalizedFact !== ''
                &&
                $normalizedFact === $normalizedUnit
            )
        ) {

            $exactFact[] = $fact;
            continue;
        }


        /*
         * Provenance containment fallback.
         *
         * Require enough text to avoid tiny phrases producing
         * accidental matches.
         */
        if (
            $sourceExcerpt !== ''
            &&
            mb_strlen($sourceExcerpt) >= 12
            &&
            (
                str_contains(
                    $normalizedUnit,
                    $sourceExcerpt
                )
                ||
                str_contains(
                    $sourceExcerpt,
                    $normalizedUnit
                )
            )
        ) {

            $containedSource[] = $fact;
            continue;
        }


        /*
         * Semantic containment fallback.
         */
        foreach (
            [$factText, $normalizedFact]
            as $candidate
        ) {

            if (
                $candidate === ''
                ||
                mb_strlen($candidate) < 12
            ) {
                continue;
            }


            if (
                str_contains(
                    $normalizedUnit,
                    $candidate
                )
                ||
                str_contains(
                    $candidate,
                    $normalizedUnit
                )
            ) {

                $containedFact[] = $fact;
                break;
            }
        }
    }


    /*
     * Use only the strongest matching tier.
     *
     * This prevents a weaker semantic match from being mixed
     * into an exact provenance match.
     */
    if ($exactSource) {
        return $exactSource;
    }

    if ($exactFact) {
        return $exactFact;
    }

    if ($containedSource) {
        return $containedSource;
    }

    return $containedFact;
}


/*
 * Generic unit classification.
 *
 * Open vocabulary by design.
 */
function case_event_unit_type(
    array $matchedFacts
): string {

    if (!$matchedFacts) {
        return 'unknown';
    }


    $allContext = true;

    foreach ($matchedFacts as $fact) {

        $importance =
            strtolower(
                trim(
                    (string) (
                        $fact['importance']
                        ?? ''
                    )
                )
            );

        if ($importance !== 'context') {
            $allContext = false;
            break;
        }
    }


    if ($allContext) {
        return 'context';
    }


    return 'event';
}


function case_event_confidence_number(
    mixed $confidence
): float {

    if (is_numeric($confidence)) {

        $number = (float) $confidence;

        if ($number > 1) {
            $number /= 100;
        }

        return max(
            0.0,
            min(
                1.0,
                $number
            )
        );
    }


    $value =
        strtolower(
            trim(
                (string) $confidence
            )
        );


    return match ($value) {

        'certain',
        'confirmed',
        'high',
        'strong'
            => 0.90,

        'medium',
        'moderate',
        'probable'
            => 0.70,

        'source',
        'reported'
            => 0.60,

        'low',
        'possible',
        'weak'
            => 0.40,

        default
            => 0.50,
    };
}


function rebuild_case_events_for_report(
    int $reportId
): array {

    ensure_case_event_schema();

    $pdo = db();


    $stmt = $pdo->prepare("
        SELECT
            id,
            worker_id,
            report_date,
            original_text,
            polished_text
        FROM reports
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $reportId
    ]);

    $report =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$report) {

        throw new RuntimeException(
            "Report {$reportId} not found."
        );
    }


    $workerId =
        (int) $report['worker_id'];

    if ($workerId < 1) {

        throw new RuntimeException(
            "Report {$reportId} has no valid worker_id."
        );
    }


    $source =
        case_event_report_source(
            $report
        );

    $sourceBasis =
        $source['basis'];

    $sourceText =
        $source['text'];

    $sourceHash =
        case_event_source_hash(
            $sourceText
        );


    /*
     * Derived layer only.
     * Safe to rebuild.
     */
    $oldUnits = $pdo->prepare("
        SELECT id
        FROM case_source_units
        WHERE worker_id = ?
          AND report_id = ?
    ");

    $oldUnits->execute([
        $workerId,
        $reportId
    ]);

    $oldUnitIds =
        array_map(
            'intval',
            $oldUnits->fetchAll(
                PDO::FETCH_COLUMN
            )
        );


    if ($oldUnitIds) {

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($oldUnitIds),
                    '?'
                )
            );

        $pdo->prepare("
            DELETE FROM case_atomic_events
            WHERE source_unit_id
            IN ({$placeholders})
        ")->execute(
            $oldUnitIds
        );
    }


    $pdo->prepare("
        DELETE FROM case_source_units
        WHERE worker_id = ?
          AND report_id = ?
    ")->execute([
        $workerId,
        $reportId
    ]);


    $facts =
        case_event_report_material_facts(
            $workerId,
            $reportId
        );


    $units =
        case_event_split_source_units(
            $sourceText
        );


    $eventizedUnits = 0;
    $contextUnits = 0;
    $unresolvedUnits = 0;
    $atomicEventCount = 0;


    foreach (
        $units
        as $index => $rawText
    ) {

        $sequenceNo =
            $index + 1;

        $normalized =
            case_event_normalize_text(
                $rawText
            );

        $unitHash =
            case_event_source_hash(
                $rawText
            );


        $matchedFacts =
            case_event_match_facts(
                $rawText,
                $facts
            );


        $unitType =
            case_event_unit_type(
                $matchedFacts
            );


        if ($unitType === 'context') {

            $coverageStatus =
                'context';

            $contextUnits++;

        } elseif ($matchedFacts) {

            $coverageStatus =
                'eventized';

            $eventizedUnits++;

        } else {

            $coverageStatus =
                'unresolved';

            $unresolvedUnits++;
        }


        $insertUnit =
            $pdo->prepare("
                INSERT INTO case_source_units (
                    worker_id,
                    report_id,
                    source_basis,
                    sequence_no,
                    raw_text,
                    normalized_text,
                    source_hash,
                    language_hint,
                    unit_type,
                    coverage_status,
                    refreshed_at
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    'und',
                    ?, ?,
                    CURRENT_TIMESTAMP
                )
            ");


        $insertUnit->execute([
            $workerId,
            $reportId,
            $sourceBasis,
            $sequenceNo,
            $rawText,
            $normalized,
            $unitHash,
            $unitType,
            $coverageStatus,
        ]);


        $sourceUnitId =
            (int) $pdo->lastInsertId();


        /*
         * Context remains preserved as a source unit.
         * No artificial event is required.
         */
        if ($unitType === 'context') {
            continue;
        }


        /*
         * If current semantic facts exist,
         * each distinct fact becomes an event interpretation.
         */
        if ($matchedFacts) {

            $eventIndex = 0;

            foreach ($matchedFacts as $fact) {

                $eventIndex++;


                $eventType =
                    trim(
                        (string) (
                            $fact['fact_type']
                            ?? ''
                        )
                    );

                if ($eventType === '') {
                    $eventType =
                        'unresolved_event';
                }


                $summary =
                    trim(
                        (string) (
                            $fact['fact_text']
                            ?? ''
                        )
                    );

                if ($summary === '') {
                    $summary = $rawText;
                }


                $confidence =
                    case_event_confidence_number(
                        $fact['confidence']
                        ?? null
                    );


                $insertEvent =
                    $pdo->prepare("
                        INSERT INTO case_atomic_events (
                            worker_id,
                            report_id,
                            source_unit_id,
                            event_index,
                            event_date,
                            event_time,
                            event_type,
                            fact_status,
                            normalized_summary,
                            interpretation_status,
                            confidence,
                            source_excerpt,
                            derived_from_kind,
                            derived_from_id,
                            refreshed_at
                        )
                        VALUES (
                            ?, ?, ?, ?, ?, ?, ?,
                            'reported',
                            ?,
                            'derived',
                            ?,
                            ?,
                            'case_material_fact',
                            ?,
                            CURRENT_TIMESTAMP
                        )
                    ");


                $insertEvent->execute([
                    $workerId,
                    $reportId,
                    $sourceUnitId,
                    $eventIndex,
                    $report['report_date']
                        ?: null,
                    $fact['event_time']
                        ?: null,
                    $eventType,
                    $summary,
                    $confidence,
                    $rawText,
                    (int) $fact['id'],
                ]);


                $atomicEventCount++;
            }

        } else {

            /*
             * Critical preservation rule:
             *
             * Unknown meaning still becomes durable knowledge.
             */
            $insertEvent =
                $pdo->prepare("
                    INSERT INTO case_atomic_events (
                        worker_id,
                        report_id,
                        source_unit_id,
                        event_index,
                        event_date,
                        event_type,
                        fact_status,
                        normalized_summary,
                        interpretation_status,
                        confidence,
                        source_excerpt,
                        derived_from_kind,
                        refreshed_at
                    )
                    VALUES (
                        ?, ?, ?, 1, ?,
                        'unresolved_event',
                        'reported',
                        ?,
                        'unresolved',
                        0.25,
                        ?,
                        'source_unit',
                        CURRENT_TIMESTAMP
                    )
                ");


            $insertEvent->execute([
                $workerId,
                $reportId,
                $sourceUnitId,
                $report['report_date']
                    ?: null,
                $rawText,
                $rawText,
            ]);


            $atomicEventCount++;
        }
    }


    /*
     * Coverage means:
     *
     * every source unit is either
     *
     * - structured/eventized
     * - preserved context
     * - preserved unresolved knowledge
     *
     * Nothing silently disappears.
     */
    $sourceUnitCount =
        count($units);


    $coverageStatus =
        'complete';

    if ($sourceUnitCount === 0) {

        $coverageStatus =
            'no_source';

    } elseif ($unresolvedUnits > 0) {

        $coverageStatus =
            'complete_with_unresolved';
    }


    $saveCoverage =
        $pdo->prepare("
            INSERT INTO case_event_coverage (
                report_id,
                worker_id,
                source_basis,
                source_hash,
                source_unit_count,
                eventized_unit_count,
                context_unit_count,
                unresolved_unit_count,
                atomic_event_count,
                coverage_status,
                refreshed_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                CURRENT_TIMESTAMP
            )

            ON CONFLICT(report_id)
            DO UPDATE SET
                worker_id =
                    excluded.worker_id,

                source_basis =
                    excluded.source_basis,

                source_hash =
                    excluded.source_hash,

                source_unit_count =
                    excluded.source_unit_count,

                eventized_unit_count =
                    excluded.eventized_unit_count,

                context_unit_count =
                    excluded.context_unit_count,

                unresolved_unit_count =
                    excluded.unresolved_unit_count,

                atomic_event_count =
                    excluded.atomic_event_count,

                coverage_status =
                    excluded.coverage_status,

                refreshed_at =
                    CURRENT_TIMESTAMP
        ");


    $saveCoverage->execute([
        $reportId,
        $workerId,
        $sourceBasis,
        $sourceHash,
        $sourceUnitCount,
        $eventizedUnits,
        $contextUnits,
        $unresolvedUnits,
        $atomicEventCount,
        $coverageStatus,
    ]);


    return [
        'worker_id' =>
            $workerId,

        'report_id' =>
            $reportId,

        'source_basis' =>
            $sourceBasis,

        'source_units' =>
            $sourceUnitCount,

        'eventized_units' =>
            $eventizedUnits,

        'context_units' =>
            $contextUnits,

        'unresolved_units' =>
            $unresolvedUnits,

        'atomic_events' =>
            $atomicEventCount,

        'coverage_status' =>
            $coverageStatus,
    ];
}


function rebuild_all_case_events(): array
{
    ensure_case_event_schema();

    $pdo = db();


    /*
     * Only reports already participating in analysis.
     *
     * This mirrors the existing intelligence stack and avoids
     * treating untouched drafts as analyzed case knowledge.
     */
    $stmt = $pdo->query("
        SELECT
            r.id
        FROM reports r

        INNER JOIN report_analysis ra
            ON ra.report_id = r.id

        WHERE r.worker_id IS NOT NULL
          AND r.worker_id > 0

        ORDER BY
            r.worker_id,
            r.report_date,
            r.id
    ");


    $reportIds =
        array_map(
            'intval',
            $stmt->fetchAll(
                PDO::FETCH_COLUMN
            )
        );


    $result = [];

    foreach ($reportIds as $reportId) {

        $result[] =
            rebuild_case_events_for_report(
                $reportId
            );
    }


    return $result;
}
