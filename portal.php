<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

ensure_auth_schema();
require_login();

/*
 * AUTHENTICATED LANDING ROUTER
 *
 * WorkReport no longer has a second "home" page after login.
 *
 * - Existing selected worker -> Worker Workspace
 * - Exactly one accessible worker -> auto-select and open Workspace
 * - Multiple/no workers -> Worker Files
 */

$worker = current_worker();

if ($worker) {
    header('Location: current-profile.php');
    exit;
}

$workers = accessible_workers();

if (count($workers) === 1) {

    $_SESSION['worker_id'] =
        (int) $workers[0]['id'];

    header('Location: current-profile.php');
    exit;
}

header('Location: worker-files.php');
exit;
