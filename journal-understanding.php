<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/work-journal.php';
require_once __DIR__ . '/includes/journal-intelligence.php';

require_login();
ensure_work_journal_schema();
ensure_journal_intelligence_schema();

$worker = require_current_worker();
$workerId = (int) $worker['id'];

$sessionId = (int) (
    $_GET['session_id']
    ?? 0
);

if ($sessionId < 1) {
    $latest = latest_open_journal_session($workerId);

    if (!$latest) {
        $stmt = db()->prepare("
            SELECT *
            FROM work_journal_sessions
            WHERE worker_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$workerId]);
        $latest = $stmt->fetch() ?: null;
    }

    if (!$latest) {
        header('Location: journal.php');
        exit;
    }

    $sessionId = (int) $latest['id'];
}

$session = journal_session_for_worker(
    $sessionId,
    $workerId
);

if (!$session) {
    http_response_code(404);
    exit('Journal session not found.');
}

journal_intelligence_process_pending(
    $workerId,
    $sessionId,
    false
);

$dashboard = journal_intelligence_session_dashboard(
    $workerId,
    $sessionId
);

$events = journal_intelligence_session_events(
    $workerId,
    $sessionId
);

$memory = journal_intelligence_worker_memory(
    $workerId,
    30
);

$pageTitle = 'Journal Understanding';
require __DIR__ . '/includes/header.php';

?>

<div class="journal-understanding-page">

    <div class="page-title-row">

        <div>
            <h1>Journal Understanding</h1>
            <p>
                Structured interpretation of the journal source record.
                Original worker messages remain unchanged.
            </p>
        </div>

        <a
            href="journal.php?session_id=<?= $sessionId ?>"
            class="button button-secondary"
        >
            Back to Journal
        </a>

    </div>


    <section class="journal-intel-disclaimer">
        <strong>Derived layer - not a finding</strong>
        <span>
            Events, signals, entities and relationships below are machine-extracted
            from reported statements. They can be wrong or incomplete and must stay
            traceable to the original journal message.
        </span>
    </section>


    <section class="panel">

        <div class="section-title">
            <h2>Session Overview</h2>
            <span>Session #<?= $sessionId ?></span>
        </div>

        <div class="journal-intel-stats">
            <div>
                <span>Structured Events</span>
                <strong><?= (int) $dashboard['event_count'] ?></strong>
            </div>
            <div>
                <span>Open Questions</span>
                <strong><?= count($dashboard['open_followups']) ?></strong>
            </div>
            <div>
                <span>Related Prior Events</span>
                <strong><?= (int) $dashboard['related_count'] ?></strong>
            </div>
            <div>
                <span>Engine</span>
                <strong><?= e(JOURNAL_INTELLIGENCE_ENGINE_VERSION) ?></strong>
            </div>
        </div>

    </section>


    <?php if ($dashboard['open_followups']): ?>

        <section class="panel">

            <div class="section-title">
                <h2>Open Questions</h2>
                <span>Answer in the journal when convenient</span>
            </div>

            <div class="journal-intel-question-list">

                <?php foreach ($dashboard['open_followups'] as $question): ?>

                    <div class="journal-intel-question">
                        <strong>
                            <?= e($question['question_text']) ?>
                        </strong>
                        <span>
                            <?= e(ucfirst($question['priority'])) ?>
                            | source message #<?= (int) $question['source_message_id'] ?>
                        </span>
                    </div>

                <?php endforeach; ?>

            </div>

        </section>

    <?php endif; ?>


    <section class="panel">

        <div class="section-title">
            <h2>Structured Events</h2>
            <span><?= count($events) ?> extracted</span>
        </div>

        <?php if (!$events): ?>

            <p class="help-text">
                No worker journal messages have been structured yet.
            </p>

        <?php else: ?>

            <div class="journal-intel-event-list">

                <?php foreach ($events as $event): ?>

                    <article class="journal-intel-event">

                        <div class="journal-intel-event-head">

                            <div>
                                <strong>
                                    <?= e($event['category']) ?>
                                </strong>
                                <span>
                                    Source message #<?= (int) $event['source_message_id'] ?>
                                    | event <?= (int) $event['event_index'] ?>
                                </span>
                            </div>

                            <span class="journal-intel-severity journal-intel-severity-<?= e($event['severity']) ?>">
                                <?= e(strtoupper($event['severity'])) ?>
                            </span>

                        </div>

                        <div class="journal-intel-event-meta">
                            <span>
                                Date:
                                <?= e($event['event_date'] ?: 'unknown') ?>
                                (<?= e($event['date_precision']) ?>)
                            </span>
                            <span>
                                Time:
                                <?= e($event['event_time'] ?: 'not stated') ?>
                            </span>
                            <span>
                                Status:
                                <?= e($event['fact_status']) ?>
                            </span>
                            <span>
                                Review:
                                <?= e($event['review_status']) ?>
                            </span>
                        </div>

                        <div class="journal-intel-source-excerpt">
                            <strong>Source excerpt</strong>
                            <p><?= nl2br(e($event['source_excerpt'])) ?></p>
                        </div>

                        <?php if ($event['signals']): ?>

                            <div class="journal-intel-section">
                                <strong>Issue signals</strong>
                                <div class="journal-intel-tags">
                                    <?php foreach ($event['signals'] as $signal): ?>
                                        <span>
                                            <?= e($signal['signal_label']) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        <?php endif; ?>

                        <?php if ($event['entities']): ?>

                            <div class="journal-intel-section">
                                <strong>People / roles / work areas</strong>
                                <div class="journal-intel-tags">
                                    <?php foreach ($event['entities'] as $entity): ?>
                                        <span>
                                            <?= e($entity['entity_value']) ?>
                                            <small><?= e($entity['entity_type']) ?></small>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        <?php endif; ?>

                        <?php if ($event['facts']): ?>

                            <div class="journal-intel-section">
                                <strong>Atomic reported facts</strong>
                                <div class="journal-intel-facts">
                                    <?php foreach ($event['facts'] as $fact): ?>
                                        <div>
                                            <span class="journal-intel-fact-type">
                                                <?= e($fact['fact_type']) ?>
                                            </span>
                                            <span>
                                                <?= e($fact['subject_text'] ?: 'Worker') ?>
                                                <?= e($fact['predicate_text']) ?>
                                                <?= e($fact['object_text'] ?: '') ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        <?php endif; ?>

                        <?php if ($event['links']): ?>

                            <div class="journal-intel-section">
                                <strong>Possible prior context</strong>
                                <div class="journal-intel-related-list">
                                    <?php foreach ($event['links'] as $link): ?>
                                        <div>
                                            <span>
                                                <?= e($link['related_event_date'] ?: 'Unknown date') ?>
                                                | <?= e($link['related_category']) ?>
                                            </span>
                                            <p><?= e($link['related_excerpt']) ?></p>
                                            <small>
                                                Link reason:
                                                <?= e($link['explanation']) ?>.
                                                This does not establish the same cause.
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>


    <section class="panel">

        <div class="section-title">
            <h2>Worker Memory Index</h2>
            <span>Top recurring context</span>
        </div>

        <?php if (!$memory): ?>
            <p class="help-text">No memory index yet.</p>
        <?php else: ?>

            <div class="journal-intel-memory-grid">
                <?php foreach ($memory as $item): ?>
                    <div>
                        <span><?= e($item['memory_type']) ?></span>
                        <strong><?= e($item['display_value']) ?></strong>
                        <small>
                            <?= (int) $item['mention_count'] ?> mention(s)
                            | last <?= e($item['last_seen_at']) ?>
                        </small>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
