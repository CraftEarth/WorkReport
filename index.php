<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

ensure_auth_schema();

if (user_count() === 0) {
    header('Location: setup.php');
    exit;
}

$pageTitle = 'WorkReport - Build the Record';
$pageDescription =
    'WorkReport is a private workplace documentation system that helps workers preserve source records, organize evidence, build timelines, and understand how events connect over time.';

require __DIR__ . '/includes/public-header.php';

?>

<section class="public-hero">

    <div class="public-hero-copy">

        <span class="public-eyebrow">
            PRIVATE WORKPLACE DOCUMENTATION
        </span>

        <h1>
            Don't just remember what happened.
            <span>Build the record.</span>
        </h1>

        <p class="public-hero-lead">
            WorkReport helps turn daily workplace experiences into an
            organized, traceable record - preserving original statements,
            evidence, timelines, people, places, machines, and important
            changes over time.
        </p>

        <div class="public-hero-actions">

            <?php if ($publicUser): ?>

                <a
                    href="portal.php"
                    class="button button-primary public-button-large"
                >
                    Open Client Portal
                </a>

            <?php else: ?>

                <a
                    href="login.php"
                    class="button button-primary public-button-large"
                >
                    Client Portal
                </a>

            <?php endif; ?>

            <a
                href="#how-it-works"
                class="button button-secondary public-button-large"
            >
                See How It Works
            </a>

        </div>

        <p class="public-invite-note">
            WorkReport is currently invite-only. There is no public
            self-registration.
        </p>

    </div>

    <div class="public-hero-record" aria-label="WorkReport record example">

        <div class="public-record-topline">
            <span>WORKPLACE RECORD</span>
            <strong>Source preserved</strong>
        </div>

        <div class="public-record-row">
            <span>Daily statement</span>
            <strong>Original wording retained</strong>
        </div>

        <div class="public-record-row">
            <span>Evidence</span>
            <strong>Connected to the source</strong>
        </div>

        <div class="public-record-row">
            <span>Timeline</span>
            <strong>Events organized over time</strong>
        </div>

        <div class="public-record-row">
            <span>Structured review</span>
            <strong>Facts kept separate from inference</strong>
        </div>

        <div class="public-record-status">
            <span>Record integrity</span>
            <strong>Traceable</strong>
        </div>

    </div>

</section>


<section class="public-trust-strip">

    <div>
        <strong>Capture</strong>
        <span>Document what happened while details are fresh.</span>
    </div>

    <div>
        <strong>Preserve</strong>
        <span>Keep original statements and supporting evidence connected.</span>
    </div>

    <div>
        <strong>Connect</strong>
        <span>See people, places, events, and reports in context.</span>
    </div>

    <div>
        <strong>Review</strong>
        <span>Understand what the record supports - and what it does not.</span>
    </div>

</section>


<section
    class="public-section"
    id="how-it-works"
