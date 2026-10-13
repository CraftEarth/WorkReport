<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$actingUser = current_user();

if (!$actingUser) {
    http_response_code(403);
    exit('Access denied.');
}

$organizationIds =
    user_organization_ids(
        (int) $actingUser['id']
    );

/*
 * For now WorkReport requires an administrator creating a worker
 * to belong to exactly one active organization.
 *
 * When multi-organization administration is added, the UI will
 * explicitly require organization selection instead of guessing.
 */

if (count($organizationIds) !== 1) {
    http_response_code(403);

    exit(
        'A single active organization must be selected before '
        . 'a worker can be created.'
    );
}

$organizationId =
    (int) $organizationIds[0];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    
    verify_csrf(
        $_POST['_csrf'] ?? null
    );

$name =
        trim(
            $_POST['full_name']
            ?? ''
        );

    $employeeNumber =
        trim(
            $_POST['employee_number']
            ?? ''
        );

    $department =
        trim(
            $_POST['department']
            ?? ''
        );

    $employer =
        trim(
            $_POST['employer']
            ?? ''
        );

    if ($name === '') {

        $error =
            'Worker name is required.';

    } else {

        $pdo = db();

        $pdo->beginTransaction();

        try {

            $stmt = $pdo->prepare("
                INSERT INTO workers (
                    full_name,
                    employee_number,
                    department,
                    employer,
                    organization_id,
                    active,
                    created_at
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    1,
                    datetime('now', 'localtime')
                )
            ");

            $stmt->execute([
                $name,
                $employeeNumber,
                $department,
                $employer,
                $organizationId
            ]);

            $workerId =
                (int) $pdo->lastInsertId();

            /*
             * Creating a worker grants the creating administrator
             * explicit access to that worker only.
             */

            $stmt = $pdo->prepare("
                INSERT INTO user_worker_access (
                    user_id,
                    worker_id,
                    permission_level
                )
                VALUES (?, ?, 'admin')

                ON CONFLICT(user_id, worker_id)
                DO UPDATE SET
                    permission_level = 'admin'
            ");

            $stmt->execute([
                (int) $actingUser['id'],
                $workerId
            ]);

            $pdo->commit();

            $_SESSION['worker_id'] =
                $workerId;

            flash(
                'success',
                'Worker profile created.'
            );

            header(
                'Location: portal.php'
            );

            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error =
                'Worker profile could not be created.';
        }
    }
}

$pageTitle = 'Add Worker';

require __DIR__ . '/includes/header.php';

?>

<div class="page-title-row">

    <div>
        <h1>Add Worker</h1>
        <p>Create a separate documentation file.</p>
    </div>

</div>

<?php if ($error): ?>

<div class="flash flash-error">
    <?= e($error) ?>
</div>

<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

<section class="panel compact-form">

    <div class="form-grid">

        <div class="field">

            <label>Worker Name *</label>

            <input
                type="text"
                name="full_name"
                required
                autofocus
            >

        </div>

        <div class="field">

            <label>Employee Number</label>

            <input
                type="text"
                name="employee_number"
            >

        </div>

        <div class="field">

            <label>Department</label>

            <input
                type="text"
                name="department"
            >

        </div>

        <div class="field">

            <label>Employer</label>

            <input
                type="text"
                name="employer"
            >

        </div>

    </div>

    <div class="form-actions">

        <a
            href="index.php"
            class="button button-secondary"
        >
            Cancel
        </a>

        <button
            class="button button-primary"
            type="submit"
        >
            Create Worker
        </button>

    </div>

</section>

</form>

<?php require __DIR__ . '/includes/footer.php'; ?>