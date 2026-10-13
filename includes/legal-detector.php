<?php

declare(strict_types=1);

require_once __DIR__ . '/legal.php';

function ensure_legal_detection_schema(): void
{
    ensure_legal_schema();

    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS legal_issue_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE COLLATE NOCASE,
            topic TEXT NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            default_severity TEXT NOT NULL DEFAULT 'medium',
            resource_topic TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_legal_flags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            issue_type_id INTEGER NOT NULL,
            severity TEXT NOT NULL DEFAULT 'medium',
            confidence TEXT NOT NULL DEFAULT 'possible',
            reason TEXT NOT NULL,
            matched_excerpt TEXT,
            detection_source TEXT NOT NULL DEFAULT 'rule',
            detected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            review_status TEXT NOT NULL DEFAULT 'unreviewed',
            reviewed_at TEXT,
            reviewed_by INTEGER,
            review_notes TEXT,

            UNIQUE (report_id, issue_type_id),

            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE,

            FOREIGN KEY (issue_type_id)
                REFERENCES legal_issue_types(id)
                ON DELETE RESTRICT
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_legal_flags_report
        ON report_legal_flags(report_id);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_legal_flags_status
        ON report_legal_flags(review_status);
    ");

    seed_legal_issue_types();
    seed_legal_detection_resources();
}

