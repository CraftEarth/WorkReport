<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

http_response_code(404);

$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$uri = $_SERVER['REQUEST_URI'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

error_log("WorkReport record-unavailable user_id={$userId} uri={$uri} ip={$ip}");

$pageTitle = 'Record unavailable';

require __DIR__ . '/includes/header.php';
?>

<section class="panel">
    <h1>Record unavailable</h1>

    <p>
        This page may not exist, or you may not have permission
        to view this workplace record.
    </p>

    <p>
        <a class="button button-primary" href="current-profile.php">
            Back to My Workspace
        </a>
    </p>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php exit; ?>
