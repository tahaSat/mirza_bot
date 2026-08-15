<?php
// Cron scripts must run from crontab (php CLI). Hitting them over HTTP occupies Apache
// workers for minutes (panel API calls) and wedges this 1 GB box.
if (PHP_SAPI !== 'cli') {
    http_response_code(204);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'cli-only';
    exit;
}
