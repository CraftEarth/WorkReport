<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/access-requests.php';

require_admin();
ensure_secure_invite_schema();

$preview =
    $_SESSION['workreport_invite_preview']
    ?? null;

$requestId =
    (int) ($_GET['request_id'] ?? 0);

if (
    !$preview
    || (int) ($preview['request_id'] ?? 0) !== $requestId
) {
    flash(
        'error',
        'Invite preview is no longer available. Create a new invite if needed.'
    );

    redirect('access-requests.php?status=invited');
}

$scheme =
    (!empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off')
    ? 'https'
    : 'http';

$host =
    $_SERVER['HTTP_HOST']
    ?? 'localhost';

$basePath =
    rtrim(
        str_replace(
            '\\',
            '/',
            dirname($_SERVER['SCRIPT_NAME'] ?? '/WorkReports')
        ),
        '/'
    );

$inviteUrl =
    $scheme
    . '://'
    . $host
    . $basePath
    . '/accept-invite.php?token='
    . rawurlencode($preview['token']);

$subject =
    'Your WorkReport access request has been approved';

$body =
    "Hello {$preview['name']},\r\n\r\n"
    . "Your request for access to WorkReport has been approved.\r\n\r\n"
    . "Use the secure one-time link below to activate your account and choose your password:\r\n\r\n"
    . $inviteUrl
    . "\r\n\r\n"
    . "Username: {$preview['username']}\r\n\r\n"
    . "This invitation expires in 48 hours and can only be used once.\r\n\r\n"
    . "For your security, WorkReport will never email you your password or ask you to send sensitive workplace evidence by email.\r\n\r\n"
    . "If you did not request access, you can ignore this message.\r\n\r\n"
    . "WorkReport\r\n"
    . "The Help When You Didn't Know You Needed Help.";

$mailto =
    'mailto:'
    . rawurlencode($preview['email'])
    . '?subject='
    . rawurlencode($subject)
    . '&body='
    . rawurlencode($body);

$pageTitle =
    'Prepare WorkReport Invite';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>
        <h1>Invite Prepared</h1>

        <p>
            The account is ready for secure activation.
            No password was created or emailed by you.
        </p>
    </div>

</section>

<section class="panel invite-preview-panel">

    <div class="invite-preview-grid">

        <div>
            <span>Name</span>
            <strong><?= e($preview['name']) ?></strong>
        </div>

        <div>
            <span>Email</span>
            <strong><?= e($preview['email']) ?></strong>
        </div>

        <div>
            <span>Username</span>
            <strong><?= e($preview['username']) ?></strong>
        </div>

        <div>
            <span>Invite expires</span>
            <strong>48 hours</strong>
        </div>

    </div>

    <div class="invite-preview-email">

        <label>Subject</label>

        <input
            type="text"
            value="<?= e($subject) ?>"
            readonly
        >

        <label>Email body</label>

        <textarea
            rows="18"
            readonly
        ><?= e($body) ?></textarea>

    </div>

    <div class="form-actions">

        <a
            href="<?= e($mailto) ?>"
            class="button button-primary"
        >
            Open in Email App
        </a>

        <button
            type="button"
            class="button button-secondary"
            onclick="navigator.clipboard.writeText(document.querySelector('.invite-preview-email textarea').value); this.textContent='Copied';"
        >
            Copy Email
        </button>

        <a
            href="access-requests.php?status=invited"
            class="button button-secondary"
        >
            Back to Requests
        </a>

    </div>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>