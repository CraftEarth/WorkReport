<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/work-journal.php';

$journalAnalyzer = __DIR__ . '/analyzer.php';
if (is_file($journalAnalyzer)) {
    require_once $journalAnalyzer;
}

const JOURNAL_INTELLIGENCE_ENGINE_VERSION = '0.2.0-local';

function journal_intelligence_table_exists(string $table): bool
{
    $stmt = db()->prepare("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'table'
          AND name = ?
        LIMIT 1
    ");

    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

function journal_intelligence_column_exists(
    string $table,
    string $column
): bool {
    if (!journal_intelligence_table_exists($table)) {
        return false;
    }

    $rows = db()->query(
        'PRAGMA table_info(' . $table . ')'
    )->fetchAll();

    foreach ($rows as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function ensure_journal_intelligence_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_intelligence_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            source_message_id INTEGER NOT NULL UNIQUE,
            source_sequence INTEGER NOT NULL,
            source_content_hash TEXT,
            engine_version TEXT NOT NULL,
            summary_text TEXT NOT NULL,
            severity TEXT NOT NULL DEFAULT 'normal',
            confidence REAL NOT NULL DEFAULT 0.50,
            analysis_json TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_intel_runs_worker
        ON journal_intelligence_runs(worker_id, session_id, source_sequence);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_intelligence_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            source_message_id INTEGER NOT NULL,
            source_sequence INTEGER NOT NULL,
            event_index INTEGER NOT NULL,
            organization_id INTEGER,
            employment_id INTEGER,
            event_date TEXT,
            event_time TEXT,
            date_precision TEXT NOT NULL DEFAULT 'workday',
            category TEXT NOT NULL,
            event_type TEXT NOT NULL,
            fact_status TEXT NOT NULL DEFAULT 'reported',
            source_excerpt TEXT NOT NULL,
            summary_text TEXT NOT NULL,
            severity TEXT NOT NULL DEFAULT 'normal',
            confidence REAL NOT NULL DEFAULT 0.50,
            review_status TEXT NOT NULL DEFAULT 'unreviewed',
            review_notes TEXT,
            created_at TEXT NOT NULL,
            UNIQUE(source_message_id, event_index)
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_intel_events_worker
        ON journal_intelligence_events(worker_id, event_date, id);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_intel_events_source
        ON journal_intelligence_events(source_message_id, event_index);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_intelligence_event_entities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_value TEXT NOT NULL,
            normalized_value TEXT NOT NULL,
            role_hint TEXT,
            confidence REAL NOT NULL DEFAULT 0.50,
            source_excerpt TEXT,
            created_at TEXT NOT NULL,
            UNIQUE(event_id, entity_type, normalized_value)
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_intel_entities_worker
        ON journal_intelligence_event_entities(worker_id, entity_type, normalized_value);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_intelligence_signals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,
            signal_key TEXT NOT NULL,
            signal_label TEXT NOT NULL,
            category TEXT NOT NULL,
            severity TEXT NOT NULL DEFAULT 'normal',
            explanation TEXT NOT NULL,
            source_excerpt TEXT,
            created_at TEXT NOT NULL,
            UNIQUE(event_id, signal_key)
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_intel_signals_worker
        ON journal_intelligence_signals(worker_id, signal_key, id);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_intelligence_facts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,
            fact_type TEXT NOT NULL,
            subject_text TEXT,
            predicate_text TEXT NOT NULL,
            object_text TEXT,
            fact_status TEXT NOT NULL DEFAULT 'reported',
            confidence REAL NOT NULL DEFAULT 0.50,
            source_excerpt TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_intel_facts_worker
        ON journal_intelligence_facts(worker_id, fact_type, id);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_intelligence_followups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            source_message_id INTEGER NOT NULL,
            event_id INTEGER,
            question_key TEXT NOT NULL,
            question_text TEXT NOT NULL,
            priority TEXT NOT NULL DEFAULT 'helpful',
            status TEXT NOT NULL DEFAULT 'open',
            answered_by_message_id INTEGER,
            created_at TEXT NOT NULL,
            resolved_at TEXT,
            UNIQUE(source_message_id, question_key)
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_intel_followups_worker
        ON journal_intelligence_followups(worker_id, session_id, status, id);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_intelligence_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            source_event_id INTEGER NOT NULL,
            related_event_id INTEGER NOT NULL,
            relation_type TEXT NOT NULL,
            relation_score INTEGER NOT NULL DEFAULT 0,
            explanation TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(source_event_id, related_event_id, relation_type)
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_intel_links_worker
        ON journal_intelligence_links(worker_id, source_event_id, related_event_id);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_intelligence_memory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            memory_type TEXT NOT NULL,
            memory_key TEXT NOT NULL,
            display_value TEXT NOT NULL,
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            mention_count INTEGER NOT NULL DEFAULT 1,
            last_source_message_id INTEGER,
            UNIQUE(worker_id, memory_type, memory_key)
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_intel_memory_worker
        ON journal_intelligence_memory(worker_id, memory_type, mention_count);
    ");


    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_person_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            canonical_name TEXT NOT NULL,
            alias TEXT NOT NULL,
            normalized_alias TEXT NOT NULL,
            confidence REAL NOT NULL DEFAULT 1.00,
            source TEXT NOT NULL DEFAULT 'user_confirmed',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(worker_id, normalized_alias)
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_person_aliases_worker
        ON journal_person_aliases(
            worker_id,
            normalized_alias
        );
    ");


    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_person_memory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            canonical_name TEXT NOT NULL,
            normalized_name TEXT NOT NULL,
            role_hint TEXT,
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            mention_count INTEGER NOT NULL DEFAULT 1,
            last_source_message_id INTEGER,
            UNIQUE(worker_id, normalized_name)
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_journal_person_memory_worker
        ON journal_person_memory(
            worker_id,
            normalized_name,
            mention_count
        );
    ");
}

function journal_intelligence_normalize_key(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9\s\-_\/]/u', '', $value) ?? $value;

    return trim($value);
}

function journal_intelligence_severity_rank(string $severity): int
{
    return match ($severity) {
        'critical' => 4,
        'high' => 3,
        'concern' => 2,
        default => 1,
    };
}

function journal_intelligence_max_severity(array $signals): string
{
    $severity = 'normal';

    foreach ($signals as $signal) {
        $candidate = (string) ($signal['severity'] ?? 'normal');

        if (
            journal_intelligence_severity_rank($candidate)
            > journal_intelligence_severity_rank($severity)
        ) {
            $severity = $candidate;
        }
    }

    return $severity;
}

function journal_intelligence_signal_rules(): array
{
    return [
        'critical_sexual_assault' => [
            'label' => 'Possible sexual assault / forced sexual conduct statement',
            'category' => 'Critical Safety',
            'severity' => 'critical',
            'patterns' => [
                '/\bsexual assault(?:ed)?\b/i',
                '/\brape(?:d)?\b/i',
                '/\bforced (?:me|her|him|them) to have sex\b/i',
                '/\bforced himself on\b/i',
                '/\bforced herself on\b/i',
            ],
            'explanation' => 'The source text contains language that may describe forced sexual conduct. The system is preserving the statement and prioritizing immediate safety, not making a legal finding.',
        ],
        'critical_violence_threat' => [
            'label' => 'Possible serious violence or threat',
            'category' => 'Critical Safety',
            'severity' => 'critical',
            'patterns' => [
                '/\bthreaten(?:ed|ing)? to kill\b/i',
                '/\b(?:had|pulled|showed|pointed) (?:a )?gun\b/i',
                '/\bchok(?:e|ed|ing)\b/i',
                '/\bheld (?:me|her|him|them) against (?:my|her|his|their) will\b/i',
                '/\battacked (?:me|her|him|them)\b/i',
            ],
            'explanation' => 'The source text contains language that may describe serious violence or a threat. The system is prioritizing immediate safety without deciding what legally occurred.',
        ],
        'unwanted_touching' => [
            'label' => 'Unwanted physical or sexual touching mentioned',
            'category' => 'Harassment / Conduct',
            'severity' => 'high',
            'patterns' => [
                '/\bunwanted (?:touch|touching|contact)\b/i',
                '/\bgrabbed (?:my|her|his) (?:ass|butt|breast|chest)\b/i',
                '/\bslapp(?:ed|ing) (?:me|her|him) (?:on )?(?:the )?(?:ass|butt)\b/i',
                '/\btouched (?:my|her|his) (?:ass|butt|breast|chest)\b/i',
            ],
            'explanation' => 'The worker reports physical conduct that may be unwanted. This is a behavioral concern requiring careful factual documentation.',
        ],
        'harassment_behavior' => [
            'label' => 'Harassment or repeated unwanted conduct mentioned',
            'category' => 'Harassment / Conduct',
            'severity' => 'concern',
            'patterns' => [
                '/\bharass(?:ed|ing|ment)?\b/i',
                '/\bsexual comment(?:s)?\b/i',
                '/\bkeeps? (?:following|watching|staring)\b/i',
                '/\bstalk(?:ed|ing)?\b/i',
                '/\bwon[\'â€™]?t leave (?:me|her|him) alone\b/i',
                '/\bcornered (?:me|her|him)\b/i',
            ],
            'explanation' => 'The worker reports conduct that may involve repeated or unwanted behavior. Motive is not inferred.',
        ],
        'fmla_leave' => [
            'label' => 'FMLA / protected leave mentioned',
            'category' => 'Leave / FMLA',
            'severity' => 'concern',
            'patterns' => [
                '/\bfmla\b/i',
                '/\bintermittent leave\b/i',
                '/\bprotected leave\b/i',
            ],
            'explanation' => 'The source mentions FMLA or protected leave. This may become important when compared with attendance or discipline records.',
        ],
        'attendance_action' => [
            'label' => 'Attendance point / occurrence mentioned',
            'category' => 'Attendance / Discipline',
            'severity' => 'concern',
            'patterns' => [
                '/\battendance point(?:s)?\b/i',
                '/\bpointed (?:me|her|him)\b/i',
                '/\b(?:got|received|given|assessed) (?:an? )?point\b/i',
                '/\boccurrence(?:s)?\b/i',
                '/\btard(?:y|iness)\b/i',
            ],
            'explanation' => 'The worker reports an attendance-related action or record.',
        ],
        'discipline' => [
            'label' => 'Discipline or adverse action mentioned',
            'category' => 'Attendance / Discipline',
            'severity' => 'concern',
            'patterns' => [
                '/\bwrite[- ]?up\b/i',
                '/\bwritten up\b/i',
                '/\bdisciplin(?:e|ed|ary)\b/i',
                '/\bwarning\b/i',
                '/\bsuspend(?:ed|sion)?\b/i',
                '/\bterminat(?:ed|ion)\b/i',
                '/\bfired\b/i',
            ],
            'explanation' => 'The source describes a disciplinary or employment action. The engine records the action without deciding whether it was justified.',
        ],
        'management_instruction' => [
            'label' => 'Work instruction or directive mentioned',
            'category' => 'Management Instruction',
            'severity' => 'normal',
            'patterns' => [
                '/\b(?:told|instructed|ordered|asked) (?:me|us) to\b/i',
                '/\bmy (?:lead|supervisor|manager) (?:said|told|instructed|ordered)\b/i',
            ],
            'explanation' => 'The worker reports receiving a work instruction or directive.',
        ],
        'management_notice' => [
            'label' => 'Possible notice to management / quality / HR',
            'category' => 'Notice / Response',
            'severity' => 'normal',
            'patterns' => [
                '/\bI (?:told|reported|informed|notified|showed) (?:my )?(?:lead|supervisor|manager|quality|hr|human resources|area owner)\b/i',
                '/\b(?:reported|informed|notified) (?:it|this|the issue|the problem) to\b/i',
                '/\bfiled (?:a )?(?:complaint|report|appeal)\b/i',
            ],
            'explanation' => 'The worker reports that someone in management, quality, HR, or another responsible role may have been notified.',
        ],
        'quality_defect' => [
            'label' => 'Defective / failed part or quality issue mentioned',
            'category' => 'Quality / Production',
            'severity' => 'concern',
            'patterns' => [
                '/\bbad part(?:s)?\b/i',
                '/\bdefective part(?:s)?\b/i',
                '/\bfailed part(?:s)?\b/i',
                '/\bpart(?:s)? (?:failed|failing|rejected)\b/i',
                '/\bquality issue\b/i',
                '/\bscrap(?:ped)?\b/i',
                '/\bred rabbit\b/i',
            ],
            'explanation' => 'The source describes a part or output that may have failed a quality requirement.',
        ],
        'unexpected_acceptance' => [
            'label' => 'Known or suspected bad output reportedly accepted / passed',
            'category' => 'Quality / Production',
            'severity' => 'high',
            'patterns' => [
                '/\baccepted bin\b/i',
                '/\b(?:bad|defective|failed|red rabbit).{0,80}\b(?:passed|accepted)\b/is',
                '/\b(?:passed|accepted).{0,80}\b(?:bad|defective|failed|red rabbit)\b/is',
                '/\bdidn[\'â€™]?t reject\b/i',
                '/\bno (?:reject|error)\b/i',
                '/\bwithout (?:an? )?(?:reject|error)\b/i',
            ],
            'explanation' => 'The source may describe a known or suspected defective item being accepted without the expected rejection response. Equipment and process condition are relevant; operator causation is not assumed.',
        ],
        'machine_problem' => [
            'label' => 'Machine / process problem mentioned',
            'category' => 'Machine / Process',
            'severity' => 'concern',
            'patterns' => [
                '/\bmachine.{0,80}\b(?:stopped|down|fault|error|failed|failing|broken|not working)\b/is',
                '/\brobot.{0,80}\b(?:stopped|down|fault|error|failed|failing|broken|not working)\b/is',
                '/\b(?:fault|error) code\b/i',
                '/\bcalibrat(?:e|ed|ion)\b/i',
                '/\bsensor\b/i',
            ],
            'explanation' => 'The worker reports a machine or process condition that may be relevant to production or quality outcomes.',
        ],
        'material_process_change' => [
            'label' => 'Material or process substitution / deviation mentioned',
            'category' => 'Material / Process',
            'severity' => 'concern',
            'patterns' => [
                '/\bran out of (?:the )?(?:normal|regular|approved)?\s*(?:material|paint|component|parts?)\b/i',
                '/\bsubstitut(?:e|ed|ion)\b/i',
                '/\bdifferent (?:material|paint|component)\b/i',
                '/\bhouse paint\b/i',
                '/\bnot (?:the )?(?:normal|regular|approved) (?:material|paint|component)\b/i',
            ],
            'explanation' => 'The source describes a possible material or process change. The engine records the deviation and timing without inferring chemistry or causation.',
        ],
        'safety_hazard' => [
            'label' => 'Workplace safety concern mentioned',
            'category' => 'Safety',
            'severity' => 'high',
            'patterns' => [
                '/\bunsafe\b/i',
                '/\bhazard(?:ous)?\b/i',
                '/\bnearly (?:hit|crushed|injured)\b/i',
                '/\balmost (?:hit|crushed|injured)\b/i',
                '/\binjur(?:y|ed)\b/i',
                '/\blockout\b/i',
                '/\bchemical (?:spill|exposure|burn)\b/i',
            ],
            'explanation' => 'The worker reports a possible workplace safety condition or injury.',
        ],
        'retaliation_timing' => [
            'label' => 'Adverse treatment after a report / complaint mentioned',
            'category' => 'Retaliation Timing',
            'severity' => 'concern',
            'patterns' => [
                '/\bafter I (?:reported|complained|told|filed)\b/i',
                '/\bsince I (?:reported|complained|told|filed)\b/i',
                '/\bafter (?:my|the) complaint\b/i',
                '/\bafter (?:my|the) fmla\b/i',
            ],
            'explanation' => 'The source explicitly links timing between an earlier report, complaint, or leave event and later treatment. Timing is recorded; retaliatory motive is not assumed.',
        ],
        'witness_mention' => [
            'label' => 'Witness or other observer mentioned',
            'category' => 'Witness / Corroboration',
            'severity' => 'normal',
            'patterns' => [
                '/\bwitness(?:ed|es)?\b/i',
                '/\bsaw it\b/i',
                '/\bheard it\b/i',
                '/\bwas there\b/i',
                '/\bwere there\b/i',
            ],
            'explanation' => 'The source suggests another person may have observed part of the event.',
        ],
        'evidence_mention' => [
            'label' => 'Supporting evidence mentioned',
            'category' => 'Evidence',
            'severity' => 'normal',
            'patterns' => [
                '/\bphoto(?:s)?\b/i',
                '/\bpicture(?:s)?\b/i',
                '/\bscreenshot(?:s)?\b/i',
                '/\bvideo\b/i',
                '/\bemail(?:s)?\b/i',
                '/\btext message(?:s)?\b/i',
                '/\bpaperwork\b/i',
                '/\bwrite[- ]?up paper\b/i',
                '/\battendance record\b/i',
            ],
            'explanation' => 'The worker mentions a document, image, message, or other item that may support part of the record.',
        ],
        'pay_wage' => [
            'label' => 'Pay / wage issue mentioned',
            'category' => 'Pay / Hours',
            'severity' => 'concern',
            'patterns' => [
                '/\bpaycheck\b/i',
                '/\bovertime\b/i',
                '/\bunpaid\b/i',
                '/\bclock(?:ed)? (?:in|out)\b/i',
                '/\bwage(?:s)?\b/i',
                '/\bpay rate\b/i',
            ],
            'explanation' => 'The source mentions pay, hours, or timekeeping.',
        ],
        'accommodation' => [
            'label' => 'Accommodation / restriction issue mentioned',
            'category' => 'Accommodation',
            'severity' => 'concern',
            'patterns' => [
                '/\baccommodation\b/i',
                '/\bwork restriction(?:s)?\b/i',
                '/\bdoctor(?:\'s)? note\b/i',
                '/\bmedical restriction(?:s)?\b/i',
            ],
            'explanation' => 'The source mentions an accommodation or work restriction. The engine does not infer medical details.',
        ],
    ];
}

function journal_intelligence_detect_signals(string $text): array
{
    $signals = [];

    foreach (journal_intelligence_signal_rules() as $key => $rule) {
        foreach ($rule['patterns'] as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $signals[$key] = [
                    'key' => $key,
                    'label' => $rule['label'],
                    'category' => $rule['category'],
                    'severity' => $rule['severity'],
                    'explanation' => $rule['explanation'],
                    'source_excerpt' => trim((string) ($match[0] ?? '')),
                ];
                break;
            }
        }
    }

    return array_values($signals);
}

function journal_intelligence_detect_time(string $text): ?string
{
    if (
        preg_match(
            '/\b(\d{1,2}):(\d{2})\s*(a\.?m\.?|p\.?m\.?)?\b/i',
            $text,
            $match
        )
    ) {
        $hour = (int) $match[1];
        $minute = (int) $match[2];
        $ampm = strtolower(str_replace('.', '', (string) ($match[3] ?? '')));

        if ($minute > 59 || $hour > 23) {
            return null;
        }

        if ($ampm === 'pm' && $hour < 12) {
            $hour += 12;
        }

        if ($ampm === 'am' && $hour === 12) {
            $hour = 0;
        }

        if ($ampm !== '' && ($hour < 0 || $hour > 23)) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    if (
        preg_match(
            '/\b(\d{1,2})\s*(a\.?m\.?|p\.?m\.?)\b/i',
            $text,
            $match
        )
    ) {
        $hour = (int) $match[1];
        $ampm = strtolower(str_replace('.', '', $match[2]));

        if ($hour < 1 || $hour > 12) {
            return null;
        }

        if ($ampm === 'pm' && $hour < 12) {
            $hour += 12;
        }

        if ($ampm === 'am' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:00', $hour);
    }

    return null;
}

function journal_intelligence_detect_event_date(
    string $text,
    ?string $workdayDate
): array {
    $date = null;
    $precision = 'unknown';

    if (
        preg_match(
            '/\b(20\d{2})[-\/](\d{1,2})[-\/](\d{1,2})\b/',
            $text,
            $match
        )
    ) {
        $candidate = sprintf(
            '%04d-%02d-%02d',
            (int) $match[1],
            (int) $match[2],
            (int) $match[3]
        );

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $candidate);

        if ($parsed && $parsed->format('Y-m-d') === $candidate) {
            return [
                'date' => $candidate,
                'precision' => 'exact',
            ];
        }
    }

    if (
        preg_match(
            '/\b(\d{1,2})\/(\d{1,2})\/(20\d{2})\b/',
            $text,
            $match
        )
    ) {
        $candidate = sprintf(
            '%04d-%02d-%02d',
            (int) $match[3],
            (int) $match[1],
            (int) $match[2]
        );

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $candidate);

        if ($parsed && $parsed->format('Y-m-d') === $candidate) {
            return [
                'date' => $candidate,
                'precision' => 'exact',
            ];
        }
    }

    if ($workdayDate) {
        try {
            $base = new DateTimeImmutable($workdayDate);

            if (preg_match('/\byesterday\b/i', $text)) {
                return [
                    'date' => $base->modify('-1 day')->format('Y-m-d'),
                    'precision' => 'relative',
                ];
            }

            if (preg_match('/\btomorrow\b/i', $text)) {
                return [
                    'date' => $base->modify('+1 day')->format('Y-m-d'),
                    'precision' => 'relative',
                ];
            }

            $date = $base->format('Y-m-d');
            $precision = 'workday';
        } catch (Throwable $e) {
            $date = null;
            $precision = 'unknown';
        }
    }

    return [
        'date' => $date,
        'precision' => $precision,
    ];
}

function journal_intelligence_split_events(string $text): array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", trim($text));

    $parts = preg_split(
        '/(?<=[.!?])\s+|\n+|\s+(?=(?:then|after that|later|afterward|next)\b)/i',
        $normalized,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    if (!$parts) {
        return [$normalized];
    }

    $chunks = [];

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        if (mb_strlen($part) < 8 && $chunks) {
            $chunks[count($chunks) - 1] .= ' ' . $part;
            continue;
        }

        $chunks[] = $part;
    }

    if (!$chunks && $normalized !== '') {
        $chunks[] = $normalized;
    }

    return array_slice($chunks, 0, 30);
}

