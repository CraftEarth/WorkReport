<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/user-admin.php';

require_admin();

$currentUser = current_user();

if (!$currentUser) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['user_admin_csrf'])) {
    $_SESSION['user_admin_csrf'] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION['user_admin_csrf'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!is_super_admin()) {
        http_response_code(403);
        exit(
            'Only a Super Admin can change user roles or remove access.'
        );
    }

    $postedCsrf = (string) (
        $_POST['csrf_token']
        ?? ''
    );

    if (
        $postedCsrf === ''
        || !hash_equals(
            $csrf,
            $postedCsrf
        )
    ) {
        http_response_code(400);
        exit('Invalid request token.');
    }

    $action = (string) (
        $_POST['action']
        ?? ''
    );

    $targetUserId = (int) (
        $_POST['user_id']
        ?? 0
    );

    try {
        if ($targetUserId < 1) {
            throw new RuntimeException(
                'Choose a valid user.'
            );
        }

        if ($action === 'change_role') {
            $newRole = (string) (
                $_POST['role']
                ?? ''
            );

            admin_change_user_role(
                $targetUserId,
                $newRole,
                (int) $currentUser['id']
            );

            flash(
                'success',
                'User role updated.'
            );
        } elseif ($action === 'set_active') {
            $active =
                ((int) (
                    $_POST['active']
                    ?? 0
                )) === 1;

            admin_set_user_active(
                $targetUserId,
                $active,
                (int) $currentUser['id']
            );

            flash(
                'success',
                $active
                    ? 'User access restored.'
                    : 'User access removed. Account is now inactive.'
            );
        } elseif ($action === 'delete_user') {
            admin_delete_user(
                $targetUserId,
                (int) $currentUser['id']
            );

            flash(
                'success',
                'Unused user account permanently deleted.'
            );
        } else {
            throw new RuntimeException(
                'Unknown user-management action.'
            );
        }

        header('Location: users.php');
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$users = admin_all_users();

$pageTitle = 'User Management';

require __DIR__ . '/includes/header.php';

?>

<div class="page-title-row">

    <div>
        <h1>User Management</h1>
        <p>
            Manage login access and application roles.
        </p>
    </div>

    <?php if (is_super_admin()): ?>

        <a
            href="add-user.php"
            class="button button-primary"
        >
            + Add User
        </a>

    <?php endif; ?>

</div>


<?php if ($error): ?>

    <div class="flash flash-error">
        <?= e($error) ?>
    </div>

<?php endif; ?>


<section class="user-admin-note">

    <strong>Account safety</strong>

    <span>
        Deactivating a user removes login access while preserving
        audit history. Permanent deletion is only allowed when
        the account has no journal or historical source records.
    </span>

</section>


<section class="panel">

    <div class="section-title">
        <h2>Accounts</h2>
        <span><?= count($users) ?> total</span>
    </div>

    <div class="user-admin-table">

        <div class="user-admin-head">
            <span>User</span>
            <span>Role</span>
            <span>Status</span>
            <span>Worker Access</span>
            <span>Last Login</span>
            <span>Actions</span>
        </div>

        <?php foreach ($users as $item): ?>

            <?php
            $isSelf =
                (int) $item['id']
                === (int) $currentUser['id'];

            $references =
                admin_user_reference_locations(
                    (int) $item['id']
                );

            $hasAuditRecords = false;

            foreach ($references as $reference) {
                if (
                    $reference['label']
                    !== 'organization membership'
                ) {
                    $hasAuditRecords = true;
                    break;
                }
            }
            ?>

            <div class="user-admin-row">

                <div class="user-admin-identity">

                    <strong>
                        <?= e($item['display_name']) ?>
                        <?php if ($isSelf): ?>
                            <span class="user-self-badge">YOU</span>
                        <?php endif; ?>
                    </strong>

                    <span>
                        <?= e($item['username']) ?>
                    </span>

                    <?php if (!empty($item['email'])): ?>
                        <span>
                            <?= e($item['email']) ?>
                        </span>
                    <?php endif; ?>

                </div>


                <div>

                    <?php if (
                        is_super_admin()
                        && !$isSelf
                    ): ?>

                        <form
                            method="post"
                            class="user-role-form"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($csrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="change_role"
                            >

                            <input
                                type="hidden"
                                name="user_id"
                                value="<?= (int) $item['id'] ?>"
                            >

                            <select name="role">

                                <option
                                    value="user"
                                    <?= $item['role'] === 'user'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    User
                                </option>

                                <option
                                    value="admin"
                                    <?= $item['role'] === 'admin'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Admin
                                </option>

                                <option
                                    value="super_admin"
                                    <?= $item['role'] === 'super_admin'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Super Admin
                                </option>

                            </select>

                            <button
                                type="submit"
                                class="button button-secondary"
                            >
                                Save Role
                            </button>

                        </form>

                    <?php else: ?>

                        <strong>
                            <?= e(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $item['role']
                                    )
                                )
                            ) ?>
                        </strong>

                    <?php endif; ?>

                </div>


                <div>

                    <?php if ((int) $item['active'] === 1): ?>

                        <span class="user-status user-status-active">
                            Active
                        </span>

                    <?php else: ?>

                        <span class="user-status user-status-inactive">
                            Inactive
                        </span>

                    <?php endif; ?>

                </div>


                <div>
                    <strong>
                        <?= (int) $item['worker_access_count'] ?>
                    </strong>
                </div>


                <div>
                    <?= e(
                        $item['last_login']
                        ?: 'Never'
                    ) ?>
                </div>


                <div class="user-admin-actions">

                    <?php if (
                        is_super_admin()
                        && !$isSelf
                    ): ?>

                        <form method="post">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($csrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="set_active"
                            >

                            <input
                                type="hidden"
                                name="user_id"
                                value="<?= (int) $item['id'] ?>"
                            >

                            <input
                                type="hidden"
                                name="active"
                                value="<?= (int) $item['active'] === 1
                                    ? '0'
                                    : '1' ?>"
                            >

                            <button
                                type="submit"
                                class="button button-secondary"
                            >
                                <?= (int) $item['active'] === 1
                                    ? 'Remove Access'
                                    : 'Restore Access' ?>
                            </button>

                        </form>


                        <?php if (!$hasAuditRecords): ?>

                            <form
                                method="post"
                                onsubmit="return confirm('Permanently delete this unused login account? Worker records will not be deleted.');"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrf) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete_user"
                                >

                                <input
                                    type="hidden"
                                    name="user_id"
                                    value="<?= (int) $item['id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="button button-danger"
                                >
                                    Delete
                                </button>

                            </form>

                        <?php else: ?>

                            <span
                                class="user-audit-protected"
                                title="This user is referenced by preserved source records."
                            >
                                Audit protected
                            </span>

                        <?php endif; ?>

                    <?php elseif ($isSelf): ?>

                        <span class="user-self-note">
                            Current account
                        </span>

                    <?php else: ?>

                        <span class="user-self-note">
                            Super Admin required
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

</section>


<section class="panel user-role-guide">

    <h2>Role Permissions</h2>

    <div class="user-role-grid">

        <div>
            <strong>Super Admin</strong>
            <span>
                Full system administration, including
                user roles and account access.
            </span>
        </div>

        <div>
            <strong>Admin</strong>
            <span>
                Administrative review features, but cannot
                promote, demote or remove user accounts.
            </span>
        </div>

        <div>
            <strong>User</strong>
            <span>
                Limited to worker files and permissions
                assigned to that account.
            </span>
        </div>

    </div>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
