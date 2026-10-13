<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function worker_profile_table_exists(string $table): bool
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

function worker_profile_column_exists(
    string $table,
    string $column
): bool {
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

function ensure_worker_profile_schema(): void
{
    $pdo = db();

    $columns = [
        'birth_date' => 'TEXT',
        'job_title' => 'TEXT',
        'shift_name' => 'TEXT',
        'hire_date' => 'TEXT',
        'primary_work_area' => 'TEXT',
        'supervisor_name' => 'TEXT',
        'training_status' => 'TEXT',
        'profile_photo_file' => 'TEXT',
        'profile_updated_at' => 'TEXT'
    ];

    foreach ($columns as $column => $definition) {
        if (!worker_profile_column_exists('workers', $column)) {
            $pdo->exec(
                "ALTER TABLE workers ADD COLUMN "
                . $column
                . " "
                . $definition
            );
        }
    }
}

function worker_age(?string $birthDate): ?int
{
    $birthDate = trim((string) $birthDate);

    if ($birthDate === '') {
        return null;
    }

    try {
        $birth = new DateTimeImmutable($birthDate);
        $today = new DateTimeImmutable('today');

        if ($birth > $today) {
            return null;
        }

        return $birth->diff($today)->y;
    } catch (Throwable $e) {
        return null;
    }
}

function worker_tenure_label(?string $hireDate): string
{
    $hireDate = trim((string) $hireDate);

    if ($hireDate === '') {
        return '-';
    }

    try {
        $hire = new DateTimeImmutable($hireDate);
        $today = new DateTimeImmutable('today');

        if ($hire > $today) {
            return '-';
        }

        $diff = $hire->diff($today);

        if ($diff->y > 0) {
            $label = $diff->y . ' year'
                . ($diff->y === 1 ? '' : 's');

            if ($diff->m > 0) {
                $label .= ', ' . $diff->m . ' month'
                    . ($diff->m === 1 ? '' : 's');
            }

            return $label;
        }

        if ($diff->m > 0) {
            return $diff->m . ' month'
                . ($diff->m === 1 ? '' : 's');
        }

        return $diff->d . ' day'
            . ($diff->d === 1 ? '' : 's');
    } catch (Throwable $e) {
        return '-';
    }
}

function worker_initials(string $name): string
{
    $parts = preg_split(
        '/\s+/',
        trim($name),
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $letters = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(
            mb_substr($part, 0, 1)
        );
    }

    return $letters !== '' ? $letters : '?';
}

function worker_profile_context(array $worker): array
{
    return [
        'worker_id' => (int) ($worker['id'] ?? 0),
        'full_name' => trim((string) ($worker['full_name'] ?? '')),
        'age' => worker_age($worker['birth_date'] ?? null),
        'job_title' => trim((string) ($worker['job_title'] ?? '')),
        'department' => trim((string) ($worker['department'] ?? '')),
        'shift' => trim((string) ($worker['shift_name'] ?? '')),
        'hire_date' => trim((string) ($worker['hire_date'] ?? '')),
        'tenure' => worker_tenure_label($worker['hire_date'] ?? null),
        'primary_work_area' => trim(
            (string) ($worker['primary_work_area'] ?? '')
        ),
        'supervisor_name' => trim(
            (string) ($worker['supervisor_name'] ?? '')
        ),
        'training_status' => trim(
            (string) ($worker['training_status'] ?? '')
        ),
        'employee_number' => trim(
            (string) ($worker['employee_number'] ?? '')
        ),
        'employer' => trim((string) ($worker['employer'] ?? '')),
        'state_code' => trim((string) ($worker['state_code'] ?? ''))
    ];
}

function worker_profile_completion(array $worker): int
{
    $fields = [
        $worker['full_name'] ?? '',
        $worker['job_title'] ?? '',
        $worker['department'] ?? '',
        $worker['shift_name'] ?? '',
        $worker['hire_date'] ?? '',
        $worker['primary_work_area'] ?? ''
    ];

    $filled = 0;

    foreach ($fields as $value) {
        if (trim((string) $value) !== '') {
            $filled++;
        }
    }

    return (int) round(
        ($filled / count($fields)) * 100
    );
}


function worker_current_employment(int $workerId): ?array
{
    if (
        $workerId <= 0
        || !worker_profile_table_exists('worker_employment')
    ) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT
            we.id AS employment_id,
            we.organization_id,
            we.worker_id,
            we.employer_id,
            we.worksite_id,
            we.job_title_id,

            we.employer_name_snapshot AS employer,
            we.worksite_name_snapshot AS worksite,
            we.job_title_snapshot AS job_title,

            we.department,
            we.work_area,
            we.shift_name,
            we.supervisor_name,
            we.employee_number,
            we.training_status,

            we.start_date,
            we.end_date,

            we.is_current,
            we.is_primary,
            we.record_status,

            we.source_type,
            we.source_key,

            we.created_at,
            we.updated_at

        FROM worker_employment we

        WHERE we.worker_id = :worker_id
          AND we.is_current = 1
          AND we.is_primary = 1
          AND we.record_status = 'active'

        ORDER BY we.id DESC

        LIMIT 1
    ");

    $stmt->execute([
        ':worker_id' => $workerId
    ]);

    $employment = $stmt->fetch();

    return $employment ?: null;
}

