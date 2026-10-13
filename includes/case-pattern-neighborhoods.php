<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/case-patterns.php';
require_once __DIR__ . '/effective-metadata.php';


/*
 * =========================================================
 * PATTERN NEIGHBORHOOD / PROVENANCE MEMORY
 * =========================================================
 *
 * This layer answers:
 *
 *   What else occurred in the reports supporting a pattern?
 *
 * It does NOT claim:
 *
 *   causation
 *   motive
 *   wrongdoing
 *   legal significance
 *   semantic dependence
 *
 * "Neighbor" means documented co-occurrence in supporting
 * source reports unless a stronger relationship is explicitly
 * stored later.
 *
 * The model is occupation-neutral.
 */


function ensure_case_pattern_neighborhood_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_pattern_neighbors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            pattern_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,

            neighbor_dimension TEXT NOT NULL,
            neighbor_key TEXT NOT NULL,
            neighbor_value TEXT NOT NULL,

            relationship_scope TEXT NOT NULL DEFAULT 'same_report',

            report_count INTEGER NOT NULL DEFAULT 0,
            occurrence_count INTEGER NOT NULL DEFAULT 0,

            first_seen TEXT,
            last_seen TEXT,

            recurrence TEXT NOT NULL DEFAULT 'single_association',
            confidence TEXT NOT NULL DEFAULT 'low',

            summary_eligible INTEGER NOT NULL DEFAULT 0,

            reasoning TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            refreshed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                pattern_id,
                neighbor_dimension,
                neighbor_key
            )
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_pattern_neighbor_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            neighbor_id INTEGER NOT NULL,
            pattern_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,
            report_id INTEGER NOT NULL,

            source_kind TEXT NOT NULL,
            source_row_id INTEGER,
            source_excerpt TEXT,

            report_date TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                neighbor_id,
                report_id,
                source_kind,
                source_row_id
            )
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_pattern_neighbors_pattern
        ON case_pattern_neighbors (
            pattern_id,
            neighbor_dimension,
            report_count
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_pattern_neighbors_worker
        ON case_pattern_neighbors (
            worker_id,
            neighbor_dimension,
            neighbor_key
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_pattern_neighbor_sources_neighbor
        ON case_pattern_neighbor_sources (
            neighbor_id,
            report_id
        )
    ");
}


function case_pattern_neighbor_normalize(
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


function case_pattern_neighbor_report_ids(
    int $patternId,
    int $workerId
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT DISTINCT report_id
        FROM case_pattern_reports
        WHERE pattern_id = ?
          AND worker_id = ?
        ORDER BY report_id
    ");

    $stmt->execute([
        $patternId,
        $workerId
    ]);

    return array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
}


function case_pattern_neighbor_confidence(
    int $reportCount
): string {

    if ($reportCount >= 3) {
        return 'high';
    }

    if ($reportCount >= 2) {
        return 'medium';
    }

    return 'low';
}


function case_pattern_neighbor_recurrence(
    int $reportCount
): string {

    return $reportCount >= 2
        ? 'repeated_association'
        : 'single_association';
}


function case_pattern_neighbor_collect_reports(
    array $reportIds
): array {

    if (!$reportIds) {
        return [];
    }

    $pdo = db();

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($reportIds),
                '?'
            )
        );

    $stmt = $pdo->prepare("
        SELECT
            id,
            worker_id,
            report_date,
            department,
            machine,
            original_text,
            polished_text
        FROM reports
        WHERE id IN ({$placeholders})
        ORDER BY report_date, id
    ");

    $stmt->execute($reportIds);

    $rows =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

    $indexed = [];

    foreach ($rows as $row) {
        $indexed[(int) $row['id']] = $row;
    }

    return $indexed;
}


function case_pattern_neighbor_add(
    array &$neighbors,
    string $dimension,
    string $value,
    int $reportId,
    ?string $reportDate,
    string $sourceKind,
    ?int $sourceRowId,
    ?string $sourceExcerpt
): void {

    $value = trim($value);

    if ($value === '') {
        return;
    }

    $key =
        case_pattern_neighbor_normalize(
            $value
        );

    if ($key === '') {
        return;
    }

    $compound =
        $dimension . '|' . $key;

    if (!isset($neighbors[$compound])) {

        $neighbors[$compound] = [
            'dimension' =>
                $dimension,

            'key' =>
                $key,

            'value' =>
                $value,

            'reports' =>
                [],

            'sources' =>
                [],
        ];
    }

    $neighbors[$compound]['reports'][$reportId] =
        $reportDate;

    $neighbors[$compound]['sources'][] = [
        'report_id' =>
            $reportId,

        'report_date' =>
            $reportDate,

        'source_kind' =>
            $sourceKind,

        'source_row_id' =>
            $sourceRowId,

        'source_excerpt' =>
            $sourceExcerpt,
    ];
}


function case_pattern_neighbor_collect(
    int $patternId,
    int $workerId,
    string $patternDimension,
    string $patternValue
): array {

    $pdo = db();

    $reportIds =
        case_pattern_neighbor_report_ids(
            $patternId,
            $workerId
        );

    if (!$reportIds) {
        return [];
    }

    $reports =
        case_pattern_neighbor_collect_reports(
            $reportIds
        );

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($reportIds),
                '?'
            )
        );

    $neighbors = [];


    /*
     * -----------------------------------------------------
     * PEOPLE
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT
            rp.report_id,
            rp.person_id,
            rp.role,
            p.name
        FROM report_people rp

        INNER JOIN people p
            ON p.id = rp.person_id

        WHERE rp.report_id IN ({$placeholders})

        ORDER BY
            rp.report_id,
            p.name
    ");

    $stmt->execute($reportIds);

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {

        $reportId =
            (int) $row['report_id'];

        case_pattern_neighbor_add(
            $neighbors,
            'person',
            (string) $row['name'],
            $reportId,
            $reports[$reportId]['report_date']
                ?? null,
            'report_person',
            (int) $row['person_id'],
            $row['role']
                ? 'Role: ' . $row['role']
                : null
        );
    }


    /*
     * -----------------------------------------------------
     * PLACES
     *
     * Place must actually occur in the preserved narrative.
     * This prevents structured metadata contamination from
     * manufacturing co-occurrence.
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT
            rp.report_id,
            rp.place_id,
            p.name,
            p.place_type
        FROM report_places rp

        INNER JOIN places p
            ON p.id = rp.place_id

        WHERE rp.report_id IN ({$placeholders})

        ORDER BY
            rp.report_id,
            p.name
    ");

    $stmt->execute($reportIds);

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {

        $reportId =
            (int) $row['report_id'];

        $report =
            $reports[$reportId]
            ?? null;

        if (!$report) {
            continue;
        }

        $sourceText =
            trim(
                (string) (
                    $report['original_text']
                    ?? ''
                )
                . "\n"
                . (string) (
                    $report['polished_text']
                    ?? ''
                )
            );

        $place =
            trim(
                (string) $row['name']
            );

        if (
            !effective_metadata_phrase_present(
                $sourceText,
                $place
            )
        ) {
            continue;
        }

        case_pattern_neighbor_add(
            $neighbors,
            'place',
            $place,
            $reportId,
            $report['report_date']
                ?? null,
            'report_place',
            (int) $row['place_id'],
            $row['place_type']
                ?? null
        );
    }


    /*
     * -----------------------------------------------------
     * CATEGORIES
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT
            rc.report_id,
            rc.category_id,
            c.name
        FROM report_categories rc

        INNER JOIN categories c
            ON c.id = rc.category_id

        WHERE rc.report_id IN ({$placeholders})

        ORDER BY
            rc.report_id,
            c.name
    ");

    $stmt->execute($reportIds);

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {

        $reportId =
            (int) $row['report_id'];

        case_pattern_neighbor_add(
            $neighbors,
            'category',
            (string) $row['name'],
            $reportId,
            $reports[$reportId]['report_date']
                ?? null,
            'report_category',
            (int) $row['category_id'],
            null
        );
    }


    /*
     * -----------------------------------------------------
     * OPERATIONAL ISSUES
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT
            id,
            report_id,
            finding_type,
            observed_fact,
            source_excerpt
        FROM report_operational_findings

        WHERE report_id IN ({$placeholders})
          AND finding_type IS NOT NULL
          AND TRIM(finding_type) <> ''

        ORDER BY
            report_id,
            finding_type,
            id
    ");

    $stmt->execute($reportIds);

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {

        $reportId =
            (int) $row['report_id'];

        $excerpt =
            trim(
                (string) (
                    $row['source_excerpt']
                    ?? ''
                )
            );

        if ($excerpt === '') {
            $excerpt =
                trim(
                    (string) (
                        $row['observed_fact']
                        ?? ''
                    )
                );
        }

        case_pattern_neighbor_add(
            $neighbors,
            'operational_issue',
            (string) $row['finding_type'],
            $reportId,
            $reports[$reportId]['report_date']
                ?? null,
            'operational_finding',
            (int) $row['id'],
            $excerpt !== ''
                ? $excerpt
                : null
        );
    }


    /*
     * -----------------------------------------------------
     * MATERIAL FACT TYPES
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT
            id,
            report_id,
            fact_type,
            fact_text,
            importance
        FROM case_material_facts

        WHERE worker_id = ?
          AND report_id IN ({$placeholders})
          AND fact_type IS NOT NULL
          AND TRIM(fact_type) <> ''
          AND LOWER(TRIM(fact_type)) NOT IN (
              'general',
              'context'
          )
          AND importance <> 'context'

        ORDER BY
            report_id,
            fact_type,
            id
    ");

    $stmt->execute(
        array_merge(
            [$workerId],
            $reportIds
        )
    );

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {

        $reportId =
            (int) $row['report_id'];

        case_pattern_neighbor_add(
            $neighbors,
            'fact_type',
            (string) $row['fact_type'],
            $reportId,
            $reports[$reportId]['report_date']
                ?? null,
            'material_fact',
            (int) $row['id'],
            (string) $row['fact_text']
        );
    }


    /*
     * -----------------------------------------------------
     * EFFECTIVE PRIMARY WORK AREA
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT
            id,
            report_id,
            effective_value,
            interpretation_status,
            reason
        FROM case_effective_metadata

        WHERE worker_id = ?
          AND report_id IN ({$placeholders})
          AND field_name = 'primary_work_area'
          AND effective_value IS NOT NULL
          AND TRIM(effective_value) <> ''
          AND interpretation_status NOT IN (
              'unresolved',
              'ambiguous'
          )

        ORDER BY
            report_id,
            id
    ");

    $stmt->execute(
        array_merge(
            [$workerId],
            $reportIds
        )
    );

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {

        $reportId =
            (int) $row['report_id'];

        case_pattern_neighbor_add(
            $neighbors,
            'primary_work_area',
            (string) $row['effective_value'],
            $reportId,
            $reports[$reportId]['report_date']
                ?? null,
            'effective_metadata',
            (int) $row['id'],
            $row['reason']
                ?? null
        );
    }


    /*
     * -----------------------------------------------------
     * DEPARTMENT / ORGANIZATIONAL CONTEXT
     * -----------------------------------------------------
     */

    foreach ($reports as $reportId => $report) {

        $department =
            trim(
                (string) (
                    $report['department']
                    ?? ''
                )
            );

        if ($department === '') {
            continue;
        }

        case_pattern_neighbor_add(
            $neighbors,
            'department',
            $department,
            (int) $reportId,
            $report['report_date']
                ?? null,
            'submitted_department',
            (int) $reportId,
            null
        );
    }


    /*
     * -----------------------------------------------------
     * PRESERVED ATTACHMENT / EVIDENCE TYPE
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT
            id,
            report_id,
            filename,
            description,
            evidence_type,
            evidence_class,
            captured_at,
            uploaded_at
        FROM attachments

        WHERE report_id IN ({$placeholders})
          AND status = 'active'

        ORDER BY
            report_id,
            id
    ");

    $stmt->execute($reportIds);

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {

        $reportId =
            (int) $row['report_id'];

        $evidenceType =
            trim(
                (string) (
                    $row['evidence_type']
                    ?? ''
                )
            );

        if ($evidenceType === '') {
            $evidenceType = 'Other';
        }

        $excerpt =
            trim(
                (string) (
                    $row['description']
                    ?? ''
                )
            );

        if ($excerpt === '') {
            $excerpt =
                trim(
                    (string) (
                        $row['filename']
                        ?? ''
                    )
                );
        }

        case_pattern_neighbor_add(
            $neighbors,
            'evidence_type',
            $evidenceType,
            $reportId,
            $reports[$reportId]['report_date']
                ?? null,
            'attachment',
            (int) $row['id'],
            $excerpt !== ''
                ? $excerpt
                : null
        );
    }


    /*
     * -----------------------------------------------------
     * REMOVE THE PATTERN FROM ITS OWN NEIGHBOR LIST
     * -----------------------------------------------------
     *
     * Example:
     *
     * PERSON | Brent Knight
     *
     * should not list
     *
     * PERSON | Brent Knight
     *
     * as a related neighbor.
     */

    $selfKey =
        $patternDimension
        . '|'
        . case_pattern_neighbor_normalize(
            $patternValue
        );

    unset(
        $neighbors[$selfKey]
    );


    return $neighbors;
}


