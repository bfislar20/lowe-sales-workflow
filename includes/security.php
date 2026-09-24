<?php
declare(strict_types=1);

/**
 * Security/error helpers for Lowe internal applications.
 *
 * Production-facing pages should log detailed exceptions server-side and show
 * users a generic message. Do not expose database, filesystem, SQL, or SMTP
 * exception text in the browser.
 */
function lowe_log_exception(Throwable $e, string $context): void {
    error_log('[Lowe Sales Workflow] '.$context.': '.$e->getMessage());
}

function lowe_safe_error(string $message='An unexpected error occurred. Please try again or contact Lowe Chemical support.'): string {
    return $message;
}
