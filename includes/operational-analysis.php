<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function ensure_operational_analysis_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_operational_findings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            finding_type TEXT NOT NULL,
            title TEXT NOT NULL,
            observed_fact TEXT NOT NULL,
            expected_condition TEXT,
            observed_condition TEXT,
            operational_impact TEXT,
            worker_response TEXT,
            management_response TEXT,
            responsibility_context TEXT,
            reasoning TEXT,
            review_question TEXT,
            confidence TEXT NOT NULL DEFAULT 'medium',
            source_excerpt TEXT,
            resolution_status TEXT NOT NULL DEFAULT 'unknown',
            detection_source TEXT NOT NULL DEFAULT 'rules',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_operational_findings_report
        ON report_operational_findings(report_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_operational_findings_type
        ON report_operational_findings(finding_type)
    ");
}

function operational_finding_type_options(): array
{
    return [
        'production_readiness' => 'Production Readiness',
        'changeover_preparation' => 'Changeover Preparation',
        'materials_unavailable' => 'Materials Unavailable',
        'staffing_coverage' => 'Staffing / Coverage',
        'work_assignment' => 'Work Assignment / Reassignment',
        'management_response' => 'Management Response',
        'response_timeliness' => 'Response Timeliness',
        'communication_breakdown' => 'Communication Breakdown',
        'quality_process' => 'Quality Process',
        'employee_productive_response' => 'Employee Productive Response',
        'disciplinary_process' => 'Disciplinary Process',
        'attendance_process' => 'Attendance / Leave Process',
        'safety_process' => 'Safety Process',
        'other' => 'Other Operational Concern'
    ];
}

function operational_confidence_options(): array
{
    return [
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low'
    ];
}

function operational_resolution_options(): array
{
    return [
        'unknown' => 'Unknown',
        'unresolved' => 'Unresolved',
        'partially_resolved' => 'Partially Resolved',
        'resolved' => 'Resolved',
        'not_applicable' => 'Not Applicable'
    ];
}

function operational_text_contains_any(string $text, array $phrases): bool
{
    foreach ($phrases as $phrase) {
        if ($phrase !== '' && mb_stripos($text, $phrase) !== false) {
            return true;
        }
    }

    return false;
}