function journal_intelligence_extract_role_entities(string $text): array
{
    $entities = [];

    $rolePatterns = [
        'lead' => '/\b(?:my\s+)?lead\b/i',
        'supervisor' => '/\b(?:my\s+)?supervisor\b/i',
        'manager' => '/\b(?:my\s+)?manager\b/i',
        'quality' => '/\bquality\b/i',
        'hr' => '/\b(?:hr|human resources)\b/i',
        'maintenance' => '/\bmaintenance\b/i',
        'area owner' => '/\barea owner\b/i',
        'coworker' => '/\bcoworker\b/i',
    ];

    foreach ($rolePatterns as $role => $pattern) {
        if (preg_match($pattern, $text, $match)) {
            $entities[] = [
                'type' => 'role',
                'value' => ucwords($role),
                'role_hint' => $role,
                'confidence' => 0.85,
                'source_excerpt' => trim((string) $match[0]),
            ];
        }
    }

    if (
        preg_match_all(
            '/\b(?:lead|supervisor|manager|quality|coworker|employee|area owner|maintenance)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/',
            $text,
            $matches,
            PREG_SET_ORDER
        )
    ) {
        foreach ($matches as $match) {
            $entities[] = [
                'type' => 'person',
                'value' => trim($match[1]),
                'role_hint' => strtolower(trim(strtok($match[0], ' '))),
                'confidence' => 0.88,
                'source_excerpt' => trim($match[0]),
            ];
        }
    }

    return $entities;
}