>

    <div class="public-section-heading">
        <span>HOW IT WORKS</span>

        <h2>
            A workplace record that becomes more useful over time.
        </h2>

        <p>
            WorkReport is designed around a simple idea: the original
            record matters. Daily documentation remains connected to the
            structured information built from it.
        </p>
    </div>

    <div class="public-feature-grid">

        <article class="public-feature">
            <span>01</span>
            <h3>Document the day</h3>
            <div class="public-feature-image">
                <img
                    src="assets/images/how-it-works/01-document-the-day.png"
                    alt=""
                    loading="lazy"
                    decoding="async"
                >
            </div>
            <p>
                Record workplace events in natural language without needing
                to know legal terminology or case-management language.
            </p>
        </article>

        <article class="public-feature">
            <span>02</span>
            <h3>Preserve the source</h3>
            <div class="public-feature-image">
                <img
                    src="assets/images/how-it-works/02-preserve-the-source.png"
                    alt=""
                    loading="lazy"
                    decoding="async"
                >
            </div>
            <p>
                Original statements remain the source record instead of
                being silently replaced by generated summaries.
            </p>
        </article>

        <article class="public-feature">
            <span>03</span>
            <h3>Organize what matters</h3>
            <div class="public-feature-image">
                <img
                    src="assets/images/how-it-works/03-organize-what-matters.png"
                    alt=""
                    loading="lazy"
                    decoding="async"
                >
            </div>
            <p>
                People, work areas, machines, categories, evidence, and
                documented events can be connected to the report.
            </p>
        </article>

        <article class="public-feature">
            <span>04</span>
            <h3>Build the timeline</h3>
            <div class="public-feature-image">
                <img
                    src="assets/images/how-it-works/04-build-the-timeline.png"
                    alt=""
                    loading="lazy"
                    decoding="async"
                >
            </div>
            <p>
                Separate days become a chronological record that can reveal
                repetition, changes, sequences, and missing information.
            </p>
        </article>

        <article class="public-feature">
            <span>05</span>
            <h3>Review the record</h3>
            <div class="public-feature-image">
                <img
                    src="assets/images/how-it-works/05-review-the-record.png"
                    alt=""
                    loading="lazy"
                    decoding="async"
                >
            </div>
            <p>
                WorkReport can help surface recurring information while
                keeping allegations, facts, inferences, and unknowns distinct.
            </p>
        </article>

        <article class="public-feature">
            <span>06</span>
            <h3>Return to the evidence</h3>
            <div class="public-feature-image">
                <img
                    src="assets/images/how-it-works/06-return-to-the-evidence.png"
                    alt=""
                    loading="lazy"
                    decoding="async"
                >
            </div>
            <p>
                Important findings are designed to remain traceable back to
                reports, events, original statements, and supporting files.
            </p>
        </article>

    </div>

</section>


<section class="public-section public-section-alt">

    <div class="public-split">

        <div>
            <span class="public-eyebrow">
                BUILT AROUND THE RECORD
            </span>

            <h2>
                WorkReport does not need the worker to know the right label.
            </h2>

            <p>
                A worker should be able to describe what happened in normal
                language. The system's job is to organize the record without
                upgrading assumptions into facts or turning every difficult
                workplace event into a legal conclusion.
            </p>
        </div>

        <div class="public-principles">

            <div>
                <strong>Facts stay facts.</strong>
                <span>
                    Reported statements, confirmed information, inference,
                    and unknowns remain distinguishable.
                </span>
            </div>

            <div>
                <strong>Original wording remains available.</strong>
                <span>
                    Structured analysis does not replace the worker's source
                    statement.
                </span>
            </div>

            <div>
                <strong>Context can change.</strong>
                <span>
                    New evidence can strengthen, weaken, clarify, or
                    contradict an earlier understanding.
                </span>
            </div>

            <div>
                <strong>Frequency is not guilt.</strong>
                <span>
                    A person appearing often in documentation does not itself
                    establish wrongdoing.
                </span>
            </div>

        </div>

    </div>

</section>


<!-- WORKREPORT_SECURITY_PROTOCOL_V1 -->
<section
    class="public-section"
    id="security"
>

    <div class="public-section-heading">
        <span>PRIVACY & SECURITY</span>

        <h2>
            Sensitive workplace records deserve a security-first design.
        </h2>

        <p>
            WorkReport is being built as a private record system rather than
            a social platform. Public information is separated from the
            authenticated client portal, and private records are intended to
            remain accessible only to authorized users.
        </p>
    </div>

    <div class="public-security-protocol">

        <article>
            <span>01</span>
            <h3>Invite-only access</h3>
            <p>
                WorkReport does not currently provide public
                self-registration. Accounts are created or assigned through
                authorized administration.
            </p>
        </article>

        <article>
            <span>02</span>
            <h3>Authenticated private portal</h3>
            <p>
                Worker profiles, reports, journals, evidence, timelines,
                analysis, and administration remain behind authenticated
                application routes.
            </p>
        </article>

        <article>
            <span>03</span>
            <h3>Server-side authorization</h3>
            <p>
                Private record access is checked on the server rather than
                relying on hidden buttons or browser-only controls.
            </p>
        </article>

        <article>
            <span>04</span>
            <h3>Protected evidence storage</h3>
            <p>
                Evidence files are stored outside normal public browsing paths
                and are served through permission-checked application
                endpoints.
            </p>
        </article>

        <article>
            <span>05</span>
            <h3>Integrity records</h3>
            <p>
                Evidence and finalized records can use SHA-256 fingerprints
                to help detect file or record changes after preservation.
            </p>
        </article>

        <article>
            <span>06</span>
            <h3>Audit-preserving controls</h3>
            <p>
                Important record actions are designed to preserve history
                instead of silently rewriting sensitive source material.
            </p>
        </article>

    </div>

    <div class="public-security-boundary">

        <div>
            <strong>What this means</strong>

            <p>
                WorkReport uses layered controls and continues to undergo
                security hardening as the system develops.
            </p>
        </div>

        <div>
            <strong>What it does not mean</strong>

            <p>
                No software should be described as impossible to breach.
                WorkReport does not make that claim.
            </p>
        </div>

    </div>

    <p class="public-security-note">
        Additional production safeguards, deployment controls, tenant
        isolation testing, incident-response procedures, and independent
        security review are planned before broader public deployment.
    </p>