function operational_sentences(string $text): array
{
    $normalized = preg_replace('/\r\n|\r/', "\n", trim($text));

    $parts = preg_split(
        '/(?<=[.!?])\s+|\n+/',
        $normalized,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $sentences = [];

    foreach ($parts as $part) {
        $sentence = trim($part);

        if ($sentence !== '') {
            $sentences[] = $sentence;
        }
    }

    return $sentences;
}

function operational_matching_sentences(
    array $sentences,
    array $phrases,
    int $limit = 2
): array {
    $matches = [];

    foreach ($sentences as $sentence) {
        if (!operational_text_contains_any($sentence, $phrases)) {
            continue;
        }

        $matches[] = $sentence;

        if (count($matches) >= $limit) {
            break;
        }
    }

    return $matches;
}

function operational_matching_all_groups(
    array $sentences,
    array $groups,
    int $limit = 2
): array {
    $matches = [];

    foreach ($sentences as $sentence) {
        $allMatched = true;

        foreach ($groups as $phrases) {
            if (!operational_text_contains_any($sentence, $phrases)) {
                $allMatched = false;
                break;
            }
        }

        if (!$allMatched) {
            continue;
        }

        $matches[] = $sentence;

        if (count($matches) >= $limit) {
            break;
        }
    }

    return $matches;
}

function operational_excerpt(array $sentences): string
{
    return trim(implode(' ', array_unique($sentences)));
}

function operational_worker_response(array $sentences): string
{
    $matches = [];

    foreach ($sentences as $sentence) {

        /*
         * This finding is specifically for productive reassignment,
         * assistance, or alternate work while the primary assignment
         * is unavailable or delayed.
         *
         * Merely continuing the assigned machine is not this finding.
         */
        $hasProductiveAction = operational_text_contains_any(
            $sentence,
            [
                'helped another worker',
                'help another worker',
                'helped load',
                'help load',
                'began loading',
                'started loading',
                'assisted',
                'loaded the line',
                'loading the line',
                'helped at',
                'helping at'
            ]
        );

        if (!$hasProductiveAction) {
            continue;
        }

        $matches[] = $sentence;

        if (count($matches) >= 3) {
            break;
        }
    }

    return operational_excerpt($matches);
}

function operational_management_response(array $sentences): string
{
    $matches = [];

    foreach ($sentences as $sentence) {
        $hasActor = operational_text_contains_any(
            $sentence,
            [
                'lead',
                'supervisor',
                'manager',
                'area owner',
                'quality',
                'human resources',
                'hr'
            ]
        );

        $hasAction = operational_text_contains_any(
            $sentence,
            [
                'told',
                'informed',
                'reported',
                'notified',
                'asked',
                'ordered',
                'called',
                'responded',
                'helped',
                'got ',
                'came over'
            ]
        );

        if ($hasActor && $hasAction) {
            $matches[] = $sentence;
        }

        if (count($matches) >= 3) {
            break;
        }
    }

    return operational_excerpt($matches);
}

function operational_make_finding(
    string $type,
    string $title,
    string $observedFact,
    string $expectedCondition,
    string $observedCondition,
    string $impact,
    string $workerResponse,
    string $managementResponse,
    string $responsibilityContext,
    string $reasoning,
    string $reviewQuestion,
    string $confidence,
    string $sourceExcerpt,
    string $resolutionStatus = 'unknown'
): array {
    return [
        'finding_type' => $type,
        'title' => $title,
        'observed_fact' => $observedFact,
        'expected_condition' => $expectedCondition,
        'observed_condition' => $observedCondition,
        'operational_impact' => $impact,
        'worker_response' => $workerResponse,
        'management_response' => $managementResponse,
        'responsibility_context' => $responsibilityContext,
        'reasoning' => $reasoning,
        'review_question' => $reviewQuestion,
        'confidence' => $confidence,
        'source_excerpt' => $sourceExcerpt,
        'resolution_status' => $resolutionStatus
    ];
}

function detect_operational_findings(string $text): array
{
    $text = trim($text);

    if ($text === '') {
        return [];
    }

    $sentences = operational_sentences($text);
    $findings = [];
    $seen = [];

    $workerResponse = operational_worker_response($sentences);
    $managementResponse = operational_management_response($sentences);

    $addFinding = static function (array $finding) use (&$findings, &$seen): void {
        $key = ($finding['finding_type'] ?? '')
            . '|'
            . mb_strtolower(trim($finding['observed_fact'] ?? ''));

        if ($key === '|' || isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $findings[] = $finding;
    };

    $materialPhrases = [
        'waiting on parts',
        'waiting for parts',
        'waiting on components',
        'waiting for components',
        'waiting on material',
        'waiting for material',
        'no parts',
        'no material',
        'no materials',
        'out of material',
        'parts needed ordered',
        'parts needed to be ordered',
        'components needed ordered',
        'components were ordered',
        'parts were ordered'
    ];

    $materialMatches = operational_matching_sentences(
        $sentences,
        $materialPhrases,
        3
    );

    if ($materialMatches) {
        $excerpt = operational_excerpt($materialMatches);

        $addFinding(
            operational_make_finding(
                'materials_unavailable',
                'Required materials or components were not immediately available',
                $excerpt,
                'For scheduled production work, required materials and components would ordinarily be expected to be available when the assignment is ready to begin or resume.',
                'The report indicates that needed parts, components, or material were unavailable or had to be obtained after work preparation was underway.',
                'The scheduled assignment may have been delayed or unable to proceed normally while material was unavailable.',
                $workerResponse,
                $managementResponse,
                'The statement documents a material-availability condition but does not by itself establish which person or department was formally responsible for staging or ordering the material.',
                'The condition may warrant review of production readiness, material staging, and responsibility for ensuring required components are available.',
                'Who was responsible for confirming or staging the required material before the scheduled work began?',
                'high',
                $excerpt,
                'unknown'
            )
        );
    }

    $hasChangeover = operational_text_contains_any(
        $text,
        [
            'change over',
            'changeover',
            'changed over'
        ]
    );

    $hasPreparationIssue = operational_text_contains_any(
        $text,
        [
            'parts needed ordered',
            'parts needed to be ordered',
            'components needed ordered',
            'components were ordered',
            'parts were ordered',
            'figure out what parts',
            'figured out what parts',
            'waiting for components',
            'waiting on components',
            'waiting for parts',
            'waiting on parts'
        ]
    );

    if ($hasChangeover && $hasPreparationIssue) {
        $matches = operational_matching_sentences(
            $sentences,
            [
                'change over',
                'changeover',
                'changed over',
                'parts needed ordered',
                'components were ordered',
                'parts were ordered',
                'figure out what parts',
                'waiting for components',
                'waiting on components'
            ],
            4
        );

        $excerpt = operational_excerpt($matches);

        $addFinding(
            operational_make_finding(
                'changeover_preparation',
                'Changeover preparation may have been incomplete when the work began',
                $excerpt,
                'For a planned changeover, the required component list, material needs, and setup responsibilities would ordinarily be identified before production is expected to resume.',
                'The report describes component identification or ordering occurring during the changeover process.',
                'Incomplete preparation can create avoidable delay, reassignment, or downtime.',
                $workerResponse,
                $managementResponse,
                'The report supports reviewing preparation and coordination. It does not establish which individual had formal responsibility for the changeover unless that responsibility is documented elsewhere.',
                'The timing of component identification or ordering may indicate a production-preparation issue rather than an employee-performance issue.',
                'Was the changeover planned in advance, and who was assigned responsibility for identifying and staging the required components?',
                'medium',
                $excerpt,
                'unknown'
            )
        );
    }

    $staffingPhrases = [
        'short staffed',
        'short-staffed',
        'not enough workers',
        'no one was there',
        'nobody was there',
        'other workers were not there',
        "other workers weren't there",
        'worker was absent',
        'workers were absent',
        'no operator',
        'no one to run',
        'nobody to run'
    ];

    $staffingMatches = operational_matching_sentences(
        $sentences,
        $staffingPhrases,
        3
    );

    if ($staffingMatches) {
        $excerpt = operational_excerpt($staffingMatches);

        $addFinding(
            operational_make_finding(
                'staffing_coverage',
                'Staffing or coverage may have affected normal operations',
                $excerpt,
                'Scheduled operations ordinarily require sufficient staffing or coverage for assigned work.',
                'The report indicates that expected staffing or coverage may not have been available.',
                'Insufficient coverage can cause reassignment, workload shifting, delay, or interruption of normal work.',
                $workerResponse,
                $managementResponse,
                'Staffing levels and coverage decisions are generally organizational matters; the statement alone does not establish who controlled scheduling or staffing.',
                'The condition may warrant review of staffing plans and how uncovered work was handled.',
                'Was the area staffed as scheduled, and who was responsible for arranging coverage?',
                'high',
                $excerpt,
                'unknown'
            )
        );
    }

    if ($workerResponse !== '') {
        $addFinding(
            operational_make_finding(
                'employee_productive_response',
                'Employee documented productive action while the primary assignment was unavailable or changing',
                $workerResponse,
                'When primary work is unavailable, documented productive reassignment or assistance may be relevant context if permitted by workplace procedure.',
                $workerResponse,
                'The employee describes performing or seeking other productive work rather than simply remaining idle.',
                $workerResponse,
                $managementResponse,
                'This finding documents the employee response described in the statement. It does not determine whether the reassignment was required or formally authorized unless the report states that.',
                'The employee response is relevant when evaluating downtime, productivity, assignment compliance, or later criticism of the employee\'s conduct during the delay.',
                'Was this assistance or reassignment directed, permitted, or consistent with normal practice?',
                'high',
                $workerResponse,
                'not_applicable'
            )
        );
    }

    $timelinessPhrases = [
        'still waiting',
        'continued waiting',
        'kept waiting',
        'for hours',
        'all day',
        'never addressed',
        'not addressed',
        'nothing was done',
        'no response for',
        'never responded',
        'remained unresolved',
        'still unresolved',
        'still had not',
        'by the end of the shift'
    ];

    $timelinessMatches = operational_matching_sentences(
        $sentences,
        $timelinessPhrases,
        3
    );

    if ($timelinessMatches) {
        $excerpt = operational_excerpt($timelinessMatches);

        $addFinding(
            operational_make_finding(
                'response_timeliness',
                'The report raises a response-timeliness question',
                $excerpt,
                'Reported operational problems would ordinarily be expected to receive acknowledgment or follow-up appropriate to their urgency and effect on work.',
                'The report indicates continued waiting, lack of response, or an issue that remained unaddressed for some period.',
                'Delayed response can extend downtime, uncertainty, production impact, or employee exposure to an unresolved concern.',
                $workerResponse,
                $managementResponse,
                'The report may support reviewing response timing, but responsibility and an acceptable response time depend on the issue, workplace procedure, and when responsible personnel were notified.',
                'A documented delay becomes more meaningful when the notification time, response time, responsible role, and eventual resolution are known.',
                'When was the issue first reported, who was responsible for responding, and when was corrective action taken?',
                'medium',
                $excerpt,
                'unresolved'
            )
        );
    }

    $communicationMatches = [];

    foreach ($sentences as $sentence) {

        $hasCommunicationAttempt =
            operational_text_contains_any(
                $sentence,
                [
                    'called her name',
                    'called his name',
                    'called their name',
                    'tried to tell',
                    'attempted to tell',
                    'tried to speak',
                    'attempted to speak',
                    'asked her',
                    'asked him',
                    'asked them',
                    'told her',
                    'told him',
                    'told them'
                ]
            );

        $hasNonResponse =
            operational_text_contains_any(
                $sentence,
                [
                    'ignored',
                    'continued walking',
                    'continued to walk',
                    'kept walking',
                    'did not answer',
                    "didn't answer",
                    'would not answer',
                    "wouldn't answer",
                    'did not respond',
                    "didn't respond",
                    'would not respond',
                    "wouldn't respond",
                    'refused to respond'
                ]
            );

        if (
            $hasCommunicationAttempt
            && $hasNonResponse
        ) {
            $communicationMatches[] =
                $sentence;
        }

        if (
            count($communicationMatches)
            >= 3
        ) {
            break;
        }
    }

    if ($communicationMatches) {
        $excerpt = operational_excerpt($communicationMatches);

        $addFinding(
            operational_make_finding(
                'communication_breakdown',
                'Attempted workplace communication may not have been acknowledged',
                $excerpt,
                'Work-related communication should ordinarily be acknowledged or routed appropriately when a response is needed.',
                'The worker describes an attempt to communicate that was not acknowledged or did not receive a response.',
                'Unacknowledged communication can leave assignments, production issues, or workplace concerns unresolved.',
                $workerResponse,
                $managementResponse,
                'The statement documents the worker\'s perception and described sequence. It does not establish intent behind another person\'s failure to respond.',
                'The event may warrant review of whether the communication was heard, whether a response was expected, and whether follow-up occurred.',
                'Was the communication received, and was a response or follow-up expected under normal procedure?',
                'medium',
                $excerpt,
                'unknown'
            )
        );
    }

    /*
     * Equipment / workstation / process failure.
     *
     * Keep this factual: the finding says the worker reported an
     * operational condition. It does not decide fault.
     */
    $equipmentMatches = operational_matching_sentences(
        $sentences,
        [
            'error message',
            'showing an error',
            'showed an error',
            'same error',
            'stopped responding',
            'not responding',
            'not working correctly',
            'not working',
            'machine stopped',
            'machine down',
            'system stopped',
            'system error',
            'console stopped',
            'console error'
        ],
        4
    );

    if ($equipmentMatches) {
        $excerpt = operational_excerpt(
            $equipmentMatches
        );

        $addFinding(
            operational_make_finding(
                'production_readiness',
                'Equipment or process problem was reported during assigned work',
                $excerpt,
                'Assigned equipment or systems would ordinarily be expected to function sufficiently for the worker to perform the required task and produce reliable results.',
                'The report describes an error, malfunction, loss of response, or another operational condition affecting the assigned equipment or process.',
                'The reported condition may have affected the worker\'s ability to continue normal work or produce reliable results.',
                $workerResponse,
                $managementResponse,
                'The report documents the condition described by the worker. It does not by itself establish the technical cause of the malfunction or responsibility for correcting it.',
                'Equipment condition may be relevant when evaluating production expectations, work instructions, downtime, quality concerns, or later discipline.',
                'What technical, maintenance, system, or production records document the reported equipment condition and how it was handled?',
                'high',
                $excerpt,
                'unknown'
            )
        );
    }

    /*
     * Reported concern followed by an instruction to continue.
     */
    $noticeMatches = operational_matching_sentences(
        $sentences,
        [
            'I told ',
            'I reported ',
            'I informed ',
            'I notified ',
            'told my supervisor',
            'told my lead',
            'told my manager'
        ],
        4
    );

    $continueMatches = operational_matching_sentences(
        $sentences,
        [
            'continue working',
            'keep running',
            'keep working',
            'continue running',
            'restart it',
            'restart the',
            'keep going'
        ],
        4
    );

    if ($noticeMatches && $continueMatches) {
        $source = array_merge(
            $noticeMatches,
            $continueMatches
        );

        $excerpt = operational_excerpt(
            $source
        );

        $addFinding(
            operational_make_finding(
                'management_response',
                'Worker reports raising an operational concern before being directed to continue',
                operational_excerpt($noticeMatches),
                'When an employee reports an operational problem, the response would ordinarily depend on the nature of the problem, workplace procedure, equipment condition, and potential effect on work or quality.',
                operational_excerpt($continueMatches),
                'The instruction to continue may be relevant when reviewing later production results, downtime, expectations, or discipline connected with the same work.',
                $workerResponse,
                $managementResponse,
                'This finding records the sequence reported by the worker. It does not determine whether the instruction was appropriate or whether management knew the technical severity of the condition.',
                'The timing between notice, management instruction, equipment behavior, and later employment action may warrant review as one sequence rather than as isolated events.',
                'What information was available when the concern was reported, and what response or instruction was given at that time?',
                'high',
                $excerpt,
                'unknown'
            )
        );
    }

    /*
     * Discipline occurring in the same reported sequence as an earlier
     * equipment or operational problem.
     */
    $disciplineMatches = operational_matching_sentences(
        $sentences,
        [
            'written warning',
            'write-up',
            'write up',
            'written up',
            'discipline',
            'disciplinary',
            'warning'
        ],
        4
    );

    if (
        $disciplineMatches
        && (
            $equipmentMatches
            || $noticeMatches
        )
    ) {
        $source = array_merge(
            $equipmentMatches,
            $noticeMatches,
            $disciplineMatches
        );

        $excerpt = operational_excerpt(
            $source
        );

        $addFinding(
            operational_make_finding(
                'disciplinary_process',
                'Discipline was reported after an earlier operational concern in the same work sequence',
                operational_excerpt(
                    $disciplineMatches
                ),
                'A disciplinary review would ordinarily consider the relevant work assignment, equipment condition, instructions given, employee actions, available records, and other surrounding facts.',
                'The report describes discipline occurring after the worker had reported an operational concern involving the same work sequence.',
                'The sequence may be relevant when determining whether the disciplinary record accurately reflects the circumstances surrounding the work.',
                $workerResponse,
                $managementResponse,
                'The sequence alone does not establish that the discipline was improper, retaliatory, discriminatory, or otherwise unlawful.',
                'The earlier reported condition and management response may provide factual context that should be reviewed alongside the stated reason for discipline.',
                'What reason was documented for the discipline, and were the reported equipment condition and prior instructions considered?',
                'high',
                $excerpt,
                'unknown'
            )
        );
    }



    /*
     * Attendance / leave / medical absence.
     *
     * This records that a leave-related or medical absence event was
     * documented. It does not decide whether leave was legally protected,
     * approved, denied, or handled correctly.
     */
    $leaveMatches = operational_matching_sentences(
        $sentences,
        [
            'called in',
            'call in',
            'fmla',
            'medical leave',
            'protected leave',
            'leave of absence',
            'went to the hospital',
            'went to hospital',
            'at the hospital',
            'hospital',
            'emergency room',
            'er visit'
        ],
        4
    );

    if ($leaveMatches) {

        $excerpt =
            operational_excerpt(
                $leaveMatches
            );

        $addFinding(
            operational_make_finding(
                'attendance_process',
                'A medical or leave-related absence was documented',
                $excerpt,
                'Attendance and leave events should ordinarily be recorded and handled according to the applicable workplace leave and attendance process.',
                'The report documents an absence, call-in, medical treatment, or leave-related event.',
                'The event may affect attendance records, scheduling, leave administration, or later review of employment actions connected with attendance.',
                '',
                '',
                'This finding records the leave or absence event described by the worker. It does not determine whether the absence qualifies for legal protection, whether required notice was satisfied, or whether the employer handled the leave correctly.',
                'The leave event should remain linked to attendance records, supporting documentation, and any later employment action involving the same period.',
                'What attendance, leave, medical-certification, approval, point, or scheduling records correspond to this absence?',
                'high',
                $excerpt,
                'unknown'
            )
        );
    }


    $qualityMatches = operational_matching_sentences(
        $sentences,
        [
            'bad part',
            'bad parts',
            'defect',
            'defective',
            'scrap',
            'quality',
            'inspection',
            'under-cured',
            'undercured',
            'quality hold',
            'test my parts',
            'testing',
            'unsuitable for production',
            'passed a bad part',
            'passing bad parts',
            'put in my bin',
            'placed in my bin'
        ],
        8
    );

    if ($qualityMatches) {
        $excerpt = operational_excerpt($qualityMatches);

        $addFinding(
            operational_make_finding(
                'quality_process',
                'Quality-control or part-accountability process may require review',
                $excerpt,
                'Quality concerns should ordinarily be traceable to the part, inspection step, handling process, and responsible workstation or employee before discipline or corrective action is assigned.',
                'The report references quality, defective parts, inspection, scrap, or disputed responsibility for parts.',
                'Weak traceability or disputed handling can affect product quality decisions and employee accountability.',
                $workerResponse,
                $managementResponse,
                'The statement can identify a quality-process concern but does not establish who caused a defect or whether a quality determination was correct.',
                'Relevant records may include inspection results, timestamps, machine records, bin or lot traceability, quality holds, and witness information.',
                'What objective quality or traceability records identify where the part was produced, handled, inspected, and rejected?',
                'medium',
                $excerpt,
                'unknown'
            )
        );
    }

    return $findings;
}

function save_report_operational_findings(
    int $reportId,
    array $findings
): void {
    ensure_operational_analysis_schema();

    $pdo = db();

    $pdo->prepare("
        DELETE FROM report_operational_findings
        WHERE report_id = ?
    ")->execute([$reportId]);

    $typeOptions = operational_finding_type_options();
    $confidenceOptions = operational_confidence_options();
    $resolutionOptions = operational_resolution_options();

    $stmt = $pdo->prepare("
        INSERT INTO report_operational_findings (
            report_id,
            finding_type,
            title,
            observed_fact,
            expected_condition,
            observed_condition,
            operational_impact,
            worker_response,
            management_response,
            responsibility_context,
            reasoning,
            review_question,
            confidence,
            source_excerpt,
            resolution_status,
            detection_source,
            created_at
        )
        VALUES (
            :report_id,
            :finding_type,
            :title,
            :observed_fact,
            :expected_condition,
            :observed_condition,
            :operational_impact,
            :worker_response,
            :management_response,
            :responsibility_context,
            :reasoning,
            :review_question,
            :confidence,
            :source_excerpt,
            :resolution_status,
            'reviewed_rules',
            datetime('now', 'localtime')
        )
    ");

    foreach ($findings as $finding) {
        if (empty($finding['enabled'])) {
            continue;
        }

        $observedFact = trim($finding['observed_fact'] ?? '');

        if ($observedFact === '') {
            continue;
        }

        $findingType = trim($finding['finding_type'] ?? 'other');

        if (!array_key_exists($findingType, $typeOptions)) {
            $findingType = 'other';
        }

        $confidence = trim($finding['confidence'] ?? 'medium');

        if (!array_key_exists($confidence, $confidenceOptions)) {
            $confidence = 'medium';
        }

        $resolutionStatus = trim(
            $finding['resolution_status'] ?? 'unknown'
        );

        if (!array_key_exists($resolutionStatus, $resolutionOptions)) {
            $resolutionStatus = 'unknown';
        }

        $stmt->execute([
            ':report_id' => $reportId,
            ':finding_type' => $findingType,
            ':title' => trim($finding['title'] ?? ($typeOptions[$findingType] ?? 'Operational Finding')),
            ':observed_fact' => $observedFact,
            ':expected_condition' => trim($finding['expected_condition'] ?? ''),
            ':observed_condition' => trim($finding['observed_condition'] ?? ''),
            ':operational_impact' => trim($finding['operational_impact'] ?? ''),
            ':worker_response' => trim($finding['worker_response'] ?? ''),
            ':management_response' => trim($finding['management_response'] ?? ''),
            ':responsibility_context' => trim($finding['responsibility_context'] ?? ''),
            ':reasoning' => trim($finding['reasoning'] ?? ''),
            ':review_question' => trim($finding['review_question'] ?? ''),
            ':confidence' => $confidence,
            ':source_excerpt' => trim($finding['source_excerpt'] ?? ''),
            ':resolution_status' => $resolutionStatus
        ]);
    }
}

function clear_report_operational_findings(int $reportId): void
{
    ensure_operational_analysis_schema();

    db()->prepare("
        DELETE FROM report_operational_findings
        WHERE report_id = ?
    ")->execute([$reportId]);
}

function operational_findings_for_report(int $reportId): array
{
    ensure_operational_analysis_schema();

    $stmt = db()->prepare("
        SELECT *
        FROM report_operational_findings
        WHERE report_id = ?
        ORDER BY id
    ");

    $stmt->execute([$reportId]);

    return $stmt->fetchAll();
}
