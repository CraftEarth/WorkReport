<?php

require __DIR__
    . '/../includes/case-events.php';


echo "Case event knowledge rebuild\n";
echo "============================\n\n";


$rows =
    rebuild_all_case_events();


$totalReports = 0;
$totalUnits = 0;
$totalEvents = 0;
$totalUnresolved = 0;


foreach ($rows as $row) {

    printf(
        "Worker %d | Report %d | source=%s | units=%d | eventized=%d | context=%d | unresolved=%d | events=%d | %s\n",
        $row['worker_id'],
        $row['report_id'],
        $row['source_basis'],
        $row['source_units'],
        $row['eventized_units'],
        $row['context_units'],
        $row['unresolved_units'],
        $row['atomic_events'],
        strtoupper(
            $row['coverage_status']
        )
    );


    $totalReports++;
    $totalUnits +=
        $row['source_units'];

    $totalEvents +=
        $row['atomic_events'];

    $totalUnresolved +=
        $row['unresolved_units'];
}


echo "\n";
echo "Reports processed: {$totalReports}\n";
echo "Source units preserved: {$totalUnits}\n";
echo "Atomic events stored: {$totalEvents}\n";
echo "Unresolved units preserved: {$totalUnresolved}\n";
