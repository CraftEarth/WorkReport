<?php

require __DIR__
    . '/../includes/case-relationships.php';

echo "Case relationship rebuild\n";
echo "=========================\n\n";

$result =
    rebuild_all_case_relationships();

$total = 0;

foreach ($result as $workerId => $count) {

    echo "Worker {$workerId}"
        . " | relationships={$count}\n";

    $total += $count;
}

echo "\n";
echo "Total relationships: {$total}\n";
