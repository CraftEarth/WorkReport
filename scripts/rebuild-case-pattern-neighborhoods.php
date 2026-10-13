<?php

require __DIR__
    . '/../includes/case-pattern-neighborhoods.php';

echo "Case pattern neighborhood rebuild\n";
echo "=================================\n\n";

$result =
    rebuild_all_case_pattern_neighborhoods();

$total = 0;

foreach (
    $result
    as $workerId => $count
) {

    echo "Worker {$workerId}"
        . " | neighbors={$count}\n";

    $total += $count;
}

echo "\n";
echo "Total pattern neighbors: {$total}\n";
