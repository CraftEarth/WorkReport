<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/operational-analysis.php';

function analyze_report_text(
    string $text,
    ?int $workerId = null
): array {
    return [
        'people' => detect_people($text, $workerId),
        'places' => detect_places($text, $workerId),
        'categories' => detect_categories($text, $workerId),
        'events' => detect_events($text, $workerId),
        'operational_findings' => detect_operational_findings($text)
    ];
}

function text_contains_phrase(string $text, string $phrase): bool
{
    if ($phrase === '') {
        return false;
    }

    return mb_stripos($text, $phrase) !== false;
}

function text_contains_person_name(
    string $text,
    string $name
): bool {
    $name = trim($name);

    if ($name === '') {
        return false;
    }

    /*
     * Person names must match as complete lexical tokens.
     *
     * Examples:
     *   Andi      -> match
     *   Andi said -> match
     *   standing  -> no match
     *   Randi     -> no match
     *   Mandi     -> no match
     *   Sandi     -> no match
     */
    $pattern =
        '/(?<![\p{L}\p{N}])'
        . preg_quote($name, '/')
        . '(?![\p{L}\p{N}])/iu';

    return preg_match(
        $pattern,
        $text
    ) === 1;
}

function detect_people(string $text, ?int $workerId = null): array
{
    $pdo = db();
    $people = [];

    /*
     * Previously learned people must be scoped to this worker.
     *
     * The people table is a canonical dictionary, but a person only
     * becomes known to a worker through one of that worker's reports.
     */
    if ($workerId !== null && $workerId > 0) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                p.id,
                p.name
            FROM people p
            INNER JOIN report_people rp
                ON rp.person_id = p.id
            INNER JOIN reports r
                ON r.id = rp.report_id
            WHERE r.worker_id = ?
            ORDER BY p.name
        ");

        $stmt->execute([
            $workerId,
        ]);

        $known = $stmt->fetchAll();
    } else {
        $known = $pdo->query("
            SELECT id, name
            FROM people
            ORDER BY name
        ")->fetchAll();
    }

    foreach ($known as $person) {

        $name = trim($person['name']);

        if ($name === '') {
            continue;
        }

        if (text_contains_person_name($text, $name)) {
            $people[$name] = $name;
            continue;
        }

        /*
         * If a person's first name is unique in our database,
         * allow the analyzer to recognize the first name alone.
         */
        $parts = preg_split('/\s+/', $name);

        $firstName = $parts[0] ?? '';

        if ($firstName !== '' && mb_strlen($firstName) >= 3) {

            if ($workerId !== null && $workerId > 0) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT p.id)
                    FROM people p
                    INNER JOIN report_people rp
                        ON rp.person_id = p.id
                    INNER JOIN reports r
                        ON r.id = rp.report_id
                    WHERE r.worker_id = ?
                      AND p.name LIKE ?
                ");

                $stmt->execute([
                    $workerId,
                    $firstName . ' %',
                ]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM people
                    WHERE name LIKE ?
                ");

                $stmt->execute([
                    $firstName . ' %'
                ]);
            }

            $count = (int) $stmt->fetchColumn();

            if (
                $count === 1 &&
                preg_match(
                    '/\b' . preg_quote($firstName, '/') . '\b/i',
                    $text
                )
            ) {
                $people[$name] = $name;
            }
        }
    }

    /*
     * Explicit learned aliases.
     */
    if ($workerId !== null && $workerId > 0) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                pa.alias,
                p.name
            FROM person_aliases pa
            INNER JOIN people p
                ON p.id = pa.person_id
            INNER JOIN report_people rp
                ON rp.person_id = p.id
            INNER JOIN reports r
                ON r.id = rp.report_id
            WHERE r.worker_id = ?
        ");

        $stmt->execute([
            $workerId,
        ]);

        $aliases = $stmt->fetchAll();
    } else {
        $aliases = $pdo->query("
            SELECT
                pa.alias,
                p.name
            FROM person_aliases pa
            INNER JOIN people p
                ON p.id = pa.person_id
        ")->fetchAll();
    }

    foreach ($aliases as $row) {
        if (
            text_contains_person_name(
                $text,
                (string) $row['alias']
            )
        ) {
            $people[$row['name']] = $row['name'];
        }
    }

    /*
     * General language patterns for people not yet known.
     */
    $patterns = [
        '/\b(?:lead|supervisor|manager|quality|coworker|employee|area owner)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/',
        '/\b(?:with|told|asked|saw|seen|called|spoke to|talked to|informed|helped by)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/'
    ];

    $bad = [
        'Ford',
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday'
    ];

    foreach ($patterns as $pattern) {

        if (!preg_match_all($pattern, $text, $matches)) {
            continue;
        }

        foreach ($matches[1] as $name) {

            $name = trim($name);

            if (
                $name !== '' &&
                !in_array($name, $bad, true)
            ) {
                $people[$name] = $name;
            }
        }
    }

    ksort($people);

    return array_values($people);
}

