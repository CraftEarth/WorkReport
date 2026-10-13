<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

ensure_auth_schema();
require_login();

$workers = accessible_workers();

$pageTitle = is_admin()
    ? 'Worker Files'
    : 'My File';

require __DIR__ . '/includes/header.php';

?>

<div class="db-page-header">

    <div>
        <h1><?= is_admin() ? 'Worker Files' : 'My File' ?></h1>
        <p>Workplace documentation records</p>
    </div>

    <?php if (is_admin()): ?>

        <a href="add-worker.php" class="db-add-link">
            + Add Worker
        </a>

    <?php endif; ?>

</div>

<?php if (!$workers): ?>

<div class="db-empty">
    No worker records available.
</div>

<?php else: ?>

<div class="db-table-wrap">

<table class="db-table">

    <thead>
        <tr>
            <th>Worker</th>
            <th>Company</th>
            <th>Department</th>
            <th>Reports</th>
            <th>Last Entry</th>
            <th>Actions</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($workers as $item): ?>

        <?php

        $stmt = db()->prepare("
            SELECT
                COUNT(*) AS report_count,
                MAX(report_date) AS last_report
            FROM reports
            WHERE worker_id = ?
        ");

        $stmt->execute([(int) $item['id']]);
        $stats = $stmt->fetch();

        $workerId = (int) $item['id'];

        ?>

        <tr>

            <td class="db-worker-name">
                <strong><?= e($item['full_name']) ?></strong>

                <?php if (!empty($item['employee_number'])): ?>
                    <small>
                        #<?= e($item['employee_number']) ?>
                    </small>
                <?php endif; ?>
            </td>

            <td>
                <?= e($item['employer'] ?: '—') ?>
            </td>

            <td>
                <?= e($item['department'] ?: '—') ?>
            </td>

            <td class="db-number">
                <?= (int) $stats['report_count'] ?>
            </td>

            <td>
                <?= $stats['last_report']
                    ? e(format_date($stats['last_report']))
                    : '—' ?>
            </td>

            <td>

                <div class="db-actions">

                    <a
                        class="db-link db-link-blue"
                        href="open-worker.php?worker_id=<?= $workerId ?>&view=reports"
                    >
                        Reports
                    </a>

                    <a
                        class="db-link db-link-green"
                        href="open-worker.php?worker_id=<?= $workerId ?>&view=new"
                    >
                        + Issue
                    </a>

                    <a
                        class="db-link db-link-purple"
                        href="open-worker.php?worker_id=<?= $workerId ?>&view=full"
                    >
                        Full Report
                    </a>

                    <a
                        class="db-link db-link-teal"
                        href="open-worker.php?worker_id=<?= $workerId ?>&view=search"
                    >
                        Search
                    </a>

                </div>

            </td>

        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

</div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>