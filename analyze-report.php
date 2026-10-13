<?php

require_once __DIR__ . '/includes/analyzer.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$id = (int) ($_GET['id'] ?? 0);

$report = get_report_for_current_worker($id);

if (!$report) {
    http_response_code(404);
    exit('Report not found.');
}

$analysis = analyze_report_text(
    $report['original_text'],
    (int) $report['worker_id']
);

$pageTitle = 'Analyze Report';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>
        <h1>Analyze Report</h1>

        <p>
            Review what the program detected before saving it.
        </p>
    </div>

</section>


<form
    method="post"
    action="save-analysis.php"
>
    <?= csrf_field() ?>

<input
    type="hidden"
    name="report_id"
    value="<?= $id ?>"
>


<section class="panel">

    <h2>Detected People</h2>

    <p class="help-text">
        Check the people who should be attached to this report.
    </p>

    <?php if (!$analysis['people']): ?>

        <p>No people detected.</p>

    <?php else: ?>

        <div class="check-grid">

        <?php foreach ($analysis['people'] as $person): ?>

            <label class="check-card">

                <input
                    type="checkbox"
                    name="people[]"
                    value="<?= e($person) ?>"
                    checked
                >

                <span>
                    <?= e($person) ?>
                </span>

            </label>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <div class="field add-field">

        <label>
            Add another person
        </label>

        <input
            type="text"
            name="extra_people"
            placeholder="Separate multiple names with commas"
        >

    </div>

</section>


<section class="panel">

    <h2>Detected Places / Machines</h2>

    <?php if (!$analysis['places']): ?>

        <p>No places detected.</p>

    <?php else: ?>

        <div class="check-grid">

        <?php foreach ($analysis['places'] as $place): ?>

            <label class="check-card">

                <input
                    type="checkbox"
                    name="places[]"
                    value="<?= e($place) ?>"
                    checked
                >

                <span>
                    <?= e($place) ?>
                </span>

            </label>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <div class="field add-field">

        <label>
            Add another place or machine
        </label>

        <input
            type="text"
            name="extra_places"
            placeholder="Separate multiple places with commas"
        >

    </div>

</section>


