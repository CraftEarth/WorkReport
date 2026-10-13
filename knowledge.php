<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf(
        $_POST['_csrf'] ?? null
    );

    $type = $_POST['type'] ?? '';

    if ($type === 'person_alias') {

        $alias = trim($_POST['alias'] ?? '');
        $personId = (int) ($_POST['person_id'] ?? 0);

        if ($alias !== '' && $personId > 0) {

            $stmt = $pdo->prepare("
                INSERT INTO person_aliases (
                    alias,
                    person_id
                )
                VALUES (?, ?)
                ON CONFLICT(alias)
                DO UPDATE SET
                    person_id = excluded.person_id
            ");

            $stmt->execute([
                $alias,
                $personId
            ]);

            flash(
                'success',
                'Person alias saved.'
            );
        }
    }

    if ($type === 'place_alias') {

        $alias = trim($_POST['alias'] ?? '');
        $placeId = (int) ($_POST['place_id'] ?? 0);

        if ($alias !== '' && $placeId > 0) {

            $stmt = $pdo->prepare("
                INSERT INTO place_aliases (
                    alias,
                    place_id
                )
                VALUES (?, ?)
                ON CONFLICT(alias)
                DO UPDATE SET
                    place_id = excluded.place_id
            ");

            $stmt->execute([
                $alias,
                $placeId
            ]);

            flash(
                'success',
                'Place alias saved.'
            );
        }
    }

    if ($type === 'category_keyword') {

        $keyword = trim($_POST['keyword'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        if ($keyword !== '' && $categoryId > 0) {

            $stmt = $pdo->prepare("
                INSERT INTO category_keywords (
                    keyword,
                    category_id
                )
                VALUES (?, ?)
                ON CONFLICT(keyword)
                DO UPDATE SET
                    category_id = excluded.category_id
            ");

            $stmt->execute([
                $keyword,
                $categoryId
            ]);

            flash(
                'success',
                'Category keyword saved.'
            );
        }
    }

    redirect('knowledge.php');
}

$people = $pdo->query("
    SELECT *
    FROM people
    ORDER BY name
")->fetchAll();

$places = $pdo->query("
    SELECT *
    FROM places
    ORDER BY name
")->fetchAll();

$categories = $pdo->query("
    SELECT *
    FROM categories
    ORDER BY name
")->fetchAll();

$personAliases = $pdo->query("
    SELECT
        pa.alias,
        p.name
    FROM person_aliases pa
    INNER JOIN people p
        ON p.id = pa.person_id
    ORDER BY pa.alias
")->fetchAll();

$placeAliases = $pdo->query("
    SELECT
        pa.alias,
        p.name
    FROM place_aliases pa
    INNER JOIN places p
        ON p.id = pa.place_id
    ORDER BY pa.alias
")->fetchAll();

$categoryKeywords = $pdo->query("
    SELECT
        ck.keyword,
        c.name
    FROM category_keywords ck
    INNER JOIN categories c
        ON c.id = ck.category_id
    ORDER BY c.name, ck.keyword
")->fetchAll();

$pageTitle = 'Local Knowledge';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>
        <h1>Local Knowledge</h1>

        <p>
            Teach the analyzer names, shorthand and phrases used at work.
        </p>
    </div>

</section>


<section class="panel">

    <h2>Person Alias</h2>

    <p class="help-text">
        Example: Brent → Brent Knight
    </p>

    <?php if (!$people): ?>

        <p>
            Analyze at least one report first so people exist in the database.
        </p>

    <?php else: ?>

        <form method="post" class="knowledge-form">
        <?= csrf_field() ?>

            <input
                type="hidden"
                name="type"
                value="person_alias"
            >

            <input
                type="text"
                name="alias"
                placeholder="Alias or first name"
                required
            >

            <select
                name="person_id"
                required
            >

                <option value="">
                    Choose person...
                </option>

                <?php foreach ($people as $person): ?>

                    <option
                        value="<?= (int) $person['id'] ?>"
                    >
                        <?= e($person['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button
                class="button button-primary"
                type="submit"
            >
                Save Alias
            </button>

        </form>

    <?php endif; ?>


    <?php if ($personAliases): ?>

        <div class="knowledge-list">

            <?php foreach ($personAliases as $row): ?>

                <div>
                    <strong>
                        <?= e($row['alias']) ?>
                    </strong>

                    <span>→</span>

                    <?= e($row['name']) ?>
                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<section class="panel">

    <h2>Place / Machine Alias</h2>

    <p class="help-text">
        Example: Ford → Ford Service
    </p>

    <?php if (!$places): ?>

        <p>
            Analyze at least one report first so places exist in the database.
        </p>

    <?php else: ?>

        <form method="post" class="knowledge-form">
        <?= csrf_field() ?>

            <input
                type="hidden"
                name="type"
                value="place_alias"
            >

            <input
                type="text"
                name="alias"
                placeholder="Short name"
                required
            >

            <select
                name="place_id"
                required
            >

                <option value="">
                    Choose place...
                </option>

                <?php foreach ($places as $place): ?>

                    <option
                        value="<?= (int) $place['id'] ?>"
                    >
                        <?= e($place['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button
                class="button button-primary"
                type="submit"
            >
                Save Alias
            </button>

        </form>

    <?php endif; ?>


    <?php if ($placeAliases): ?>

        <div class="knowledge-list">

            <?php foreach ($placeAliases as $row): ?>

                <div>
                    <strong>
                        <?= e($row['alias']) ?>
                    </strong>

                    <span>→</span>

                    <?= e($row['name']) ?>
                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<section class="panel">

    <h2>Category Keyword</h2>

    <p class="help-text">
        Teach the analyzer that a phrase belongs to a particular category.
        Example: "sat waiting for material" → Waiting on Parts.
    </p>

    <?php if (!$categories): ?>

        <p>
            Analyze at least one report first so categories exist.
        </p>

    <?php else: ?>

        <form method="post" class="knowledge-form">
        <?= csrf_field() ?>

            <input
                type="hidden"
                name="type"
                value="category_keyword"
            >

            <input
                type="text"
                name="keyword"
                placeholder="Phrase or keyword"
                required
            >

            <select
                name="category_id"
                required
            >

                <option value="">
                    Choose category...
                </option>

                <?php foreach ($categories as $category): ?>

                    <option
                        value="<?= (int) $category['id'] ?>"
                    >
                        <?= e($category['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button
                class="button button-primary"
                type="submit"
            >
                Save Keyword
            </button>

        </form>

    <?php endif; ?>


    <?php if ($categoryKeywords): ?>

        <div class="knowledge-list">

            <?php foreach ($categoryKeywords as $row): ?>

                <div>
                    <strong>
                        <?= e($row['keyword']) ?>
                    </strong>

                    <span>→</span>

                    <?= e($row['name']) ?>
                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>