function detect_places(string $text, ?int $workerId = null): array
{
    $pdo = db();
    $places = [];

    /*
     * Previously confirmed places/machines are worker scoped.
     */
    if ($workerId !== null && $workerId > 0) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                p.name
            FROM places p
            INNER JOIN report_places rp
                ON rp.place_id = p.id
            INNER JOIN reports r
                ON r.id = rp.report_id
            WHERE r.worker_id = ?
            ORDER BY p.name
        ");

        $stmt->execute([
            $workerId,
        ]);

        $known = $stmt->fetchAll();
    } else {
        $known = $pdo->query("
            SELECT name
            FROM places
            ORDER BY name
        ")->fetchAll();
    }

    foreach ($known as $row) {

        if (text_contains_phrase($text, $row['name'])) {
            $places[$row['name']] = $row['name'];
        }
    }

    /*
     * Learned aliases.
     */
    if ($workerId !== null && $workerId > 0) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                pa.alias,
                p.name
            FROM place_aliases pa
            INNER JOIN places p
                ON p.id = pa.place_id
            INNER JOIN report_places rp
                ON rp.place_id = p.id
            INNER JOIN reports r
                ON r.id = rp.report_id
            WHERE r.worker_id = ?
        ");

        $stmt->execute([
            $workerId,
        ]);

        $aliases = $stmt->fetchAll();
    } else {
        $aliases = $pdo->query("
            SELECT
                pa.alias,
                p.name
            FROM place_aliases pa
            INNER JOIN places p
                ON p.id = pa.place_id
        ")->fetchAll();
    }

    foreach ($aliases as $row) {

        if (text_contains_phrase($text, $row['alias'])) {
            $places[$row['name']] = $row['name'];
        }
    }

    /*
     * Starter vocabulary.
     */
    $starterPlaces = [
        'Ford',
        'Ford Service',
        'Eagle 1',
        'Eagle 2',
        'CD6',
        'SHIM',
        'Quality',
        'Production',
        'Break Room',
        'Warehouse',
        'Shipping',
        'Receiving'
    ];

    foreach ($starterPlaces as $place) {

        if (text_contains_phrase($text, $place)) {
            $places[$place] = $place;
        }
    }

    /*
     * Prefer Ford Service over plain Ford when both exist.
     */
    if (
        isset($places['Ford Service']) &&
        isset($places['Ford'])
    ) {
        unset($places['Ford']);
    }

    ksort($places);

    return array_values($places);
}

