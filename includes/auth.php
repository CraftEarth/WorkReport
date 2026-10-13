<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/organizations.php';

function ensure_auth_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            display_name TEXT NOT NULL,
            email TEXT,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            first_login TEXT,
            last_login TEXT
        );
    ");

    /*
     * Authentication-owned login history fields.
     *
     * Login must never depend on another subsystem having already
     * initialized the users table.
     */
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS workers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            employee_number TEXT,
            department TEXT,
            employer TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_worker_access (
            user_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,
            permission_level TEXT NOT NULL DEFAULT 'write',

            PRIMARY KEY (user_id, worker_id),

            FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE,

            FOREIGN KEY (worker_id)
                REFERENCES workers(id)
                ON DELETE CASCADE
        );
    ");

    /*
     * Add worker_id to existing reports if it does not exist.
     */
    $columns = [];

    $result = $pdo->query(
        "PRAGMA table_info(reports)"
    );

    foreach ($result->fetchAll() as $column) {
        $columns[$column['name']] = true;
    }

    if (!isset($columns['worker_id'])) {

        $pdo->exec("
            ALTER TABLE reports
            ADD COLUMN worker_id INTEGER
        ");
    }

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_reports_worker
        ON reports(worker_id);
    ");

    ensure_organization_schema();
}

function user_count(): int
{
    ensure_auth_schema();

    return (int) db()
        ->query("SELECT COUNT(*) FROM users")
        ->fetchColumn();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM users
        WHERE id = ?
          AND active = 1
        LIMIT 1
    ");

    $stmt->execute([
        (int) $_SESSION['user_id']
    ]);

    $user = $stmt->fetch();

    return $user ?: null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_super_admin(): bool
{
    $user = current_user();

    return $user
        && $user['role'] === 'super_admin';
}

function is_admin(): bool
{
    $user = current_user();

    return $user
        && in_array(
            $user['role'],
            [
                'super_admin',
                'admin'
            ],
            true
        );
}

function require_login(): void
{
    if (!is_logged_in()) {

        header(
            'Location: login.php'
        );

        exit;
    }
}

function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function require_super_admin(): void
{
    require_login();

    if (!is_super_admin()) {
        http_response_code(403);
        exit('Super Admin access required.');
    }
}

function logout_user(): void
{
    $_SESSION = [];

    if (
        ini_get('session.use_cookies')
    ) {

        $params =
            session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function worker_accessible(
    int $workerId
): bool {

    if ($workerId < 1) {
        return false;
    }

    $user = current_user();

    if (!$user) {
        return false;
    }

    /*
     * SECURITY:
     *
     * No global Admin or Super Admin bypass.
     *
     * Record access requires BOTH:
     *   1. explicit user_worker_access
     *   2. active membership in the worker's organization
     *
     * A platform role alone does not grant access to client records.
     */

    $stmt = db()->prepare("
        SELECT 1
        FROM user_worker_access uwa

        INNER JOIN workers w
            ON w.id = uwa.worker_id

        INNER JOIN organization_memberships om
            ON om.user_id = uwa.user_id
           AND om.organization_id = w.organization_id
           AND om.active = 1

        INNER JOIN organizations o
            ON o.id = w.organization_id
           AND o.active = 1

        WHERE uwa.user_id = ?
          AND uwa.worker_id = ?
          AND w.active = 1

        LIMIT 1
    ");

    $stmt->execute([
        (int) $user['id'],
        $workerId
    ]);

    return (bool) $stmt->fetchColumn();
}

function accessible_workers(): array
{
    $user = current_user();

    if (!$user) {
        return [];
    }

    /*
     * Use exactly the same authorization chain when listing workers.
     *
     * Super Admin and Admin roles do not automatically expose
     * every worker in the database.
     */

    $stmt = db()->prepare("
        SELECT DISTINCT w.*
        FROM workers w

        INNER JOIN user_worker_access uwa
            ON uwa.worker_id = w.id

        INNER JOIN organization_memberships om
            ON om.user_id = uwa.user_id
           AND om.organization_id = w.organization_id
           AND om.active = 1

        INNER JOIN organizations o
            ON o.id = w.organization_id
           AND o.active = 1

        WHERE uwa.user_id = ?
          AND w.active = 1

        ORDER BY w.full_name
    ");

    $stmt->execute([
        (int) $user['id']
    ]);

    return $stmt->fetchAll();
}

function current_worker(): ?array
{
    $workerId =
        (int) (
            $_SESSION['worker_id']
            ?? 0
        );

    if ($workerId < 1) {
        return null;
    }

    if (
        !worker_accessible(
            $workerId
        )
    ) {
        unset(
            $_SESSION['worker_id']
        );

        return null;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM workers
        WHERE id = ?
          AND active = 1
        LIMIT 1
    ");

    $stmt->execute([
        $workerId
    ]);

    return $stmt->fetch() ?: null;
}

function require_current_worker(): array
{
    require_login();

    $worker = current_worker();

    if (!$worker) {
        header('Location: select-worker.php');
        exit;
    }

    return $worker;
}

function get_report_for_current_worker(int $reportId): ?array
{
    if ($reportId < 1) {
        return null;
    }

    $worker = require_current_worker();

    $stmt = db()->prepare("
        SELECT *
        FROM reports
        WHERE id = ?
          AND worker_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $reportId,
        (int) $worker['id']
    ]);

    $report = $stmt->fetch();

    return $report ?: null;
}


function get_attachment_for_current_worker(int $attachmentId): ?array
{
    if ($attachmentId < 1) {
        return null;
    }

    $worker = require_current_worker();

    $stmt = db()->prepare("
        SELECT a.*
        FROM attachments a
        INNER JOIN reports r
            ON r.id = a.report_id
        WHERE a.id = ?
          AND r.worker_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $attachmentId,
        (int) $worker['id']
    ]);

    $attachment = $stmt->fetch();

    return $attachment ?: null;
}
