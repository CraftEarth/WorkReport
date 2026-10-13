<?php

require_once __DIR__ . '/includes/media-evidence.php';

ensure_media_evidence_schema();

if (
    !in_array(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ['GET', 'HEAD'],
        true
    )
) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);

$attachment = media_require_worker_attachment($id);

/*
 * media_require_worker_attachment() scopes through the report and
 * currently selected worker. A guessed attachment ID is not enough.
 */
media_stream_file($attachment);