function base_category_rules(): array
{
    return [
        'Changeover' => [
            'change over',
            'changeover',
            'changed over',
            'switching from',
            'switch from'
        ],

        'Waiting on Parts' => [
            'waiting on parts',
            'waiting for parts',
            'waiting on components',
            'waiting for components',
            'no parts',
            'ran out of',
            'out of bushings'
        ],

        'Quality' => [
            'quality',
            'bad part',
            'bad parts',
            'defect',
            'inspection',
            'scrap'
        ],

        'Management Interaction' => [
            'lead',
            'supervisor',
            'manager',
            'area owner'
        ],

        'Work Assignment' => [
            'assigned',
            'scheduled to run',
            'told me to',
            'sent me to',
            'moved to',
            'helped load'
        ],

        'Downtime' => [
            'waiting',
            'machine down',
            'downtime',
            'stopped',
            'not running'
        ],

        'Disciplinary Action' => [
            'write up',
            'write-up',
            'written up',
            'discipline',
            'pointed',
            'warning'
        ],

        'Attendance / Leave' => [
            'fmla',
            'leave',
            'attendance',
            'called off',
            'absence'
        ],

        'Harassment / Conduct' => [
            'harass',
            'threat',
            'yell',
            'holler',
            'ignored',
            'retaliation',
            'hostile'
        ],

        'Safety' => [
            'unsafe',
            'injury',
            'hurt',
            'accident',
            'hazard',
            'safety'
        ]
    ];
}

function detect_categories(string $text, ?int $workerId = null): array
{
    $pdo = db();

    $rules = base_category_rules();

    /*
     * Learned category keywords are global legacy data.
     *
     * For worker-scoped analysis, only use learned categories that
     * have already been attached to one of this worker's reports.
     */
    if ($workerId !== null && $workerId > 0) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                ck.keyword,
                c.name AS category_name
            FROM category_keywords ck
            INNER JOIN categories c
                ON c.id = ck.category_id
            INNER JOIN report_categories rc
                ON rc.category_id = c.id
            INNER JOIN reports r
                ON r.id = rc.report_id
            WHERE r.worker_id = ?
        ");

        $stmt->execute([
            $workerId,
        ]);

        $learned = $stmt->fetchAll();
    } else {
        $learned = $pdo->query("
            SELECT
                ck.keyword,
                c.name AS category_name
            FROM category_keywords ck
            INNER JOIN categories c
                ON c.id = ck.category_id
        ")->fetchAll();
    }

    foreach ($learned as $row) {

        $category = $row['category_name'];
        $keyword = $row['keyword'];

        if (!isset($rules[$category])) {
            $rules[$category] = [];
        }

        $rules[$category][] = $keyword;
    }

    $found = [];

    foreach ($rules as $category => $keywords) {

        foreach ($keywords as $keyword) {

            if (text_contains_phrase($text, $keyword)) {
                $found[$category] = $category;
                break;
            }
        }
    }

    return array_values($found);
}

function detect_events(string $text, ?int $workerId = null): array
{
    $normalized = preg_replace(
        '/\r\n|\r/',
        "\n",
        trim($text)
    );

    $parts = preg_split(
        '/(?<=[.!?])\s+|\n+/',
        $normalized,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $events = [];

    foreach ($parts as $part) {

        $sentence = trim($part);

        if (mb_strlen($sentence) < 12) {
            continue;
        }

        $events[] = [
            'time' => detect_time($sentence),
            'description' => $sentence,
            'category' => detect_event_category(
                $sentence,
                $workerId
            )
        ];
    }

    return $events;
}

function detect_time(string $text): ?string
{
    if (
        preg_match(
            '/\b(\d{1,2}):(\d{2})\s*(AM|PM|am|pm)?\b/',
            $text,
            $match
        )
    ) {
        $hour = (int) $match[1];
        $minute = (int) $match[2];
        $ampm = strtolower($match[3] ?? '');

        if ($ampm === 'pm' && $hour < 12) {
            $hour += 12;
        }

        if ($ampm === 'am' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    if (
        preg_match(
            '/\b(\d{1,2})\s*(AM|PM|am|pm)\b/',
            $text,
            $match
        )
    ) {
        $hour = (int) $match[1];
        $ampm = strtolower($match[2]);

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

function detect_event_category(
    string $text,
    ?int $workerId = null
): string {
    $categories = detect_categories(
        $text,
        $workerId
    );

    return $categories[0] ?? 'General';
}