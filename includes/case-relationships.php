<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/effective-metadata.php';


/**
 * Cross-report relationship memory.
 *
 * IMPORTANT:
 * - Raw reports are never modified.
 * - Relationships are derived/indexed intelligence only.
 * - Every relationship is hard-scoped by worker_id.
 * - A relationship means "documented shared elements,"
 *   not causation, misconduct, liability, or legal proof.
 */

function ensure_case_relationship_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_report_relationships (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            worker_id INTEGER NOT NULL,

            report_a_id INTEGER NOT NULL,
            report_b_id INTEGER NOT NULL,

            report_a_date TEXT,
            report_b_date TEXT,
            day_gap INTEGER,

            relationship_score INTEGER NOT NULL DEFAULT 0,
            confidence TEXT NOT NULL DEFAULT 'weak',

            shared_person_count INTEGER NOT NULL DEFAULT 0,
            shared_place_count INTEGER NOT NULL DEFAULT 0,
            shared_category_count INTEGER NOT NULL DEFAULT 0,
            shared_operational_count INTEGER NOT NULL DEFAULT 0,

            same_machine INTEGER NOT NULL DEFAULT 0,
            same_department INTEGER NOT NULL DEFAULT 0,

            relationship_summary TEXT,
            relationship_data TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            refreshed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                worker_id,
                report_a_id,
                report_b_id
            )
        )
    ");

    /*
     * Relationship intelligence evolves separately from
     * immutable source reports.
     */
    $columns = $pdo
        ->query("
            PRAGMA table_info(case_report_relationships)
        ")
        ->fetchAll(PDO::FETCH_ASSOC);

    $columnNames = array_column(
        $columns,
        'name'
    );

    if (
        !in_array(
            'same_primary_work_area',
            $columnNames,
            true
        )
    ) {
        $pdo->exec("
            ALTER TABLE case_report_relationships
            ADD COLUMN same_primary_work_area
            INTEGER NOT NULL DEFAULT 0
        ");
    }

    if (
        !in_array(
            'primary_work_area',
            $columnNames,
            true
        )
    ) {
        $pdo->exec("
            ALTER TABLE case_report_relationships
            ADD COLUMN primary_work_area TEXT
        ");
    }

    if (
        !in_array(
            'primary_work_area_strength',
            $columnNames,
            true
        )
    ) {
        $pdo->exec("
            ALTER TABLE case_report_relationships
            ADD COLUMN primary_work_area_strength TEXT
        ");
    }


    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_relationship_worker
        ON case_report_relationships (
            worker_id,
            report_a_date,
            report_b_date
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_relationship_report_a
        ON case_report_relationships (
            worker_id,
            report_a_id
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_relationship_report_b
        ON case_report_relationships (
            worker_id,
            report_b_id
        )
    ");
}


function case_relationship_report(
    int $workerId,
    int $reportId
): ?array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            worker_id,
            report_date,
            department,
            machine,
            title,
            original_text,
            polished_text
        FROM reports
        WHERE id = ?
          AND worker_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $reportId,
        $workerId
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}


function case_relationship_shared_ids(
    string $table,
    string $column,
    int $reportA,
    int $reportB
): array {

    /*
     * Table/column names are internal constants only.
     * Do not pass user input here.
     */

    $allowed = [
        'report_people' => 'person_id',
        'report_places' => 'place_id',
        'report_categories' => 'category_id',
    ];

    if (
        !isset($allowed[$table])
        || $allowed[$table] !== $column
    ) {
        throw new InvalidArgumentException(
            'Unsupported relationship source.'
        );
    }

    $pdo = db();

    $sql = "
        SELECT {$column}
        FROM {$table}
        WHERE report_id = ?

        INTERSECT

        SELECT {$column}
        FROM {$table}
        WHERE report_id = ?

        ORDER BY {$column}
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        $reportA,
        $reportB
    ]);

    return array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
}



function case_relationship_source_text(
    array $report
): string {

    $polished = trim(
        (string) (
            $report['polished_text']
            ?? ''
        )
    );

    $original = trim(
        (string) (
            $report['original_text']
            ?? ''
        )
    );

    /*
     * Search both preserved representations.
     *
     * This does not alter either source.
     */
    return trim(
        $polished . "\n" . $original
    );
}


function case_relationship_text_contains(
    string $sourceText,
    string $needle
): bool {

    $sourceText = trim($sourceText);
    $needle = trim($needle);

    if (
        $sourceText === ''
        || $needle === ''
    ) {
        return false;
    }

    return mb_stripos(
        $sourceText,
        $needle
    ) !== false;
}


function case_relationship_entity_name(
    string $table,
    int $id
): ?string {

    $allowed = [
        'people',
        'places',
        'categories',
    ];

    if (!in_array(
        $table,
        $allowed,
        true
    )) {
        throw new InvalidArgumentException(
            'Unsupported relationship entity table.'
        );
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT name
        FROM {$table}
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $name = $stmt->fetchColumn();

    if ($name === false) {
        return null;
    }

    $name = trim((string) $name);

    return $name !== ''
        ? $name
        : null;
}


function case_relationship_filter_ids_by_source(
    array $ids,
    string $entityTable,
    array $reportA,
    array $reportB
): array {

    $sourceA =
        case_relationship_source_text(
            $reportA
        );

    $sourceB =
        case_relationship_source_text(
            $reportB
        );

    $verified = [];

    foreach ($ids as $id) {

        $id = (int) $id;

        $name =
            case_relationship_entity_name(
                $entityTable,
                $id
            );

        if (!$name) {
            continue;
        }

        /*
         * Derived relationship evidence must be supported
         * by BOTH underlying report narratives.
         */
        if (
            !case_relationship_text_contains(
                $sourceA,
                $name
            )
            ||
            !case_relationship_text_contains(
                $sourceB,
                $name
            )
        ) {
            continue;
        }

        $verified[] = $id;
    }

    return $verified;
}


function case_relationship_shared_operational_types(
    int $reportA,
    int $reportB
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT finding_type
        FROM report_operational_findings
        WHERE report_id = ?
          AND finding_type IS NOT NULL
          AND TRIM(finding_type) <> ''

        INTERSECT

        SELECT finding_type
        FROM report_operational_findings
        WHERE report_id = ?
          AND finding_type IS NOT NULL
          AND TRIM(finding_type) <> ''

        ORDER BY finding_type
    ");

    $stmt->execute([
        $reportA,
        $reportB
    ]);

    return array_values(
        array_filter(
            array_map(
                static fn ($value) =>
                    trim((string) $value),
                $stmt->fetchAll(PDO::FETCH_COLUMN)
            )
        )
    );
}



function case_relationship_effective_work_area(
    int $workerId,
    int $reportId
): ?array {

    ensure_effective_metadata_schema();

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            effective_value,
            interpretation_status,
            confidence,
            confidence_score
        FROM case_effective_metadata
        WHERE worker_id = ?
          AND report_id = ?
          AND field_name = 'primary_work_area'
        LIMIT 1
    ");

    $stmt->execute([
        $workerId,
        $reportId
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $value = trim(
        (string) (
            $row['effective_value']
            ?? ''
        )
    );

    if ($value === '') {
        return null;
    }

    $status = trim(
        (string) (
            $row['interpretation_status']
            ?? 'unresolved'
        )
    );

    /*
     * These states do not establish a usable primary area.
     */
    if (
        in_array(
            $status,
            [
                'unresolved',
                'ambiguous',
            ],
            true
        )
    ) {
        return null;
    }

    return [
        'value' =>
            $value,

        'normalized_value' =>
            effective_metadata_normalize(
                $value
            ),

        'status' =>
            $status,

        'confidence' =>
            (string) (
                $row['confidence']
                ?? 'low'
            ),

        'confidence_score' =>
            (int) (
                $row['confidence_score']
                ?? 0
            ),
    ];
}


function case_relationship_work_area_strength(
    ?array $a,
    ?array $b
): string {

    if (
        !$a
        || !$b
    ) {
        return 'none';
    }

    if (
        $a['normalized_value']
        !== $b['normalized_value']
    ) {
        return 'none';
    }

    $strongStatuses = [
        'confirmed',
        'derived',
        'derived_conflict',
    ];

    $aStrong =
        in_array(
            $a['status'],
            $strongStatuses,
            true
        );

    $bStrong =
        in_array(
            $b['status'],
            $strongStatuses,
            true
        );

    if (
        $aStrong
        && $bStrong
    ) {
        return 'strong';
    }

    if (
        $aStrong
        || $bStrong
    ) {
        return 'moderate';
    }

    /*
     * Both may be submitted_only.
     *
     * That is still a possible relationship signal,
     * but deliberately weak.
     */
    return 'weak';
}


function case_relationship_day_gap(
    ?string $dateA,
    ?string $dateB
): ?int {

    if (!$dateA || !$dateB) {
        return null;
    }

    try {

        $a = new DateTimeImmutable($dateA);
        $b = new DateTimeImmutable($dateB);

        return (int) abs(
            $a->diff($b)->days
        );

    } catch (Throwable $e) {

        return null;
    }
}


function case_relationship_same_text(
    ?string $a,
    ?string $b
): bool {

    $a = trim((string) $a);
    $b = trim((string) $b);

    if (
        $a === ''
        || $b === ''
    ) {
        return false;
    }

    return mb_strtolower($a)
        === mb_strtolower($b);
}


function case_relationship_confidence(
    int $score
): string {

    if ($score >= 16) {
        return 'strong';
    }

    if ($score >= 8) {
        return 'moderate';
    }

    return 'weak';
}


function case_relationship_summary(
    array $details
): string {

    $parts = [];

    if ($details['shared_person_count'] > 0) {

        $count =
            $details['shared_person_count'];

        $parts[] =
            $count === 1
                ? '1 shared person'
                : "{$count} shared people";
    }

    if ($details['shared_place_count'] > 0) {

        $count =
            $details['shared_place_count'];

        $parts[] =
            $count === 1
                ? '1 shared place'
                : "{$count} shared places";
    }

    if ($details['shared_category_count'] > 0) {

        $count =
            $details['shared_category_count'];

        $parts[] =
            $count === 1
                ? '1 shared category'
                : "{$count} shared categories";
    }

    if ($details['shared_operational_count'] > 0) {

        $count =
            $details['shared_operational_count'];

        $parts[] =
            $count === 1
                ? '1 shared operational issue'
                : "{$count} shared operational issues";
    }

    if (
        !empty(
            $details['same_primary_work_area']
        )
    ) {

        $value = trim(
            (string) (
                $details['primary_work_area']
                ?? ''
            )
        );

        if ($value !== '') {

            $parts[] =
                "same primary work area ({$value})";

        } else {

            $parts[] =
                'same primary work area';
        }
    }

    if ($details['same_department']) {
        $parts[] = 'same department';
    }

    if (!$parts) {
        return 'No substantial shared case elements detected.';
    }

    return ucfirst(
        implode(', ', $parts)
    ) . '.';
}


function analyze_case_report_relationship(
    int $workerId,
    int $reportAId,
    int $reportBId
): ?array {

    if ($reportAId === $reportBId) {
        return null;
    }

    /*
     * Canonical ordering prevents A→B and B→A duplicates.
     */
    if ($reportAId > $reportBId) {

        [$reportAId, $reportBId] =
            [$reportBId, $reportAId];
    }

    $reportA =
        case_relationship_report(
            $workerId,
            $reportAId
        );

    $reportB =
        case_relationship_report(
            $workerId,
            $reportBId
        );

    if (
        !$reportA
        || !$reportB
    ) {
        return null;
    }


    $sharedPeople =
        case_relationship_shared_ids(
            'report_people',
            'person_id',
            $reportAId,
            $reportBId
        );

    $sharedPeople =
        case_relationship_filter_ids_by_source(
            $sharedPeople,
            'people',
            $reportA,
            $reportB
        );

    $sharedPlaces =
        case_relationship_shared_ids(
            'report_places',
            'place_id',
            $reportAId,
            $reportBId
        );

    $sharedPlaces =
        case_relationship_filter_ids_by_source(
            $sharedPlaces,
            'places',
            $reportA,
            $reportB
        );

    $sharedCategories =
        case_relationship_shared_ids(
            'report_categories',
            'category_id',
            $reportAId,
            $reportBId
        );

    $sharedOperational =
        case_relationship_shared_operational_types(
            $reportAId,
            $reportBId
        );


    /*
     * Raw machine comparison is retained as provenance only.
     *
     * We do NOT let this raw field strengthen relationship
     * scoring because submitted metadata can be incomplete
     * or incorrect.
     */
    $sameMachine =
        case_relationship_same_text(
            $reportA['machine'] ?? null,
            $reportB['machine'] ?? null
        );


    /*
     * Preferred relationship signal:
     * derived effective primary work area.
     */
    $workAreaA =
        case_relationship_effective_work_area(
            $workerId,
            $reportAId
        );

    $workAreaB =
        case_relationship_effective_work_area(
            $workerId,
            $reportBId
        );

    $workAreaStrength =
        case_relationship_work_area_strength(
            $workAreaA,
            $workAreaB
        );

    $samePrimaryWorkArea =
        $workAreaStrength !== 'none';

    $primaryWorkArea =
        $samePrimaryWorkArea
            ? (string) $workAreaA['value']
            : null;


    $sameDepartment =
        case_relationship_same_text(
            $reportA['department'] ?? null,
            $reportB['department'] ?? null
        );


    /*
     * Conservative scoring.
     *
     * This score ranks relatedness.
     * It is NOT a legal or factual confidence score.
     */
    $score = 0;

    $score += min(
        count($sharedPeople),
        5
    ) * 4;

    $score += min(
        count($sharedPlaces),
        4
    ) * 5;

    $score += min(
        count($sharedCategories),
        5
    ) * 3;

    $score += min(
        count($sharedOperational),
        5
    ) * 4;

    /*
     * Effective work area, not raw submitted machine data,
     * strengthens the relationship.
     */
    if ($workAreaStrength === 'strong') {

        $score += 6;

    } elseif ($workAreaStrength === 'moderate') {

        $score += 3;

    } elseif ($workAreaStrength === 'weak') {

        $score += 1;
    }

    if ($sameDepartment) {
        $score += 2;
    }


    /*
     * Require at least one meaningful connection.
     *
     * Weak-but-real relationships may still be stored.
     * Completely unrelated report pairs are not.
     */
    if ($score < 4) {
        return null;
    }


    $details = [
        'shared_person_ids' =>
            $sharedPeople,

        'shared_place_ids' =>
            $sharedPlaces,

        'shared_category_ids' =>
            $sharedCategories,

        'shared_operational_types' =>
            $sharedOperational,

        'shared_person_count' =>
            count($sharedPeople),

        'shared_place_count' =>
            count($sharedPlaces),

        'shared_category_count' =>
            count($sharedCategories),

        'shared_operational_count' =>
            count($sharedOperational),

        'same_machine' =>
            $sameMachine,

        'machine' =>
            $sameMachine
                ? trim(
                    (string) $reportA['machine']
                )
                : null,

        'same_primary_work_area' =>
            $samePrimaryWorkArea,

        'primary_work_area' =>
            $primaryWorkArea,

        'primary_work_area_strength' =>
            $workAreaStrength,

        'same_department' =>
            $sameDepartment,

        'department' =>
            $sameDepartment
                ? trim(
                    (string) $reportA['department']
                )
                : null,
    ];


    return [
        'worker_id' =>
            $workerId,

        'report_a_id' =>
            $reportAId,

        'report_b_id' =>
            $reportBId,

        'report_a_date' =>
            $reportA['report_date'] ?? null,

        'report_b_date' =>
            $reportB['report_date'] ?? null,

        'day_gap' =>
            case_relationship_day_gap(
                $reportA['report_date'] ?? null,
                $reportB['report_date'] ?? null
            ),

        'relationship_score' =>
            $score,

        'confidence' =>
            case_relationship_confidence(
                $score
            ),

        'shared_person_count' =>
            count($sharedPeople),

        'shared_place_count' =>
            count($sharedPlaces),

        'shared_category_count' =>
            count($sharedCategories),

        'shared_operational_count' =>
            count($sharedOperational),

        'same_machine' =>
            $sameMachine ? 1 : 0,

        'same_primary_work_area' =>
            $samePrimaryWorkArea ? 1 : 0,

        'primary_work_area' =>
            $primaryWorkArea,

        'primary_work_area_strength' =>
            $workAreaStrength,

        'same_department' =>
            $sameDepartment ? 1 : 0,

        'relationship_summary' =>
            case_relationship_summary(
                $details
            ),

        'relationship_data' =>
            json_encode(
                $details,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            ),
    ];
}


function save_case_report_relationship(
    array $relationship
): void {

    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO case_report_relationships (
            worker_id,
            report_a_id,
            report_b_id,
            report_a_date,
            report_b_date,
            day_gap,
            relationship_score,
            confidence,
            shared_person_count,
            shared_place_count,
            shared_category_count,
            shared_operational_count,
            same_machine,
            same_primary_work_area,
            primary_work_area,
            primary_work_area_strength,
            same_department,
            relationship_summary,
            relationship_data,
            refreshed_at
        )
        VALUES (
            :worker_id,
            :report_a_id,
            :report_b_id,
            :report_a_date,
            :report_b_date,
            :day_gap,
            :relationship_score,
            :confidence,
            :shared_person_count,
            :shared_place_count,
            :shared_category_count,
            :shared_operational_count,
            :same_machine,
            :same_primary_work_area,
            :primary_work_area,
            :primary_work_area_strength,
            :same_department,
            :relationship_summary,
            :relationship_data,
            CURRENT_TIMESTAMP
        )

        ON CONFLICT (
            worker_id,
            report_a_id,
            report_b_id
        )
        DO UPDATE SET
            report_a_date =
                excluded.report_a_date,

            report_b_date =
                excluded.report_b_date,

            day_gap =
                excluded.day_gap,

            relationship_score =
                excluded.relationship_score,

            confidence =
                excluded.confidence,

            shared_person_count =
                excluded.shared_person_count,

            shared_place_count =
                excluded.shared_place_count,

            shared_category_count =
                excluded.shared_category_count,

            shared_operational_count =
                excluded.shared_operational_count,

            same_machine =
                excluded.same_machine,

            same_primary_work_area =
                excluded.same_primary_work_area,

            primary_work_area =
                excluded.primary_work_area,

            primary_work_area_strength =
                excluded.primary_work_area_strength,

            same_department =
                excluded.same_department,

            relationship_summary =
                excluded.relationship_summary,

            relationship_data =
                excluded.relationship_data,

            refreshed_at =
                CURRENT_TIMESTAMP
    ");

    $stmt->execute(
        $relationship
    );
}


function rebuild_case_relationships_for_worker(
    int $workerId
): int {

    ensure_case_relationship_schema();

    $pdo = db();

    /*
     * Only this worker's derived relationships are rebuilt.
     */
    $delete = $pdo->prepare("
        DELETE FROM case_report_relationships
        WHERE worker_id = ?
    ");

    $delete->execute([
        $workerId
    ]);


    $stmt = $pdo->prepare("
        SELECT id
        FROM reports
        WHERE worker_id = ?
        ORDER BY
            report_date,
            id
    ");

    $stmt->execute([
        $workerId
    ]);

    $reportIds = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );


    $saved = 0;
    $count = count($reportIds);

    for ($i = 0; $i < $count; $i++) {

        for (
            $j = $i + 1;
            $j < $count;
            $j++
        ) {

            $relationship =
                analyze_case_report_relationship(
                    $workerId,
                    $reportIds[$i],
                    $reportIds[$j]
                );

            if (!$relationship) {
                continue;
            }

            save_case_report_relationship(
                $relationship
            );

            $saved++;
        }
    }

    return $saved;
}


function rebuild_all_case_relationships(): array
{
    ensure_case_relationship_schema();

    $pdo = db();

    $workerIds = $pdo
        ->query("
            SELECT DISTINCT worker_id
            FROM reports
            WHERE worker_id IS NOT NULL
            ORDER BY worker_id
        ")
        ->fetchAll(PDO::FETCH_COLUMN);

    $result = [];

    foreach ($workerIds as $workerId) {

        $workerId = (int) $workerId;

        $result[$workerId] =
            rebuild_case_relationships_for_worker(
                $workerId
            );
    }

    return $result;
}
