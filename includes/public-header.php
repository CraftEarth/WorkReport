<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

ensure_auth_schema();

$publicUser = current_user();

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
>

<title><?= e($pageTitle ?? 'WorkReport') ?></title>

<meta
    name="description"
    content="<?= e($pageDescription ?? 'WorkReport helps workers preserve workplace documentation, organize evidence, build timelines, and understand how events connect over time.') ?>"
>

<meta name="application-name" content="WorkReport">
<meta name="referrer" content="no-referrer">
<meta name="format-detection" content="telephone=no">
<meta name="theme-color" content="#ffffff">
<meta name="color-scheme" content="light">

<link
    rel="stylesheet"
    href="assets/css/style.css"
>

</head>

<body class="public-site">

<header class="public-topbar">

    <div class="public-nav-shell">

        <a
            href="index.php"
            class="public-brand"
            aria-label="WorkReport home"
        >
            WorkReport
        </a>

        <nav class="public-nav" aria-label="Public navigation">

            <a href="index.php">Home</a>
            <a href="index.php#how-it-works">How It Works</a>
            <a href="index.php#security">Security</a>
            <a href="index.php#about">About</a>
            <a href="index.php#faq">FAQ</a>

            <a href="request-access.php">
                Request Access
            </a>

            <?php if ($publicUser): ?>

                <a
                    href="portal.php"
                    class="public-nav-portal"
                >
                    Open Portal
                </a>

                <a href="logout.php">
                    Sign Out
                </a>

            <?php else: ?>

                <a
                    href="login.php"
                    class="public-nav-portal"
                >
                    Client Portal
                </a>

            <?php endif; ?>

        </nav>

    </div>

</header>

<main class="public-main">