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


function get_report_attachments(PDO $pdo, int $reportId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM attachments
            WHERE report_id = ?
            ORDER BY id
        ");

        $stmt->execute([$reportId]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function report_event_narrative(array $events): array
{
    if (!$events) {
        return [];
    }

    $paragraphs = [];
    $buffer = [];

    foreach ($events as $event) {

        $description = trim(
            (string) ($event['description'] ?? '')
        );

        if ($description === '') {
            continue;
        }

        $time = trim(
            (string) ($event['event_time'] ?? '')
        );

        if ($time !== '') {
            $formattedTime = date(
                'g:i A',
                strtotime($time)
            );

            $description =
                'At approximately '
                . $formattedTime
                . ', '
                . lcfirst($description);
        }

        $description = rtrim($description);

        if (!preg_match('/[.!?]$/', $description)) {
            $description .= '.';
        }

        $buffer[] = $description;

        /*
         * Keep paragraphs readable instead of turning the
         * chronology into one huge wall of text.
         */
        if (count($buffer) >= 3) {
            $paragraphs[] = implode(' ', $buffer);
            $buffer = [];
        }
    }

    if ($buffer) {
        $paragraphs[] = implode(' ', $buffer);
    }

    return $paragraphs;
}

function report_attachment_label(array $attachment): string
{
    foreach (
        [
            'description',
            'title',
            'original_name',
            'filename',
            'file_name',
            'name'
        ]
        as $field
    ) {
        $value = trim(
            (string) ($attachment[$field] ?? '')
        );

        if ($value !== '') {
            return $value;
        }
    }

    return 'Evidence attachment';
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

<?php

$firstReportDate = $reports[0]['report_date'] ?? null;
$lastReportDate = $reports[count($reports) - 1]['report_date'] ?? null;

$repeatedPeople = array_values(
    array_filter(
        $caseSummary['people'],
        static fn(array $item): bool =>
            (int) $item['report_count'] >= 2
    )
);

$repeatedPlaces = array_values(
    array_filter(
        $caseSummary['places'],
        static fn(array $item): bool =>
            (int) $item['report_count'] >= 2
    )
);

?>

<section class="final-report-document final-report-professional">

    <header class="final-report-cover">

        <div>

            <div class="final-report-kicker">
                WorkReport
            </div>

            <h1>
                Final Workplace Documentation Report
            </h1>

            <p class="final-report-subtitle">
                Prepared from documented workplace records
            </p>

        </div>

        <div class="final-report-cover-meta">

            <div>
                <span>Worker</span>
                <strong>
                    <?= e(
                        $worker['name']
                        ?? $worker['full_name']
                        ?? 'Selected Worker'
                    ) ?>
                </strong>
            </div>

            <div>
                <span>Documentation Period</span>
                <strong>
                    <?= $firstReportDate
                        ? e(format_date($firstReportDate))
                        : '—' ?>
                    —
                    <?= $lastReportDate
                        ? e(format_date($lastReportDate))
                        : '—' ?>
                </strong>
            </div>

            <div>
                <span>Source Reports</span>
                <strong>
                    <?= count($reports) ?>
                </strong>
            </div>

        </div>

    </header>


    <section class="final-report-section">

        <h2>Executive Summary</h2>

        <p>
            The selected records contain
            <strong>
                <?= count($reports) ?>
                workplace report<?= count($reports) === 1 ? '' : 's' ?>
            </strong>
            documented between
            <strong>
                <?= e(format_date($firstReportDate)) ?>
            </strong>
            and
            <strong>
                <?= e(format_date($lastReportDate)) ?>
            </strong>.
            <?= (int) $caseSummary['finalized_count'] ?>
            of these reports
            <?= (int) $caseSummary['finalized_count'] === 1
                ? 'is'
                : 'are' ?>
            finalized, and the selected records contain
            <?= (int) $caseSummary['evidence_count'] ?>
            preserved evidence attachment<?= (int) $caseSummary['evidence_count'] === 1 ? '' : 's' ?>.
        </p>

        <?php if ($caseSummary['categories']): ?>

            <?php
            $topCategories = array_slice(
                $caseSummary['categories'],
                0,
                5
            );
            ?>

            <p>
                The documented subjects include
                <?php
                $categoryLabels = [];

                foreach ($topCategories as $item) {
                    $categoryLabels[] =
                        $item['name']
                        . ' ('
                        . (int) $item['report_count']
                        . ')';
                }

                echo e(implode(', ', $categoryLabels));
                ?>.
            </p>

        <?php endif; ?>

        <?php if ($repeatedPeople || $repeatedPlaces): ?>

            <p>
                Recurring references appear across multiple source records.

                <?php if ($repeatedPeople): ?>
                    People appearing in more than one report include
                    <?php
                    $labels = [];

                    foreach (
                        array_slice($repeatedPeople, 0, 5)
                        as $item
                    ) {
                        $labels[] =
                            $item['name']
                            . ' ('
                            . (int) $item['report_count']
                            . ' reports)';
                    }

                    echo e(implode(', ', $labels));
                    ?>.
                <?php endif; ?>

                <?php if ($repeatedPlaces): ?>
                    Repeated work areas or locations include
                    <?php
                    $labels = [];

                    foreach (
                        array_slice($repeatedPlaces, 0, 5)
                        as $item
                    ) {
                        $labels[] =
                            $item['name']
                            . ' ('
                            . (int) $item['report_count']
                            . ' reports)';
                    }

                    echo e(implode(', ', $labels));
                    ?>.
                <?php endif; ?>
            </p>

        <?php else: ?>

            <p>
                No recurring person or work-area pattern is established
                from the currently selected records alone.
            </p>

        <?php endif; ?>

        <p class="final-report-neutrality">
            This report summarizes documented records and extracted
            chronology. It does not independently determine fault,
            intent, retaliation, policy violation, or legal liability.
        </p>

    </section>


    <section class="final-report-section">

        <h2>Case Snapshot</h2>

        <div class="final-report-snapshot">

            <div>
                <span>Reports</span>
                <strong>
                    <?= (int) $caseSummary['report_count'] ?>
                </strong>
            </div>

            <div>
                <span>Finalized</span>
                <strong>
                    <?= (int) $caseSummary['finalized_count'] ?>
                </strong>
            </div>

            <div>
                <span>Evidence</span>
                <strong>
                    <?= (int) $caseSummary['evidence_count'] ?>
                </strong>
            </div>

            <div>
                <span>Review Flags</span>
                <strong>
                    <?= (int) $caseSummary['legal_flag_count'] ?>
                </strong>
            </div>

        </div>

    </section>


    <?php if ($caseSummary['legal_issues']): ?>

        <section class="final-report-section">

            <h2>
                Potential Legal / Compliance Issues for Review
            </h2>

            <p>
                Automated review identified the following subjects for
                possible human review. These entries are screening
                indicators only and do not establish that a law,
                regulation, policy, or employment right was violated.
            </p>

            <div class="final-report-review-list">

                <?php foreach ($caseSummary['legal_issues'] as $issue): ?>

                    <div class="final-report-review-item">

                        <strong>
                            <?= e($issue['title']) ?>
                        </strong>

                        <span>
                            Referenced across
                            <?= (int) $issue['report_count'] ?>
                            source report<?= (int) $issue['report_count'] === 1 ? '' : 's' ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        </section>

    <?php endif; ?>


    <section class="final-report-section final-report-chronology">

        <h2>Detailed Chronology</h2>

        <p class="final-report-section-intro">
            The following chronology is organized by source-report date.
            Each entry is derived from the worker's documented record and
            related extracted events.
        </p>


        <?php foreach ($reports as $report): ?>

            <?php

            $reportId = (int) $report['id'];

            $reportPeople = get_report_people(
                $pdo,
                $reportId
            );

            $reportPlaces = get_report_places(
                $pdo,
                $reportId
            );

            $reportCategories = get_report_categories(
                $pdo,
                $reportId
            );

            $reportEvents = get_report_events(
                $pdo,
                $reportId
            );

            $reportAttachments = get_report_attachments(
                $pdo,
                $reportId
            );

            $narrativeParagraphs = report_event_narrative(
                $reportEvents
            );

            ?>

            <article class="final-report-entry">

                <div class="final-report-entry-heading">

                    <div class="final-report-entry-date">
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

                    <div class="final-report-entry-source">
                        Source Record:
                        Daily Report #<?= $reportId ?>

                        <?php if (($report['status'] ?? '') === 'finalized'): ?>
                            · Finalized
                        <?php endif; ?>
                    </div>

                </div>


                <?php if (
                    !empty($report['machine'])
                    || !empty($report['department'])
                    || $reportPeople
                ): ?>

                    <div class="final-report-entry-context">

                        <?php if (!empty($report['machine'])): ?>
                            <span>
                                <strong>Work Area:</strong>
                                <?= e($report['machine']) ?>
                            </span>
                        <?php elseif (!empty($report['department'])): ?>
                            <span>
                                <strong>Department:</strong>
                                <?= e($report['department']) ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($reportPeople): ?>
                            <span>
                                <strong>People Referenced:</strong>
                                <?= e(implode(', ', $reportPeople)) ?>
                            </span>
                        <?php endif; ?>

                    </div>

                <?php endif; ?>


                <?php if ($narrativeParagraphs): ?>

                    <div class="final-report-narrative">

                        <?php foreach ($narrativeParagraphs as $paragraph): ?>

                            <p>
                                <?= e($paragraph) ?>
                            </p>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="final-report-narrative">

                        <p>
                            <?= nl2br(
                                e(
                                    $report['polished_text']
                                    ?: $report['original_text']
                                )
                            ) ?>
                        </p>

                    </div>

                <?php endif; ?>


                <?php if ($reportAttachments): ?>

                    <aside class="final-report-evidence-box">

                        <div class="final-report-evidence-title">
                            Evidence Referenced
                        </div>

                        <ul>

                            <?php foreach ($reportAttachments as $attachment): ?>

                                <li>
                                    <?= e(
                                        report_attachment_label(
                                            $attachment
                                        )
                                    ) ?>
                                </li>

                            <?php endforeach; ?>

                        </ul>

                        <div class="final-report-evidence-source">
                            Source:
                            Daily Report #<?= $reportId ?>
                        </div>

                    </aside>

                <?php endif; ?>


                <?php if ($reportCategories): ?>

                    <div class="final-report-entry-topics">

                        <strong>
                            Documented subjects:
                        </strong>

                        <?= e(
                            implode(
                                ', ',
                                $reportCategories
                            )
                        ) ?>

                    </div>

                <?php endif; ?>


                <?php if ($style === 'detailed'): ?>

                    <details class="final-report-original-record">

                        <summary>
                            Original Daily Statement
                        </summary>

                        <p>
                            <?= nl2br(
                                e($report['original_text'])
                            ) ?>
                        </p>

                    </details>

                <?php endif; ?>

            </article>

        <?php endforeach; ?>

    </section>


    <section class="final-report-section">

        <h2>Observed Patterns</h2>

        <?php if (
            !$repeatedPeople
            && !$repeatedPlaces
            && !array_filter(
                $caseSummary['categories'],
                static fn(array $item): bool =>
                    (int) $item['report_count'] >= 2
            )
        ): ?>

            <p>
                The current selection does not contain enough repeated
                references to identify a recurring pattern solely from
                frequency. Individual reports remain documented in the
                chronology above.
            </p>

        <?php else: ?>

            <?php

            $repeatedCategories = array_values(
                array_filter(
                    $caseSummary['categories'],
                    static fn(array $item): bool =>
                        (int) $item['report_count'] >= 2
                )
            );

            ?>

            <?php if ($repeatedCategories): ?>

                <h3>Recurring Documented Subjects</h3>

                <?php foreach ($repeatedCategories as $item): ?>

                    <p>
                        <strong><?= e($item['name']) ?></strong>
                        appears in
                        <?= (int) $item['report_count'] ?>
                        separate source reports.
                    </p>

                <?php endforeach; ?>

            <?php endif; ?>


            <?php if ($repeatedPeople): ?>

                <h3>Recurring People Referenced</h3>

                <?php foreach ($repeatedPeople as $item): ?>

                    <p>
                        <strong><?= e($item['name']) ?></strong>
                        is referenced in
                        <?= (int) $item['report_count'] ?>
                        separate source reports.
                    </p>

                <?php endforeach; ?>

            <?php endif; ?>


            <?php if ($repeatedPlaces): ?>

                <h3>Recurring Work Areas / Locations</h3>

                <?php foreach ($repeatedPlaces as $item): ?>

                    <p>
                        <strong><?= e($item['name']) ?></strong>
                        appears in
                        <?= (int) $item['report_count'] ?>
                        separate source reports.
                    </p>

                <?php endforeach; ?>

            <?php endif; ?>

        <?php endif; ?>

    </section>


    <section class="final-report-section">

        <h2>Evidence Index</h2>

        <?php

        $allEvidence = [];

        foreach ($reports as $report) {

            $attachments = get_report_attachments(
                $pdo,
                (int) $report['id']
            );

            foreach ($attachments as $attachment) {

                $allEvidence[] = [
                    'report_id' => (int) $report['id'],
                    'report_date' => $report['report_date'],
                    'title' => $report['title'],
                    'label' => report_attachment_label(
                        $attachment
                    )
                ];
            }
        }

        ?>

        <?php if ($allEvidence): ?>

            <div class="final-report-index">

                <?php foreach ($allEvidence as $item): ?>

                    <div class="final-report-index-row">

                        <div>
                            <strong>
                                <?= e($item['label']) ?>
                            </strong>

                            <span>
                                <?= e(
                                    format_date(
                                        $item['report_date']
                                    )
                                ) ?>
                                ·
                                <?= e(
                                    $item['title']
                                    ?: 'Daily Work Report'
                                ) ?>
                            </span>
                        </div>

                        <span>
                            Report #<?= (int) $item['report_id'] ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <p>
                No evidence attachments are associated with the
                selected source reports.
            </p>

        <?php endif; ?>

    </section>


    <section class="final-report-section">

        <h2>Source Record Index</h2>

        <div class="final-report-index">

            <?php foreach ($reports as $report): ?>

                <div class="final-report-index-row">

                    <div>

                        <strong>
                            Daily Report #<?= (int) $report['id'] ?>
                        </strong>

                        <span>
                            <?= e(
                                format_date(
                                    $report['report_date']
                                )
                            ) ?>
                            ·
                            <?= e(
                                $report['title']
                                ?: $report['machine']
                                ?: 'Daily Work Report'
                            ) ?>
                        </span>

                    </div>

                    <span>
                        <?= e(
                            ucfirst(
                                (string) (
                                    $report['status']
                                    ?: 'draft'
                                )
                            )
                        ) ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

    </section>


    <section class="final-report-section final-report-integrity">

        <h2>Source Integrity</h2>

        <p>
            This Final Report was generated from workplace records
            stored in WorkReport. Original daily statements remain
            preserved separately from generated summaries and extracted
            chronology. Generated language is intended to organize and
            summarize the underlying documentation and does not replace
            the original source records.
        </p>

    </section>

</section>


<div class="final-report-actions">

    <button
        type="button"
        class="button button-primary"
        onclick="window.print()"
    >
        Print Report
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