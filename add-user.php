<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_super_admin();

$error = '';

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $displayName =
        trim(
            $_POST['display_name']
            ?? ''
        );

    $username =
        trim(
            $_POST['username']
            ?? ''
        );

    $email =
        trim(
            $_POST['email']
            ?? ''
        );

    $password =
        $_POST['password']
        ?? '';

    $role =
        $_POST['role']
        ?? 'user';

    if (
        !in_array(
            $role,
            [
                'super_admin',
                'admin',
                'user'
            ],
            true
        )
    ) {
        $role = 'user';
    }

    if (
        $displayName === ''
        || $username === ''
        || strlen($password) < 10
    ) {

        $error =
            'Name, username and a password of at least 10 characters are required.';

    } else {

        try {

            $stmt = db()->prepare("
                INSERT INTO users (
                    username,
                    display_name,
                    email,
                    password_hash,
                    role,
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
                $username,
                $displayName,
                $email,
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                $role
            ]);

            flash(
                'success',
                'User account created.'
            );

            header(
                'Location: users.php'
            );

            exit;

        } catch (PDOException $e) {

            $error =
                'That username may already exist.';
        }
    }
}

$pageTitle = 'Add User';

require __DIR__ . '/includes/header.php';

?>

<div class="page-title-row">

<div>

<h1>Add User</h1>

<p>
    Create a login account and assign its system role.
</p>

</div>

</div>

<?php if ($error): ?>

<div class="flash flash-error">
    <?= e($error) ?>
</div>

<?php endif; ?>

<form method="post">

<section class="panel compact-form">

<div class="form-grid">

<div class="field">

<label>Display Name *</label>

<input
    type="text"
    name="display_name"
    required
>

</div>


<div class="field">

<label>Username *</label>

<input
    type="text"
    name="username"
    required
>

</div>


<div class="field">

<label>Email</label>

<input
    type="email"
    name="email"
>

</div>


<div class="field">

<label>Role *</label>

<select
    name="role"
    required
>

<option value="user">
    User
</option>

<option value="admin">
    Admin
</option>

<option value="super_admin">
    Super Admin
</option>

</select>

</div>


<div class="field">

<label>Temporary Password *</label>

<input
    type="password"
    name="password"
    minlength="10"
    required
>

</div>

</div>


<div class="form-actions">

<a
    href="users.php"
    class="button button-secondary"
>
    Cancel
</a>

<button
    type="submit"
    class="button button-primary"
>
    Create User
</button>

</div>

</section>

</form>

<?php require __DIR__ . '/includes/footer.php'; ?>