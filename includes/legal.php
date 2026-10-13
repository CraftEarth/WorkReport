<?php

require_once __DIR__ . '/db.php';

function ensure_legal_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jurisdictions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            state_code TEXT NOT NULL UNIQUE COLLATE NOCASE,
            state_name TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS legal_resources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            jurisdiction_id INTEGER,
            resource_scope TEXT NOT NULL DEFAULT 'state',
            topic TEXT NOT NULL,
            title TEXT NOT NULL,
            summary TEXT,
            source_name TEXT NOT NULL,
            source_url TEXT NOT NULL,
            reviewed_at TEXT,
            sort_order INTEGER NOT NULL DEFAULT 100,
            active INTEGER NOT NULL DEFAULT 1,

            FOREIGN KEY (jurisdiction_id)
                REFERENCES jurisdictions(id)
                ON DELETE CASCADE
        );
    ");

    /*
     * Add state_code to worker profiles.
     */
    $columns = [];

    $result = $pdo->query(
        "PRAGMA table_info(workers)"
    );

    foreach ($result->fetchAll() as $column) {
        $columns[$column['name']] = true;
    }

    if (!isset($columns['state_code'])) {

        $pdo->exec("
            ALTER TABLE workers
            ADD COLUMN state_code TEXT DEFAULT 'KY'
        ");
    }

    seed_kentucky_resources();
}

function seed_kentucky_resources(): void
{
    $pdo = db();

    $pdo->exec("
        INSERT OR IGNORE INTO jurisdictions (
            state_code,
            state_name,
            active
        )
        VALUES (
            'KY',
            'Kentucky',
            1
        );
    ");

    $stmt = $pdo->prepare("
        SELECT id
        FROM jurisdictions
        WHERE state_code = 'KY'
        LIMIT 1
    ");

    $stmt->execute();

    $kyId =
        (int) $stmt->fetchColumn();

    $resources = [

        [
            'state',
            'Discrimination',
            'Kentucky Civil Rights',
            'Learn about workplace discrimination protections and how to submit an inquiry to the Kentucky Commission on Human Rights.',
            'Kentucky Commission on Human Rights',
            'https://kchr.ky.gov/Pages/File-a-Complaint.aspx',
            10
        ],

        [
            'federal',
            'FMLA',
            'FMLA Employee Protections',
            'Federal guidance covering job-protected leave, restoration rights, interference, retaliation, and prohibited use of FMLA leave in negative employment actions.',
            'U.S. Department of Labor',
            'https://www.dol.gov/agencies/whd/fact-sheets/28a-fmla-employee-protections',
            20
        ],

        [
            'federal',
            'FMLA',
            'FMLA Retaliation Protections',
            'Federal guidance explaining that employers may not interfere with FMLA rights or retaliate against employees for exercising those rights.',
            'U.S. Department of Labor',
            'https://www.dol.gov/agencies/whd/fact-sheets/77b-fmla-protections',
            30
        ],

        [
            'state',
            'Legal Help',
            'Find a Kentucky Lawyer',
            'Kentucky lawyer-referral resources organized by region, including information about legal-aid options.',
            'Kentucky Bar Association',
            'https://kybar.org/For-Public/Lawyer-Referral-Services',
            40
        ]
    ];

    foreach ($resources as $resource) {

        [
            $scope,
            $topic,
            $title,
            $summary,
            $source,
            $url,
            $sort
        ] = $resource;

        $stmt = $pdo->prepare("
            SELECT id
            FROM legal_resources
            WHERE jurisdiction_id = ?
              AND title = ?
            LIMIT 1
        ");

        $stmt->execute([
            $kyId,
            $title
        ]);

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
            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                date('now'),
                ?,
                1
            )
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

function legal_resources_for_state(
    string $stateCode
): array {

    ensure_legal_schema();

    $stmt = db()->prepare("
        SELECT
            lr.*,
            j.state_code,
            j.state_name
        FROM legal_resources lr
        INNER JOIN jurisdictions j
            ON j.id = lr.jurisdiction_id
        WHERE j.state_code = ?
          AND j.active = 1
          AND lr.active = 1
        ORDER BY
            lr.sort_order,
            lr.topic,
            lr.title
    ");

    $stmt->execute([
        strtoupper($stateCode)
    ]);

    return $stmt->fetchAll();
}