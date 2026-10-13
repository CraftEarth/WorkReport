<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/final-report-summary.php';

require_login();

$worker = require_current_worker();
$workerId = (int) $worker['id'];
$pdo = db();

$stmt = $pdo->prepare("
    SELECT DISTINCT
        p.id,
        p.name
    FROM people p
    INNER JOIN report_people rp
        ON rp.person_id = p.id
    INNER JOIN reports r
        ON r.id = rp.report_id
    WHERE r.worker_id = :worker_id
    ORDER BY p.name
");

$stmt->execute([
    ':worker_id' => $workerId
]);

$people = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT DISTINCT
        c.id,
        c.name
    FROM categories c
    INNER JOIN report_categories rc
        ON rc.category_id = c.id
    INNER JOIN reports r
        ON r.id = rc.report_id
    WHERE r.worker_id = :worker_id
    ORDER BY c.name
");

$stmt->execute([
    ':worker_id' => $workerId
]);

$categories = $stmt->fetchAll();

$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$personId = (int) ($_GET['person_id'] ?? 0);
$categoryId = (int) ($_GET['category_id'] ?? 0);
$style = trim($_GET['style'] ?? 'chronological');

$where = [
    "r.worker_id = :worker_id"
];

$params = [
    ':worker_id' => $workerId
];

if ($startDate !== '') {
    $where[] = "r.report_date >= :start_date";
    $params[':start_date'] = $startDate;
}

if ($endDate !== '') {
    $where[] = "r.report_date <= :end_date";
    $params[':end_date'] = $endDate;
}

if ($personId > 0) {
    $where[] = "
        EXISTS (
            SELECT 1
            FROM report_people rp
            WHERE rp.report_id = r.id
              AND rp.person_id = :person_id
        )
    ";

    $params[':person_id'] = $personId;
}

if ($categoryId > 0) {
    $where[] = "
        EXISTS (
            SELECT 1
            FROM report_categories rc
            WHERE rc.report_id = r.id
              AND rc.category_id = :category_id
        )
    ";

    $params[':category_id'] = $categoryId;
}

$whereSql = "WHERE " . implode(" AND ", $where);

$sql = "
    SELECT r.*
    FROM reports r
    $whereSql
    ORDER BY r.report_date ASC, r.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$reports = $stmt->fetchAll();

$caseSummary = build_final_report_case_summary(
    $pdo,
    $reports
);

$selectedPerson = null;

foreach ($people as $person) {
    if ((int) $person['id'] === $personId) {
        $selectedPerson = $person;
        break;
    }
}

$selectedCategory = null;

foreach ($categories as $category) {
    if ((int) $category['id'] === $categoryId) {
        $selectedCategory = $category;
        break;
    }
}

function get_report_people(PDO $pdo, int $reportId): array
{
    $stmt = $pdo->prepare("
        SELECT p.name
        FROM people p
        INNER JOIN report_people rp
            ON rp.person_id = p.id
        WHERE rp.report_id = ?
        ORDER BY p.name
    ");

    $stmt->execute([$reportId]);

    return array_column(
        $stmt->fetchAll(),
        'name'
    );
}

function get_report_places(PDO $pdo, int $reportId): array
{
    $stmt = $pdo->prepare("
        SELECT p.name
        FROM places p
        INNER JOIN report_places rp
            ON rp.place_id = p.id
        WHERE rp.report_id = ?
        ORDER BY p.name
    ");

    $stmt->execute([$reportId]);

    return array_column(
        $stmt->fetchAll(),
        'name'
    );
}

function get_report_categories(PDO $pdo, int $reportId): array
{
    $stmt = $pdo->prepare("
        SELECT c.name
        FROM categories c
        INNER JOIN report_categories rc
            ON rc.category_id = c.id
        WHERE rc.report_id = ?
        ORDER BY c.name
    ");

    $stmt->execute([$reportId]);

    return array_column(
        $stmt->fetchAll(),
        'name'
    );
}

function get_report_events(PDO $pdo, int $reportId): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM events
        WHERE report_id = ?
        ORDER BY
            CASE
                WHEN event_time IS NULL
                  OR event_time = ''
                THEN 1
                ELSE 0
            END,
            event_time,
            id
    ");

    $stmt->execute([$reportId]);

    return $stmt->fetchAll();
}

function report_summary_sentence(
    array $report,
    array $people,
    array $places,
    array $categories
): string {

    $parts = [];

    $date = format_date($report['report_date']);

    $parts[] = "On {$date}";

    if ($report['shift']) {
        $parts[] = "during {$report['shift']}";
    }

    if ($report['machine']) {
        $parts[] = "while assigned to {$report['machine']}";
    } elseif ($report['department']) {
        $parts[] = "while working in {$report['department']}";
    }

    $sentence = implode(' ', $parts) . '.';

    if ($people) {
        $sentence .= ' People documented in this report included ' .
            implode(', ', $people) .
            '.';
    }

    if ($places) {
        $sentence .= ' Work areas or machines referenced included ' .
            implode(', ', $places) .
            '.';
    }

    if ($categories) {
        $sentence .= ' The report was categorized under ' .
            implode(', ', $categories) .
            '.';
    }

    return $sentence;
}

$pageTitle = 'Final Report Generator';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>Final Report Generator</h1>

        <p>
            Build a chronological report from your documented records.
        </p>

    </div>

</section>


<section class="panel">

    <form
        method="get"
        class="final-report-filters"
    >

        <div class="field">

            <label>Start Date</label>

            <input
                type="date"
                name="start_date"
                value="<?= e($startDate) ?>"
            >

        </div>

        <div class="field">

            <label>End Date</label>

            <input
                type="date"
                name="end_date"
                value="<?= e($endDate) ?>"
            >

        </div>

        <div class="field">

            <label>Person</label>

            <select name="person_id">

                <option value="0">
                    All People
                </option>

                <?php foreach ($people as $person): ?>

                    <option
                        value="<?= (int) $person['id'] ?>"
                        <?= $personId === (int) $person['id']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($person['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <div class="field">

            <label>Category</label>

            <select name="category_id">

                <option value="0">
                    All Categories
                </option>

                <?php foreach ($categories as $category): ?>

                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= $categoryId === (int) $category['id']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($category['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <div class="field">

            <label>Report Style</label>

            <select name="style">

                <option
                    value="chronological"
                    <?= $style === 'chronological'
                        ? 'selected'
                        : '' ?>
                >
                    Chronological
                </option>

                <option
                    value="detailed"
                    <?= $style === 'detailed'
                        ? 'selected'
                        : '' ?>
                >
                    Detailed
                </option>

            </select>

        </div>

        <div class="filter-action">

            <button
                type="submit"
                class="button button-primary"
            >
                Generate Report
            </button>

        </div>

    </form>

</section>


<?php if ($reports): ?>

<section class="panel final-report-document">

    <div class="final-report-heading">

        <div>

            <h1>
                Workplace Documentation Report
            </h1>

            <p>
                Generated from locally stored daily documentation.
            </p>

        </div>

        <div class="final-report-count">
            <?= count($reports) ?>
            report<?= count($reports) === 1 ? '' : 's' ?>
        </div>

    </div>


    <section class="report-section">

        <h2>Report Scope</h2>

        <dl class="scope-list">

            <div>

                <dt>Date Range</dt>

                <dd>

                    <?php if ($startDate || $endDate): ?>

                        <?= e(
                            $startDate
                                ? format_date($startDate)
                                : 'Beginning of records'
                        ) ?>

                        through

                        <?= e(
                            $endDate
                                ? format_date($endDate)
                                : 'Latest record'
                        ) ?>

                    <?php else: ?>

                        All documented dates

                    <?php endif; ?>

                </dd>

            </div>

            <div>

                <dt>Person Filter</dt>

                <dd>
                    <?= e(
                        $selectedPerson['name']
                        ?? 'All people'
                    ) ?>
                </dd>

            </div>

            <div>

                <dt>Category Filter</dt>

                <dd>
                    <?= e(
                        $selectedCategory['name']
                        ?? 'All categories'
                    ) ?>
                </dd>

            </div>

        </dl>

    </section>


    <section class="report-section case-summary-section">

        <h2>Case Summary</h2>

        <p class="case-summary-narrative">
            <?= e($caseSummary['narrative']) ?>
        </p>

        <div class="case-summary-stats">

            <div>
                <span>Reports</span>
                <strong><?= (int) $caseSummary['report_count'] ?></strong>
            </div>

            <div>
                <span>Finalized</span>
                <strong><?= (int) $caseSummary['finalized_count'] ?></strong>
            </div>

            <div>
                <span>Evidence</span>
                <strong><?= (int) $caseSummary['evidence_count'] ?></strong>
            </div>

            <div>
                <span>Legal / Compliance Flags</span>
                <strong><?= (int) $caseSummary['legal_flag_count'] ?></strong>
            </div>

        </div>


        <div class="case-summary-columns">

            <div>

                <h3>Documented Concerns</h3>

                <?php if ($caseSummary['categories']): ?>

                    <?php foreach (
                        array_slice(
                            $caseSummary['categories'],
                            0,
                            8
                        )
                        as $item
                    ): ?>

                        <div class="case-summary-row">

                            <span><?= e($item['name']) ?></span>

                            <strong>
                                <?= (int) $item['report_count'] ?>
                            </strong>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p class="muted">
                        No categorized concerns have been saved
                        for the selected reports.
                    </p>

                <?php endif; ?>

            </div>


            <div>

                <h3>Potential Legal / Compliance Concerns</h3>

                <?php if ($caseSummary['legal_issues']): ?>

                    <?php foreach (
                        $caseSummary['legal_issues']
                        as $issue
                    ): ?>

                        <div class="case-summary-legal">

                            <strong>
                                <?= e($issue['title']) ?>
                            </strong>

                            <span>
                                <?= (int) $issue['report_count'] ?>
                                report<?= (int) $issue['report_count'] === 1 ? '' : 's' ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                    <p class="case-summary-disclaimer">
                        These flags identify possible issues for
                        human review. They do not establish that a
                        law or workplace policy was violated.
                    </p>

                <?php else: ?>

                    <p class="muted">
                        No legal or compliance flags were generated
                        from the selected reports.
                    </p>

                <?php endif; ?>

            </div>

        </div>


        <?php

        $repeatedPeople = array_filter(
            $caseSummary['people'],
            static fn(array $item): bool =>
                (int) $item['report_count'] >= 2
        );

        $repeatedPlaces = array_filter(
            $caseSummary['places'],
            static fn(array $item): bool =>
                (int) $item['report_count'] >= 2
        );

        ?>


        <?php if ($repeatedPeople || $repeatedPlaces): ?>

            <div class="case-observed-patterns">

                <h3>Observed Recurring References</h3>

                <?php foreach ($repeatedPeople as $item): ?>

                    <div class="case-pattern-line">
                        <span>
                            Person:
                            <strong><?= e($item['name']) ?></strong>
                        </span>

                        <span>
                            <?= (int) $item['report_count'] ?>
                            reports
                        </span>
                    </div>

                <?php endforeach; ?>


                <?php foreach ($repeatedPlaces as $item): ?>

                    <div class="case-pattern-line">
                        <span>
                            Area / Location:
                            <strong><?= e($item['name']) ?></strong>
                        </span>

                        <span>
                            <?= (int) $item['report_count'] ?>
                            reports
                        </span>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>


    <section class="report-section">

        <h2>Chronological Documentation</h2>


        <?php foreach ($reports as $report): ?>

            <?php

            $reportPeople = get_report_people(
                $pdo,
                (int) $report['id']
            );

            $reportPlaces = get_report_places(
                $pdo,
                (int) $report['id']
            );

            $reportCategories = get_report_categories(
                $pdo,
                (int) $report['id']
            );

            $reportEvents = get_report_events(
                $pdo,
                (int) $report['id']
            );

            ?>

            <article class="generated-report-entry">

                <div class="generated-report-date">

                    <?= e(
                        format_date(
                            $report['report_date']
                        )
                    ) ?>

                </div>

                <h3>

                    <?= e(
                        $report['title']
                        ?: $report['machine']
                        ?: 'Daily Work Report'
                    ) ?>

                </h3>


                <p class="generated-summary">

                    <?= e(
                        report_summary_sentence(
                            $report,
                            $reportPeople,
                            $reportPlaces,
                            $reportCategories
                        )
                    ) ?>

                </p>


                <?php if ($style === 'detailed'): ?>

                    <div class="generated-original">

                        <h4>Original Daily Statement</h4>

                        <p>
                            <?= nl2br(
                                e($report['original_text'])
                            ) ?>
                        </p>

                    </div>

                <?php endif; ?>


                <?php if ($reportEvents): ?>

                    <div class="generated-events">

                        <h4>Documented Events</h4>

                        <?php foreach ($reportEvents as $event): ?>

                            <div class="generated-event">

                                <div class="generated-event-meta">

                                    <span>

                                        <?= $event['event_time']
                                            ? e(
                                                date(
                                                    'g:i A',
                                                    strtotime(
                                                        $event['event_time']
                                                    )
                                                )
                                            )
                                            : 'Time not stated' ?>

                                    </span>

                                    <span class="tag">
                                        <?= e($event['category']) ?>
                                    </span>

                                </div>

                                <p>
                                    <?= e($event['description']) ?>
                                </p>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>


                <div class="generated-source">

                    Source:

                    <a
                        href="report.php?id=<?= (int) $report['id'] ?>"
                        target="_blank"
                    >
                        Daily Report #<?= (int) $report['id'] ?>
                    </a>

                    <?php if ($report['status'] === 'finalized'): ?>

                        <span>
                            - Finalized
                        </span>

                    <?php endif; ?>

                </div>

            </article>

        <?php endforeach; ?>

    </section>


    <section class="report-section">

        <h2>Summary of Documented Patterns</h2>

        <?php

        $patternCategories = [];
        $patternPeople = [];
        $patternPlaces = [];

        foreach ($reports as $report) {

            foreach (
                get_report_categories(
                    $pdo,
                    (int) $report['id']
                )
                as $name
            ) {
                $patternCategories[$name] =
                    ($patternCategories[$name] ?? 0) + 1;
            }

            foreach (
                get_report_people(
                    $pdo,
                    (int) $report['id']
                )
                as $name
            ) {
                $patternPeople[$name] =
                    ($patternPeople[$name] ?? 0) + 1;
            }

            foreach (
                get_report_places(
                    $pdo,
                    (int) $report['id']
                )
                as $name
            ) {
                $patternPlaces[$name] =
                    ($patternPlaces[$name] ?? 0) + 1;
            }
        }

        arsort($patternCategories);
        arsort($patternPeople);
        arsort($patternPlaces);

        ?>

        <div class="pattern-grid">

            <div>

                <h3>Categories</h3>

                <?php if ($patternCategories): ?>

                    <?php foreach ($patternCategories as $name => $count): ?>

                        <div class="pattern-row">

                            <span>
                                <?= e($name) ?>
                            </span>

                            <strong>
                                <?= (int) $count ?>
                            </strong>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p>No category data.</p>

                <?php endif; ?>

            </div>


            <div>

                <h3>People</h3>

                <?php if ($patternPeople): ?>

                    <?php foreach ($patternPeople as $name => $count): ?>

                        <div class="pattern-row">

                            <span>
                                <?= e($name) ?>
                            </span>

                            <strong>
                                <?= (int) $count ?>
                            </strong>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p>No people data.</p>

                <?php endif; ?>

            </div>


            <div>

                <h3>Places / Machines</h3>

                <?php if ($patternPlaces): ?>

                    <?php foreach ($patternPlaces as $name => $count): ?>

                        <div class="pattern-row">

                            <span>
                                <?= e($name) ?>
                            </span>

                            <strong>
                                <?= (int) $count ?>
                            </strong>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p>No place data.</p>

                <?php endif; ?>

            </div>

        </div>

    </section>


    <section class="report-section source-note">

        <h2>Source Integrity</h2>

        <p>
            This report was generated from the daily records stored
            within the Work Documentation database. Original daily
            statements remain stored separately from the generated
            summaries and can be reviewed through the source links
            above.
        </p>

    </section>

</section>


<div class="final-report-actions">

    <button
        type="button"
        class="button button-primary"
        onclick="window.print()"
    >
        Print / Save as PDF
    </button>

</div>


<?php else: ?>

<section class="panel empty-state">

    <h3>No reports matched your filters</h3>

    <p>
        Change the date, person or category selection
        and generate the report again.
    </p>

</section>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>