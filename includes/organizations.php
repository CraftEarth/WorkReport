<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function organization_table_has_column(string $table, string $column): bool
{
    $stmt = db()->query("PRAGMA table_info(" . $table . ")");

    foreach ($stmt->fetchAll() as $item) {
        if (($item['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function ensure_organization_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS organizations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS organization_memberships (
            user_id INTEGER NOT NULL,
            organization_id INTEGER NOT NULL,
            membership_role TEXT NOT NULL DEFAULT 'employee',
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (user_id, organization_id),

            FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE,

            FOREIGN KEY (organization_id)
                REFERENCES organizations(id)
                ON DELETE CASCADE
        );
    ");

    if (!organization_table_has_column('workers', 'organization_id')) {
        $pdo->exec("
            ALTER TABLE workers
            ADD COLUMN organization_id INTEGER
        ");
    }

    if (!organization_table_has_column('reports', 'organization_id')) {
        $pdo->exec("
            ALTER TABLE reports
            ADD COLUMN organization_id INTEGER
        ");
    }

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_workers_organization
        ON workers(organization_id);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_reports_organization
        ON reports(organization_id);
    ");

    /*
     * SECURITY:
     *
     * Organization assignment must be explicit.
     *
     * Never automatically put an unassigned user, worker, or report
     * into the first organization in the database.
     */

    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_reports_organization_from_worker
        AFTER INSERT ON reports
        WHEN NEW.organization_id IS NULL
         AND NEW.worker_id IS NOT NULL
        BEGIN
            UPDATE reports
            SET organization_id = (
                SELECT organization_id
                FROM workers
                WHERE id = NEW.worker_id
            )
            WHERE id = NEW.id;
        END;
    ");
}

function user_organization_ids(int $userId): array
{
    ensure_organization_schema();

    $stmt = db()->prepare("
        SELECT om.organization_id
        FROM organization_memberships om
        INNER JOIN organizations o
            ON o.id = om.organization_id
        WHERE om.user_id = ?
          AND om.active = 1
          AND o.active = 1
        ORDER BY o.name
    ");

    $stmt->execute([$userId]);

    return array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
}

function primary_organization_id_for_user(int $userId): ?int
{
    $ids = user_organization_ids($userId);

    return $ids[0] ?? null;
}

function user_belongs_to_organization(
    int $userId,
    int $organizationId
): bool {
    if ($userId < 1 || $organizationId < 1) {
        return false;
    }

    ensure_organization_schema();

    $stmt = db()->prepare("
        SELECT 1
        FROM organization_memberships om
        INNER JOIN organizations o
            ON o.id = om.organization_id
        WHERE om.user_id = ?
          AND om.organization_id = ?
          AND om.active = 1
          AND o.active = 1
        LIMIT 1
    ");

    $stmt->execute([
        $userId,
        $organizationId
    ]);

    return (bool) $stmt->fetchColumn();
}

function worker_organization_id(int $workerId): ?int
{
    ensure_organization_schema();

    $stmt = db()->prepare("
        SELECT organization_id
        FROM workers
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$workerId]);

    $organizationId = (int) $stmt->fetchColumn();

    return $organizationId > 0
        ? $organizationId
        : null;
}