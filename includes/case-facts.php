<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/operational-analysis.php';


function ensure_case_fact_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_material_facts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            worker_id INTEGER NOT NULL,
            report_id INTEGER NOT NULL,

            fact_type TEXT NOT NULL,
            fact_text TEXT NOT NULL,
            normalized_text TEXT NOT NULL,
            fact_hash TEXT NOT NULL,

            materiality TEXT NOT NULL DEFAULT 'material',
            importance TEXT NOT NULL DEFAULT 'material',
            confidence TEXT NOT NULL DEFAULT 'source',

            source_kind TEXT NOT NULL,
            source_row_id INTEGER,
            source_excerpt TEXT,

            event_time TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE,

            UNIQUE (
                worker_id,
                report_id,
                fact_hash
            )
        )
    ");


    /*
     * Existing installations may already have the table.
     */
    $columns = $pdo
        ->query("
            PRAGMA table_info(case_material_facts)
        ")
        ->fetchAll(PDO::FETCH_ASSOC);

    $columnNames = array_column(
        $columns,
        'name'
    );

    if (!in_array(
        'importance',
        $columnNames,
        true
    )) {

        $pdo->exec("
            ALTER TABLE case_material_facts
            ADD COLUMN importance TEXT
            NOT NULL DEFAULT 'material'
        ");
    }

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS
            idx_case_material_worker
        ON case_material_facts(worker_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS
            idx_case_material_report
        ON case_material_facts(report_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS
            idx_case_material_type
        ON case_material_facts(fact_type)
    ");
}


function case_fact_normalize(string $text): string
{
    $text = mb_strtolower(
        trim($text)
    );

    $text = preg_replace(
        '/[^\p{L}\p{N}\s]/u',
        ' ',
        $text
    );

    $text = preg_replace(
        '/\s+/',
        ' ',
        $text
    );

    return trim($text);
}


function case_fact_hash(string $text): string
{
    return hash(
        'sha256',
        case_fact_normalize($text)
    );
}




function case_fact_semantic_type(
    string $text,
    string $existingType = 'General'
): string {

    $text = trim($text);
    $existingType = trim($existingType);

    /*
     * Keep an existing meaningful analyzer classification.
     */
    if (
        $existingType !== ''
        && strcasecmp($existingType, 'General') !== 0
        && strcasecmp($existingType, 'Unclassified') !== 0
    ) {
        return $existingType;
    }

    if ($text === '') {
        return 'General';
    }


    /*
     * -----------------------------------------------------
     * CONTEXT / NON-FACTUAL STRUCTURE
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}$/i',
            $text
        )
        ||
        preg_match(
            '/^\d{1,2}\/\d{1,2}\/\d{2,4}$/',
            $text
        )
        ||
        preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $text
        )
        ||
        preg_match(
            '/^this statement reflects the events\b/i',
            $text
        )
        ||
        preg_match(
            '/^this report reflects the events\b/i',
            $text
        )
        ||
        preg_match(
            '/^idk what else to do[.!]*$/i',
            $text
        )
        ||
        preg_match(
            '/^i don[\'’]?t know what else to do[.!]*$/i',
            $text
        )
    ) {
        return 'context';
    }


    /*
     * -----------------------------------------------------
     * HEALTH / PERSONAL CONDITION
     *
     * Describes a person's physical condition without
     * diagnosing the cause.
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(sick|ill|hurting|hurt|pain|fever|vomiting|throwing up|dizzy|bleeding|stomach hurts|stomach feels|couldn[\'’]?t breathe|can[\'’]?t breathe)\b/i',
            $text
        )
    ) {
        return 'health_condition';
    }


    /*
     * -----------------------------------------------------
     * DIRECT NOTIFICATION
     *
     * High-confidence first-person reporting must outrank
     * words appearing later in the sentence such as
     * "statement" or embedded quoted/reported speech.
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/^\s*(i|we)\s+(told|reported|notified|informed|warned|advised|contacted|explained)\b/i',
            $text
        )
    ) {
        return 'notification';
    }


    /*
     * -----------------------------------------------------
     * DIRECT RESPONSE
     *
     * The primary actor responding must outrank reported
     * speech contained inside the response.
     *
     * Example:
     * "Brent responded that she should not have said that."
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/^\s*(he|she|they|[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+(responded|replied|answered|acknowledged)\b/i',
            $text
        )
    ) {
        return 'response';
    }


    /*
     * -----------------------------------------------------
     * REPORTED STATEMENT
     *
     * Keep attributed speech separate from objective facts.
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(he|she|they|[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+(stated|said|commented|remarked)\b/i',
            $text
        )
        ||
        preg_match(
            '/[“”"]/',
            $text
        )
    ) {
        return 'reported_statement';
    }


    /*
     * -----------------------------------------------------
     * INSTRUCTION
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(i|we)\s+(was|were)\s+(told|instructed|ordered|directed|asked)\b/i',
            $text
        )
        ||
        preg_match(
            '/\bi\s+was\s+instructed\b/i',
            $text
        )
        ||
        preg_match(
            '/\b(he|she|they|[A-Z][a-z]+)\s+(instructed|ordered|directed)\s+(me|us)\b/i',
            $text
        )
    ) {
        return 'instruction';
    }


    /*
     * -----------------------------------------------------
     * REQUIRED ACTION
     *
     * "Had to" is meaningful even when the exact job or
     * object is unknown.
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(i|we)\s+had to\b/i',
            $text
        )
    ) {
        return 'required_action';
    }


    /*
     * -----------------------------------------------------
     * RESPONSE / ACKNOWLEDGEMENT
     *
     * Run this before notification because:
     *
     * "She acknowledged what I told her"
     *
     * contains "I told" but is primarily about her response.
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(acknowledge|acknowledged|responded|replied|answered)\b/i',
            $text
        )
    ) {
        return 'response';
    }


    /*
     * -----------------------------------------------------
     * NOTIFICATION / REPORTING
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(i|we)\s+(told|reported|notified|informed|warned|advised|contacted|explained)\b/i',
            $text
        )
        ||
        preg_match(
            '/\b(i|we)\s+(expressed|raised)\s+(my|our)?\s*(concern|concerns|issue|issues)\b/i',
            $text
        )
        ||
        preg_match(
            '/\b(he|she|they|[A-Z][a-z]+)\s+(contacted|notified|informed|reported)\b/i',
            $text
        )
    ) {
        return 'notification';
    }


    /*
     * -----------------------------------------------------
     * UNRESOLVED RESPONSE / CONDITION
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(no one|nobody)\s+(addressed|responded|returned|helped|followed up)\b/i',
            $text
        )
        ||
        preg_match(
            '/\b(never|not)\s+(addressed|resolved|corrected|fixed)\b/i',
            $text
        )
    ) {
        return 'unresolved_response';
    }

    if (
        preg_match(
            '/\bstill\s+(did not|didn[\'’]?t|had not|hadn[\'’]?t|was not|wasn[\'’]?t)\b/i',
            $text
        )
    ) {
        return 'unresolved_condition';
    }


    /*
     * -----------------------------------------------------
     * OUTCOME
     *
     * Must run before generic Action.
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(as a result|resulted in|ultimately|produced|completed|finished|ended with|unable to)\b/i',
            $text
        )
        ||
        preg_match(
            '/\b(was|were)\s+eventually\s+able to\b/i',
            $text
        )
        ||
        preg_match(
            '/\b\d+(?:\.\d+)?\b.*\b(parts?|items?|units?|pallets?|hours?|minutes?|days?|pounds?|people|pieces?)\b/i',
            $text
        )
    ) {
        return 'outcome';
    }


    /*
     * -----------------------------------------------------
     * CONDITION / FAILURE
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(failed|malfunctioned|jammed|stuck|broke|broken|would not|wouldn[\'’]?t|could not|couldn[\'’]?t|not working|stopped working)\b/i',
            $text
        )
    ) {
        return 'condition';
    }


    /*
     * -----------------------------------------------------
     * CONCERN
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(concerned|concern|concerns|worried|unsafe)\b/i',
            $text
        )
    ) {
        return 'concern';
    }


    /*
     * -----------------------------------------------------
     * OBSERVATION
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(i|we)\s+(saw|noticed|observed|found|located)\b/i',
            $text
        )
        ||
        preg_match(
            '/\bthere\s+(was|were)\b/i',
            $text
        )
        ||
        preg_match(
            '/\b(he|she|they)\s+appeared\s+to\b/i',
            $text
        )
    ) {
        return 'observation';
    }


    /*
     * -----------------------------------------------------
     * ACTION
     *
     * Lowest-priority factual classification because many
     * sentences contain an action but are primarily about an
     * instruction, outcome, response, condition, etc.
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(i|we|he|she|they|[A-Z][a-z]+)\s+(tried|attempted|used|checked|inspected|removed|returned|continued|started|began|went|walked|called|sorted|loaded|pressed|worked|showed)\b/i',
            $text
        )
    ) {
        return 'action';
    }


    /*
     * Unknown does not mean irrelevant.
     */
    return 'General';
}

function case_fact_event_materiality(
    string $text,
    string $category = 'General'
): string {

    $text = trim($text);

    if ($text === '') {
        return 'context';
    }

    $normalized = mb_strtolower(
        preg_replace(
            '/\s+/',
            ' ',
            $text
        )
    );

    /*
     * -----------------------------------------------------
     * CONSERVATIVE MATERIALITY
     * -----------------------------------------------------
     *
     * WorkReport should not assume that a timeline category
     * determines importance.
     *
     * "General" can contain the most important sentence in
     * the report.
     *
     * Therefore:
     *
     *     factual statement -> material by default
     *     obvious boilerplate/noise -> context
     *
     * We intentionally keep this list small. The goal is to
     * avoid silently excluding real facts.
     * -----------------------------------------------------
     */

    $contextOnlyPatterns = [
        '/^(January|February|March|April|May|June|July|August|September|October|November|December)\\s+\\d{1,2},\\s+\\d{4}$/i',
        '/^\s*\d{1,2}\/\d{1,2}\/\d{2,4}\s*$/',
        '/^\s*\d{4}-\d{2}-\d{2}\s*$/',

        '/^this statement reflects the events\b/i',
        '/^this report reflects the events\b/i',
        '/^to the best of my recollection\b/i',
        '/^to the best of my knowledge\b/i',

        '/^idk what else to do[.!]*$/i',
        '/^i don[\'’]?t know what else to do[.!]*$/i'
    ];

    foreach ($contextOnlyPatterns as $pattern) {

        if (preg_match($pattern, $text)) {
            return 'context';
        }
    }

    /*
     * If the timeline analyzer already identified a specific
     * category, that is useful metadata, but it does NOT make
     * the sentence more or less factual than a General event.
     */
    return 'material';
}


function case_fact_importance(
    string $text,
    string $factType,
    string $sourceKind
): string {

    $text = trim($text);

    if ($text === '') {
        return 'context';
    }

    /*
     * Evidence is always important to the case record.
     */
    if ($sourceKind === 'attachment') {
        return 'key';
    }

    /*
     * Reviewed operational findings are already derived from
     * material source language and should not be downgraded.
     */
    if ($sourceKind === 'operational_finding') {

        $keyTypes = [
            'disciplinary_process',
            'attendance_process',
            'safety_process',
            'quality_process',
            'production_readiness',
            'management_response'
        ];

        if (in_array(
            $factType,
            $keyTypes,
            true
        )) {
            return 'key';
        }

        return 'material';
    }

    /*
     * Universal high-value factual structures.
     *
     * These are intentionally occupation-neutral:
     * notification, instruction, failure, unresolved condition,
     * measurable outcome, discipline, evidence, etc.
     */
    $keyPatterns = [
        '/\b(i|we)\s+(told|reported|notified|informed|warned)\b/i',

        '/\b(was|were)\s+(told|instructed|ordered|directed)\b/i',

        '/\b(failed|malfunctioned|stopped working|would not|wouldn[\'’]?t)\b/i',

        '/\b(no one|nobody)\s+(responded|addressed|returned|helped)\b/i',

        '/\b(not addressed|never addressed|remained unresolved|still unresolved)\b/i',

        '/\b(written warning|write[- ]?up|discipline|suspended|terminated|fired)\b/i',

        '/\b(photo|image|recording|audio|video|screenshot|document|message|email)\b/i',

        '/\b\d+\b.*\b(parts?|items?|people|hours?|minutes?|days?|pounds?|pallets?|units?)\b/i'
    ];

    foreach ($keyPatterns as $pattern) {

        if (preg_match($pattern, $text)) {
            return 'key';
        }
    }

    return 'material';
}


function case_fact_sentences(string $text): array
{
    $text = trim($text);

    if ($text === '') {
        return [];
    }

    $parts = preg_split(
        '/(?<=[.!?])\s+|\n+/',
        $text,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $sentences = [];

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

        $sentences[] = $part;
    }

    return $sentences;
}


function case_fact_insert(
    PDO $pdo,
    int $workerId,
    int $reportId,
    string $factType,
    string $factText,
    string $sourceKind,
    ?int $sourceRowId = null,
    ?string $sourceExcerpt = null,
    ?string $eventTime = null,
    string $confidence = 'source',
    string $materiality = 'material',
    ?string $importance = null
): void {

    $factText = trim($factText);

    if ($factText === '') {
        return;
    }

    $normalized =
        case_fact_normalize(
            $factText
        );

    if ($normalized === '') {
        return;
    }

    $hash =
        hash(
            'sha256',
            $normalized
        );

    if ($importance === null) {

        /*
         * Context remains preserved, but it must not accidentally
         * become Final Report material simply because another
         * importance rule matched it.
         */
        if ($materiality === 'context') {

            $importance = 'context';

        } else {

            $importance =
                case_fact_importance(
                    $factText,
                    $factType,
                    $sourceKind
                );
        }
    }

    /*
     * Prefer stronger derived classifications when the same
     * sentence already exists from a weaker timeline source.
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
            source_kind
        FROM case_material_facts
        WHERE worker_id = ?
          AND report_id = ?
          AND fact_hash = ?
        LIMIT 1
    ");

    $stmt->execute([
        $workerId,
        $reportId,
        $hash
    ]);

    $existing =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {

        if (
            $sourceKind === 'operational_finding'
            && $existing['source_kind'] !== 'operational_finding'
        ) {

            $stmt = $pdo->prepare("
                UPDATE case_material_facts
                SET
                    fact_type = :fact_type,
                    confidence = :confidence,
                    materiality = :materiality,
                    importance = :importance,
                    source_kind = :source_kind,
                    source_row_id = :source_row_id,
                    source_excerpt = :source_excerpt
                WHERE id = :id
            ");

            $stmt->execute([
                ':fact_type' => $factType,
                ':confidence' => $confidence,
                ':materiality' => $materiality,
                ':importance' => $importance,
                ':source_kind' => $sourceKind,
                ':source_row_id' => $sourceRowId,
                ':source_excerpt' => $sourceExcerpt,
                ':id' => (int) $existing['id']
            ]);
        }

        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO case_material_facts (
            worker_id,
            report_id,
            fact_type,
            fact_text,
            normalized_text,
            fact_hash,
            materiality,
            importance,
            confidence,
            source_kind,
            source_row_id,
            source_excerpt,
            event_time,
            created_at
        )
        VALUES (
            :worker_id,
            :report_id,
            :fact_type,
            :fact_text,
            :normalized_text,
            :fact_hash,
            :materiality,
            :importance,
            :confidence,
            :source_kind,
            :source_row_id,
            :source_excerpt,
            :event_time,
            datetime('now', 'localtime')
        )
    ");

    $stmt->execute([
        ':worker_id' => $workerId,
        ':report_id' => $reportId,
        ':fact_type' => $factType,
        ':fact_text' => $factText,
        ':normalized_text' => $normalized,
        ':fact_hash' => $hash,
        ':materiality' => $materiality,
        ':importance' => $importance,
        ':confidence' => $confidence,
        ':source_kind' => $sourceKind,
        ':source_row_id' => $sourceRowId,
        ':source_excerpt' => $sourceExcerpt,
        ':event_time' => $eventTime
    ]);
}


function rebuild_case_facts_for_report(
    int $reportId,
    int $workerId
): array {

    ensure_case_fact_schema();

    $pdo = db();

    /*
     * Hard privacy boundary.
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
            worker_id,
            report_date,
            title
        FROM reports
        WHERE id = ?
          AND worker_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $reportId,
        $workerId
    ]);

    $report =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        throw new RuntimeException(
            'Report not found for worker.'
        );
    }

    $pdo->prepare("
        DELETE FROM case_material_facts
        WHERE worker_id = ?
          AND report_id = ?
    ")->execute([
        $workerId,
        $reportId
    ]);


    /*
     * -----------------------------------------------------
     * TIMELINE FACTS
     *
     * Non-General events are treated as material.
     * General events remain supporting context.
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

    $events =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($events as $event) {

        $category = trim(
            (string) (
                $event['category']
                ?? 'General'
            )
        );

        $factType =
            case_fact_semantic_type(
                (string) $event['description'],
                $category
            );

        $materiality =
            case_fact_event_materiality(
                (string) $event['description'],
                $category
            );

        case_fact_insert(
            $pdo,
            $workerId,
            $reportId,
            $factType,
            (string) $event['description'],
            'event',
            (int) $event['id'],
            (string) $event['description'],
            !empty($event['event_time'])
                ? (string) $event['event_time']
                : null,
            'source',
            $materiality
        );
    }


    /*
     * -----------------------------------------------------
     * OPERATIONAL FINDINGS
     *
     * Split observed facts back into individual factual
     * statements. This prevents one giant finding paragraph
     * from becoming one giant "fact."
     * -----------------------------------------------------
     */

    $stmt = $pdo->prepare("
        SELECT *
        FROM report_operational_findings
        WHERE report_id = ?
        ORDER BY id
    ");

    $stmt->execute([$reportId]);

    $findings =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($findings as $finding) {

        $sentences =
            case_fact_sentences(
                (string) (
                    $finding['observed_fact']
                    ?? ''
                )
            );

        foreach ($sentences as $sentence) {

            case_fact_insert(
                $pdo,
                $workerId,
                $reportId,
                (string) $finding['finding_type'],
                $sentence,
                'operational_finding',
                (int) $finding['id'],
                (string) (
                    $finding['source_excerpt']
                    ?? $sentence
                ),
                null,
                (string) (
                    $finding['confidence']
                    ?? 'medium'
                ),
                'material'
            );
        }
    }


    /*
     * -----------------------------------------------------
     * PRESERVED EVIDENCE
     * -----------------------------------------------------
     */

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM attachments
            WHERE report_id = ?
            ORDER BY id
        ");

        $stmt->execute([$reportId]);

        $attachments =
            $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        $label = '';

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
                $label = $value;
                break;
            }
        }

        if ($label === '') {
            $label =
                'Preserved evidence attachment';
        }

        case_fact_insert(
            $pdo,
            $workerId,
            $reportId,
            'evidence_preserved',
            $label,
            'attachment',
            $attachmentId,
            $label,
            null,
            'source',
            'material'
        );
    }


    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(
                CASE
                    WHEN materiality = 'material'
                    THEN 1
                    ELSE 0
                END
            ) AS material,
            SUM(
                CASE
                    WHEN materiality = 'supporting'
                    THEN 1
                    ELSE 0
                END
            ) AS supporting
        FROM case_material_facts
        WHERE worker_id = ?
          AND report_id = ?
    ");

    $stmt->execute([
        $workerId,
        $reportId
    ]);

    $counts =
        $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'report_id' => $reportId,
        'worker_id' => $workerId,
        'total' =>
            (int) ($counts['total'] ?? 0),
        'material' =>
            (int) ($counts['material'] ?? 0),
        'supporting' =>
            (int) ($counts['supporting'] ?? 0)
    ];
}
