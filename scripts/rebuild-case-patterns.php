<?php

require __DIR__
    . '/../includes/case-patterns.php';

echo "Case pattern rebuild\n";
echo "====================\n\n";

$result =
    rebuild_all_case_patterns();

$total = 0;

foreach (
    $result
    as $workerId => $count
) {

    echo "Worker {$workerId}"
        . " | patterns={$count}\n";

    $total += $count;
}

echo "\n";
echo "Total patterns: {$total}\n";
