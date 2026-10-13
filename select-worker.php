<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$workerId =
    (int) (
        $_POST['worker_id']
        ?? $_GET['worker_id']
        ?? 0
    );

if (
    $workerId > 0
    && worker_accessible($workerId)
) {
    $_SESSION['worker_id'] =
        $workerId;

    header(
        'Location: current-profile.php'
    );

    exit;
}

header(
    'Location: worker-files.php'
);

exit;
