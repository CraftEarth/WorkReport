<?php
/* WORKREPORT_LOGIN_ACTIVITY_V1 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

ensure_auth_schema();

if (user_count() === 0) {

    header(
        'Location: setup.php'
    );

    exit;
}

if (is_logged_in()) {

    header(
        'Location: portal.php'
    );

    exit;
}

$error = '';

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $username =
        trim(
            $_POST['username']
            ?? ''
        );

    $password =
        $_POST['password']
        ?? '';

    $stmt = db()->prepare("
        SELECT *
        FROM users
        WHERE username = ?
          AND active = 1
        LIMIT 1
    ");

    $stmt->execute([
        $username
    ]);

    $user = $stmt->fetch();

    if (
        $user
        && password_verify(
            $password,
            $user['password_hash']
        )
    ) {

        session_regenerate_id(true);

        $_SESSION['user_id'] =
            (int) $user['id'];

        db()->prepare("
            UPDATE users
            SET
                    last_login = datetime('now', 'localtime'),
                    first_login = COALESCE(first_login, datetime('now', 'localtime')),
                    login_count = COALESCE(login_count, 0) + 1
            WHERE id = ?
        ")->execute([
            (int) $user['id']
        ]);

        header(
            'Location: portal.php'
        );

        exit;
    }

    $error =
        'Invalid username or password.';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>Sign In</title>

<link
    rel="stylesheet"
    href="assets/css/style.css?v=20261013-workspace2"
>

</head>

<body class="auth-body">

<div class="auth-shell">

    <div class="auth-box">

        <h1>
            WorkReport Timeline
        </h1>

        <p>
            Private documentation system
        </p>

        <?php
/* WORKREPORT_LOGIN_ACTIVITY_V1 */ if ($error): ?>

            <div class="flash flash-error">
                <?= e($error) ?>
            </div>

        <?php
/* WORKREPORT_LOGIN_ACTIVITY_V1 */ endif; ?>

        <form method="post">

            <div class="field">

                <label>
                    Username
                </label>

                <input
                    type="text"
                    name="username"
                    required
                    autofocus
                    autocomplete="username"
                >

            </div>


            <div class="field">

                <label>
                    Password
                </label>

                <input
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                >

            </div>


            <button
                type="submit"
                class="button button-primary button-block"
            >
                Sign In
            </button>

        </form>

    </div>

</div>

</body>
</html>