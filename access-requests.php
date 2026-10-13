<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/access-requests.php';

require_super_admin();
ensure_secure_invite_schema();
ensure_organization_schema();

$organizations = db()->query("
    SELECT
        id,
        name
    FROM organizations
    WHERE active = 1
    ORDER BY name
")->fetchAll();

$status =
    trim((string) ($_GET['status'] ?? 'pending'));

if (!in_array($status, access_request_statuses(), true)) {
    $status = 'pending';
}

$stmt = db()->prepare("
    SELECT
        ar.*,
        reviewer.display_name AS reviewed_by_name,
        u.username,
        u.active AS user_active,
        u.created_at AS account_created_at,
        u.first_login,
        u.last_login,
        COALESCE(u.login_count, 0) AS login_count
    FROM access_requests ar
    LEFT JOIN users reviewer
        ON reviewer.id = ar.reviewed_by_user_id
    LEFT JOIN users u
        ON u.id = ar.user_id
    WHERE ar.status = ?
    ORDER BY ar.created_at DESC, ar.id DESC
");

$stmt->execute([$status]);

$requests =
    $stmt->fetchAll();

$countStmt = db()->query("
    SELECT status, COUNT(*) AS total
    FROM access_requests
    GROUP BY status
");

$statusCounts = [
    'pending' => 0,
    'reviewed' => 0,
    'invited' => 0,
    'declined' => 0
];

foreach ($countStmt->fetchAll() as $row) {

    if (array_key_exists($row['status'], $statusCounts)) {
        $statusCounts[$row['status']] =
            (int) $row['total'];
    }
}

$pageTitle =
    'Access Requests';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>
        <h1>Access Requests</h1>

        <p>
            Track requests from initial interest through invitation,
            activation, and actual WorkReport usage.
        </p>
    </div>

</section>

<nav class="access-request-tabs">

    <?php foreach ($statusCounts as $key => $count): ?>

        <a
            href="access-requests.php?status=<?= e($key) ?>"
            class="<?= $status === $key ? 'active' : '' ?>"
        >
            <?= e(ucfirst($key)) ?>
            <span><?= $count ?></span>
        </a>

    <?php endforeach; ?>

</nav>

<section class="panel">

    <?php if (!$requests): ?>

        <div class="empty-state">
            <p>
                No <?= e($status) ?> access requests.
            </p>
        </div>

    <?php else: ?>

        <div class="access-request-list">

        <?php foreach ($requests as $request): ?>

            <?php
            $usageState =
                workreport_request_usage_state($request);
            ?>

            <article class="access-request-row access-request-lifecycle-row">

                <div class="access-request-main">

                    <div class="access-request-heading">

                        <div>
                            <strong>
                                <?= e($request['full_name']) ?>
                            </strong>

                            <a
                                href="mailto:<?= e($request['email']) ?>"
                            >
                                <?= e($request['email']) ?>
                            </a>
                        </div>

                        <span class="tag">
                            <?= e($usageState) ?>
                        </span>

                    </div>

                    <?php if ($request['organization']): ?>

                        <div class="access-request-org">
                            <?= e($request['organization']) ?>
                        </div>

                    <?php endif; ?>

                    <p class="access-request-reason">
                        <?= nl2br(e($request['interest_reason'])) ?>
                    </p>

                    <div class="access-lifecycle">

                        <div class="<?= $request['created_at'] ? 'complete' : '' ?>">
                            <span>Requested</span>
                            <strong>
                                <?= e(format_datetime($request['created_at'])) ?>
                            </strong>
                        </div>

                        <div class="<?= $request['invited_at'] ? 'complete' : '' ?>">
                            <span>Invited</span>
                            <strong>
                                <?= $request['invited_at']
                                    ? e(format_datetime($request['invited_at']))
                                    : 'Not yet' ?>
                            </strong>
                        </div>

                        <div class="<?= $request['activated_at'] ? 'complete' : '' ?>">
                            <span>Activated</span>
                            <strong>
                                <?= $request['activated_at']
                                    ? e(format_datetime($request['activated_at']))
                                    : 'Not yet' ?>
                            </strong>
                        </div>

                        <div class="<?= $request['first_login'] ? 'complete' : '' ?>">
                            <span>First Login</span>
                            <strong>
                                <?= $request['first_login']
                                    ? e(format_datetime($request['first_login']))
                                    : 'Not yet' ?>
                            </strong>
                        </div>

                        <div class="<?= $request['last_login'] ? 'complete' : '' ?>">
                            <span>Last Login</span>
                            <strong>
                                <?= $request['last_login']
                                    ? e(format_datetime($request['last_login']))
                                    : 'Not yet' ?>
                            </strong>
                        </div>

                        <div class="<?= (int) $request['login_count'] > 0 ? 'complete' : '' ?>">
                            <span>Logins</span>
                            <strong>
                                <?= (int) $request['login_count'] ?>
                            </strong>
                        </div>

                    </div>

                    <?php if ($request['username']): ?>

                        <div class="access-account-line">
                            <span>Username</span>
                            <strong><?= e($request['username']) ?></strong>
                        </div>

                    <?php endif; ?>

                </div>

                <div class="access-request-actions">

                    <?php if (!$request['user_id'] || !$request['invited_at']): ?>

                        <form
                            method="post"
                            action="create-access-invite.php"
                        >
                            <?= csrf_field() ?>
                            <input
                                type="hidden"
                                name="request_id"
                                value="<?= (int) $request['id'] ?>"
                            >

                            <label>
                                WorkReport tenant

                                <select
                                    name="organization_id"
                                    required
                                >
                                    <option value="">
                                        Select organization
                                    </option>

                                    <?php foreach ($organizations as $organization): ?>

                                        <option
                                            value="<?= (int) $organization['id'] ?>"
                                            <?= (int) ($request['organization_id'] ?? 0)
                                                === (int) $organization['id']
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= e($organization['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </label>

                            <button
                                type="submit"
                                class="button button-primary"
                            >
                                Approve & Prepare Invite
                            </button>
                        </form>

                    <?php elseif (!$request['activated_at']): ?>

                        <form
                            method="post"
                            action="create-access-invite.php"
                        >
                            <?= csrf_field() ?>
                            <input
                                type="hidden"
                                name="request_id"
                                value="<?= (int) $request['id'] ?>"
                            >

                            <label>
                                WorkReport tenant

                                <select
                                    name="organization_id"
                                    required
                                >
                                    <option value="">
                                        Select organization
                                    </option>

                                    <?php foreach ($organizations as $organization): ?>

                                        <option
                                            value="<?= (int) $organization['id'] ?>"
                                            <?= (int) ($request['organization_id'] ?? 0)
                                                === (int) $organization['id']
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= e($organization['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </label>

                            <button
                                type="submit"
                                class="button button-secondary"
                            >
                                Generate New Invite
                            </button>
                        </form>

                    <?php endif; ?>

                    <form
                        method="post"
                        action="update-access-request.php"
                    >
                            <?= csrf_field() ?>

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int) $request['id'] ?>"
                        >

                        <label>
                            Request status

                            <select name="status">

                                <?php foreach (access_request_statuses() as $option): ?>

                                    <option
                                        value="<?= e($option) ?>"
                                        <?= $request['status'] === $option
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= e(ucfirst($option)) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </label>

                        <label>
                            Admin notes

                            <textarea
                                name="admin_notes"
                                rows="3"
                                maxlength="2000"
                            ><?= e((string) $request['admin_notes']) ?></textarea>
                        </label>

                        <button
                            type="submit"
                            class="button button-secondary"
                        >
                            Save Review
                        </button>

                    </form>

                </div>

            </article>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>