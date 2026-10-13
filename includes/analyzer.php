<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/operational-analysis.php';

function analyze_report_text(string $text): array
{
    return [
        'people' => detect_people($text),
        'places' => detect_places($text),
        'categories' => detect_categories($text),
        'events' => detect_events($text),
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

function detect_people(string $text): array
{
    $pdo = db();
    $people = [];

    /*
     * First use everything the program has already learned.
     */
    $known = $pdo->query("
        SELECT id, name
        FROM people
        ORDER BY name
    ")->fetchAll();

    foreach ($known as $person) {

        $name = trim($person['name']);

        if ($name === '') {
            continue;
        }

        if (text_contains_phrase($text, $name)) {
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

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM people
                WHERE name LIKE ?
            ");

            $stmt->execute([
                $firstName . ' %'
            ]);

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
    $aliases = $pdo->query("
        SELECT
            pa.alias,
            p.name
        FROM person_aliases pa
        INNER JOIN people p
            ON p.id = pa.person_id
    ")->fetchAll();

    foreach ($aliases as $row) {
        if (text_contains_phrase($text, $row['alias'])) {
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

function detect_places(string $text): array
{
    $pdo = db();
    $places = [];

    /*
     * Previously confirmed places/machines.
     */
    $known = $pdo->query("
        SELECT name
        FROM places
        ORDER BY name
    ")->fetchAll();

    foreach ($known as $row) {

        if (text_contains_phrase($text, $row['name'])) {
            $places[$row['name']] = $row['name'];
        }
    }

    /*
     * Learned aliases.
     */
    $aliases = $pdo->query("
        SELECT
            pa.alias,
            p.name
        FROM place_aliases pa
        INNER JOIN places p
            ON p.id = pa.place_id
    ")->fetchAll();

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

function detect_categories(string $text): array
{
    $pdo = db();

    $rules = base_category_rules();

    /*
     * Add anything the user has taught the program.
     */
    $learned = $pdo->query("
        SELECT
            ck.keyword,
            c.name AS category_name
        FROM category_keywords ck
        INNER JOIN categories c
            ON c.id = ck.category_id
    ")->fetchAll();

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

function detect_events(string $text): array
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
            'category' => detect_event_category($sentence)
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

function detect_event_category(string $text): string
{
    $categories = detect_categories($text);

    return $categories[0] ?? 'General';
}