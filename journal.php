<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/work-journal.php';
require_once __DIR__ . '/includes/journal-intelligence.php';

require_login();
ensure_work_journal_schema();

$worker = require_current_worker();
$workerId = (int) $worker['id'];
$user = current_user();
$userId = $user ? (int) $user['id'] : null;

if (empty($_SESSION['journal_csrf'])) {
    $_SESSION['journal_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrf = $_SESSION['journal_csrf'];
$error = '';

$requestedSessionId = (int) (
    $_GET['session_id']
    ?? $_POST['session_id']
    ?? 0
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedCsrf =
        (string) ($_POST['csrf_token'] ?? '');

    if (
        $postedCsrf === ''
        || !hash_equals($csrf, $postedCsrf)
    ) {
        http_response_code(400);
        exit('Invalid request token.');
    }

    $action =
        (string) ($_POST['action'] ?? '');

    try {

        if ($action === 'new_session') {

            $session = create_journal_session(
                $workerId,
                $userId
            );

            header(
                'Location: journal.php?session_id='
                . (int) $session['id']
            );
            exit;
        }

        if ($requestedSessionId < 1) {
            throw new RuntimeException(
                'Journal session not found.'
            );
        }

        $session = journal_session_for_worker(
            $requestedSessionId,
            $workerId
        );

        if (!$session) {
            http_response_code(404);
            exit('Journal session not found.');
        }

        if ($action === 'add_message') {

            if ($session['status'] !== 'open') {
                throw new RuntimeException(
                    'This journal session is closed.'
                );
            }

            $messageText =
                trim(
                    (string) (
                        $_POST['message_text']
                        ?? ''
                    )
                );

            if ($messageText === '') {
                throw new RuntimeException(
                    'Write something before saving.'
                );
            }

            if (mb_strlen($messageText) > 20000) {
                throw new RuntimeException(
                    'That entry is too long. Keep each message under 20,000 characters.'
                );
            }

            add_journal_message(
                $requestedSessionId,
                $userId,
                'worker',
                $messageText
            );

            /*
             * Source capture comes first.
             * Intelligence failure must never prevent the worker's
             * original statement from being preserved.
             */
            try {
                journal_intelligence_process_pending(
                    $workerId,
                    $requestedSessionId,
                    true
                );
            } catch (Throwable $intelligenceError) {
                error_log(
                    'WorkReport journal intelligence error: '
                    . $intelligenceError->getMessage()
                );
            }

            header(
                'Location: journal.php?session_id='
                . $requestedSessionId
                . '#journal-bottom'
            );
            exit;
        }

        if ($action === 'close_session') {

            close_journal_session(
                $requestedSessionId,
                $workerId
            );

            header(
                'Location: journal.php?session_id='
                . $requestedSessionId
            );
            exit;
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$session = null;

if ($requestedSessionId > 0) {
    $session = journal_session_for_worker(
        $requestedSessionId,
        $workerId
    );
}

if (!$session) {
    $session = latest_open_journal_session(
        $workerId
    );
}

if (!$session) {
    $session = create_journal_session(
        $workerId,
        $userId
    );
}

$journalIntelligence = null;
$journalIntelligenceError = null;

try {
    /*
     * Catch any older unprocessed worker messages.
     * Closed sessions are analyzed without adding new system replies.
     */
    journal_intelligence_process_pending(
        $workerId,
        (int) $session['id'],
        true
    );

    $journalIntelligence =
        journal_intelligence_session_dashboard(
            $workerId,
            (int) $session['id']
        );
} catch (Throwable $intelligenceError) {
    $journalIntelligenceError =
        $intelligenceError->getMessage();

    error_log(
        'WorkReport journal intelligence page error: '
        . $journalIntelligenceError
    );
}

$messages = journal_messages(
    (int) $session['id']
);

$integrity = verify_journal_chain(
    (int) $session['id']
);

$recentSessions = recent_journal_sessions(
    $workerId,
    20
);

$pageTitle = 'Work Journal';

require __DIR__ . '/includes/header.php';

?>

<div class="journal-page">

    <header class="journal-header">

        <div>

            <div class="journal-eyebrow">
                PRIVATE WORK JOURNAL
            </div>

            <h1>
                <?= e($worker['full_name']) ?>
            </h1>

            <p>
                Tell WorkReport what happened.
                Your source conversation is preserved in order.
            </p>

        </div>

        <div class="journal-header-actions">

            <a
                href="worker-profile.php?worker_id=<?= $workerId ?>"
                class="button button-secondary"
            >
                Worker Profile
            </a>

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrf) ?>"
                >
                <input
                    type="hidden"
                    name="action"
                    value="new_session"
                >

                <button
                    type="submit"
                    class="button button-primary"
                >
                    + New Journal
                </button>
            </form>

        </div>

    </header>


    <?php if ($error): ?>

        <div class="flash flash-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <section class="journal-integrity-bar">

        <div>

            <strong>
                Source Record
            </strong>

            <span>
                Session #<?= (int) $session['id'] ?>
                |
                <?= e($session['journal_date']) ?>
                |
                <?= e(ucfirst($session['status'])) ?>
            </span>

        </div>

        <div class="journal-integrity-status">

            <?php if ($integrity['valid']): ?>

                <span class="journal-integrity-ok">
                    Chain verified
                </span>

            <?php else: ?>

                <span class="journal-integrity-bad">
                    Integrity check failed
                </span>

            <?php endif; ?>

        </div>

    </section>


    <?php if (!empty($journalIntelligenceError)): ?>

        <section class="journal-intelligence-panel journal-intelligence-panel-error">
            <div>
                <strong>Understanding engine unavailable</strong>
                <span>
                    Your journal entry is still preserved. Structured analysis can be rebuilt later.
                </span>
            </div>
        </section>

    <?php elseif (!empty($journalIntelligence)): ?>

        <section class="journal-intelligence-panel">

            <div class="journal-intelligence-head">

                <div>
                    <span class="journal-intelligence-eyebrow">
                        WORKREPORT UNDERSTANDING
                    </span>
                    <strong>
                        <?= $journalIntelligence['latest']
                            ? e($journalIntelligence['latest']['summary_text'])
                            : 'Waiting for the first worker entry.' ?>
                    </strong>
                </div>

                <a
                    href="journal-understanding.php?session_id=<?= (int) $session['id'] ?>"
                    class="button button-secondary"
                >
                    View Structured Record
                </a>

            </div>

            <div class="journal-intelligence-stats">
                <span>
                    <strong><?= (int) $journalIntelligence['event_count'] ?></strong>
                    events
                </span>
                <span>
                    <strong><?= (int) $journalIntelligence['related_count'] ?></strong>
                    related prior events
                </span>
                <span>
                    <strong><?= count($journalIntelligence['open_followups']) ?></strong>
                    open questions
                </span>
            </div>

            <?php if ($journalIntelligence['signals']): ?>
                <div class="journal-intelligence-tags">
                    <?php foreach ($journalIntelligence['signals'] as $signal): ?>
                        <span class="journal-intelligence-tag journal-intelligence-tag-<?= e($signal['severity']) ?>">
                            <?= e($signal['signal_label']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($journalIntelligence['entities']): ?>
                <div class="journal-intelligence-context">
                    <strong>Context:</strong>
                    <?php
                    $contextLabels = [];
                    foreach ($journalIntelligence['entities'] as $entity) {
                        $contextLabels[] = $entity['entity_value'];
                    }
                    echo e(implode(', ', array_slice(array_unique($contextLabels), 0, 8)));
                    ?>
                </div>
            <?php endif; ?>

            <?php if ($journalIntelligence['open_followups']): ?>
                <div class="journal-intelligence-questions">
                    <strong>Details that may matter:</strong>
                    <?php foreach (array_slice($journalIntelligence['open_followups'], 0, 3) as $question): ?>
                        <span><?= e($question['question_text']) ?></span>
                    <?php endforeach; ?>
                    <small>
                        Answer in the journal now or later. The original entry is already saved.
                    </small>
                </div>
            <?php endif; ?>

            <div class="journal-intelligence-footnote">
                This is a derived interpretation, not a finding of fact or wrongdoing.
                Source messages remain unchanged and separately fingerprinted.
            </div>

        </section>

    <?php endif; ?>


    <div class="journal-workbench">

        <main class="journal-conversation">

            <?php foreach ($messages as $message): ?>

                <article
                    class="journal-message journal-message-<?= e($message['speaker']) ?>"
                >

                    <div class="journal-message-meta">

                        <strong>
                            <?= $message['speaker'] === 'worker'
                                ? e($worker['full_name'])
                                : 'WorkReport' ?>
                        </strong>

                        <span>
                            <?= e($message['created_at']) ?>
                        </span>

                        <span>
                            #<?= (int) $message['sequence_number'] ?>
                        </span>

                    </div>

                    <div class="journal-message-text">
                        <?= nl2br(
                            e($message['message_text'])
                        ) ?>
                    </div>

                </article>

            <?php endforeach; ?>


            <?php if ($session['status'] === 'open'): ?>

                <form
                    method="post"
                    class="journal-compose"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="session_id"
                        value="<?= (int) $session['id'] ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="add_message"
                    >

                    <label for="message_text">
                        What happened next?
                    </label>

                    <textarea
                        id="message_text"
                        name="message_text"
                        rows="6"
                        maxlength="20000"
                        placeholder="Talk normally. Names, times, machines, conversations, good days, bad days - write what you remember."
                        required
                    ></textarea>

                    <div class="journal-compose-actions">

                        <span>
                            Saved messages are preserved as source records.
                        </span>

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            Save to Journal
                        </button>

                    </div>

                </form>


                <form
                    method="post"
                    class="journal-close-form"
                    onsubmit="return confirm('Close this journal session? You can start another session later.');"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="session_id"
                        value="<?= (int) $session['id'] ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="close_session"
                    >

                    <button
                        type="submit"
                        class="journal-close-link"
                    >
                        Close and fingerprint this session
                    </button>

                </form>

            <?php else: ?>

                <div class="journal-closed-notice">

                    <strong>
                        Session closed.
                    </strong>

                    <span>
                        Final chain:
                        <?= e(
                            substr(
                                (string) $session['final_chain_hash'],
                                0,
                                20
                            )
                        ) ?>...
                    </span>

                </div>

            <?php endif; ?>

            <div id="journal-bottom"></div>

        </main>


        <aside class="journal-history">

            <div class="journal-history-title">
                JOURNAL HISTORY
            </div>

            <?php foreach ($recentSessions as $item): ?>

                <a
                    href="journal.php?session_id=<?= (int) $item['id'] ?>"
                    class="journal-history-row <?= (int) $item['id'] === (int) $session['id']
                        ? 'journal-history-row-active'
                        : '' ?>"
                >

                    <strong>
                        <?= e($item['journal_date']) ?>
                    </strong>

                    <span>
                        <?= (int) $item['worker_message_count'] ?>
                        entries
                    </span>

                    <span>
                        <?= e(ucfirst($item['status'])) ?>
                    </span>

                </a>

            <?php endforeach; ?>

        </aside>

    </div>


    <section class="journal-proof-note">

        <strong>What this proves - and what it does not</strong>

        <p>
            WorkReport preserves message order, timestamps,
            content hashes and a chained integrity hash.
            This helps show whether the journal record has
            changed. It does not by itself prove that every
            statement in the journal is true. Photos, messages,
            witnesses, company records and other independent
            evidence can strengthen the record.
        </p>

    </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
