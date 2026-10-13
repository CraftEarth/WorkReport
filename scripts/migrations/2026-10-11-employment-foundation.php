<?php

declare(strict_types=1);

const DB_PATH = '/var/lib/workreport/reports.sqlite';

function normalize_name(?string $value): string
{
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return mb_strtolower($value, 'UTF-8');
}

function clean_value(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM sqlite_master
         WHERE type = 'table'
           AND name = ?
         LIMIT 1"
    );
    $stmt->execute([$table]);

    return (bool)$stmt->fetchColumn();
}

if (!is_file(DB_PATH)) {
    fwrite(STDERR, "ERROR: Database not found: " . DB_PATH . PHP_EOL);
    exit(1);
}

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');

$integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();

if ($integrity !== 'ok') {
    fwrite(STDERR, "ERROR: Pre-migration integrity check failed: {$integrity}\n");
    exit(1);
}

foreach (['workers', 'organizations'] as $requiredTable) {
    if (!table_exists($pdo, $requiredTable)) {
        fwrite(STDERR, "ERROR: Required table missing: {$requiredTable}\n");
        exit(1);
    }
}

echo "Preflight database integrity: OK\n";
echo "Beginning additive employment migration...\n\n";

try {
    $pdo->exec('BEGIN IMMEDIATE');

    /*
     * GLOBAL EMPLOYER DIRECTORY
     *
     * normalized_name only normalizes case and whitespace.
     * It must never fuzzy-match similar company names.
     */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            canonical_name TEXT NOT NULL,
            normalized_name TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1
                CHECK (active IN (0,1)),
            created_by_user_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS ux_employers_normalized_name
        ON employers(normalized_name)
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employer_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employer_id INTEGER NOT NULL,
            alias_name TEXT NOT NULL,
            normalized_alias TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1
                CHECK (active IN (0,1)),
            created_by_user_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (employer_id)
                REFERENCES employers(id)
                ON DELETE RESTRICT
        )
    ");

    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS ux_employer_aliases_normalized
        ON employer_aliases(normalized_alias)
    ");

    /*
     * WORKSITES
     *
     * No UNKNOWN placeholder rows.
     * Unknown worksite = NULL worker_employment.worksite_id.
     */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS worksites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employer_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            normalized_name TEXT NOT NULL,
            address_line1 TEXT NULL,
            address_line2 TEXT NULL,
            city TEXT NULL,
            state_code TEXT NULL,
            postal_code TEXT NULL,
            country_code TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1
                CHECK (active IN (0,1)),
            created_by_user_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (employer_id)
                REFERENCES employers(id)
                ON DELETE RESTRICT
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS ix_worksites_employer
        ON worksites(employer_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS ix_worksites_normalized_name
        ON worksites(normalized_name)
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS worksite_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worksite_id INTEGER NOT NULL,
            alias_name TEXT NOT NULL,
            normalized_alias TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1
                CHECK (active IN (0,1)),
            created_by_user_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (worksite_id)
                REFERENCES worksites(id)
                ON DELETE RESTRICT
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS ix_worksite_aliases_normalized
        ON worksite_aliases(normalized_alias)
    ");

    /*
     * JOB TITLE DIRECTORY
     */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS job_titles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            canonical_name TEXT NOT NULL,
            normalized_name TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1
                CHECK (active IN (0,1)),
            created_by_user_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS ux_job_titles_normalized_name
        ON job_titles(normalized_name)
    ");

    /*
     * EMPLOYMENT RECORD
     *
     * organization_id is stored for defense-in-depth tenant scoping.
     *
     * Snapshot values preserve what was entered when this employment
     * record was created even if a canonical directory name changes later.
     *
     * source_key makes migration/backfill idempotent.
     */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS worker_employment (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            organization_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,

            employer_id INTEGER NOT NULL,
            worksite_id INTEGER NULL,
            job_title_id INTEGER NULL,

            employer_name_snapshot TEXT NOT NULL,
            worksite_name_snapshot TEXT NULL,
            job_title_snapshot TEXT NULL,

            department TEXT NULL,
            work_area TEXT NULL,
            shift_name TEXT NULL,
            supervisor_name TEXT NULL,
            employee_number TEXT NULL,
            training_status TEXT NULL,

            start_date TEXT NULL,
            end_date TEXT NULL,

            is_current INTEGER NOT NULL DEFAULT 1
                CHECK (is_current IN (0,1)),

            is_primary INTEGER NOT NULL DEFAULT 0
                CHECK (is_primary IN (0,1)),

            record_status TEXT NOT NULL DEFAULT 'active'
                CHECK (
                    record_status IN (
                        'active',
                        'ended',
                        'superseded',
                        'voided'
                    )
                ),

            source_type TEXT NOT NULL,
            source_key TEXT NULL,

            supersedes_employment_id INTEGER NULL,

            created_by_user_id INTEGER NULL,
            updated_by_user_id INTEGER NULL,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (organization_id)
                REFERENCES organizations(id)
                ON DELETE RESTRICT,

            FOREIGN KEY (worker_id)
                REFERENCES workers(id)
                ON DELETE RESTRICT,

            FOREIGN KEY (employer_id)
                REFERENCES employers(id)
                ON DELETE RESTRICT,

            FOREIGN KEY (worksite_id)
                REFERENCES worksites(id)
                ON DELETE RESTRICT,

            FOREIGN KEY (job_title_id)
                REFERENCES job_titles(id)
                ON DELETE RESTRICT,

            FOREIGN KEY (supersedes_employment_id)
                REFERENCES worker_employment(id)
                ON DELETE RESTRICT,

            CHECK (
                end_date IS NULL
                OR start_date IS NULL
                OR end_date >= start_date
            )
        )
    ");

    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS ux_worker_employment_source_key
        ON worker_employment(source_key)
        WHERE source_key IS NOT NULL
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS ix_worker_employment_worker
        ON worker_employment(worker_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS ix_worker_employment_org
        ON worker_employment(organization_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS ix_worker_employment_employer
        ON worker_employment(employer_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS ix_worker_employment_worksite
        ON worker_employment(worksite_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS ix_worker_employment_job_title
        ON worker_employment(job_title_id)
    ");

    /*
     * A worker may have several simultaneous jobs, but only one
     * PRIMARY current employment record.
     */
    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS ux_worker_employment_primary_current
        ON worker_employment(worker_id)
        WHERE
            is_current = 1
            AND is_primary = 1
            AND record_status = 'active'
    ");

    /*
     * Defense-in-depth:
     * employment organization must match worker organization.
     */
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_worker_employment_org_insert
        BEFORE INSERT ON worker_employment
        FOR EACH ROW
        WHEN NOT EXISTS (
            SELECT 1
            FROM workers
            WHERE id = NEW.worker_id
              AND organization_id = NEW.organization_id
        )
        BEGIN
            SELECT RAISE(
                ABORT,
                'worker employment organization mismatch'
            );
        END
    ");

    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_worker_employment_org_update
        BEFORE UPDATE OF organization_id, worker_id
        ON worker_employment
        FOR EACH ROW
        WHEN NOT EXISTS (
            SELECT 1
            FROM workers
            WHERE id = NEW.worker_id
              AND organization_id = NEW.organization_id
        )
        BEGIN
            SELECT RAISE(
                ABORT,
                'worker employment organization mismatch'
            );
        END
    ");

    /*
     * A selected worksite must belong to the selected employer.
     */
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_worker_employment_worksite_insert
        BEFORE INSERT ON worker_employment
        FOR EACH ROW
        WHEN
            NEW.worksite_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM worksites
                WHERE id = NEW.worksite_id
                  AND employer_id = NEW.employer_id
            )
        BEGIN
            SELECT RAISE(
                ABORT,
                'worksite does not belong to employer'
            );
        END
    ");

    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_worker_employment_worksite_update
        BEFORE UPDATE OF worksite_id, employer_id
        ON worker_employment
        FOR EACH ROW
        WHEN
            NEW.worksite_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM worksites
                WHERE id = NEW.worksite_id
                  AND employer_id = NEW.employer_id
            )
        BEGIN
            SELECT RAISE(
                ABORT,
                'worksite does not belong to employer'
            );
        END
    ");

    /*
     * Committed employment records are preservation records.
     * They may be ended, superseded, or voided — not hard deleted.
     */
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_worker_employment_no_delete
        BEFORE DELETE ON worker_employment
        FOR EACH ROW
        BEGIN
            SELECT RAISE(
                ABORT,
                'worker employment records cannot be hard deleted'
            );
        END
    ");

    /*
     * LEGACY PROFILE BACKFILL
     *
     * Reads current worker profile.
     * Does NOT modify workers.
     * Does NOT create a worksite.
     * Does NOT fuzzy-match employer names.
     */
    $workers = $pdo->query("
        SELECT
            id,
            full_name,
            organization_id,
            employer,
            job_title,
            department,
            shift_name,
            hire_date,
            primary_work_area,
            supervisor_name,
            training_status,
            employee_number
        FROM workers
        ORDER BY id
    ")->fetchAll();

    $findEmployer = $pdo->prepare("
        SELECT id, canonical_name
        FROM employers
        WHERE normalized_name = ?
        LIMIT 1
    ");

    $insertEmployer = $pdo->prepare("
        INSERT INTO employers (
            canonical_name,
            normalized_name
        )
        VALUES (?, ?)
    ");

    $findJobTitle = $pdo->prepare("
        SELECT id, canonical_name
        FROM job_titles
        WHERE normalized_name = ?
        LIMIT 1
    ");

    $insertJobTitle = $pdo->prepare("
        INSERT INTO job_titles (
            canonical_name,
            normalized_name
        )
        VALUES (?, ?)
    ");

    $employmentExists = $pdo->prepare("
        SELECT id
        FROM worker_employment
        WHERE source_key = ?
        LIMIT 1
    ");

    $insertEmployment = $pdo->prepare("
        INSERT INTO worker_employment (
            organization_id,
            worker_id,
            employer_id,
            worksite_id,
            job_title_id,

            employer_name_snapshot,
            worksite_name_snapshot,
            job_title_snapshot,

            department,
            work_area,
            shift_name,
            supervisor_name,
            employee_number,
            training_status,

            start_date,
            end_date,

            is_current,
            is_primary,
            record_status,

            source_type,
            source_key
        )
        VALUES (
            ?, ?, ?, NULL, ?,
            ?, NULL, ?,
            ?, ?, ?, ?, ?, ?,
            ?, NULL,
            1, 1, 'active',
            'legacy_worker_profile',
            ?
        )
    ");

    $createdEmployment = 0;
    $skippedEmployment = 0;

    foreach ($workers as $worker) {
        $workerId = (int)$worker['id'];
        $workerName = clean_value($worker['full_name']) ?? "Worker #{$workerId}";
        $orgId = $worker['organization_id'] !== null
            ? (int)$worker['organization_id']
            : null;

        $employerName = clean_value($worker['employer']);

        /*
         * No employer means we do not invent an employment record.
         */
        if ($employerName === null) {
            echo "SKIP worker #{$workerId} {$workerName}: no employer recorded\n";
            $skippedEmployment++;
            continue;
        }

        if ($orgId === null) {
            echo "SKIP worker #{$workerId} {$workerName}: no organization assigned\n";
            $skippedEmployment++;
            continue;
        }

        $sourceKey = "legacy-worker-profile:{$workerId}:employment-v1";

        $employmentExists->execute([$sourceKey]);

        if ($employmentExists->fetchColumn()) {
            echo "EXISTS worker #{$workerId} {$workerName}: employment already migrated\n";
            continue;
        }

        /*
         * Employer:
         * safe normalization = whitespace + case only.
         */
        $employerNormalized = normalize_name($employerName);

        $findEmployer->execute([$employerNormalized]);
        $employer = $findEmployer->fetch();

        if ($employer) {
            $employerId = (int)$employer['id'];
        } else {
            $insertEmployer->execute([
                $employerName,
                $employerNormalized
            ]);
            $employerId = (int)$pdo->lastInsertId();

            echo "CREATE employer #{$employerId}: {$employerName}\n";
        }

        /*
         * Job title is optional.
         */
        $jobTitleName = clean_value($worker['job_title']);
        $jobTitleId = null;

        if ($jobTitleName !== null) {
            $jobTitleNormalized = normalize_name($jobTitleName);

            $findJobTitle->execute([$jobTitleNormalized]);
            $jobTitle = $findJobTitle->fetch();

            if ($jobTitle) {
                $jobTitleId = (int)$jobTitle['id'];
            } else {
                $insertJobTitle->execute([
                    $jobTitleName,
                    $jobTitleNormalized
                ]);
                $jobTitleId = (int)$pdo->lastInsertId();

                echo "CREATE job title #{$jobTitleId}: {$jobTitleName}\n";
            }
        }

        $insertEmployment->execute([
            $orgId,
            $workerId,
            $employerId,
            $jobTitleId,

            $employerName,
            $jobTitleName,

            clean_value($worker['department']),
            clean_value($worker['primary_work_area']),
            clean_value($worker['shift_name']),
            clean_value($worker['supervisor_name']),
            clean_value($worker['employee_number']),
            clean_value($worker['training_status']),

            clean_value($worker['hire_date']),

            $sourceKey
        ]);

        $employmentId = (int)$pdo->lastInsertId();

        echo "CREATE employment #{$employmentId}: {$workerName}\n";
        $createdEmployment++;
    }

    $foreignKeyProblems = $pdo->query(
        'PRAGMA foreign_key_check'
    )->fetchAll();

    if ($foreignKeyProblems) {
        throw new RuntimeException(
            'Foreign key validation failed before commit.'
        );
    }

    $pdo->exec('COMMIT');

    echo "\nMigration committed successfully.\n";
    echo "Employment records created: {$createdEmployment}\n";
    echo "Workers skipped: {$skippedEmployment}\n";

} catch (Throwable $e) {
    try {
        $pdo->exec('ROLLBACK');
    } catch (Throwable) {
    }

    fwrite(
        STDERR,
        "\nMIGRATION FAILED — TRANSACTION ROLLED BACK\n"
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
}

$integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();

echo "Post-migration database integrity: {$integrity}\n";

if ($integrity !== 'ok') {
    exit(1);
}

echo "\nDONE\n";
