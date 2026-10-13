<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$worker = require_current_worker();

/*
 * Keep worker-profile.php compatible with its existing
 * worker_id input while making the navbar ID-free.
 *
 * The selected worker comes from the authenticated session.
 * Users never need to know or type a worker ID.
 */
$_GET['worker_id'] = (int) $worker['id'];

require __DIR__ . '/worker-profile.php';