function seed_legal_issue_types(): void
{
    $pdo = db();

    $issues = [
        [
            'sexual_harassment',
            'Harassment',
            'Possible Sexual Harassment / Unwanted Sexual Contact',
            'Reported conduct may involve unwelcome sexual or intimate physical contact.',
            'high',
            'Harassment'
        ],
        [
            'fmla_interference',
            'FMLA',
            'Possible FMLA Interference',
            'Reported conduct may involve interference with or discouragement of protected FMLA leave.',
            'high',
            'FMLA'
        ],
        [
            'fmla_retaliation',
            'FMLA',
            'Possible FMLA Retaliation',
            'Reported employment action may relate to use or attempted use of FMLA leave.',
            'high',
            'FMLA'
        ],
        [
            'eeo_retaliation',
            'Retaliation',
            'Possible Retaliation After Protected Complaint',
            'Reported employment action may have followed a discrimination or harassment complaint.',
            'high',
            'Retaliation'
        ],
        [
            'workplace_safety',
            'Safety',
            'Possible Workplace Safety Concern',
            'Reported facts may involve an unsafe or hazardous workplace condition.',
            'high',
            'Safety'
        ]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO legal_issue_types (
            code,
            topic,
            title,
            description,
            default_severity,
            resource_topic,
            active
        )
        VALUES (?, ?, ?, ?, ?, ?, 1)
        ON CONFLICT(code)
        DO UPDATE SET
            topic = excluded.topic,
            title = excluded.title,
            description = excluded.description,
            default_severity = excluded.default_severity,
            resource_topic = excluded.resource_topic,
            active = 1
    ");

    foreach ($issues as $issue) {
        $stmt->execute($issue);
    }
}

function seed_legal_detection_resources(): void
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id
        FROM jurisdictions
        WHERE state_code = 'KY'
        LIMIT 1
    ");

    $stmt->execute();

    $kyId = (int) $stmt->fetchColumn();

    if ($kyId < 1) {
        return;
    }

    $resources = [
        [
            'federal',
            'Harassment',
            'EEOC: Harassment at Work',
            'Federal guidance about workplace harassment, including unwelcome physical conduct.',
            'U.S. Equal Employment Opportunity Commission',
            'https://www.eeoc.gov/questions-and-answers-employees-harassment-work',
            15
        ],
        [
            'federal',
            'Retaliation',
            'EEOC: Retaliation',
            'Federal guidance about retaliation connected to discrimination complaints and other protected EEO activity.',
            'U.S. Equal Employment Opportunity Commission',
            'https://www.eeoc.gov/retaliation',
            25
        ],
        [
            'federal',
            'Safety',
            'OSHA Worker Rights and Protections',
            'Federal workplace safety information, worker rights, complaint information and whistleblower protections.',
            'Occupational Safety and Health Administration',
            'https://www.osha.gov/workers',
            35
        ],
        [
            'federal',
            'Safety',
            'OSHA: File a Safety Complaint',
            'Official OSHA information for reporting unsafe or unhealthy workplace conditions.',
            'Occupational Safety and Health Administration',
            'https://www.osha.gov/workers/file-complaint',
            36
        ]
    ];

    foreach ($resources as $resource) {

        [$scope, $topic, $title, $summary, $source, $url, $sort] = $resource;

        $stmt = $pdo->prepare("
            SELECT id
            FROM legal_resources
            WHERE jurisdiction_id = ?
              AND title = ?
            LIMIT 1
        ");

        $stmt->execute([$kyId, $title]);

        if ($stmt->fetchColumn()) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO legal_resources (
                jurisdiction_id,
                resource_scope,
                topic,
                title,
                summary,
                source_name,
                source_url,
                reviewed_at,
                sort_order,
                active
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, date('now'), ?, 1)
        ");

        $stmt->execute([
            $kyId,
            $scope,
            $topic,
            $title,
            $summary,
            $source,
            $url,
            $sort
        ]);
    }
}

function legal_match_excerpt(string $text, string $pattern): ?string
{
    if (!preg_match($pattern, $text, $match)) {
        return null;
    }

    return trim($match[0]);
}

function detect_legal_issues(string $text): array
{
    $issues = [];

    $add = static function (
        string $code,
        string $severity,
        string $reason,
        ?string $excerpt = null
    ) use (&$issues): void {

        $issues[$code] = [
            'code' => $code,
            'severity' => $severity,
            'confidence' => 'possible',
            'reason' => $reason,
            'matched_excerpt' => $excerpt
        ];
    };

    $sexualPattern =
        '/\b(grabbed|groped|touched|slapped|smacked|rubbed|fondled|kissed)\b.{0,60}\b(butt|ass|breast|breasts|boob|boobs|groin|crotch|genitals?|private\s+parts?|inner\s+thigh)\b/i';

    $sexualGeneral =
        '/\b(sexual harassment|unwanted sexual (touching|contact|advance)|touched me inappropriately|sexual comments?)\b/i';

    $excerpt = legal_match_excerpt($text, $sexualPattern)
        ?? legal_match_excerpt($text, $sexualGeneral);

    if ($excerpt !== null) {
        $add(
            'sexual_harassment',
            'high',
            'The report describes possible unwanted sexual or intimate physical conduct. Human review is recommended.',
            $excerpt
        );
    }

    $fmlaMarker = preg_match(
        '/\b(FMLA|family and medical leave|family medical leave|protected medical leave)\b/i',
        $text
    );

    if ($fmlaMarker) {

        $interferencePattern =
            '/\b(denied|refused|discouraged|would not let|wouldn\'t let|told me not to use|attendance point|pointed me for|counted.*attendance|used.*leave.*against me)\b/i';

        $excerpt = legal_match_excerpt(
            $text,
            $interferencePattern
        );

        if ($excerpt !== null) {
            $add(
                'fmla_interference',
                'high',
                'The report references FMLA or protected medical leave together with possible denial, discouragement, attendance points, or other interference.',
                $excerpt
            );
        }

        $adversePattern =
            '/\b(write[- ]?up|wrote me up|written up|disciplin|fired|terminated|suspended|demoted|hours cut|cut my hours|sent me home|threatened|attendance point|pointed me)\b/i';

        $excerpt = legal_match_excerpt(
            $text,
            $adversePattern
        );

        if ($excerpt !== null) {
            $add(
                'fmla_retaliation',
                'high',
                'The report references FMLA or protected medical leave together with a potentially adverse employment action. Timing and context require human review.',
                $excerpt
            );
        }
    }

    $protectedComplaint = preg_match(
        '/\b(reported harassment|reported discrimination|complained about harassment|complained about discrimination|reported sexual harassment|filed an EEOC complaint|filed a discrimination complaint|complained to HR about harassment|complained to HR about discrimination)\b/i',
        $text
    );

    if ($protectedComplaint) {

        $retaliationPattern =
            '/\b(write[- ]?up|written up|wrote me up|disciplin|fired|terminated|suspended|demoted|hours cut|cut my hours|threatened|transferred|pointed me)\b/i';

        $excerpt = legal_match_excerpt(
            $text,
            $retaliationPattern
        );

        if ($excerpt !== null) {
            $add(
                'eeo_retaliation',
                'high',
                'The report describes a discrimination or harassment complaint together with a potentially adverse employment action. Human review is recommended.',
                $excerpt
            );
        }
    }

    $safetyPattern =
        '/\b(OSHA|unsafe condition|serious hazard|imminent danger|life[- ]threatening|machine guard removed|safety guard removed|could have killed|almost killed|almost got hurt)\b/i';

    $excerpt = legal_match_excerpt(
        $text,
        $safetyPattern
    );

    if ($excerpt !== null) {
        $add(
            'workplace_safety',
            'high',
            'The report contains language describing a potentially serious workplace safety or hazard concern.',
            $excerpt
        );
    }

    return array_values($issues);
}

function save_report_legal_flags(
    int $reportId,
    array $issues
): void {

    ensure_legal_detection_schema();

    $pdo = db();

    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {

        $stmt = $pdo->prepare("
            DELETE FROM report_legal_flags
            WHERE report_id = ?
              AND detection_source = 'rule'
              AND review_status = 'unreviewed'
        ");

        $stmt->execute([$reportId]);

        foreach ($issues as $issue) {

            $stmt = $pdo->prepare("
                SELECT id
                FROM legal_issue_types
                WHERE code = ?
                  AND active = 1
                LIMIT 1
            ");

            $stmt->execute([$issue['code']]);

            $issueTypeId = (int) $stmt->fetchColumn();

            if ($issueTypeId < 1) {
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO report_legal_flags (
                    report_id,
                    issue_type_id,
                    severity,
                    confidence,
                    reason,
                    matched_excerpt,
                    detection_source,
                    detected_at,
                    review_status
                )
                VALUES (
                    :report_id,
                    :issue_type_id,
                    :severity,
                    :confidence,
                    :reason,
                    :matched_excerpt,
                    'rule',
                    datetime('now', 'localtime'),
                    'unreviewed'
                )
                ON CONFLICT(report_id, issue_type_id)
                DO UPDATE SET
                    severity = excluded.severity,
                    confidence = excluded.confidence,
                    reason = excluded.reason,
                    matched_excerpt = excluded.matched_excerpt,
                    detected_at = excluded.detected_at
                WHERE report_legal_flags.review_status = 'unreviewed'
            ");

            $stmt->execute([
                ':report_id' => $reportId,
                ':issue_type_id' => $issueTypeId,
                ':severity' => $issue['severity'],
                ':confidence' => $issue['confidence'],
                ':reason' => $issue['reason'],
                ':matched_excerpt' => $issue['matched_excerpt']
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

    } catch (Throwable $e) {

        $pdo->rollBack();
        throw $e;
    }
}

function legal_flags_for_report(int $reportId): array
{
    ensure_legal_detection_schema();

    $stmt = db()->prepare("
        SELECT
            f.*,
            t.code,
            t.topic,
            t.title,
            t.description,
            t.resource_topic
        FROM report_legal_flags f
        INNER JOIN legal_issue_types t
            ON t.id = f.issue_type_id
        WHERE f.report_id = ?
        ORDER BY
            CASE f.severity
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                ELSE 4
            END,
            f.id
    ");

    $stmt->execute([$reportId]);

    return $stmt->fetchAll();
}

function legal_resources_for_issue(
    string $stateCode,
    string $resourceTopic
): array {

    $resources = legal_resources_for_state($stateCode);

    return array_values(
        array_filter(
            $resources,
            static function (array $resource) use ($resourceTopic): bool {
                return strcasecmp(
                    $resource['topic'],
                    $resourceTopic
                ) === 0
                || strcasecmp(
                    $resource['topic'],
                    'Legal Help'
                ) === 0;
            }
        )
    );
}