</section>


<section class="public-access-cta">

    <div>
        <span class="public-eyebrow">
            INTERESTED IN WORKREPORT?
        </span>

        <h2>
            Access remains invite-only.
        </h2>

        <p>
            If you would like to be considered for WorkReport access,
            submit a request. Requests are reviewed by an administrator and
            never create an account automatically.
        </p>
    </div>

    <div class="public-access-cta-actions">

        <a
            href="request-access.php"
            class="button button-primary public-button-large"
        >
            Request Access
        </a>

        <a
            href="login.php"
            class="button button-secondary public-button-large"
        >
            Existing Client Login
        </a>

    </div>

</section>

<section
    class="public-section public-section-alt"
    id="about"
>

    <div class="public-split public-about-split">

        <div>
            <span class="public-eyebrow">
                ABOUT WORKREPORT
            </span>

            <h2>
                The help when you didn't know you needed help.
            </h2>
        </div>

        <div>
            <p>
                WorkReport is being built to give ordinary workers a practical
                way to create an organized workplace record before individual
                days, conversations, documents, and events become difficult to
                reconstruct.
            </p>

            <p>
                The system is intended to preserve the record and help people
                review it more clearly. It is not designed to automatically
                decide that an employee, supervisor, or employer is right or
                wrong.
            </p>

            <p>
                WorkReport is not a law firm and does not create an
                attorney-client relationship or guarantee legal outcomes.
            </p>
        </div>

    </div>

</section>


<section
    class="public-section"
    id="faq"
>

    <div class="public-section-heading">
        <span>FAQ</span>
        <h2>Common questions</h2>
    </div>

    <div class="public-faq">

        <details>
            <summary>Can anyone create a WorkReport account?</summary>
            <p>
                No. The current system is invite-only. Authorized
                administrators create or assign access.
            </p>
        </details>

        <details>
            <summary>Is WorkReport a law firm?</summary>
            <p>
                No. WorkReport is a documentation and record-organization
                system. It does not provide a guaranteed legal conclusion or
                create an attorney-client relationship.
            </p>
        </details>

        <details>
            <summary>Does WorkReport change my original statement?</summary>
            <p>
                The design principle is that the worker's original statement
                remains the source record. Structured information is stored
                separately from that source.
            </p>
        </details>

        <details>
            <summary>Can WorkReport prove an allegation is true?</summary>
            <p>
                No. A statement or uploaded file does not become true merely
                because it is stored in WorkReport. The system can organize
                documentation, evidence, chronology, and corroborating or
                conflicting information for review.
            </p>
        </details>

        <details>
            <summary>Where do invited users sign in?</summary>
            <p>
                Use the Client Portal button. Existing invited users are sent
                to the private login area.
            </p>
        </details>

    </div>

</section>


<section class="public-portal-callout">

    <div>
        <span class="public-eyebrow">
            INVITED CLIENTS
        </span>

        <h2>Already have WorkReport access?</h2>

        <p>
            Enter the private client portal using the account provided to you.
        </p>
    </div>

    <?php if ($publicUser): ?>

        <a
            href="portal.php"
            class="button button-primary public-button-large"
        >
            Open Client Portal
        </a>

    <?php else: ?>

        <a
            href="login.php"
            class="button button-primary public-button-large"
        >
            Client Portal
        </a>

    <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/public-footer.php'; ?>