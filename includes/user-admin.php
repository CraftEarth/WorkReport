<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function admin_table_exists(string $table): bool
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

function admin_column_exists(
    string $table,
    string $column
): bool {
    if (!admin_table_exists($table)) {
        return false;
    }

    $rows = db()->query(
        "PRAGMA table_info(" . $table . ")"
    )->fetchAll();

    foreach ($rows as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function admin_get_user(int $userId): ?array
{
    $stmt = db()->prepare("
        SELECT *
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $row = $stmt->fetch();

    return $row ?: null;
}

function admin_all_users(): array
{
    return db()->query("
        SELECT
            u.*,
            (
                SELECT COUNT(*)
                FROM user_worker_access uwa
                WHERE uwa.user_id = u.id
            ) AS worker_access_count
        FROM users u
        ORDER BY
            CASE u.role
                WHEN 'super_admin' THEN 1
                WHEN 'admin' THEN 2
                ELSE 3
            END,
            u.active DESC,
            u.display_name COLLATE NOCASE,
            u.username COLLATE NOCASE
    ")->fetchAll();
}

function admin_active_super_admin_count(): int
{
    $stmt = db()->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'super_admin'
          AND active = 1
    ");

    return (int) $stmt->fetchColumn();
}

function admin_allowed_roles(): array
{
    return [
        'super_admin',
        'admin',
        'user'
    ];
}

function admin_user_reference_locations(
    int $userId
): array {
    $locations = [];

    $checks = [
        [
            'table' => 'work_journal_messages',
            'column' => 'user_id',
            'label' => 'journal messages'
        ],
        [
            'table' => 'work_journal_sessions',
            'column' => 'created_by_user_id',
            'label' => 'journal sessions'
        ],
        [
            'table' => 'work_journal_attachments',
            'column' => 'uploaded_by_user_id',
            'label' => 'journal evidence'
        ],
        [
            'table' => 'historical_intake_batches',
            'column' => 'imported_by_user_id',
            'label' => 'historical imports'
        ],
        [
            'table' => 'historical_intake_files',
            'column' => 'uploaded_by_user_id',
            'label' => 'historical evidence'
        ],
        [
            'table' => 'organization_memberships',
            'column' => 'user_id',
            'label' => 'organization membership'
        ]
    ];

    foreach ($checks as $check) {
        if (
            !admin_column_exists(
                $check['table'],
                $check['column']
            )
        ) {
            continue;
        }

        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM "
            . $check['table']
            . " WHERE "
            . $check['column']
            . " = ?"
        );

        $stmt->execute([$userId]);

        $count = (int) $stmt->fetchColumn();

        if ($count > 0) {
            $locations[] = [
                'label' => $check['label'],
                'count' => $count
            ];
        }
    }

    return $locations;
}

function admin_change_user_role(
    int $targetUserId,
    string $newRole,
    int $actingUserId
): void {
    if (!in_array(
        $newRole,
        admin_allowed_roles(),
        true
    )) {
        throw new InvalidArgumentException(
            'Invalid user role.'
        );
    }

    $target = admin_get_user($targetUserId);

    if (!$target) {
        throw new RuntimeException(
            'User not found.'
        );
    }

    if ($targetUserId === $actingUserId) {
        throw new RuntimeException(
            'You cannot change your own role while signed in.'
        );
    }

    if (
        $target['role'] === 'super_admin'
        && $newRole !== 'super_admin'
        && (int) $target['active'] === 1
        && admin_active_super_admin_count() <= 1
    ) {
        throw new RuntimeException(
            'You cannot demote the last active Super Admin.'
        );
    }

    $stmt = db()->prepare("
        UPDATE users
        SET role = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $newRole,
        $targetUserId
    ]);
}

function admin_set_user_active(
    int $targetUserId,
    bool $active,
    int $actingUserId
): void {
    $target = admin_get_user($targetUserId);

    if (!$target) {
        throw new RuntimeException(
            'User not found.'
        );
    }

    if ($targetUserId === $actingUserId && !$active) {
        throw new RuntimeException(
            'You cannot deactivate your own signed-in account.'
        );
    }

    if (
        !$active
        && $target['role'] === 'super_admin'
        && (int) $target['active'] === 1
        && admin_active_super_admin_count() <= 1
    ) {
        throw new RuntimeException(
            'You cannot deactivate the last active Super Admin.'
        );
    }

    $stmt = db()->prepare("
        UPDATE users
        SET active = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $active ? 1 : 0,
        $targetUserId
    ]);
}

function admin_delete_user(
    int $targetUserId,
    int $actingUserId
): void {
    $target = admin_get_user($targetUserId);

    if (!$target) {
        throw new RuntimeException(
            'User not found.'
        );
    }

    if ($targetUserId === $actingUserId) {
        throw new RuntimeException(
            'You cannot delete the account you are currently using.'
        );
    }

    if (
        $target['role'] === 'super_admin'
        && (int) $target['active'] === 1
        && admin_active_super_admin_count() <= 1
    ) {
        throw new RuntimeException(
            'You cannot delete the last active Super Admin.'
        );
    }

    $references =
        admin_user_reference_locations(
            $targetUserId
        );

    $blockingReferences = array_filter(
        $references,
        static fn (array $item): bool =>
            $item['label'] !== 'organization membership'
    );

    if ($blockingReferences) {
        $parts = [];

        foreach ($blockingReferences as $reference) {
            $parts[] =
                $reference['count']
                . ' '
                . $reference['label'];
        }

        throw new RuntimeException(
            'This account has audit/source records ('
            . implode(', ', $parts)
            . '). Deactivate it instead so those records keep their provenance.'
        );
    }

    $pdo = db();

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            DELETE FROM user_worker_access
            WHERE user_id = ?
        ");

        $stmt->execute([$targetUserId]);

        if (
            admin_table_exists(
                'organization_memberships'
            )
        ) {
            $stmt = $pdo->prepare("
                DELETE FROM organization_memberships
                WHERE user_id = ?
            ");

            $stmt->execute([$targetUserId]);
        }

        $stmt = $pdo->prepare("
            DELETE FROM users
            WHERE id = ?
        ");

        $stmt->execute([$targetUserId]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
