<?php

require __DIR__
    . '/../includes/effective-metadata.php';

echo "Effective metadata rebuild\n";
echo "==========================\n\n";

$count =
    rebuild_all_effective_metadata();

echo "Reports processed: {$count}\n";
