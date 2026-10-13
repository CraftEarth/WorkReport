<?php

require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Search Documentation';

$query = trim($_GET['q'] ?? '');

$reports = [];

if ($query !== '') {

    $like = '%' . $query . '%';

    $stmt = db()->prepare("
        SELECT *
        FROM reports
        WHERE
            title LIKE :query
            OR original_text LIKE :query
            OR department LIKE :query
            OR machine LIKE :query
            OR shift LIKE :query
        ORDER BY report_date DESC, id DESC
    ");

    $stmt->execute([
        ':query' => $like
    ]);

    $reports = $stmt->fetchAll();
}

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>Search Documentation</h1>

        <p>
            Search every report for names,
            machines, incidents or keywords.
        </p>

    </div>

</section>


<section class="panel">

    <form
        method="get"
        class="search-form"
    >

        <input
            type="search"
            name="q"
            value="<?= e($query) ?>"
            placeholder="Example: Brent, Ford, quality, bushings..."
            autofocus
        >

        <button
            type="submit"
            class="button button-primary"
        >
            Search
        </button>

    </form>

</section>


<?php if ($query !== ''): ?>

<section class="panel">

    <div class="panel-header">

        <div>

            <h2>
                Search Results
            </h2>

            <p>
                <?= count($reports) ?>
                report(s) containing
                "<?= e($query) ?>"
            </p>

        </div>

    </div>

    <?php if (!$reports): ?>

        <div class="empty-state">

            <p>
                No matching documentation was found.
            </p>

        </div>

    <?php else: ?>

        <div class="report-list">

        <?php foreach ($reports as $report): ?>

            <a
                class="search-result"
                href="report.php?id=<?= (int) $report['id'] ?>"
            >

                <div class="search-result-top">

                    <strong>
                        <?= e(
                            $report['title']
                            ?: $report['machine']
                            ?: 'Daily Report'
                        ) ?>
                    </strong>

                    <span>
                        <?= e(
                            format_date(
                                $report['report_date']
                            )
                        ) ?>
                    </span>

                </div>

                <p>
                    <?= e(
                        mb_strimwidth(
                            $report['original_text'],
                            0,
                            400,
                            '...'
                        )
                    ) ?>
                </p>

            </a>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
