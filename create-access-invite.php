<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/access-requests.php';

require_super_admin();

ensure_secure_invite_schema();
ensure_organization_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('access-requests.php');
}

verify_csrf(
    $_POST['_csrf'] ?? null
);

$requestId =
    (int) ($_POST['request_id'] ?? 0);

$organizationId =
    (int) ($_POST['organization_id'] ?? 0);

if (
    $requestId < 1
    || $organizationId < 1
) {
    flash(
        'error',
        'Select a valid WorkReport tenant before preparing an invitation.'
    );

    redirect('access-requests.php');
}

$stmt = db()->prepare("
    SELECT *
    FROM organizations
    WHERE id = ?
      AND active = 1
    LIMIT 1
");

$stmt->execute([
    $organizationId
]);

$organization = $stmt->fetch();

if (!$organization) {
    flash(
        'error',
        'The selected WorkReport tenant is not available.'
    );

    redirect('access-requests.php');
}

$stmt = db()->prepare("
    SELECT *
    FROM access_requests
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([
    $requestId
]);

$request = $stmt->fetch();

if (!$request) {
    flash(
        'error',
        'Access request not found.'
    );

    redirect('access-requests.php');
}

$linkedUserId =
    (int) ($request['user_id'] ?? 0);

$linkedWorkerId =
    (int) ($request['worker_id'] ?? 0);

$linkedOrganizationId =
    (int) ($request['organization_id'] ?? 0);

$hasAnyProvisioning =
    $linkedUserId > 0
    || $linkedWorkerId > 0
    || $linkedOrganizationId > 0;

$hasCompleteProvisioning =
    $linkedUserId > 0
    && $linkedWorkerId > 0
    && $linkedOrganizationId > 0;

/*
 * SECURITY:
 *
 * Never guess how to repair partial historical provisioning.
 *
 * A request must either:
 *   1. have no trusted provisioning relationship yet, or
 *   2. have a complete user + worker + organization relationship.
 */
if (
    $hasAnyProvisioning
    && !$hasCompleteProvisioning
) {
    flash(
        'error',
        'This request has incomplete legacy provisioning and cannot be repaired automatically.'
    );

    redirect('access-requests.php');
}

if (
    $hasCompleteProvisioning
    && $linkedOrganizationId !== $organizationId
) {
    flash(
        'error',
        'This request is already assigned to a different WorkReport tenant.'
    );

    redirect('access-requests.php');
}

$pdo = db();

$pdo->beginTransaction();

try {

    $userId = 0;
    $workerId = 0;
    $username = '';

    if ($hasCompleteProvisioning) {

        /*
         * Reissuing an invite is allowed only when the existing
         * relationship still passes every tenant boundary check.
         */

        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $linkedUserId
        ]);

        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException(
                'Linked user account was not found.'
            );
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM workers
            WHERE id = ?
              AND organization_id = ?
              AND active = 1
            LIMIT 1
        ");

        $stmt->execute([
            $linkedWorkerId,
            $organizationId
        ]);

        $worker = $stmt->fetch();

        if (!$worker) {
            throw new RuntimeException(
                'Linked worker does not belong to the assigned WorkReport tenant.'
            );
        }

        if (
            !user_belongs_to_organization(
                $linkedUserId,
                $organizationId
            )
        ) {
            throw new RuntimeException(
                'Linked user does not have active membership in the assigned WorkReport tenant.'
            );
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM user_worker_access
            WHERE user_id = ?
              AND worker_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $linkedUserId,
            $linkedWorkerId
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'Linked user does not have explicit access to the linked worker.'
            );
        }

        $userId =
            $linkedUserId;

        $workerId =
            $linkedWorkerId;

        $username =
            (string) $user['username'];

    } else {

        /*
         * New provisioning.
         *
         * Email is contact information only.
         * WorkReport never silently links an account by matching email.
         */

        $username =
            workreport_unique_username(
                (string) $request['email'],
                (string) $request['full_name']
            );

        $temporarySecret =
            bin2hex(
                random_bytes(32)
            );

        $stmt = $pdo->prepare("
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
                'user',
                0,
                datetime('now', 'localtime')
            )
        ");

        $stmt->execute([
            $username,
            $request['full_name'],
            $request['email'],
            password_hash(
                $temporarySecret,
                PASSWORD_DEFAULT
            )
        ]);

        $userId =
            (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO workers (
                full_name,
                employer,
                organization_id,
                active,
                created_at
            )
            VALUES (
                ?,
                ?,
                ?,
                1,
                datetime('now', 'localtime')
            )
        ");

        $stmt->execute([
            $request['full_name'],
            $request['organization']
                ?: null,
            $organizationId
        ]);

        $workerId =
            (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO organization_memberships (
                user_id,
                organization_id,
                membership_role,
                active,
                created_at
            )
            VALUES (
                ?,
                ?,
                'employee',
                1,
                datetime('now', 'localtime')
            )
        ");

        $stmt->execute([
            $userId,
            $organizationId
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO user_worker_access (
                user_id,
                worker_id,
                permission_level
            )
            VALUES (
                ?,
                ?,
                'write'
            )
        ");

        $stmt->execute([
            $userId,
            $workerId
        ]);
    }

    /*
     * Invalidate any previous unused invitation for this user.
     */
    $stmt = $pdo->prepare("
        UPDATE user_invites
        SET used_at = datetime('now', 'localtime')
        WHERE user_id = ?
          AND used_at IS NULL
    ");

    $stmt->execute([
        $userId
    ]);

    $token =
        bin2hex(
            random_bytes(32)
        );

    $tokenHash =
        hash(
            'sha256',
            $token
        );

    $admin =
        current_user();

    if (!$admin) {
        throw new RuntimeException(
            'Authenticated administrator was not available.'
        );
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_invites (
            user_id,
            access_request_id,
            token_hash,
            expires_at,
            created_by_user_id
        )
        VALUES (
            ?,
            ?,
            ?,
            datetime('now', '+48 hours', 'localtime'),
            ?
        )
    ");

    $stmt->execute([
        $userId,
        $requestId,
        $tokenHash,
        (int) $admin['id']
    ]);

    $stmt = $pdo->prepare("
        UPDATE access_requests
        SET
            user_id = ?,
            worker_id = ?,
            organization_id = ?,
            status = 'invited',
            invited_at = datetime('now', 'localtime'),
            reviewed_at = COALESCE(
                reviewed_at,
                datetime('now', 'localtime')
            ),
            reviewed_by_user_id = COALESCE(
                reviewed_by_user_id,
                ?
            )
        WHERE id = ?
    ");

    $stmt->execute([
        $userId,
        $workerId,
        $organizationId,
        (int) $admin['id'],
        $requestId
    ]);

    $pdo->commit();

    /*
     * The plaintext token exists only in this administrator session
     * long enough to prepare the invitation.
     *
     * The database stores only its SHA-256 hash.
     */
    $_SESSION['workreport_invite_preview'] = [
        'request_id' => $requestId,
        'name' => $request['full_name'],
        'email' => $request['email'],
        'username' => $username,
        'token' => $token
    ];

    redirect(
        'invite-preview.php?request_id='
        . $requestId
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $e;
}
