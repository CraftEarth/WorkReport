<?php

require_once __DIR__ . '/functions.php';


/*
 * =========================================================
 * EFFECTIVE REPORT METADATA
 * =========================================================
 *
 * Submitted report fields are historical source data.
 * They are NEVER rewritten by this subsystem.
 *
 * This layer stores derived interpretations separately.
 *
 * Examples:
 *
 * submitted:
 *     machine = "CD6"
 *
 * derived:
 *     primary_work_area = "Ford Service"
 *
 * The original remains untouched.
 *
 * No interpretation is a legal conclusion.
 */


function ensure_effective_metadata_schema(): void
{
    $pdo = db();

    /*
     * Every candidate interpretation and its provenance.
     */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_metadata_candidates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            worker_id INTEGER NOT NULL,
            report_id INTEGER NOT NULL,

            field_name TEXT NOT NULL,
            candidate_value TEXT NOT NULL,

            normalized_value TEXT NOT NULL,

            source_kind TEXT NOT NULL,
            source_row_id INTEGER,

            source_excerpt TEXT,

            evidence_type TEXT NOT NULL DEFAULT 'derived',

            confidence TEXT NOT NULL DEFAULT 'medium',
            confidence_score INTEGER NOT NULL DEFAULT 0,

            reason TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                worker_id,
                report_id,
                field_name,
                normalized_value,
                source_kind,
                source_excerpt
            )
        )
    ");

    /*
     * One currently preferred interpretation for a field.
     *
     * This can be rebuilt as intelligence improves.
     */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_effective_metadata (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            worker_id INTEGER NOT NULL,
            report_id INTEGER NOT NULL,

            field_name TEXT NOT NULL,

            submitted_value TEXT,
            effective_value TEXT,

            interpretation_status TEXT NOT NULL DEFAULT 'unresolved',

            confidence TEXT NOT NULL DEFAULT 'low',
            confidence_score INTEGER NOT NULL DEFAULT 0,

            selected_candidate_id INTEGER,

            reason TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            refreshed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE (
                worker_id,
                report_id,
                field_name
            )
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_metadata_candidates_report
        ON case_metadata_candidates (
            worker_id,
            report_id,
            field_name
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_case_effective_metadata_report
        ON case_effective_metadata (
            worker_id,
            report_id,
            field_name
        )
    ");
}


function effective_metadata_normalize(
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


function effective_metadata_confidence(
    int $score
): string {

    if ($score >= 80) {
        return 'high';
    }

    if ($score >= 50) {
        return 'medium';
    }

    return 'low';
}


function effective_metadata_report(
    int $workerId,
    int $reportId
): ?array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            worker_id,
            report_date,
            shift,
            department,
            machine,
            title,
            original_text,
            polished_text,
            status
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


function effective_metadata_source_text(
    array $report
): string {

    /*
     * Preserve both forms.
     *
     * We search them.
     * We do not modify either.
     */
    $original = trim(
        (string) (
            $report['original_text']
            ?? ''
        )
    );

    $polished = trim(
        (string) (
            $report['polished_text']
            ?? ''
        )
    );

    if ($polished === '') {
        return $original;
    }

    if ($original === '') {
        return $polished;
    }

    return $original . "\n" . $polished;
}


function effective_metadata_phrase_present(
    string $text,
    string $value
): bool {

    $text = trim($text);
    $value = trim($value);

    if (
        $text === ''
        || $value === ''
    ) {
        return false;
    }

    $pattern =
        '/(?<![\p{L}\p{N}])'
        . preg_quote($value, '/')
        . '(?![\p{L}\p{N}])/iu';

    return preg_match(
        $pattern,
        $text
    ) === 1;
}


function effective_metadata_add_candidate(
    int $workerId,
    int $reportId,
    string $fieldName,
    string $candidateValue,
    string $sourceKind,
    ?int $sourceRowId,
    ?string $sourceExcerpt,
    string $evidenceType,
    int $confidenceScore,
    ?string $reason
): void {

    $candidateValue =
        trim($candidateValue);

    if ($candidateValue === '') {
        return;
    }

    $normalized =
        effective_metadata_normalize(
            $candidateValue
        );

    if ($normalized === '') {
        return;
    }

    $confidenceScore =
        max(
            0,
            min(
                100,
                $confidenceScore
            )
        );

    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO case_metadata_candidates (
            worker_id,
            report_id,
            field_name,
            candidate_value,
            normalized_value,
            source_kind,
            source_row_id,
            source_excerpt,
            evidence_type,
            confidence,
            confidence_score,
            reason
        )
        VALUES (
            :worker_id,
            :report_id,
            :field_name,
            :candidate_value,
            :normalized_value,
            :source_kind,
            :source_row_id,
            :source_excerpt,
            :evidence_type,
            :confidence,
            :confidence_score,
            :reason
        )
    ");

    $stmt->execute([
        ':worker_id' =>
            $workerId,

        ':report_id' =>
            $reportId,

        ':field_name' =>
            $fieldName,

        ':candidate_value' =>
            $candidateValue,

        ':normalized_value' =>
            $normalized,

        ':source_kind' =>
            $sourceKind,

        ':source_row_id' =>
            $sourceRowId,

        ':source_excerpt' =>
            $sourceExcerpt,

        ':evidence_type' =>
            $evidenceType,

        ':confidence' =>
            effective_metadata_confidence(
                $confidenceScore
            ),

        ':confidence_score' =>
            $confidenceScore,

        ':reason' =>
            $reason,
    ]);
}


/*
 * =========================================================
 * PRIMARY WORK AREA CANDIDATES
 * =========================================================
 *
 * Uses already-known places plus sentence structure.
 *
 * It does NOT know what "Ford Service", "ICU", "Route 41",
 * "Register 6", "Dock 3", etc. mean.
 *
 * They are simply candidate location/work-area names.
 */


function effective_metadata_report_places(
    int $reportId
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.name,
            p.place_type
        FROM report_places rp

        INNER JOIN places p
            ON p.id = rp.place_id

        WHERE rp.report_id = ?

        ORDER BY p.name
    ");

    $stmt->execute([
        $reportId
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


function effective_metadata_sentences(
    string $text
): array {

    $text = trim($text);

    if ($text === '') {
        return [];
    }

    $parts = preg_split(
        '/(?<=[.!?])\s+|\R+/u',
        $text,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $sentences = [];

    foreach ($parts as $part) {

        $part = trim($part);

        if ($part !== '') {
            $sentences[] = $part;
        }
    }

    return $sentences;
}


function effective_metadata_assignment_score(
    string $sentence,
    string $place
): int {

    if (
        !effective_metadata_phrase_present(
            $sentence,
            $place
        )
    ) {
        return 0;
    }

    $quotedPlace =
        preg_quote(
            $place,
            '/'
        );


    /*
     * -----------------------------------------------------
     * EXPLICIT PRIMARY ASSIGNMENT
     * -----------------------------------------------------
     *
     * These statements directly describe where the worker
     * was assigned/scheduled/stationed.
     */

    $explicitAssignmentPatterns = [

        '/\b(?:was|were)\s+(?:scheduled|assigned)\s+to\s+'
            . '(?:run|work|cover|operate)?\s*'
            . $quotedPlace
            . '\b/iu',

        '/\b(?:was|were)\s+(?:scheduled|assigned|stationed)\s+'
            . '(?:at|in|on)\s+'
            . $quotedPlace
            . '\b/iu',

        '/\b(?:scheduled|assigned)\s+to\s+'
            . '(?:run|work|cover|operate)?\s*'
            . $quotedPlace
            . '\b/iu',

        '/\b(?:my|the)\s+'
            . '(?:assignment|assigned\s+area|assigned\s+station|work\s+assignment)'
            . '\s+(?:was|is)\s+'
            . $quotedPlace
            . '\b/iu',

        '/\b(?:supposed|expected)\s+to\s+'
            . '(?:work|run|cover|operate)\s+'
            . $quotedPlace
            . '\b/iu',
    ];

    foreach (
        $explicitAssignmentPatterns
        as $pattern
    ) {

        if (
            preg_match(
                $pattern,
                $sentence
            )
        ) {
            return 95;
        }
    }


    /*
     * -----------------------------------------------------
     * DIRECT REPORTING / ARRIVAL TO ASSIGNMENT
     * -----------------------------------------------------
     *
     * Strong context, but not as definitive as an explicit
     * "assigned/scheduled" statement.
     */

    if (
        preg_match(
            '/\b(?:reported|arrived)\s+'
            . '(?:to|at)\s+'
            . $quotedPlace
            . '\b/iu',
            $sentence
        )
    ) {
        return 70;
    }


    /*
     * -----------------------------------------------------
     * ACTIVITY AT A LOCATION
     * -----------------------------------------------------
     *
     * "I worked at X" or "continued working at X" proves
     * activity there. It does NOT prove X was the primary
     * assignment.
     */

    if (
        preg_match(
            '/\b(?:worked|working|continued\s+working|helped|helping)\s+'
            . '(?:at|in|on)\s+'
            . $quotedPlace
            . '\b/iu',
            $sentence
        )
    ) {
        return 45;
    }


    /*
     * -----------------------------------------------------
     * MOVEMENT / TRANSIENT PRESENCE
     * -----------------------------------------------------
     */

    if (
        preg_match(
            '/\b(?:went|walked|moved|returned)\s+'
            . '(?:to|toward|into|at)\s+'
            . $quotedPlace
            . '\b/iu',
            $sentence
        )
    ) {
        return 30;
    }


    /*
     * Mere presence in the narrative.
     */
    return 20;
}

function rebuild_effective_work_area_for_report(
    int $workerId,
    int $reportId
): void {

    ensure_effective_metadata_schema();

    $report =
        effective_metadata_report(
            $workerId,
            $reportId
        );

    if (!$report) {
        return;
    }

    $pdo = db();

    /*
     * Rebuild derived candidates only.
     * Original report data remains untouched.
     */
    $delete = $pdo->prepare("
        DELETE FROM case_metadata_candidates
        WHERE worker_id = ?
          AND report_id = ?
          AND field_name = 'primary_work_area'
    ");

    $delete->execute([
        $workerId,
        $reportId
    ]);


    $sourceText =
        effective_metadata_source_text(
            $report
        );

    $sentences =
        effective_metadata_sentences(
            $sourceText
        );


    /*
     * -----------------------------------------------------
     * SUBMITTED STRUCTURED VALUE
     * -----------------------------------------------------
     *
     * A worker-supplied structured field is useful evidence,
     * but not infallible.
     */

    $submittedMachine =
        trim(
            (string) (
                $report['machine']
                ?? ''
            )
        );

    if ($submittedMachine !== '') {

        $score = 45;
        $reason =
            'Worker-submitted machine/workstation field.';

        if (
            effective_metadata_phrase_present(
                $sourceText,
                $submittedMachine
            )
        ) {
            $score += 15;

            $reason .=
                ' The same value also appears in the narrative.';
        }

        effective_metadata_add_candidate(
            $workerId,
            $reportId,
            'primary_work_area',
            $submittedMachine,
            'submitted_machine',
            $reportId,
            null,
            'structured_metadata',
            $score,
            $reason
        );
    }


    /*
     * -----------------------------------------------------
     * NARRATIVE PLACE CANDIDATES
     * -----------------------------------------------------
     */

    $places =
        effective_metadata_report_places(
            $reportId
        );

    foreach ($places as $placeRow) {

        $place =
            trim(
                (string) (
                    $placeRow['name']
                    ?? ''
                )
            );

        if ($place === '') {
            continue;
        }

        /*
         * A derived report_place that is not actually present
         * in the narrative cannot establish primary assignment.
         */
        if (
            !effective_metadata_phrase_present(
                $sourceText,
                $place
            )
        ) {
            continue;
        }

        $bestScore = 20;
        $bestSentence = null;

        foreach ($sentences as $sentence) {

            $score =
                effective_metadata_assignment_score(
                    $sentence,
                    $place
                );

            if ($score > $bestScore) {

                $bestScore =
                    $score;

                $bestSentence =
                    $sentence;
            }
        }

        effective_metadata_add_candidate(
            $workerId,
            $reportId,
            'primary_work_area',
            $place,
            'report_place',
            isset($placeRow['id'])
                ? (int) $placeRow['id']
                : null,
            $bestSentence,
            $bestScore >= 80
                ? 'explicit_assignment'
                : 'narrative_mention',
            $bestScore,
            $bestScore >= 80
                ? 'Narrative explicitly describes this place as the worker assignment.'
                : 'Place is documented in the narrative but primary assignment is not explicit.'
        );
    }


    effective_metadata_select_best(
        $workerId,
        $reportId,
        'primary_work_area',
        $submittedMachine
    );
}


function effective_metadata_select_best(
    int $workerId,
    int $reportId,
    string $fieldName,
    ?string $submittedValue
): void {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM case_metadata_candidates
        WHERE worker_id = ?
          AND report_id = ?
          AND field_name = ?
        ORDER BY
            confidence_score DESC,
            id ASC
    ");

    $stmt->execute([
        $workerId,
        $reportId,
        $fieldName
    ]);

    $candidates =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    $submittedValue =
        trim(
            (string) $submittedValue
        );

    $submittedNormalized =
        effective_metadata_normalize(
            $submittedValue
        );


    /*
     * -----------------------------------------------------
     * NO CANDIDATES
     * -----------------------------------------------------
     */

    if (!$candidates) {

        if ($submittedValue !== '') {

            $effectiveValue =
                $submittedValue;

            $status =
                'submitted_only';

            $confidenceScore = 35;

            $candidateId = null;

            $reason =
                'Only the submitted structured value is available; no independent narrative interpretation was found.';

        } else {

            $effectiveValue = '';

            $status =
                'unresolved';

            $confidenceScore = 0;

            $candidateId = null;

            $reason =
                'No supported interpretation was available.';
        }


    } else {

        /*
         * -------------------------------------------------
         * GROUP BY NORMALIZED VALUE
         * -------------------------------------------------
         *
         * Multiple evidence sources supporting the same
         * interpretation should be considered together.
         */

        $groups = [];

        foreach ($candidates as $candidate) {

            $normalized =
                (string) $candidate['normalized_value'];

            if (!isset($groups[$normalized])) {

                $groups[$normalized] = [
                    'normalized_value' =>
                        $normalized,

                    'candidate_value' =>
                        (string) $candidate['candidate_value'],

                    'best_score' =>
                        (int) $candidate['confidence_score'],

                    'best_candidate_id' =>
                        (int) $candidate['id'],

                    'has_explicit_assignment' =>
                        false,

                    'has_submitted_metadata' =>
                        false,

                    'has_narrative_support' =>
                        false,
                ];
            }


            $score =
                (int) $candidate['confidence_score'];

            if (
                $score
                > $groups[$normalized]['best_score']
            ) {

                $groups[$normalized]['best_score'] =
                    $score;

                $groups[$normalized]['best_candidate_id'] =
                    (int) $candidate['id'];

                $groups[$normalized]['candidate_value'] =
                    (string) $candidate['candidate_value'];
            }


            if (
                ($candidate['evidence_type'] ?? '')
                === 'explicit_assignment'
            ) {

                $groups[$normalized]['has_explicit_assignment'] =
                    true;

                $groups[$normalized]['has_narrative_support'] =
                    true;
            }


            if (
                ($candidate['source_kind'] ?? '')
                === 'submitted_machine'
            ) {

                $groups[$normalized]['has_submitted_metadata'] =
                    true;
            }


            if (
                ($candidate['source_kind'] ?? '')
                === 'report_place'
                &&
                (int) $candidate['confidence_score'] >= 30
            ) {

                $groups[$normalized]['has_narrative_support'] =
                    true;
            }
        }


        /*
         * Add a small corroboration bonus when independent
         * source types support the SAME interpretation.
         *
         * This cannot turn a weak mention into an explicit
         * assignment.
         */

        foreach ($groups as &$group) {

            $score =
                (int) $group['best_score'];

            if (
                $group['has_submitted_metadata']
                &&
                $group['has_narrative_support']
            ) {
                $score += 5;
            }

            $group['combined_score'] =
                min(
                    100,
                    $score
                );
        }

        unset($group);


        usort(
            $groups,
            static function (
                array $a,
                array $b
            ): int {

                return
                    $b['combined_score']
                    <=>
                    $a['combined_score'];
            }
        );


        $best =
            $groups[0];

        $second =
            $groups[1]
            ?? null;


        /*
         * -------------------------------------------------
         * AMBIGUOUS TOP RESULT
         * -------------------------------------------------
         *
         * Never silently choose between equally supported
         * conflicting interpretations.
         */

        if (
            $second !== null
            &&
            (int) $best['combined_score']
            === (int) $second['combined_score']
            &&
            $best['normalized_value']
            !== $second['normalized_value']
        ) {

            $effectiveValue =
                $submittedValue;

            $status =
                'ambiguous';

            $confidenceScore =
                (int) $best['combined_score'];

            $candidateId = null;

            $reason =
                'Two or more conflicting interpretations have equal support. No derived interpretation was selected.';


        } else {

            $bestNormalized =
                (string) $best['normalized_value'];

            $bestScore =
                (int) $best['combined_score'];

            $candidateId =
                (int) $best['best_candidate_id'];


            /*
             * A weak narrative mention must never become
             * effective primary metadata by itself.
             */

            if (
                $submittedNormalized === ''
                &&
                !$best['has_explicit_assignment']
            ) {

                $effectiveValue = '';

                $status =
                    'unresolved';

                $confidenceScore =
                    $bestScore;

                $candidateId = null;

                $reason =
                    'Places were mentioned, but none was explicitly supported as the primary work area.';


            } elseif (
                $submittedNormalized !== ''
                &&
                $bestNormalized
                    === $submittedNormalized
            ) {

                $effectiveValue =
                    (string) $best['candidate_value'];

                $confidenceScore =
                    $bestScore;


                if (
                    $best['has_narrative_support']
                ) {

                    $status =
                        'confirmed';

                    $reason =
                        'Submitted metadata is independently supported by the narrative.';

                } else {

                    $status =
                        'submitted_only';

                    $reason =
                        'The submitted value has no independent narrative support.';
                }


            } elseif (
                $best['has_explicit_assignment']
                &&
                $bestScore >= 80
            ) {

                $effectiveValue =
                    (string) $best['candidate_value'];

                $confidenceScore =
                    $bestScore;


                if (
                    $submittedNormalized !== ''
                ) {

                    $status =
                        'derived_conflict';

                    $reason =
                        'Explicit narrative assignment evidence differs from the submitted metadata. The original submitted value remains preserved.';

                } else {

                    $status =
                        'derived';

                    $reason =
                        'Explicit narrative assignment evidence supplied a primary work area not present in structured metadata.';
                }


            } else {

                /*
                 * Evidence exists, but not strongly enough
                 * to override structured metadata.
                 */

                $effectiveValue =
                    $submittedValue;

                $confidenceScore =
                    $submittedValue !== ''
                        ? 35
                        : $bestScore;

                $candidateId = null;


                if ($submittedValue !== '') {

                    $status =
                        'submitted_only';

                    $reason =
                        'Narrative evidence was insufficient to replace the submitted structured value.';

                } else {

                    $status =
                        'unresolved';

                    $reason =
                        'Available evidence does not establish a primary work area.';
                }
            }
        }
    }


    $confidence =
        effective_metadata_confidence(
            $confidenceScore
        );


    $save = $pdo->prepare("
        INSERT INTO case_effective_metadata (
            worker_id,
            report_id,
            field_name,
            submitted_value,
            effective_value,
            interpretation_status,
            confidence,
            confidence_score,
            selected_candidate_id,
            reason,
            refreshed_at
        )
        VALUES (
            :worker_id,
            :report_id,
            :field_name,
            :submitted_value,
            :effective_value,
            :interpretation_status,
            :confidence,
            :confidence_score,
            :selected_candidate_id,
            :reason,
            CURRENT_TIMESTAMP
        )

        ON CONFLICT (
            worker_id,
            report_id,
            field_name
        )
        DO UPDATE SET
            submitted_value =
                excluded.submitted_value,

            effective_value =
                excluded.effective_value,

            interpretation_status =
                excluded.interpretation_status,

            confidence =
                excluded.confidence,

            confidence_score =
                excluded.confidence_score,

            selected_candidate_id =
                excluded.selected_candidate_id,

            reason =
                excluded.reason,

            refreshed_at =
                CURRENT_TIMESTAMP
    ");

    $save->execute([
        ':worker_id' =>
            $workerId,

        ':report_id' =>
            $reportId,

        ':field_name' =>
            $fieldName,

        ':submitted_value' =>
            $submittedValue,

        ':effective_value' =>
            $effectiveValue,

        ':interpretation_status' =>
            $status,

        ':confidence' =>
            $confidence,

        ':confidence_score' =>
            $confidenceScore,

        ':selected_candidate_id' =>
            $candidateId,

        ':reason' =>
            $reason,
    ]);
}

function rebuild_effective_metadata_for_report(
    int $workerId,
    int $reportId
): void {

    rebuild_effective_work_area_for_report(
        $workerId,
        $reportId
    );
}


function rebuild_all_effective_metadata(): int
{
    ensure_effective_metadata_schema();

    $pdo = db();

    $rows = $pdo
        ->query("
            SELECT
                id,
                worker_id
            FROM reports
            WHERE worker_id IS NOT NULL
            ORDER BY
                worker_id,
                report_date,
                id
        ")
        ->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;

    foreach ($rows as $row) {

        rebuild_effective_metadata_for_report(
            (int) $row['worker_id'],
            (int) $row['id']
        );

        $count++;
    }

    return $count;
}
