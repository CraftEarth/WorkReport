<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/access-requests.php';

ensure_auth_schema();
ensure_access_request_schema();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    empty($_SESSION['public_access_request_csrf'])
    || !is_string($_SESSION['public_access_request_csrf'])
) {
    $_SESSION['public_access_request_csrf'] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION['public_access_request_csrf'];

$errors = [];
$success = false;

$fullName = '';
$email = '';
$organization = '';
$interestReason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken =
        (string) ($_POST['csrf_token'] ?? '');

    if (
        $postedToken === ''
        || !hash_equals($csrf, $postedToken)
    ) {
        $errors[] =
            'The form session expired. Please refresh and try again.';
    }

    /*
     * Honeypot field. Real visitors never see or fill this.
     */
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $errors[] =
            'Request could not be submitted.';
    }

    $fullName =
        trim((string) ($_POST['full_name'] ?? ''));

    $email =
        strtolower(
            trim((string) ($_POST['email'] ?? ''))
        );

    $organization =
        trim((string) ($_POST['organization'] ?? ''));

    $interestReason =
        trim((string) ($_POST['interest_reason'] ?? ''));

    if ($fullName === '' || mb_strlen($fullName) > 120) {
        $errors[] =
            'Enter your name.';
    }

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL)
        || mb_strlen($email) > 190
    ) {
        $errors[] =
            'Enter a valid email address.';
    }

    if (mb_strlen($organization) > 180) {
        $errors[] =
            'Organization name is too long.';
    }

    if (
        $interestReason === ''
        || mb_strlen($interestReason) < 10
        || mb_strlen($interestReason) > 2000
    ) {
        $errors[] =
            'Tell us briefly why you are interested in WorkReport.';
    }

    if (!$errors) {

        /*
         * Prevent accidental rapid duplicate submissions.
         * This does not expose whether an email already has an account.
         */
        $stmt = db()->prepare("
            SELECT id
            FROM access_requests
            WHERE email = ?
              AND created_at >= datetime('now', '-24 hours')
            LIMIT 1
        ");

        $stmt->execute([$email]);

        if ($stmt->fetchColumn()) {

            $success = true;

        } else {

            $stmt = db()->prepare("
                INSERT INTO access_requests (
                    full_name,
                    email,
                    organization,
                    interest_reason,
                    status,
                    created_at
                )
                VALUES (?, ?, ?, ?, 'pending', datetime('now', 'localtime'))
            ");

            $stmt->execute([
                $fullName,
                $email,
                $organization !== '' ? $organization : null,
                $interestReason
            ]);

            $success = true;
        }

        if ($success) {
            $_SESSION['public_access_request_csrf'] =
                bin2hex(random_bytes(32));

            $csrf = $_SESSION['public_access_request_csrf'];

            $fullName = '';
            $email = '';
            $organization = '';
            $interestReason = '';
        }
    }
}

$pageTitle = 'Request WorkReport Access';
$pageDescription =
    'Request invite-only access to WorkReport. Requests are reviewed by an authorized administrator and do not automatically create an account.';

require __DIR__ . '/includes/public-header.php';

?>

<section class="public-request-page">

    <div class="public-request-intro">

        <span class="public-eyebrow">
            INVITE-ONLY ACCESS
        </span>

        <h1>Request WorkReport access.</h1>

        <p>
            WorkReport does not currently offer public self-registration.
            Access requests are reviewed before an account is created.
        </p>

        <div class="public-request-note">
            <strong>Please do not submit workplace evidence here.</strong>
            <span>
                This form is only for requesting access. Do not include
                medical records, legal documents, allegations, passwords,
                evidence files, or other sensitive case information.
            </span>
        </div>

    </div>

    <div class="public-request-card">

        <?php if ($success): ?>

            <div class="public-request-success">
                <strong>Request received.</strong>
                <p>
                    Your request has been recorded for administrator review.
                    Submitting this form does not create an account or
                    guarantee access.
                </p>
            </div>

        <?php endif; ?>

        <?php if ($errors): ?>

            <div class="public-request-errors">
                <strong>Please correct the following:</strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

        <?php endif; ?>

        <form method="post" class="public-request-form">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrf) ?>"
            >

            <div
                class="public-request-honeypot"
                aria-hidden="true"
            >
                <label>
                    Website
                    <input
                        type="text"
                        name="website"
                        tabindex="-1"
                        autocomplete="off"
                    >
                </label>
            </div>

            <div class="field">
                <label for="full_name">Name</label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="120"
                    autocomplete="name"
                    value="<?= e($fullName) ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="email">Email</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="190"
                    autocomplete="email"
                    value="<?= e($email) ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="organization">
                    Organization / Company
                    <span class="public-optional">(optional)</span>
                </label>

                <input
                    type="text"
                    id="organization"
                    name="organization"
                    maxlength="180"
                    autocomplete="organization"
                    value="<?= e($organization) ?>"
                >
            </div>

            <div class="field">
                <label for="interest_reason">
                    Why are you interested in WorkReport?
                </label>

                <textarea
                    id="interest_reason"
                    name="interest_reason"
                    rows="6"
                    maxlength="2000"
                    required
                ><?= e($interestReason) ?></textarea>

                <small>
                    Keep this general. Do not paste private workplace
                    evidence or case details into this request form.
                </small>
            </div>

            <button
                type="submit"
                class="button button-primary public-button-large"
            >
                Submit Access Request
            </button>

        </form>

    </div>

</section>

<?php require __DIR__ . '/includes/public-footer.php'; ?>