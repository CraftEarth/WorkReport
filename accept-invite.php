<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/access-requests.php';

ensure_auth_schema();
ensure_secure_invite_schema();
ensure_organization_schema();

$token =
    trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

$error = '';
$success = false;
$invite = null;

if ($token !== '') {

    $tokenHash =
        hash('sha256', $token);

    $stmt = db()->prepare("
        SELECT
            i.*,
            u.username,
            u.display_name,
            u.email,
            ar.worker_id,
            ar.organization_id
        FROM user_invites i

        INNER JOIN users u
            ON u.id = i.user_id
           AND u.active = 0

        INNER JOIN access_requests ar
            ON ar.id = i.access_request_id
           AND ar.user_id = i.user_id
           AND ar.worker_id IS NOT NULL
           AND ar.organization_id IS NOT NULL
           AND ar.status = 'invited'

        INNER JOIN workers w
            ON w.id = ar.worker_id
           AND w.organization_id = ar.organization_id
           AND w.active = 1

        INNER JOIN organization_memberships om
            ON om.user_id = i.user_id
           AND om.organization_id = ar.organization_id
           AND om.active = 1

        INNER JOIN organizations o
            ON o.id = ar.organization_id
           AND o.active = 1

        INNER JOIN user_worker_access uwa
            ON uwa.user_id = i.user_id
           AND uwa.worker_id = ar.worker_id

        WHERE i.token_hash = ?
          AND i.used_at IS NULL
          AND datetime(i.expires_at) > datetime('now', 'localtime')

        LIMIT 1
    ");

    $stmt->execute([$tokenHash]);

    $invite =
        $stmt->fetch() ?: null;
}

if (!$invite) {
    $error =
        'This invitation is invalid, expired, or has already been used.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $invite
) {

    $password =
        (string) ($_POST['password'] ?? '');

    $confirm =
        (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 12) {

        $error =
            'Password must be at least 12 characters.';

    } elseif ($password !== $confirm) {

        $error =
            'Passwords do not match.';

    } else {

        $pdo = db();
        $pdo->beginTransaction();

        try {

            /*
             * Revalidate the full tenant relationship inside the
             * activation transaction.
             */
            $stmt = $pdo->prepare("
                SELECT 1
                FROM access_requests ar

                INNER JOIN workers w
                    ON w.id = ar.worker_id
                   AND w.organization_id = ar.organization_id
                   AND w.active = 1

                INNER JOIN organization_memberships om
                    ON om.user_id = ar.user_id
                   AND om.organization_id = ar.organization_id
                   AND om.active = 1

                INNER JOIN organizations o
                    ON o.id = ar.organization_id
                   AND o.active = 1

                INNER JOIN user_worker_access uwa
                    ON uwa.user_id = ar.user_id
                   AND uwa.worker_id = ar.worker_id

                WHERE ar.id = ?
                  AND ar.user_id = ?
                  AND ar.worker_id = ?
                  AND ar.organization_id = ?
                  AND ar.status = 'invited'

                LIMIT 1
            ");

            $stmt->execute([
                (int) $invite['access_request_id'],
                (int) $invite['user_id'],
                (int) $invite['worker_id'],
                (int) $invite['organization_id']
            ]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Invitation provisioning is no longer valid.'
                );
            }

            /*
             * Consume the invitation atomically.
             *
             * If another request already used or expired this token,
             * this UPDATE affects zero rows and activation stops.
             */
            $stmt = $pdo->prepare("
                UPDATE user_invites
                SET used_at = datetime('now', 'localtime')
                WHERE id = ?
                  AND user_id = ?
                  AND used_at IS NULL
                  AND datetime(expires_at) > datetime('now', 'localtime')
            ");

            $stmt->execute([
                (int) $invite['id'],
                (int) $invite['user_id']
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Invitation is no longer valid.'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    password_hash = ?,
                    active = 1
                WHERE id = ?
                  AND active = 0
            ");

            $stmt->execute([
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                (int) $invite['user_id']
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Account activation is no longer available.'
                );
            }

            if (!empty($invite['access_request_id'])) {

                $stmt = $pdo->prepare("
                    UPDATE access_requests
                    SET
                        status = 'reviewed',
                        activated_at = datetime('now', 'localtime')
                    WHERE id = ?
                      AND user_id = ?
                      AND worker_id = ?
                      AND organization_id = ?
                ");

                $stmt->execute([
                    (int) $invite['access_request_id'],
                    (int) $invite['user_id'],
                    (int) $invite['worker_id'],
                    (int) $invite['organization_id']
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Access request activation state could not be updated.'
                    );
                }
            }

            $pdo->commit();

            $success = true;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}

$pageTitle =
    'Activate WorkReport Account';

$pageDescription =
    'Securely activate your invite-only WorkReport account.';

require __DIR__ . '/includes/public-header.php';

?>

<section class="public-request-page">

    <div class="public-request-intro">

        <span class="public-eyebrow">
            SECURE ACCOUNT ACTIVATION
        </span>

        <h1>Activate your WorkReport account.</h1>

        <p>
            Choose your password using the one-time invitation
            provided by WorkReport.
        </p>

    </div>

    <div class="public-request-card">

        <?php if ($success): ?>

            <div class="public-request-success">

                <strong>Account activated.</strong>

                <p>
                    Your password has been created.
                    You may now sign in to the Client Portal.
                </p>

            </div>

            <a
                href="login.php"
                class="button button-primary public-button-large"
            >
                Go to Client Login
            </a>

        <?php elseif ($error): ?>

            <div class="public-request-errors">
                <?= e($error) ?>
            </div>

            <?php if ($invite): ?>

                <form method="post" class="public-request-form">

                    <input
                        type="hidden"
                        name="token"
                        value="<?= e($token) ?>"
                    >

                    <div class="field">
                        <label>Username</label>

                        <input
                            type="text"
                            value="<?= e($invite['username']) ?>"
                            readonly
                        >
                    </div>

                    <div class="field">
                        <label for="password">
                            Create password
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            minlength="12"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="password_confirm">
                            Confirm password
                        </label>

                        <input
                            type="password"
                            id="password_confirm"
                            name="password_confirm"
                            minlength="12"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <button
                        type="submit"
                        class="button button-primary public-button-large"
                    >
                        Activate Account
                    </button>

                </form>

            <?php endif; ?>

        <?php elseif ($invite): ?>

            <form method="post" class="public-request-form">

                <input
                    type="hidden"
                    name="token"
                    value="<?= e($token) ?>"
                >

                <div class="field">
                    <label>Username</label>

                    <input
                        type="text"
                        value="<?= e($invite['username']) ?>"
                        readonly
                    >
                </div>

                <div class="field">
                    <label for="password">
                        Create password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="12"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="field">
                    <label for="password_confirm">
                        Confirm password
                    </label>

                    <input
                        type="password"
                        id="password_confirm"
                        name="password_confirm"
                        minlength="12"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button
                    type="submit"
                    class="button button-primary public-button-large"
                >
                    Activate Account
                </button>

            </form>

        <?php endif; ?>

    </div>

</section>

<?php require __DIR__ . '/includes/public-footer.php'; ?>