<section class="panel">

    <h2>Detected Categories</h2>

    <?php if (!$analysis['categories']): ?>

        <p>No categories detected.</p>

    <?php else: ?>

        <div class="check-grid">

        <?php foreach ($analysis['categories'] as $category): ?>

            <label class="check-card">

                <input
                    type="checkbox"
                    name="categories[]"
                    value="<?= e($category) ?>"
                    checked
                >

                <span>
                    <?= e($category) ?>
                </span>

            </label>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<section class="panel operational-analysis-review">

    <h2>Operational / Management Analysis</h2>

    <p class="help-text">
        Review the analyzer's interpretation before saving it.
        Facts, expectations and responsibility questions are kept separate
        so an inference is not stored as a proven fact.
    </p>

    <?php if (empty($analysis['operational_findings'])): ?>

        <p>
            No operational findings were detected from this entry.
        </p>

    <?php else: ?>

        <?php

        $findingTypeOptions =
            operational_finding_type_options();

        $confidenceOptions =
            operational_confidence_options();

        $resolutionOptions =
            operational_resolution_options();

        ?>

        <?php foreach (
            $analysis['operational_findings']
            as $index => $finding
        ): ?>

            <details
                class="operational-finding-review"
                open
            >

                <summary>
                    <?= e($finding['title']) ?>
                </summary>

                <div class="field">

                    <label>
                        <input
                            type="checkbox"
                            name="operational_findings[<?= $index ?>][enabled]"
                            value="1"
                            checked
                        >
                        Save this finding
                    </label>

                </div>

                <div class="form-grid">

                    <div class="field">

                        <label>Finding Type</label>

                        <select
                            name="operational_findings[<?= $index ?>][finding_type]"
                        >

                            <?php foreach (
                                $findingTypeOptions
                                as $value => $label
                            ): ?>

                                <option
                                    value="<?= e($value) ?>"
                                    <?= $value === $finding['finding_type']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="field">

                        <label>Confidence</label>

                        <select
                            name="operational_findings[<?= $index ?>][confidence]"
                        >

                            <?php foreach (
                                $confidenceOptions
                                as $value => $label
                            ): ?>

                                <option
                                    value="<?= e($value) ?>"
                                    <?= $value === $finding['confidence']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="field">

                        <label>Resolution Status</label>

                        <select
                            name="operational_findings[<?= $index ?>][resolution_status]"
                        >

                            <?php foreach (
                                $resolutionOptions
                                as $value => $label
                            ): ?>

                                <option
                                    value="<?= e($value) ?>"
                                    <?= $value === $finding['resolution_status']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

                <div class="field">

                    <label>Finding Title</label>

                    <input
                        type="text"
                        name="operational_findings[<?= $index ?>][title]"
                        value="<?= e($finding['title']) ?>"
                    >

                </div>

                <div class="field">

                    <label>Observed Fact</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][observed_fact]"
                        rows="3"
                    ><?= e($finding['observed_fact']) ?></textarea>

                </div>

                <div class="field">

                    <label>Expected Condition</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][expected_condition]"
                        rows="3"
                    ><?= e($finding['expected_condition']) ?></textarea>

                </div>

                <div class="field">

                    <label>Observed Condition</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][observed_condition]"
                        rows="3"
                    ><?= e($finding['observed_condition']) ?></textarea>

                </div>

                <div class="field">

                    <label>Operational Impact</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][operational_impact]"
                        rows="3"
                    ><?= e($finding['operational_impact']) ?></textarea>

                </div>

                <div class="field">

                    <label>Worker Response</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][worker_response]"
                        rows="3"
                    ><?= e($finding['worker_response']) ?></textarea>

                </div>

                <div class="field">

                    <label>Management / Other Response</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][management_response]"
                        rows="3"
                    ><?= e($finding['management_response']) ?></textarea>

                </div>

                <div class="field">

                    <label>Responsibility Context</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][responsibility_context]"
                        rows="3"
                    ><?= e($finding['responsibility_context']) ?></textarea>

                </div>

                <div class="field">

                    <label>Why This Matters</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][reasoning]"
                        rows="3"
                    ><?= e($finding['reasoning']) ?></textarea>

                </div>

                <div class="field">

                    <label>Review Question</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][review_question]"
                        rows="2"
                    ><?= e($finding['review_question']) ?></textarea>

                </div>

                <div class="field">

                    <label>Source Excerpt</label>

                    <textarea
                        name="operational_findings[<?= $index ?>][source_excerpt]"
                        rows="3"
                    ><?= e($finding['source_excerpt']) ?></textarea>

                </div>

            </details>

        <?php endforeach; ?>

    <?php endif; ?>

</section>


<section class="panel">

    <h2>Detected Timeline Events</h2>

    <p class="help-text">
        Each detected sentence becomes an individual timeline event.
        Uncheck anything you do not want saved.
    </p>

    <div class="event-review">

    <?php foreach ($analysis['events'] as $index => $event): ?>

        <div class="event-review-row">

            <input
                type="checkbox"
                name="events[<?= $index ?>][enabled]"
                value="1"
                checked
            >

            <input
                type="time"
                name="events[<?= $index ?>][time]"
                value="<?= e($event['time']) ?>"
                class="event-time"
            >

            <select
                name="events[<?= $index ?>][category]"
            >
                <?php

                $options = [
                    'General',
                    'Changeover',
                    'Waiting on Parts',
                    'Quality',
                    'Management Interaction',
                    'Work Assignment',
                    'Downtime',
                    'Disciplinary Action',
                    'Attendance / Leave',
                    'Harassment / Conduct',
                    'Safety'
                ];

                foreach ($options as $option):

                ?>

                    <option
                        value="<?= e($option) ?>"
                        <?= $option === $event['category']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($option) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <textarea
                name="events[<?= $index ?>][description]"
                rows="3"
            ><?= e($event['description']) ?></textarea>

        </div>

    <?php endforeach; ?>

    </div>

</section>


<div class="form-actions">

    <a
        href="report.php?id=<?= $id ?>"
        class="button button-secondary"
    >
        Cancel
    </a>

    <button
        type="submit"
        class="button button-primary"
    >
        Save Analysis
    </button>

</div>

</form>

<?php require __DIR__ . '/includes/footer.php'; ?>