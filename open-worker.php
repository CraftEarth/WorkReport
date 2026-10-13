<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$workerId = (int) ($_GET['worker_id'] ?? 0);
$view = $_GET['view'] ?? 'reports';

if (
    $workerId < 1
    || !worker_accessible($workerId)
) {
    http_response_code(404);
    exit('Worker not found.');
}

$_SESSION['worker_id'] = $workerId;

$routes = [
    'reports' => 'timeline.php',
    'new' => 'new-report.php',
    'analysis' => 'case-analysis.php',
    'full' => 'final-report.php',
    'search' => 'search.php'
];

$destination = $routes[$view] ?? $routes['reports'];

header('Location: ' . $destination);
exit;