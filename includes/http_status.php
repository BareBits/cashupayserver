<?php
/**
 * HTTP status helper.
 *
 * Standalone, http_response_code() is all that is needed. Under WordPress it
 * is not sufficient: WordPress queues a literal status line
 * ("HTTP/1.1 200 OK") via status_header() during its send_headers phase,
 * before it hands the request to the plugin. http_response_code() updates
 * PHP's internal response code but does NOT replace that already-queued header
 * line, so the 200 is what actually reaches the client.
 *
 * The practical damage is worst in the admin panel, whose JavaScript branches
 * on `response.ok`: with every error arriving as 200 it read failures —
 * rejected CSRF tokens, wrong passwords, validation errors — as successes.
 *
 * status_header() re-emits the status line with replace=true, which does
 * override the queued one. Call this instead of http_response_code() anywhere
 * a non-200 has to reach the client.
 */

if (!function_exists('cashupay_status')) {
    function cashupay_status(int $code): void {
        // Only defined once WordPress has loaded; absent standalone.
        if (function_exists('status_header')) {
            status_header($code);
        }
        http_response_code($code);
    }
}
