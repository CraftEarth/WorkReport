<?php
/* WORKREPORT_PRIVATE_HOME_PORTAL_V1 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

ensure_auth_schema();

if (user_count() === 0) {

    header(
        'Location: setup.php'
    );

    exit;
}

require_login();

$navUser = current_user();
$navWorker = current_worker();

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

<!-- WORKREPORT_APP_META_V1 -->
<meta
    name="description"
    content="WorkReport is a private workplace documentation, evidence, timeline, and review system."
>
<meta name="application-name" content="WorkReport">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<meta name="format-detection" content="telephone=no">
<meta name="theme-color" content="#ffffff">
<meta name="color-scheme" content="light">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="WorkReport">
<title>
    <?= e($pageTitle ?? APP_NAME) ?>
</title>

<link
    rel="stylesheet"
    href="assets/css/style.css?v=20261013-icons1"
>

</head>

<body>

<header class="topbar compact-topbar">

    <a
        href="<?= $navWorker ? 'current-profile.php' : 'worker-files.php' ?>"
        class="brand-inline"
    >
        WorkReport
    </a>


    <?php if ($navWorker): ?>

        <a
            href="current-profile.php"
            class="current-worker"
            title="Open current worker profile"
        >

            <span>Worker:</span>

            <strong>
                <?= e(
                    $navWorker['full_name']
                ) ?>
            </strong>

        </a>

    <?php endif; ?>


    <nav class="compact-nav">

        <?php if ($navWorker): ?>

            <a href="current-profile.php">
                Profile
            </a>

            <a href="journal.php">
                Journal
            </a>

            <a href="timeline.php">
                Timeline
            </a>

            <a href="case-analysis.php">
                Analysis
            </a>

        <?php endif; ?>

        <a href="rights.php">
            Rights
        </a>

        <?php if (is_admin()): ?>

            <a href="worker-files.php">
                Workers
            </a>

            <a href="admin-legal-review.php">
                Review
            </a>

            <a href="access-requests.php">
                Access
            </a>

            <a href="users.php">
                Users
            </a>

        <?php endif; ?>

        <a href="logout.php">
            Sign Out
        </a>

    </nav>

</header>

<main class="container compact-container">

<?php display_flash(); ?>
