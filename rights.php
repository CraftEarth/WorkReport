<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/legal.php';

require_login();

$worker = current_worker();

$stateCode =
    $worker['state_code']
    ?? 'KY';

$resources =
    legal_resources_for_state(
        $stateCode
    );

$pageTitle = 'Know Your Rights';

require __DIR__ . '/includes/header.php';

?>

<div class="page-title-row">

    <div>

        <h1>Know Your Rights at Work</h1>

        <p>
            General workplace information and official resources.
        </p>

    </div>

</div>


<section class="rights-notice">

    <strong>
        Educational information only
    </strong>

    <span>
        These resources do not determine whether a law
        has been violated and are not a substitute for
        advice from a qualified attorney.
    </span>

</section>


<section class="panel">

    <div class="section-title">

        <div>

            <h2>
                Kentucky Workplace Resources
            </h2>

            <span>
                State and federal information
            </span>

        </div>

    </div>


    <div class="rights-list">

    <?php foreach ($resources as $resource): ?>

        <article class="rights-row">

            <div class="rights-topic">

                <?= e(
                    $resource['topic']
                ) ?>

            </div>


            <div class="rights-content">

                <strong>
                    <?= e(
                        $resource['title']
                    ) ?>
                </strong>

                <p>
                    <?= e(
                        $resource['summary']
                    ) ?>
                </p>

                <span>
                    Source:
                    <?= e(
                        $resource['source_name']
                    ) ?>
                </span>

            </div>


            <div>

                <a
                    href="<?= e(
                        $resource['source_url']
                    ) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="button button-secondary"
                >
                    Official Resource
                </a>

            </div>

        </article>

    <?php endforeach; ?>

    </div>

</section>


<section class="info-callout">

    <div>

        <h2>
            Are you documenting a problem at work?
        </h2>

        <p>
            Record facts while they are fresh:
            dates, times, people involved, conversations,
            work assignments, discipline, schedules,
            photographs and supporting documents.
        </p>

    </div>

    <a
        href="new-report.php"
        class="button button-primary"
    >
        Document Today
    </a>

</section>


<section class="lawyer-callout">

    <div>

        <span class="lawyer-eyebrow">
            LEGAL RESOURCES
        </span>

        <h2>
            Need help understanding what happened?
        </h2>

        <p>
            Your documentation may help a qualified attorney
            evaluate workplace discrimination, retaliation,
            leave-related issues or other employment concerns.
        </p>

    </div>

    <a
        href="https://kybar.org/For-Public/Lawyer-Referral-Services"
        target="_blank"
        rel="noopener noreferrer"
        class="button button-secondary"
    >
        Find a Kentucky Lawyer
    </a>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>