<?php

require_once __DIR__ . '/functions.php';

function ensure_access_request_schema(): void
{
    db()->exec("
        CREATE TABLE IF NOT EXISTS access_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            organization TEXT NULL,
            interest_reason TEXT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            admin_notes TEXT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            reviewed_at TEXT NULL,
            reviewed_by_user_id INTEGER NULL
        )
    ");

    db()->exec("
        CREATE INDEX IF NOT EXISTS idx_access_requests_status
        ON access_requests(status)
    ");

    db()->exec("
        CREATE INDEX IF NOT EXISTS idx_access_requests_email
        ON access_requests(email)
    ");

    /*
     * Tenant assignment is trusted application state.
     *
     * The existing organization field is applicant-provided text and
     * must never be treated as a WorkReport tenant identifier.
     *
     * NULL means the request has not yet been assigned to a tenant.
     */
    $columns = [];

    foreach (
        db()->query("PRAGMA table_info(access_requests)")->fetchAll()
        as $column
    ) {
        $columns[$column['name']] = true;
    }

    if (!isset($columns['organization_id'])) {
        db()->exec("
            ALTER TABLE access_requests
            ADD COLUMN organization_id INTEGER
        ");
    }

    if (!isset($columns['worker_id'])) {
        db()->exec("
            ALTER TABLE access_requests
            ADD COLUMN worker_id INTEGER
        ");
    }

    db()->exec("
        CREATE INDEX IF NOT EXISTS idx_access_requests_organization
        ON access_requests(organization_id)
    ");

    db()->exec("
        CREATE INDEX IF NOT EXISTS idx_access_requests_worker
        ON access_requests(worker_id)
    ");
}

function access_request_statuses(): array
{
    return [
        'pending',
        'reviewed',
        'invited',
        'declined'
    ];
}

/* WORKREPORT_SECURE_INVITE_SCHEMA_V1 */

function ensure_secure_invite_schema(): void
{
    ensure_access_request_schema();

    $pdo = db();

    $userColumns = [];

    foreach (
        $pdo->query("PRAGMA table_info(users)")->fetchAll()
        as $column
    ) {
        $userColumns[$column['name']] = true;
    }

    if (!isset($userColumns['first_login'])) {
        $pdo->exec("
            ALTER TABLE users
            ADD COLUMN first_login TEXT
        ");
    }

    if (!isset($userColumns['login_count'])) {
        $pdo->exec("
            ALTER TABLE users
            ADD COLUMN login_count INTEGER NOT NULL DEFAULT 0
        ");
    }

    $requestColumns = [];

    foreach (
        $pdo->query("PRAGMA table_info(access_requests)")->fetchAll()
        as $column
    ) {
        $requestColumns[$column['name']] = true;
    }

    if (!isset($requestColumns['user_id'])) {
        $pdo->exec("
            ALTER TABLE access_requests
            ADD COLUMN user_id INTEGER
        ");
    }

    if (!isset($requestColumns['invited_at'])) {
        $pdo->exec("
            ALTER TABLE access_requests
            ADD COLUMN invited_at TEXT
        ");
    }

    if (!isset($requestColumns['activated_at'])) {
        $pdo->exec("
            ALTER TABLE access_requests
            ADD COLUMN activated_at TEXT
        ");
    }

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_access_requests_user_id
        ON access_requests(user_id)
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            access_request_id INTEGER,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            created_by_user_id INTEGER,
            FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_user_invites_user
        ON user_invites(user_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_user_invites_request
        ON user_invites(access_request_id)
    ");
}

function workreport_unique_username(
    string $email,
    string $displayName
): string {
    $base = strtolower(
        trim(
            preg_replace(
                '/[^a-zA-Z0-9._-]+/',
                '',
                strstr($email, '@', true) ?: ''
            )
        )
    );

    if ($base === '') {
        $base = strtolower(
            trim(
                preg_replace(
                    '/[^a-zA-Z0-9._-]+/',
                    '',
                    str_replace(' ', '.', $displayName)
                )
            )
        );
    }

    if ($base === '') {
        $base = 'user';
    }

    $candidate = $base;
    $suffix = 1;

    while (true) {
        $stmt = db()->prepare("
            SELECT 1
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->execute([$candidate]);

        if (!$stmt->fetchColumn()) {
            return $candidate;
        }

        $suffix++;
        $candidate = $base . $suffix;
    }
}

function workreport_request_usage_state(array $row): string
{
    if (
        !empty($row['last_login'])
        || (int) ($row['login_count'] ?? 0) > 0
    ) {
        return 'Active User';
    }

    if (!empty($row['first_login'])) {
        return 'First Login';
    }

    if (!empty($row['activated_at'])) {
        return 'Activated';
    }

    if (!empty($row['invited_at'])) {
        return 'Invited';
    }

    return ucfirst((string) ($row['status'] ?? 'pending'));
}