function journal_intelligence_worker_known_people(
    int $workerId,
    string $text
): array {
    if ($workerId < 1) {
        return [];
    }

    ensure_journal_intelligence_schema();

    $stmt = db()->prepare("
        SELECT
            canonical_name,
            normalized_name,
            role_hint,
            mention_count
        FROM journal_person_memory
        WHERE worker_id = ?
        ORDER BY
            LENGTH(canonical_name) DESC,
            mention_count DESC
    ");

    $stmt->execute([
        $workerId,
    ]);

    $found = [];

    foreach ($stmt->fetchAll() as $row) {
        $name = trim(
            (string) ($row['canonical_name'] ?? '')
        );

        if ($name === '') {
            continue;
        }

        $pattern =
            '/(?<![\p{L}\p{N}])'
            . preg_quote($name, '/')
            . '(?![\p{L}\p{N}])/iu';

        if (
            preg_match(
                $pattern,
                $text,
                $match
            ) !== 1
        ) {
            continue;
        }

        $found[] = [
            'type' => 'person',
            'value' => $name,
            'role_hint' => $row['role_hint'] ?: null,
            'confidence' => 0.96,
            'source_excerpt' => trim(
                (string) $match[0]
            ),
        ];
    }

    return $found;
}

function journal_intelligence_person_memory_touch(
    int $workerId,
    string $canonicalName,
    ?string $roleHint,
    int $sourceMessageId
): void {
    $canonicalName = trim($canonicalName);

    if (
        $workerId < 1
        || $canonicalName === ''
    ) {
        return;
    }

    $normalized = journal_intelligence_normalize_key(
        $canonicalName
    );

    if ($normalized === '') {
        return;
    }

    ensure_journal_intelligence_schema();

    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare("
        INSERT INTO journal_person_memory (
            worker_id,
            canonical_name,
            normalized_name,
            role_hint,
            first_seen_at,
            last_seen_at,
            mention_count,
            last_source_message_id
        )
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ON CONFLICT(worker_id, normalized_name)
        DO UPDATE SET
            canonical_name = excluded.canonical_name,
            role_hint = CASE
                WHEN excluded.role_hint IS NOT NULL
                 AND excluded.role_hint <> ''
                THEN excluded.role_hint
                ELSE journal_person_memory.role_hint
            END,
            last_seen_at = excluded.last_seen_at,
            mention_count =
                journal_person_memory.mention_count + 1,
            last_source_message_id =
                excluded.last_source_message_id
    ");

    $stmt->execute([
        $workerId,
        $canonicalName,
        $normalized,
        $roleHint,
        $now,
        $now,
        $sourceMessageId,
    ]);
}

function journal_intelligence_worker_person_aliases(
    int $workerId,
    string $text
): array {
    if ($workerId < 1) {
        return [];
    }

    ensure_journal_intelligence_schema();

    $stmt = db()->prepare("
        SELECT
            canonical_name,
            alias,
            normalized_alias,
            confidence
        FROM journal_person_aliases
        WHERE worker_id = ?
        ORDER BY
            LENGTH(normalized_alias) DESC,
            id ASC
    ");

    $stmt->execute([
        $workerId,
    ]);

    $found = [];

    foreach ($stmt->fetchAll() as $row) {
        $alias = trim(
            (string) ($row['alias'] ?? '')
        );

        if ($alias === '') {
            continue;
        }

        $pattern =
            '/(?<![\p{L}\p{N}])'
            . preg_quote($alias, '/')
            . '(?![\p{L}\p{N}])/iu';

        if (
            preg_match(
                $pattern,
                $text,
                $match
            ) !== 1
        ) {
            continue;
        }

        $canonical = trim(
            (string) (
                $row['canonical_name']
                ?? ''
            )
        );

        if ($canonical === '') {
            continue;
        }

        $found[] = [
            'type' => 'person',
            'value' => $canonical,
            'role_hint' => null,
            'confidence' => (float) (
                $row['confidence']
                ?? 1.0
            ),
            'source_excerpt' => trim(
                (string) $match[0]
            ),
        ];
    }

    return $found;
}

function journal_intelligence_save_person_alias(
    int $workerId,
    string $canonicalName,
    string $alias,
    string $source = 'user_confirmed',
    float $confidence = 1.0
): void {
    $canonicalName = trim($canonicalName);
    $alias = trim($alias);

    if (
        $workerId < 1
        || $canonicalName === ''
        || $alias === ''
    ) {
        return;
    }

    $normalized = journal_intelligence_normalize_key(
        $alias
    );

    if ($normalized === '') {
        return;
    }

    ensure_journal_intelligence_schema();

    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare("
        INSERT INTO journal_person_aliases (
            worker_id,
            canonical_name,
            alias,
            normalized_alias,
            confidence,
            source,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(worker_id, normalized_alias)
        DO UPDATE SET
            canonical_name = excluded.canonical_name,
            alias = excluded.alias,
            confidence = excluded.confidence,
            source = excluded.source,
            updated_at = excluded.updated_at
    ");

    $stmt->execute([
        $workerId,
        $canonicalName,
        $alias,
        $normalized,
        max(
            0.0,
            min(1.0, $confidence)
        ),
        $source,
        $now,
        $now,
    ]);
}


function journal_intelligence_extract_entities(
    string $text,
    ?int $workerId = null
): array {
    $entities = journal_intelligence_extract_role_entities($text);

    if ($workerId !== null && $workerId > 0) {
        foreach (
            journal_intelligence_worker_person_aliases(
                $workerId,
                $text
            )
            as $person
        ) {
            $entities[] = $person;
        }

        foreach (
            journal_intelligence_worker_known_people(
                $workerId,
                $text
            )
            as $person
        ) {
            $entities[] = $person;
        }
    }

    /*
     * The legacy people table is global.
     *
     * Never use it for a real worker-scoped journal analysis.
     * Worker journal memory must remain isolated.
     */
    if (
        ($workerId === null || $workerId < 1)
        && function_exists('detect_people')
    ) {
        try {
            foreach (detect_people($text) as $person) {
                $entities[] = [
                    'type' => 'person',
                    'value' => (string) $person,
                    'role_hint' => null,
                    'confidence' => 0.92,
                    'source_excerpt' => (string) $person,
                ];
            }
        } catch (Throwable $e) {
            // Legacy/no-worker analysis keeps working.
        }
    }

    if (function_exists('detect_places')) {
        try {
            foreach (detect_places($text) as $place) {
                $entities[] = [
                    'type' => 'work_area',
                    'value' => (string) $place,
                    'role_hint' => null,
                    'confidence' => 0.92,
                    'source_excerpt' => (string) $place,
                ];
            }
        } catch (Throwable $e) {
            // Learned places are optional. The journal engine keeps working.
        }
    }

    $machinePatterns = [
        '/\b(?:machine|line|press|robot|tester|station)\s+(?:#?\d+[A-Za-z0-9-]*|[A-Z]{1,4}\d+[A-Za-z0-9-]*|[A-Z])\b/i',
        '/\b(?:Ford Service|Eagle\s*\d+|CD\d+|SHIM)\b/i',
    ];

    foreach ($machinePatterns as $pattern) {
        if (
            preg_match_all(
                $pattern,
                $text,
                $matches
            )
        ) {
            foreach ($matches[0] as $value) {
                $entities[] = [
                    'type' => 'work_area',
                    'value' => trim((string) $value),
                    'role_hint' => null,
                    'confidence' => 0.72,
                    'source_excerpt' => trim((string) $value),
                ];
            }
        }
    }

    $evidenceTerms = [
        'photo', 'picture', 'screenshot', 'video', 'email',
        'text message', 'paperwork', 'write-up', 'attendance record'
    ];

    foreach ($evidenceTerms as $term) {
        if (mb_stripos($text, $term) !== false) {
            $entities[] = [
                'type' => 'evidence_type',
                'value' => $term,
                'role_hint' => null,
                'confidence' => 0.80,
                'source_excerpt' => $term,
            ];
        }
    }

    $deduped = [];

    foreach ($entities as $entity) {
        $normalized = journal_intelligence_normalize_key(
            (string) $entity['value']
        );

        if ($normalized === '') {
            continue;
        }

        $key = $entity['type'] . '|' . $normalized;

        if (!isset($deduped[$key])) {
            $entity['normalized_value'] = $normalized;
            $deduped[$key] = $entity;
        } elseif (
            (float) $entity['confidence']
            > (float) $deduped[$key]['confidence']
        ) {
            $entity['normalized_value'] = $normalized;
            $deduped[$key] = $entity;
        }
    }

    return array_values($deduped);
}

function journal_intelligence_extract_categories(
    string $text,
    array $signals
): array {
    $categories = [];

    foreach ($signals as $signal) {
        $category = trim((string) $signal['category']);
        if ($category !== '') {
            $categories[$category] = $category;
        }
    }

    if (function_exists('detect_categories')) {
        try {
            foreach (detect_categories($text) as $category) {
                $category = trim((string) $category);
                if ($category !== '') {
                    $categories[$category] = $category;
                }
            }
        } catch (Throwable $e) {
            // Existing learned categories are optional.
        }
    }

    if (!$categories) {
        $categories['General Workplace Event'] = 'General Workplace Event';
    }

    return array_values($categories);
}

function journal_intelligence_event_type(array $signals): string
{
    $priority = [
        'critical_sexual_assault' => 'critical_safety_event',
        'critical_violence_threat' => 'critical_safety_event',
        'unwanted_touching' => 'conduct_event',
        'fmla_leave' => 'leave_event',
        'attendance_action' => 'attendance_event',
        'discipline' => 'disciplinary_event',
        'unexpected_acceptance' => 'quality_event',
        'quality_defect' => 'quality_event',
        'machine_problem' => 'equipment_event',
        'material_process_change' => 'process_event',
        'management_instruction' => 'instruction_event',
        'management_notice' => 'notice_event',
        'safety_hazard' => 'safety_event',
        'retaliation_timing' => 'timing_event',
        'pay_wage' => 'pay_event',
        'accommodation' => 'accommodation_event',
    ];

    $keys = array_column($signals, 'key');

    foreach ($priority as $key => $eventType) {
        if (in_array($key, $keys, true)) {
            return $eventType;
        }
    }

    return 'workplace_event';
}

function journal_intelligence_extract_facts(string $text): array
{
    $facts = [];

    $patterns = [
        [
            'type' => 'instruction',
            'pattern' => '/\b(?:my\s+)?(?<subject>lead|supervisor|manager|area owner|quality|[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+(?:told|instructed|ordered|asked)\s+(?:me|us)\s+to\s+(?<object>[^.!?\n]+)/i',
            'predicate' => 'reportedly instructed worker to',
            'confidence' => 0.88,
        ],
        [
            'type' => 'notice',
            'pattern' => '/\bI\s+(?:told|reported|informed|notified|showed)\s+(?:my\s+)?(?<subject>lead|supervisor|manager|quality|hr|human resources|area owner|maintenance|[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+(?:that\s+)?(?<object>[^.!?\n]+)/i',
            'predicate' => 'worker reportedly notified',
            'confidence' => 0.87,
        ],
        [
            'type' => 'attendance_action',
            'pattern' => '/\bI\s+(?:got|received|was given|was assessed)\s+(?<object>(?:an?\s+)?(?:attendance\s+)?point|an?\s+occurrence|a\s+write[- ]?up|a\s+warning)(?<detail>[^.!?\n]*)/i',
            'predicate' => 'worker reportedly received',
            'confidence' => 0.86,
        ],
        [
            'type' => 'quality_outcome',
            'pattern' => '/\b(?<subject>part|parts|red rabbit|machine|robot|tester)\b(?<object>[^.!?\n]{0,140}\b(?:failed|passed|accepted|rejected|stopped|faulted|errored)\b[^.!?\n]*)/i',
            'predicate' => 'reported outcome',
            'confidence' => 0.78,
        ],
    ];

    foreach ($patterns as $rule) {
        if (!preg_match_all(
            $rule['pattern'],
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            continue;
        }

        foreach ($matches as $match) {
            $subject = trim((string) ($match['subject'] ?? 'worker'));
            $object = trim(
                (string) (
                    ($match['object'] ?? '')
                    . ($match['detail'] ?? '')
                )
            );

            $facts[] = [
                'fact_type' => $rule['type'],
                'subject_text' => $subject !== '' ? $subject : null,
                'predicate_text' => $rule['predicate'],
                'object_text' => $object !== '' ? $object : null,
                'fact_status' => 'reported',
                'confidence' => $rule['confidence'],
                'source_excerpt' => trim((string) $match[0]),
            ];
        }
    }

    return array_slice($facts, 0, 20);
}

function journal_intelligence_analyze_text(
    string $text,
    array $context = []
): array {
    $workdayDate = isset($context['workday_date'])
        ? (string) $context['workday_date']
        : null;

    $workerId = isset($context['worker_id'])
        ? (int) $context['worker_id']
        : null;

    $chunks = journal_intelligence_split_events($text);
    $events = [];
    $allSignals = [];
    $allEntities = [];

    foreach ($chunks as $index => $chunk) {
        $signals = journal_intelligence_detect_signals($chunk);
        $entities = journal_intelligence_extract_entities(
            $chunk,
            $workerId
        );
        $categories = journal_intelligence_extract_categories($chunk, $signals);
        $dateInfo = journal_intelligence_detect_event_date($chunk, $workdayDate);
        $time = journal_intelligence_detect_time($chunk);
        $severity = journal_intelligence_max_severity($signals);
        $facts = journal_intelligence_extract_facts($chunk);

        $confidence = 0.55;
        if ($signals) {
            $confidence += 0.12;
        }
        if ($entities) {
            $confidence += 0.08;
        }
        if ($time !== null) {
            $confidence += 0.05;
        }
        if ($facts) {
            $confidence += 0.05;
        }
        $confidence = min(0.92, $confidence);

        $events[] = [
            'event_index' => $index + 1,
            'event_date' => $dateInfo['date'],
            'event_time' => $time,
            'date_precision' => $dateInfo['precision'],
            'category' => $categories[0],
            'categories' => $categories,
            'event_type' => journal_intelligence_event_type($signals),
            'fact_status' => 'reported',
            'source_excerpt' => $chunk,
            'summary_text' => $chunk,
            'severity' => $severity,
            'confidence' => $confidence,
            'signals' => $signals,
            'entities' => $entities,
            'facts' => $facts,
        ];

        foreach ($signals as $signal) {
            $allSignals[$signal['key']] = $signal;
        }

        foreach ($entities as $entity) {
            $key = $entity['type'] . '|' . $entity['normalized_value'];
            $allEntities[$key] = $entity;
        }
    }

    $severity = journal_intelligence_max_severity(
        array_values($allSignals)
    );

    $categories = [];
    foreach ($events as $event) {
        foreach ($event['categories'] as $category) {
            $categories[$category] = $category;
        }
    }

    $analysis = [
        'events' => $events,
        'signals' => array_values($allSignals),
        'entities' => array_values($allEntities),
        'categories' => array_values($categories),
        'severity' => $severity,
    ];

    $analysis['intent'] = journal_intelligence_classify_intent(
        $text,
        $analysis
    );

    return $analysis;
}

function journal_intelligence_classify_intent(
    string $text,
    array $analysis
): string {
    $text = trim($text);

    if (($analysis['severity'] ?? 'normal') === 'critical') {
        return 'critical_safety';
    }

    $signalKeys = array_column(
        $analysis['signals'] ?? [],
        'key'
    );

    $entities = $analysis['entities'] ?? [];

    $strongEventSignals = [
        'unwanted_touching',
        'harassment_behavior',
        'attendance_action',
        'discipline',
        'management_instruction',
        'management_notice',
        'quality_defect',
        'unexpected_acceptance',
        'machine_problem',
        'material_process_change',
        'safety_hazard',
        'retaliation_timing',
        'pay_wage',
        'accommodation',
    ];

    $hasStrongEventSignal = (bool) array_intersect(
        $signalKeys,
        $strongEventSignals
    );

    /*
     * Direct workplace-action language.
     *
     * This catches plain conversational wording that may not have
     * triggered one of the lower-level signal rules yet.
     */
    $directWorkplaceEvent = preg_match(
        '/\b(?:'
        . 'wrote me up|write me up|written me up|'
        . 'gave me a write[- ]?up|'
        . 'gave me an? warning|'
        . 'pointed me|gave me an? point|'
        . 'sent me home|'
        . 'suspended me|'
        . 'fired me|terminated me|'
        . 'threatened me|'
        . 'yelled at me|'
        . 'grabbed me|touched me|'
        . 'machine (?:stopped|failed|broke|broke down)|'
        . 'machine isn[\'’]?t working|'
        . 'machine is not working'
        . ')\b/i',
        $text
    ) === 1;

    /*
     * Follow-up wording matters even when the worker does not repeat
     * the full details from an earlier incident.
     */
    $followupLanguage = preg_match(
        '/\b(?:'
        . 'again|'
        . 'same thing|'
        . 'same thing again|'
        . 'like before|'
        . 'this happened before|'
        . 'this time|'
        . 'still happening|'
        . 'keeps? happening|'
        . 'happened again|'
        . 'did it again|'
        . 'doing it again'
        . ')\b/i',
        $text
    ) === 1;

    /*
     * A named person, role, or work area gives vague follow-up
     * language enough context to be worth connecting with memory.
     */
    $hasContextEntity = false;

    foreach ($entities as $entity) {
        if (
            in_array(
                $entity['type'] ?? '',
                [
                    'person',
                    'role',
                    'work_area',
                ],
                true
            )
        ) {
            $hasContextEntity = true;
            break;
        }
    }

    if (
        $followupLanguage
        && (
            $hasStrongEventSignal
            || $directWorkplaceEvent
            || $hasContextEntity
        )
    ) {
        return 'workplace_followup';
    }

    if (
        $hasStrongEventSignal
        || $directWorkplaceEvent
    ) {
        return 'workplace_event';
    }

    /*
     * Emotional language gets priority over schedule/leave language.
     *
     * "I don't want to go back Thursday" is a feeling being expressed,
     * not merely a return-to-work calendar update.
     */
    $venting = preg_match(
        '/\b(?:'
        . 'don[\'’]?t want to go back|'
        . 'do not want to go back|'
        . 'hate (?:it|this|work)|'
        . 'tired of|'
        . 'sick of|'
        . 'fed up|'
        . 'stressed|'
        . 'nervous|'
        . 'worried|'
        . 'upset|'
        . 'frustrated|'
        . 'angry|'
        . 'pissed|'
        . 'overwhelmed|'
        . 'rough day|'
        . 'bad day'
        . ')\b/i',
        $text
    ) === 1;

    if ($venting) {
        return 'venting';
    }

    $leaveUpdate = preg_match(
        '/\b(?:'
        . 'off work|'
        . 'off for|'
        . 'doctor|'
        . 'medical appointment|'
        . 'medical leave|'
        . 'return to work|'
        . 'go back(?: to work)?|'
        . 'back to work|'
        . 'don[\'’]?t go back|'
        . 'not scheduled|'
        . 'days? off'
        . ')\b/i',
        $text
    ) === 1;

    if (
        $leaveUpdate
        && !array_intersect(
            $signalKeys,
            [
                'attendance_action',
                'discipline',
                'retaliation_timing',
            ]
        )
    ) {
        return 'leave_update';
    }

    if (str_contains($text, '?')) {
        return 'question';
    }

    return 'casual_update';
}

function journal_intelligence_intent_needs_documentation(
    string $intent
): bool {
    return in_array(
        $intent,
        [
            'critical_safety',
            'workplace_event',
            'workplace_followup',
        ],
        true
    );
}

function journal_intelligence_primary_summary(array $analysis): string
{
    $intent = (string) (
        $analysis['intent']
        ?? 'workplace_event'
    );

    $categories = array_values(
        $analysis['categories'] ?? []
    );

    $entities = array_values(
        $analysis['entities'] ?? []
    );

    if ($intent === 'critical_safety') {
        return 'Possible immediate safety concern reported.';
    }

    if ($intent === 'leave_update') {
        return 'Journal update about time away from work or returning to work.';
    }

    if ($intent === 'venting') {
        return 'Personal journal entry about how work is feeling right now.';
    }

    if ($intent === 'question') {
        return 'Journal question or request for help.';
    }

    if ($intent === 'casual_update') {
        return 'General journal update; no specific workplace incident identified.';
    }

    $parts = [];

    $parts[] = $intent === 'workplace_followup'
        ? 'Follow-up to a reported workplace event'
        : 'Reported workplace event';

    if ($categories) {
        $parts[] = 'topics: '
            . implode(
                ', ',
                array_slice($categories, 0, 4)
            );
    }

    $entityNames = [];

    foreach ($entities as $entity) {
        if (
            in_array(
                $entity['type'],
                [
                    'person',
                    'role',
                    'work_area',
                ],
                true
            )
        ) {
            $entityNames[] = $entity['value'];
        }
    }

    $entityNames = array_values(
        array_unique($entityNames)
    );

    if ($entityNames) {
        $parts[] = 'context: '
            . implode(
                ', ',
                array_slice($entityNames, 0, 6)
            );
    }

    return implode('; ', $parts) . '.';
}

function journal_intelligence_message_context(
    int $workerId,
    int $sessionId,
    int $messageId
): ?array {
    $workdaySelect = journal_intelligence_column_exists(
        'work_journal_sessions',
        'workday_date'
    )
        ? 'COALESCE(s.workday_date, s.journal_date) AS workday_date'
        : 's.journal_date AS workday_date';

    $organizationSelect = journal_intelligence_column_exists(
        'work_journal_sessions',
        'organization_id'
    )
        ? 's.organization_id'
        : 'NULL AS organization_id';

    $employmentSelect = journal_intelligence_column_exists(
        'work_journal_sessions',
        'employment_id'
    )
        ? 's.employment_id'
        : 'NULL AS employment_id';

    $stmt = db()->prepare("
        SELECT
            m.*,
            s.worker_id,
            s.status AS session_status,
            {$workdaySelect},
            {$organizationSelect},
            {$employmentSelect}
        FROM work_journal_messages m
        INNER JOIN work_journal_sessions s
            ON s.id = m.session_id
        WHERE m.id = ?
          AND m.session_id = ?
          AND s.worker_id = ?
          AND m.speaker = 'worker'
        LIMIT 1
    ");

    $stmt->execute([
        $messageId,
        $sessionId,
        $workerId,
    ]);

    $row = $stmt->fetch();

    return $row ?: null;
}

function journal_intelligence_pending_messages(
    int $workerId,
    int $sessionId,
    int $limit = 50
): array {
    $limit = max(1, min($limit, 200));

    $stmt = db()->prepare("
        SELECT m.id
        FROM work_journal_messages m
        INNER JOIN work_journal_sessions s
            ON s.id = m.session_id
        LEFT JOIN journal_intelligence_runs r
            ON r.source_message_id = m.id
        WHERE s.worker_id = ?
          AND s.id = ?
          AND m.speaker = 'worker'
          AND r.id IS NULL
        ORDER BY m.sequence_number ASC
        LIMIT {$limit}
    ");

    $stmt->execute([
        $workerId,
        $sessionId,
    ]);

    return array_map(
        static fn (array $row): int => (int) $row['id'],
        $stmt->fetchAll()
    );
}

function journal_intelligence_insert_event(
    array $message,
    array $event
): int {
    $stmt = db()->prepare("
        INSERT INTO journal_intelligence_events (
            worker_id,
            session_id,
            source_message_id,
            source_sequence,
            event_index,
            organization_id,
            employment_id,
            event_date,
            event_time,
            date_precision,
            category,
            event_type,
            fact_status,
            source_excerpt,
            summary_text,
            severity,
            confidence,
            review_status,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reported', ?, ?, ?, ?, 'unreviewed', ?)
    ");

    $stmt->execute([
        (int) $message['worker_id'],
        (int) $message['session_id'],
        (int) $message['id'],
        (int) $message['sequence_number'],
        (int) $event['event_index'],
        $message['organization_id'] !== null
            ? (int) $message['organization_id']
            : null,
        $message['employment_id'] !== null
            ? (int) $message['employment_id']
            : null,
        $event['event_date'],
        $event['event_time'],
        $event['date_precision'],
        $event['category'],
        $event['event_type'],
        $event['source_excerpt'],
        $event['summary_text'],
        $event['severity'],
        (float) $event['confidence'],
        date('Y-m-d H:i:s'),
    ]);

    return (int) db()->lastInsertId();
}

function journal_intelligence_store_event_details(
    int $eventId,
    int $workerId,
    array $event
): void {
    $now = date('Y-m-d H:i:s');

    foreach ($event['entities'] as $entity) {
        $stmt = db()->prepare("
            INSERT OR IGNORE INTO journal_intelligence_event_entities (
                event_id,
                worker_id,
                entity_type,
                entity_value,
                normalized_value,
                role_hint,
                confidence,
                source_excerpt,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $eventId,
            $workerId,
            $entity['type'],
            $entity['value'],
            $entity['normalized_value'],
            $entity['role_hint'],
            (float) $entity['confidence'],
            $entity['source_excerpt'],
            $now,
        ]);
    }

    foreach ($event['signals'] as $signal) {
        $stmt = db()->prepare("
            INSERT OR IGNORE INTO journal_intelligence_signals (
                event_id,
                worker_id,
                signal_key,
                signal_label,
                category,
                severity,
                explanation,
                source_excerpt,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $eventId,
            $workerId,
            $signal['key'],
            $signal['label'],
            $signal['category'],
            $signal['severity'],
            $signal['explanation'],
            $signal['source_excerpt'],
            $now,
        ]);
    }

    foreach ($event['facts'] as $fact) {
        $stmt = db()->prepare("
            INSERT INTO journal_intelligence_facts (
                event_id,
                worker_id,
                fact_type,
                subject_text,
                predicate_text,
                object_text,
                fact_status,
                confidence,
                source_excerpt,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $eventId,
            $workerId,
            $fact['fact_type'],
            $fact['subject_text'],
            $fact['predicate_text'],
            $fact['object_text'],
            $fact['fact_status'],
            (float) $fact['confidence'],
            $fact['source_excerpt'],
            $now,
        ]);
    }
}

function journal_intelligence_memory_touch(
    int $workerId,
    string $type,
    string $key,
    string $displayValue,
    int $sourceMessageId
): void {
    $normalizedKey = journal_intelligence_normalize_key($key);

    if ($normalizedKey === '') {
        return;
    }

    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare("
        INSERT INTO journal_intelligence_memory (
            worker_id,
            memory_type,
            memory_key,
            display_value,
            first_seen_at,
            last_seen_at,
            mention_count,
            last_source_message_id
        )
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ON CONFLICT(worker_id, memory_type, memory_key)
        DO UPDATE SET
            display_value = excluded.display_value,
            last_seen_at = excluded.last_seen_at,
            mention_count = journal_intelligence_memory.mention_count + 1,
            last_source_message_id = excluded.last_source_message_id
    ");

    $stmt->execute([
        $workerId,
        $type,
        $normalizedKey,
        $displayValue,
        $now,
        $now,
        $sourceMessageId,
    ]);
}

function journal_intelligence_update_memory(
    int $workerId,
    int $sourceMessageId,
    array $analysis
): void {
    foreach ($analysis['categories'] as $category) {
        journal_intelligence_memory_touch(
            $workerId,
            'category',
            $category,
            $category,
            $sourceMessageId
        );
    }

    foreach ($analysis['signals'] as $signal) {
        journal_intelligence_memory_touch(
            $workerId,
            'signal',
            $signal['key'],
            $signal['label'],
            $sourceMessageId
        );
    }

    foreach ($analysis['entities'] as $entity) {
        if (!in_array(
            $entity['type'],
            ['person', 'role', 'work_area'],
            true
        )) {
            continue;
        }

        journal_intelligence_memory_touch(
            $workerId,
            $entity['type'],
            $entity['normalized_value'],
            $entity['value'],
            $sourceMessageId
        );

        if ($entity['type'] === 'person') {
            journal_intelligence_person_memory_touch(
                $workerId,
                (string) $entity['value'],
                isset($entity['role_hint'])
                    ? (string) $entity['role_hint']
                    : null,
                $sourceMessageId
            );
        }
    }
}

function journal_intelligence_make_followups(
    array $analysis
): array {
    $questions = [];

    $signalKeys = array_column(
        $analysis['signals'] ?? [],
        'key'
    );

    $entityTypes = array_column(
        $analysis['entities'] ?? [],
        'type'
    );

    $intent = (string) (
        $analysis['intent']
        ?? 'workplace_event'
    );

    if (
        !journal_intelligence_intent_needs_documentation(
            $intent
        )
    ) {
        return [];
    }

    if (
        in_array(
            'critical_sexual_assault',
            $signalKeys,
            true
        )
        || in_array(
            'critical_violence_threat',
            $signalKeys,
            true
        )
    ) {
        return [
            [
                'key' => 'critical_safety_now',
                'question' => 'Are you safe right now?',
                'priority' => 'important',
            ],
        ];
    }

    /*
     * Combine the event excerpts so conversational wording can drive
     * better questions even when a lower-level signal rule did not
     * fire.
     */
    $sourceText = '';

    foreach ($analysis['events'] ?? [] as $event) {
        $excerpt = trim(
            (string) (
                $event['source_excerpt']
                ?? ''
            )
        );

        if ($excerpt !== '') {
            $sourceText .= ' ' . $excerpt;
        }
    }

    $sourceText = trim($sourceText);

    /*
     * Discipline/write-up:
     * why it happened is generally more useful than the clock time.
     */
    if (
        in_array(
            'discipline',
            $signalKeys,
            true
        )
        || preg_match(
            '/\b(?:'
            . 'wrote me up|'
            . 'written up|'
            . 'write[- ]?up|'
            . 'gave me an? warning|'
            . 'disciplin(?:e|ed|ary)'
            . ')\b/i',
            $sourceText
        )
    ) {
        $questions[] = [
            'key' => 'discipline_reason',
            'question' => 'What reason were you given for the write-up or discipline?',
            'priority' => 'important',
        ];
    }

    /*
     * Attendance/FMLA interaction.
     */
    if (
        in_array(
            'fmla_leave',
            $signalKeys,
            true
        )
        && (
            in_array(
                'attendance_action',
                $signalKeys,
                true
            )
            || in_array(
                'discipline',
                $signalKeys,
                true
            )
        )
    ) {
        $questions[] = [
            'key' => 'fmla_documents',
            'question' => 'Do you have the leave approval, attendance point or write-up, notice to the company, or appeal paperwork connected with this?',
            'priority' => 'important',
        ];
    }

    /*
     * Machine / quality context.
     */
    if (
        (
            in_array(
                'quality_defect',
                $signalKeys,
                true
            )
            || in_array(
                'unexpected_acceptance',
                $signalKeys,
                true
            )
            || in_array(
                'machine_problem',
                $signalKeys,
                true
            )
            || in_array(
                'material_process_change',
                $signalKeys,
                true
            )
        )
        && !in_array(
            'work_area',
            $entityTypes,
            true
        )
    ) {
        $questions[] = [
            'key' => 'machine_area',
            'question' => 'Which machine, line, or work area was involved?',
            'priority' => 'important',
        ];
    }

    if (
        in_array(
            'management_instruction',
            $signalKeys,
            true
        )
        && !in_array(
            'person',
            $entityTypes,
            true
        )
        && !in_array(
            'role',
            $entityTypes,
            true
        )
    ) {
        $questions[] = [
            'key' => 'instruction_actor',
            'question' => 'Who gave that instruction?',
            'priority' => 'important',
        ];
    }

    if (
        in_array(
            'unexpected_acceptance',
            $signalKeys,
            true
        )
        || in_array(
            'quality_defect',
            $signalKeys,
            true
        )
    ) {
        $questions[] = [
            'key' => 'quality_response',
            'question' => 'What happened after the bad or failed part was identified?',
            'priority' => 'important',
        ];
    }

    if (
        in_array(
            'machine_problem',
            $signalKeys,
            true
        )
    ) {
        $questions[] = [
            'key' => 'machine_response',
            'question' => 'Was Maintenance, Quality, or a technician notified about the machine problem?',
            'priority' => 'helpful',
        ];
    }

    /*
     * Conduct / harassment context.
     */
    if (
        in_array(
            'unwanted_touching',
            $signalKeys,
            true
        )
        || in_array(
            'harassment_behavior',
            $signalKeys,
            true
        )
    ) {
        $questions[] = [
            'key' => 'conduct_witnesses',
            'question' => 'Was anyone else present who may have seen or heard what happened?',
            'priority' => 'important',
        ];

        if (
            !in_array(
                'management_notice',
                $signalKeys,
                true
            )
        ) {
            $questions[] = [
                'key' => 'conduct_notice',
                'question' => 'Did you tell a lead, supervisor, manager, HR, or anyone else at work about it?',
                'priority' => 'important',
            ];
        }
    }

    if (
        in_array(
            'evidence_mention',
            $signalKeys,
            true
        )
    ) {
        $questions[] = [
            'key' => 'evidence_upload',
            'question' => 'You mentioned supporting evidence. Attach the original file when you are ready.',
            'priority' => 'helpful',
        ];
    }

    /*
     * A vague conversational follow-up should not be interrogated for
     * a clock time. The memory connection matters more first.
     */
    if (
        $intent === 'workplace_followup'
        && !$questions
    ) {
        return [];
    }

    /*
     * Time is now a fallback, not the default.
     *
     * Only ask it when there are no stronger factual questions and the
     * entry carries a meaningful issue signal.
     */
    if (!$questions) {
        $hasTime = false;

        foreach ($analysis['events'] ?? [] as $event) {
            if (!empty($event['event_time'])) {
                $hasTime = true;
                break;
            }
        }

        $importantTimingSignals = [
            'unwanted_touching',
            'harassment_behavior',
            'attendance_action',
            'discipline',
            'safety_hazard',
            'retaliation_timing',
            'unexpected_acceptance',
            'quality_defect',
        ];

        if (
            !$hasTime
            && array_intersect(
                $signalKeys,
                $importantTimingSignals
            )
        ) {
            $questions[] = [
                'key' => 'event_time',
                'question' => 'About what time did this happen?',
                'priority' => 'helpful',
            ];
        }
    }

    $deduped = [];

    foreach ($questions as $question) {
        $deduped[
            $question['key']
        ] = $question;
    }

    /*
     * The conversational reply only asks one question at a time, but
     * storing a few useful unresolved questions still lets later
     * messages answer them naturally.
     */
    return array_values($deduped);
}

function journal_intelligence_resolve_prior_followups(
    int $workerId,
    int $sessionId,
    int $messageId,
    array $analysis
): void {
    $signalKeys = array_column($analysis['signals'], 'key');
    $entityTypes = array_column($analysis['entities'], 'type');
    $hasTime = false;

    foreach ($analysis['events'] as $event) {
        if (!empty($event['event_time'])) {
            $hasTime = true;
            break;
        }
    }

    $resolvedKeys = [];

    if ($hasTime) {
        $resolvedKeys[] = 'event_time';
    }

    if (in_array('work_area', $entityTypes, true)) {
        $resolvedKeys[] = 'machine_area';
    }

    if (
        in_array('person', $entityTypes, true)
        || in_array('role', $entityTypes, true)
    ) {
        $resolvedKeys[] = 'instruction_actor';
    }

    if (in_array('management_notice', $signalKeys, true)) {
        $resolvedKeys[] = 'conduct_notice';
    }

    if (in_array('witness_mention', $signalKeys, true)) {
        $resolvedKeys[] = 'conduct_witnesses';
    }

    if (!$resolvedKeys) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($resolvedKeys), '?'));
    $params = [
        $messageId,
        date('Y-m-d H:i:s'),
        $workerId,
        $sessionId,
        $messageId,
    ];

    foreach ($resolvedKeys as $key) {
        $params[] = $key;
    }

    $stmt = db()->prepare("
        UPDATE journal_intelligence_followups
        SET
            status = 'answered',
            answered_by_message_id = ?,
            resolved_at = ?
        WHERE worker_id = ?
          AND session_id = ?
          AND status = 'open'
          AND source_message_id <> ?
          AND question_key IN ({$placeholders})
    ");

    $stmt->execute($params);
}

function journal_intelligence_store_followups(
    int $workerId,
    int $sessionId,
    int $messageId,
    array $questions
): void {
    $now = date('Y-m-d H:i:s');

    foreach ($questions as $question) {
        $stmt = db()->prepare("
            INSERT OR IGNORE INTO journal_intelligence_followups (
                worker_id,
                session_id,
                source_message_id,
                event_id,
                question_key,
                question_text,
                priority,
                status,
                created_at
            )
            VALUES (?, ?, ?, NULL, ?, ?, ?, 'open', ?)
        ");

        $stmt->execute([
            $workerId,
            $sessionId,
            $messageId,
            $question['key'],
            $question['question'],
            $question['priority'],
            $now,
        ]);
    }
}

function journal_intelligence_event_entities(int $eventId): array
{
    $stmt = db()->prepare("
        SELECT entity_type, entity_value, normalized_value
        FROM journal_intelligence_event_entities
        WHERE event_id = ?
    ");

    $stmt->execute([$eventId]);

    return $stmt->fetchAll();
}

function journal_intelligence_event_signal_keys(int $eventId): array
{
    $stmt = db()->prepare("
        SELECT signal_key
        FROM journal_intelligence_signals
        WHERE event_id = ?
    ");

    $stmt->execute([$eventId]);

    return array_map(
        static fn (array $row): string => (string) $row['signal_key'],
        $stmt->fetchAll()
    );
}

function journal_intelligence_link_event_context(
    int $workerId,
    int $eventId,
    int $sourceMessageId
): int {
    $currentStmt = db()->prepare("
        SELECT *
        FROM journal_intelligence_events
        WHERE id = ?
          AND worker_id = ?
        LIMIT 1
    ");

    $currentStmt->execute([
        $eventId,
        $workerId,
    ]);

    $current = $currentStmt->fetch();

    if (!$current) {
        return 0;
    }

    $currentEntities = journal_intelligence_event_entities($eventId);
    $currentEntityKeys = [];

    foreach ($currentEntities as $entity) {
        if (in_array(
            $entity['entity_type'],
            ['person', 'role', 'work_area'],
            true
        )) {
            $currentEntityKeys[
                $entity['entity_type'] . '|' . $entity['normalized_value']
            ] = true;
        }
    }

    $currentSignals = journal_intelligence_event_signal_keys($eventId);

    $priorStmt = db()->prepare("
        SELECT *
        FROM journal_intelligence_events
        WHERE worker_id = ?
          AND id <> ?
          AND source_message_id <> ?
        ORDER BY id DESC
        LIMIT 150
    ");

    $priorStmt->execute([
        $workerId,
        $eventId,
        $sourceMessageId,
    ]);

    $matches = [];

    foreach ($priorStmt->fetchAll() as $prior) {
        $score = 0;
        $reasons = [];

        if (
            $current['category'] !== 'General Workplace Event'
            && $prior['category'] === $current['category']
        ) {
            $score += 4;
            $reasons[] = 'same topic';
        }

        $priorSignals = journal_intelligence_event_signal_keys((int) $prior['id']);
        $sharedSignals = array_values(array_intersect(
            $currentSignals,
            $priorSignals
        ));

        if ($sharedSignals) {
            $score += min(6, count($sharedSignals) * 3);
            $reasons[] = 'shared issue signal';
        }

        $priorEntities = journal_intelligence_event_entities((int) $prior['id']);
        $sharedEntityCount = 0;

        foreach ($priorEntities as $entity) {
            $key = $entity['entity_type'] . '|' . $entity['normalized_value'];
            if (isset($currentEntityKeys[$key])) {
                $sharedEntityCount++;
            }
        }

        if ($sharedEntityCount > 0) {
            $score += min(6, $sharedEntityCount * 2);
            $reasons[] = 'same person / role / work area';
        }

        if ($score >= 4) {
            $matches[] = [
                'event_id' => (int) $prior['id'],
                'score' => $score,
                'explanation' => implode(', ', array_unique($reasons)),
            ];
        }
    }

    usort(
        $matches,
        static fn (array $a, array $b): int => $b['score'] <=> $a['score']
    );

    $matches = array_slice($matches, 0, 5);
    $now = date('Y-m-d H:i:s');

    foreach ($matches as $match) {
        $stmt = db()->prepare("
            INSERT OR IGNORE INTO journal_intelligence_links (
                worker_id,
                source_event_id,
                related_event_id,
                relation_type,
                relation_score,
                explanation,
                created_at
            )
            VALUES (?, ?, ?, 'possible_context', ?, ?, ?)
        ");

        $stmt->execute([
            $workerId,
            $eventId,
            $match['event_id'],
            $match['score'],
            $match['explanation'],
            $now,
        ]);
    }

    return count($matches);
}

function journal_intelligence_related_count_for_message(int $messageId): int
{
    $stmt = db()->prepare("
        SELECT COUNT(DISTINCT l.related_event_id)
        FROM journal_intelligence_links l
        INNER JOIN journal_intelligence_events e
            ON e.id = l.source_event_id
        WHERE e.source_message_id = ?
    ");

    $stmt->execute([$messageId]);

    return (int) $stmt->fetchColumn();
}

function journal_intelligence_related_context_for_message(
    int $messageId,
    int $limit = 3
): array {
    $limit = max(
        1,
        min($limit, 5)
    );

    $stmt = db()->prepare("
        SELECT
            l.relation_score,
            l.explanation,
            pe.event_date,
            pe.category,
            pe.source_excerpt
        FROM journal_intelligence_links l
        INNER JOIN journal_intelligence_events e
            ON e.id = l.source_event_id
        INNER JOIN journal_intelligence_events pe
            ON pe.id = l.related_event_id
        WHERE e.source_message_id = ?
        ORDER BY
            l.relation_score DESC,
            pe.id DESC
        LIMIT {$limit}
    ");

    $stmt->execute([
        $messageId,
    ]);

    return $stmt->fetchAll();
}

function journal_intelligence_related_reports_for_message(
    int $workerId,
    string $messageText,
    array $analysis,
    int $limit = 3
): array {
    if ($workerId < 1) {
        return [];
    }

    $limit = max(
        1,
        min($limit, 5)
    );

    $stmt = db()->prepare("
        SELECT
            r.id,
            r.report_date,
            r.title,
            r.machine,
            r.original_text,

            (
                SELECT GROUP_CONCAT(p.name, '|')
                FROM report_people rp
                INNER JOIN people p
                    ON p.id = rp.person_id
                WHERE rp.report_id = r.id
            ) AS people,

            (
                SELECT GROUP_CONCAT(pl.name, '|')
                FROM report_places rpl
                INNER JOIN places pl
                    ON pl.id = rpl.place_id
                WHERE rpl.report_id = r.id
            ) AS places,

            (
                SELECT GROUP_CONCAT(c.name, '|')
                FROM report_categories rc
                INNER JOIN categories c
                    ON c.id = rc.category_id
                WHERE rc.report_id = r.id
            ) AS categories

        FROM reports r

        INNER JOIN report_analysis ra
            ON ra.report_id = r.id

        WHERE r.worker_id = ?

        ORDER BY
            r.report_date DESC,
            r.id DESC

        LIMIT 50
    ");

    $stmt->execute([
        $workerId,
    ]);

    $messageLower = mb_strtolower(
        trim($messageText)
    );

    $messageWords = preg_split(
        '/[^\p{L}\p{N}]+/u',
        $messageLower,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $stopWords = [
        'about',
        'again',
        'after',
        'before',
        'being',
        'could',
        'would',
        'should',
        'there',
        'their',
        'those',
        'these',
        'today',
        'thing',
        'things',
        'working',
        'worker',
        'report',
        'because',
        'while',
        'where',
        'which',
        'with',
        'from',
        'that',
        'this',
        'have',
        'they',
        'them',
        'then',
        'when',
        'what',
        'were',
        'been',
        'into',
        'just',
        'said',
        'told',
    ];

    $messageKeywords = [];

    foreach ($messageWords as $word) {
        if (
            mb_strlen($word) < 5
            || in_array(
                $word,
                $stopWords,
                true
            )
        ) {
            continue;
        }

        $messageKeywords[$word] = $word;
    }

    $matches = [];

    foreach ($stmt->fetchAll() as $row) {
        $score = 0;
        $reasons = [];

        $people = array_filter(
            array_map(
                'trim',
                explode(
                    '|',
                    (string) ($row['people'] ?? '')
                )
            )
        );

        foreach ($people as $person) {
            if (
                function_exists(
                    'text_contains_person_name'
                )
                && text_contains_person_name(
                    $messageText,
                    $person
                )
            ) {
                $score += 10;
                $reasons[] = $person;
                continue;
            }

            /*
             * Allow a lower-confidence first-name match.
             * This is still restricted to this worker's own reports.
             */
            $parts = preg_split(
                '/\s+/u',
                trim($person)
            );

            $firstName = trim(
                (string) ($parts[0] ?? '')
            );

            if (
                mb_strlen($firstName) >= 3
                && function_exists(
                    'text_contains_person_name'
                )
                && text_contains_person_name(
                    $messageText,
                    $firstName
                )
            ) {
                $score += 5;
                $reasons[] = $person;
            }
        }

        $places = array_filter(
            array_map(
                'trim',
                explode(
                    '|',
                    (string) ($row['places'] ?? '')
                )
            )
        );

        $machine = trim(
            (string) ($row['machine'] ?? '')
        );

        if (
            $machine !== ''
            && mb_stripos(
                $messageText,
                $machine
            ) !== false
        ) {
            $score += 9;
            $reasons[] = $machine;
        }

        foreach ($places as $place) {
            if (
                $place !== ''
                && mb_stripos(
                    $messageText,
                    $place
                ) !== false
            ) {
                $score += 8;
                $reasons[] = $place;
            }
        }

        /*
         * Use significant overlapping terms as supporting evidence,
         * never as the only strong reason for linking a report.
         */
        $reportLower = mb_strtolower(
            (string) ($row['original_text'] ?? '')
        );

        $keywordHits = 0;

        foreach ($messageKeywords as $keyword) {
            if (
                mb_stripos(
                    $reportLower,
                    $keyword
                ) !== false
            ) {
                $keywordHits++;
            }
        }

        if ($keywordHits > 0) {
            $score += min(
                6,
                $keywordHits * 2
            );
        }

        /*
         * Require meaningful overlap.
         * A weak generic keyword hit should never create a relationship.
         */
        if ($score < 7) {
            continue;
        }

        $reasons = array_values(
            array_unique(
                array_filter($reasons)
            )
        );

        $categories = array_filter(
            array_map(
                'trim',
                explode(
                    '|',
                    (string) ($row['categories'] ?? '')
                )
            )
        );

        $operationalFindings = [];

        if (
            journal_intelligence_table_exists(
                'report_operational_findings'
            )
        ) {
            $findingStmt = db()->prepare("
                SELECT title
                FROM report_operational_findings
                WHERE report_id = ?
                ORDER BY
                    CASE confidence
                        WHEN 'high' THEN 1
                        WHEN 'medium' THEN 2
                        ELSE 3
                    END,
                    id
                LIMIT 3
            ");

            $findingStmt->execute([
                (int) $row['id'],
            ]);

            foreach ($findingStmt->fetchAll() as $findingRow) {
                $title = trim(
                    (string) ($findingRow['title'] ?? '')
                );

                if ($title !== '') {
                    $operationalFindings[] = $title;
                }
            }
        }

        $matches[] = [
            'report_id' => (int) $row['id'],
            'report_date' =>
                (string) ($row['report_date'] ?? ''),
            'title' =>
                trim((string) ($row['title'] ?? '')),
            'machine' => $machine,
            'score' => $score,
            'reasons' => $reasons,
            'categories' => array_values(
                array_unique($categories)
            ),
            'operational_findings' => array_values(
                array_unique($operationalFindings)
            ),
        ];
    }

    usort(
        $matches,
        static function (
            array $a,
            array $b
        ): int {
            return $b['score']
                <=> $a['score'];
        }
    );

    return array_slice(
        $matches,
        0,
        $limit
    );
}

function journal_intelligence_build_reply(
    array $analysis,
    int $workerId,
    string $messageText,
    int $messageId,
    array $questions
): string {
    $severity = (string) (
        $analysis['severity']
        ?? 'normal'
    );

    $intent = (string) (
        $analysis['intent']
        ?? 'workplace_event'
    );

    if ($severity === 'critical') {
        return implode(
            "\n\n",
            [
                'I saved exactly what you wrote.',
                'What you described may involve an immediate safety concern. Are you safe right now? If you are in immediate danger, contact emergency services or move to a safe place if you can.',
                'You do not need to answer anything else right now. I am preserving what you reported, not making a legal finding.',
            ]
        );
    }

    if ($intent === 'leave_update') {
        return implode(
            "\n\n",
            [
                'Got it. I saved this as an update about your time away from work.',
                'Since you are not describing a specific workplace incident here, I will not turn it into one or press you for incident details.',
            ]
        );
    }

    if ($intent === 'venting') {
        return implode(
            "\n\n",
            [
                'I hear you. You can use the journal to talk through what is bothering you without having to turn every thought into a formal incident.',
                'If something specific happened at work, tell me in your own words and I will keep the useful details organized while you talk.',
            ]
        );
    }

    if ($intent === 'question') {
        return implode(
            "\n\n",
            [
                'I saved what you wrote.',
                'You can ask questions here without the question itself being treated as a workplace incident.',
            ]
        );
    }

    if ($intent === 'casual_update') {
        return implode(
            "\n\n",
            [
                'Got it. I saved the update.',
                'Nothing in this entry needs to be treated as a workplace incident right now.',
            ]
        );
    }

    $lines = [];

    $lines[] = $intent === 'workplace_followup'
        ? 'I saved this as a follow-up to what you are documenting.'
        : 'I saved what you reported and I am organizing the useful details while you talk.';

    $related = journal_intelligence_related_context_for_message(
        $messageId,
        3
    );

    $relatedReports =
        journal_intelligence_related_reports_for_message(
            $workerId,
            $messageText,
            $analysis,
            2
        );

    if ($relatedReports) {
        $report = $relatedReports[0];

        $score = (int) (
            $report['score']
            ?? 0
        );

        $title = trim(
            (string) (
                $report['title']
                ?? ''
            )
        );

        $date = trim(
            (string) (
                $report['report_date']
                ?? ''
            )
        );

        $machine = trim(
            (string) (
                $report['machine']
                ?? ''
            )
        );

        $reasons = array_values(
            array_filter(
                $report['reasons']
                ?? []
            )
        );

        $categories = array_values(
            array_filter(
                $report['categories']
                ?? []
            )
        );

        $findings = array_values(
            array_filter(
                $report['operational_findings']
                ?? []
            )
        );

        /*
         * Strong match:
         * Speak like useful memory, while keeping the connection
         * explicitly non-conclusive.
         */
        if ($score >= 20) {
            $contextParts = [];

            if ($machine !== '') {
                $contextParts[] = $machine;
            }

            foreach (
                array_slice(
                    $reasons,
                    0,
                    2
                )
                as $reason
            ) {
                if (
                    !in_array(
                        $reason,
                        $contextParts,
                        true
                    )
                ) {
                    $contextParts[] = $reason;
                }
            }

            foreach (
                array_slice(
                    $categories,
                    0,
                    2
                )
                as $category
            ) {
                if (
                    !in_array(
                        $category,
                        $contextParts,
                        true
                    )
                ) {
                    $contextParts[] = $category;
                }
            }

            $contextText = $contextParts
                ? implode(
                    ', ',
                    $contextParts
                )
                : 'several matching details';

            $lines[] =
                'I remember that. You documented an earlier report'
                . ($date !== ''
                    ? ' on ' . $date
                    : '')
                . ($title !== ''
                    ? ' about "' . $title . '"'
                    : '')
                . '. The earlier record includes '
                . $contextText
                . '.';

            if ($findings) {
                $lines[] =
                    'That earlier report also raised context around '
                    . implode(
                        ' and ',
                        array_slice(
                            $findings,
                            0,
                            2
                        )
                    )
                    . '.';
            }

            $lines[] =
                'What you just mentioned may connect to that earlier record. I am keeping the connection as context only and not assuming it is the same event or had the same cause.';
        }

        /*
         * Medium match:
         * Mention it, but stay lighter and more tentative.
         */
        elseif ($score >= 10) {
            $reasonText = $reasons
                ? implode(
                    ', ',
                    array_slice(
                        $reasons,
                        0,
                        2
                    )
                )
                : 'overlapping details';

            $lines[] =
                'That sounds similar to something you documented earlier'
                . ($date !== ''
                    ? ' on ' . $date
                    : '')
                . '. I found a prior report'
                . ($title !== ''
                    ? ' called "' . $title . '"'
                    : '')
                . ' with overlap around '
                . $reasonText
                . '. I will keep it in context while you continue, without assuming the two events are the same.';
        }
    }

    if ($related) {
        $reasons = [];

        foreach ($related as $row) {
            $reason = trim(
                (string) (
                    $row['explanation']
                    ?? ''
                )
            );

            if ($reason !== '') {
                $reasons[$reason] = $reason;
            }
        }

        $reasonText = $reasons
            ? implode(
                ', ',
                array_slice(
                    array_values($reasons),
                    0,
                    2
                )
            )
            : 'overlapping details';

        $lines[] = 'I found earlier journal context connected by '
            . $reasonText
            . '. I am keeping that as possible context only, not assuming the events had the same cause.';
    }

    if ($questions) {
        $lines[] = 'One detail that may help the record: '
            . $questions[0]['question'];

        $lines[] = 'Answer it now or later. Your original wording is already saved.';
    } else {
        $lines[] = 'I have enough detail for now. Keep talking naturally if there is more you want to add.';
    }

    return implode(
        "\n\n",
        $lines
    );
}

function journal_intelligence_process_message(
    int $workerId,
    int $sessionId,
    int $messageId,
    bool $allowSystemReply = true
): ?array {
    ensure_journal_intelligence_schema();

    $existing = db()->prepare("
        SELECT *
        FROM journal_intelligence_runs
        WHERE source_message_id = ?
        LIMIT 1
    ");

    $existing->execute([$messageId]);
    $row = $existing->fetch();

    if ($row) {
        return $row;
    }

    $message = journal_intelligence_message_context(
        $workerId,
        $sessionId,
        $messageId
    );

    if (!$message) {
        return null;
    }

    $analysis = journal_intelligence_analyze_text(
        (string) $message['message_text'],
        [
            'workday_date' => $message['workday_date'],
            'worker_id' => $workerId,
        ]
    );

    $summary = journal_intelligence_primary_summary($analysis);
    $questions = journal_intelligence_make_followups($analysis);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO journal_intelligence_runs (
                worker_id,
                session_id,
                source_message_id,
                source_sequence,
                source_content_hash,
                engine_version,
                summary_text,
                severity,
                confidence,
                analysis_json,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $overallConfidence = 0.55;
        if ($analysis['signals']) {
            $overallConfidence += 0.12;
        }
        if ($analysis['entities']) {
            $overallConfidence += 0.08;
        }
        $overallConfidence = min(0.90, $overallConfidence);

        $stmt->execute([
            $workerId,
            $sessionId,
            $messageId,
            (int) $message['sequence_number'],
            $message['content_hash'] ?? null,
            JOURNAL_INTELLIGENCE_ENGINE_VERSION,
            $summary,
            $analysis['severity'],
            $overallConfidence,
            json_encode(
                $analysis,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            ),
            date('Y-m-d H:i:s'),
        ]);

        $eventIds = [];

        foreach ($analysis['events'] as $event) {
            $eventId = journal_intelligence_insert_event(
                $message,
                $event
            );

            journal_intelligence_store_event_details(
                $eventId,
                $workerId,
                $event
            );

            $eventIds[] = $eventId;
        }

        journal_intelligence_update_memory(
            $workerId,
            $messageId,
            $analysis
        );

        journal_intelligence_resolve_prior_followups(
            $workerId,
            $sessionId,
            $messageId,
            $analysis
        );

        journal_intelligence_store_followups(
            $workerId,
            $sessionId,
            $messageId,
            $questions
        );

        $pdo->commit();

        foreach ($eventIds as $eventId) {
            journal_intelligence_link_event_context(
                $workerId,
                $eventId,
                $messageId
            );
        }

        if (
            $allowSystemReply
            && ($message['session_status'] ?? '') === 'open'
            && function_exists('add_journal_message')
        ) {
            $reply = journal_intelligence_build_reply(
                $analysis,
                $workerId,
                (string) $message['message_text'],
                $messageId,
                $questions
            );

            add_journal_message(
                $sessionId,
                null,
                'system',
                $reply
            );
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM journal_intelligence_runs
        WHERE source_message_id = ?
        LIMIT 1
    ");

    $stmt->execute([$messageId]);

    $run = $stmt->fetch();

    return $run ?: null;
}

function journal_intelligence_process_pending(
    int $workerId,
    int $sessionId,
    bool $allowSystemReply = true
): int {
    ensure_journal_intelligence_schema();

    $session = journal_session_for_worker(
        $sessionId,
        $workerId
    );

    if (!$session) {
        return 0;
    }

    if (($session['status'] ?? '') !== 'open') {
        $allowSystemReply = false;
    }

    $messageIds = journal_intelligence_pending_messages(
        $workerId,
        $sessionId,
        100
    );

    $processed = 0;

    foreach ($messageIds as $messageId) {
        journal_intelligence_process_message(
            $workerId,
            $sessionId,
            $messageId,
            $allowSystemReply
        );
        $processed++;
    }

    return $processed;
}

function journal_intelligence_backfill_worker(int $workerId): int
{
    ensure_journal_intelligence_schema();

    $stmt = db()->prepare("
        SELECT id
        FROM work_journal_sessions
        WHERE worker_id = ?
        ORDER BY id ASC
    ");

    $stmt->execute([$workerId]);

    $count = 0;

    foreach ($stmt->fetchAll() as $row) {
        $count += journal_intelligence_process_pending(
            $workerId,
            (int) $row['id'],
            false
        );
    }

    return $count;
}

function journal_intelligence_backfill_all(): int
{
    ensure_journal_intelligence_schema();

    $workers = db()->query("
        SELECT DISTINCT worker_id
        FROM work_journal_sessions
        ORDER BY worker_id
    ")->fetchAll();

    $count = 0;

    foreach ($workers as $row) {
        $count += journal_intelligence_backfill_worker(
            (int) $row['worker_id']
        );
    }

    return $count;
}

function journal_intelligence_session_dashboard(
    int $workerId,
    int $sessionId
): array {
    ensure_journal_intelligence_schema();

    $latestStmt = db()->prepare("
        SELECT *
        FROM journal_intelligence_runs
        WHERE worker_id = ?
          AND session_id = ?
        ORDER BY source_sequence DESC
        LIMIT 1
    ");

    $latestStmt->execute([
        $workerId,
        $sessionId,
    ]);

    $latest = $latestStmt->fetch() ?: null;

    $eventStmt = db()->prepare("
        SELECT COUNT(*)
        FROM journal_intelligence_events
        WHERE worker_id = ?
          AND session_id = ?
    ");

    $eventStmt->execute([
        $workerId,
        $sessionId,
    ]);

    $eventCount = (int) $eventStmt->fetchColumn();

    $followStmt = db()->prepare("
        SELECT *
        FROM journal_intelligence_followups
        WHERE worker_id = ?
          AND session_id = ?
          AND status = 'open'
        ORDER BY
            CASE priority
                WHEN 'important' THEN 1
                ELSE 2
            END,
            id ASC
        LIMIT 8
    ");

    $followStmt->execute([
        $workerId,
        $sessionId,
    ]);

    $followups = $followStmt->fetchAll();

    $signalStmt = db()->prepare("
        SELECT
            s.signal_key,
            s.signal_label,
            s.severity,
            COUNT(*) AS mention_count
        FROM journal_intelligence_signals s
        INNER JOIN journal_intelligence_events e
            ON e.id = s.event_id
        WHERE e.worker_id = ?
          AND e.session_id = ?
        GROUP BY
            s.signal_key,
            s.signal_label,
            s.severity
        ORDER BY
            CASE s.severity
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'concern' THEN 3
                ELSE 4
            END,
            mention_count DESC
        LIMIT 8
    ");

    $signalStmt->execute([
        $workerId,
        $sessionId,
    ]);

    $signals = $signalStmt->fetchAll();

    $entityStmt = db()->prepare("
        SELECT
            ee.entity_type,
            ee.entity_value,
            COUNT(*) AS mention_count
        FROM journal_intelligence_event_entities ee
        INNER JOIN journal_intelligence_events e
            ON e.id = ee.event_id
        WHERE e.worker_id = ?
          AND e.session_id = ?
          AND ee.entity_type IN ('person', 'role', 'work_area')
        GROUP BY
            ee.entity_type,
            ee.normalized_value,
            ee.entity_value
        ORDER BY mention_count DESC, ee.entity_value
        LIMIT 10
    ");

    $entityStmt->execute([
        $workerId,
        $sessionId,
    ]);

    $entities = $entityStmt->fetchAll();

    $relatedStmt = db()->prepare("
        SELECT COUNT(DISTINCT l.related_event_id)
        FROM journal_intelligence_links l
        INNER JOIN journal_intelligence_events e
            ON e.id = l.source_event_id
        WHERE e.worker_id = ?
          AND e.session_id = ?
    ");

    $relatedStmt->execute([
        $workerId,
        $sessionId,
    ]);

    $relatedCount = (int) $relatedStmt->fetchColumn();

    return [
        'latest' => $latest,
        'event_count' => $eventCount,
        'open_followups' => $followups,
        'signals' => $signals,
        'entities' => $entities,
        'related_count' => $relatedCount,
    ];
}

function journal_intelligence_session_events(
    int $workerId,
    int $sessionId
): array {
    $stmt = db()->prepare("
        SELECT *
        FROM journal_intelligence_events
        WHERE worker_id = ?
          AND session_id = ?
        ORDER BY source_sequence ASC, event_index ASC, id ASC
    ");

    $stmt->execute([
        $workerId,
        $sessionId,
    ]);

    $events = $stmt->fetchAll();

    foreach ($events as &$event) {
        $event['entities'] = journal_intelligence_event_entities(
            (int) $event['id']
        );

        $signalStmt = db()->prepare("
            SELECT *
            FROM journal_intelligence_signals
            WHERE event_id = ?
            ORDER BY id ASC
        ");
        $signalStmt->execute([(int) $event['id']]);
        $event['signals'] = $signalStmt->fetchAll();

        $factStmt = db()->prepare("
            SELECT *
            FROM journal_intelligence_facts
            WHERE event_id = ?
            ORDER BY id ASC
        ");
        $factStmt->execute([(int) $event['id']]);
        $event['facts'] = $factStmt->fetchAll();

        $linkStmt = db()->prepare("
            SELECT
                l.*,
                pe.event_date AS related_event_date,
                pe.category AS related_category,
                pe.source_excerpt AS related_excerpt,
                pe.source_message_id AS related_source_message_id
            FROM journal_intelligence_links l
            INNER JOIN journal_intelligence_events pe
                ON pe.id = l.related_event_id
            WHERE l.source_event_id = ?
            ORDER BY l.relation_score DESC, l.id ASC
        ");
        $linkStmt->execute([(int) $event['id']]);
        $event['links'] = $linkStmt->fetchAll();
    }
    unset($event);

    return $events;
}

function journal_intelligence_worker_memory(
    int $workerId,
    int $limit = 20
): array {
    $limit = max(1, min($limit, 100));

    $stmt = db()->prepare("
        SELECT *
        FROM journal_intelligence_memory
        WHERE worker_id = ?
        ORDER BY mention_count DESC, last_seen_at DESC
        LIMIT {$limit}
    ");

    $stmt->execute([$workerId]);

    return $stmt->fetchAll();
}