function case_pattern_neighbor_save(
    int $patternId,
    int $workerId,
    array $neighbor
): ?int {

    $pdo = db();

    $reportCount =
        count(
            $neighbor['reports']
        );

    $occurrenceCount =
        count(
            $neighbor['sources']
        );

    if ($reportCount < 1) {
        return null;
    }

    $dates =
        array_values(
            array_filter(
                array_map(
                    static fn ($value) =>
                        trim((string) $value),
                    $neighbor['reports']
                )
            )
        );

    sort($dates);

    $firstSeen =
        $dates
            ? $dates[0]
            : null;

    $lastSeen =
        $dates
            ? $dates[count($dates) - 1]
            : null;

    $recurrence =
        case_pattern_neighbor_recurrence(
            $reportCount
        );

    $confidence =
        case_pattern_neighbor_confidence(
            $reportCount
        );

    /*
     * Summary eligibility requires recurrence.
     *
     * Single-report neighbors remain stored and traceable,
     * but should not automatically appear in synthesis.
     */
    $summaryEligible =
        $reportCount >= 2
            ? 1
            : 0;

    $reasoning =
        $reportCount >= 2
            ? "This {$neighbor['dimension']} appears in {$reportCount} distinct reports supporting the pattern."
            : "This {$neighbor['dimension']} appears in one report supporting the pattern; it is preserved as context but is not itself recurrent.";


    $stmt = $pdo->prepare("
        INSERT INTO case_pattern_neighbors (
            pattern_id,
            worker_id,
            neighbor_dimension,
            neighbor_key,
            neighbor_value,
            relationship_scope,
            report_count,
            occurrence_count,
            first_seen,
            last_seen,
            recurrence,
            confidence,
            summary_eligible,
            reasoning,
            refreshed_at
        )
        VALUES (
            :pattern_id,
            :worker_id,
            :neighbor_dimension,
            :neighbor_key,
            :neighbor_value,
            'same_report',
            :report_count,
            :occurrence_count,
            :first_seen,
            :last_seen,
            :recurrence,
            :confidence,
            :summary_eligible,
            :reasoning,
            CURRENT_TIMESTAMP
        )

        ON CONFLICT (
            pattern_id,
            neighbor_dimension,
            neighbor_key
        )
        DO UPDATE SET
            neighbor_value =
                excluded.neighbor_value,

            relationship_scope =
                excluded.relationship_scope,

            report_count =
                excluded.report_count,

            occurrence_count =
                excluded.occurrence_count,

            first_seen =
                excluded.first_seen,

            last_seen =
                excluded.last_seen,

            recurrence =
                excluded.recurrence,

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
        ':pattern_id' =>
            $patternId,

        ':worker_id' =>
            $workerId,

        ':neighbor_dimension' =>
            $neighbor['dimension'],

        ':neighbor_key' =>
            $neighbor['key'],

        ':neighbor_value' =>
            $neighbor['value'],

        ':report_count' =>
            $reportCount,

        ':occurrence_count' =>
            $occurrenceCount,

        ':first_seen' =>
            $firstSeen,

        ':last_seen' =>
            $lastSeen,

        ':recurrence' =>
            $recurrence,

        ':confidence' =>
            $confidence,

        ':summary_eligible' =>
            $summaryEligible,

        ':reasoning' =>
            $reasoning,
    ]);


    $find = $pdo->prepare("
        SELECT id
        FROM case_pattern_neighbors
        WHERE pattern_id = ?
          AND neighbor_dimension = ?
          AND neighbor_key = ?
        LIMIT 1
    ");

    $find->execute([
        $patternId,
        $neighbor['dimension'],
        $neighbor['key'],
    ]);

    $neighborId =
        (int) $find->fetchColumn();

    if ($neighborId < 1) {
        return null;
    }


    foreach (
        $neighbor['sources']
        as $source
    ) {

        $save = $pdo->prepare("
            INSERT OR IGNORE INTO case_pattern_neighbor_sources (
                neighbor_id,
                pattern_id,
                worker_id,
                report_id,
                source_kind,
                source_row_id,
                source_excerpt,
                report_date
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $save->execute([
            $neighborId,
            $patternId,
            $workerId,
            (int) $source['report_id'],
            $source['source_kind'],
            $source['source_row_id'],
            $source['source_excerpt'],
            $source['report_date'],
        ]);
    }


    return $neighborId;
}


function rebuild_case_pattern_neighborhoods_for_worker(
    int $workerId
): int {

    ensure_case_pattern_neighborhood_schema();

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            pattern_dimension,
            pattern_value
        FROM case_patterns
        WHERE worker_id = ?
        ORDER BY id
    ");

    $stmt->execute([
        $workerId
    ]);

    $patterns =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /*
     * Remove only derived neighborhood memory.
     */
    $patternIds =
        array_map(
            static fn ($row) =>
                (int) $row['id'],
            $patterns
        );

    if ($patternIds) {

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($patternIds),
                    '?'
                )
            );

        $pdo->prepare("
            DELETE FROM case_pattern_neighbor_sources
            WHERE pattern_id IN ({$placeholders})
        ")->execute($patternIds);

        $pdo->prepare("
            DELETE FROM case_pattern_neighbors
            WHERE pattern_id IN ({$placeholders})
        ")->execute($patternIds);
    }


    $created = 0;

    foreach ($patterns as $pattern) {

        $patternId =
            (int) $pattern['id'];

        $neighbors =
            case_pattern_neighbor_collect(
                $patternId,
                $workerId,
                (string) $pattern['pattern_dimension'],
                (string) $pattern['pattern_value']
            );

        foreach ($neighbors as $neighbor) {

            if (
                case_pattern_neighbor_save(
                    $patternId,
                    $workerId,
                    $neighbor
                )
            ) {
                $created++;
            }
        }
    }

    return $created;
}


function rebuild_all_case_pattern_neighborhoods(): array
{
    ensure_case_pattern_neighborhood_schema();

    $pdo = db();

    $workerIds =
        $pdo->query("
            SELECT DISTINCT worker_id
            FROM case_patterns
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
            rebuild_case_pattern_neighborhoods_for_worker(
                $workerId
            );
    }

    return $result;
}
