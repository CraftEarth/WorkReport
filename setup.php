<?php

declare(strict_types=1);

/*
 * WorkReport production bootstrap is intentionally unavailable
 * through the web application.
 *
 * Future fresh-install bootstrap operations must use an explicit,
 * controlled deployment/CLI procedure.
 */

http_response_code(403);

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

exit('WorkReport web setup is disabled.');
