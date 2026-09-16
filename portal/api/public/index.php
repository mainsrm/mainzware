<?php
declare(strict_types=1);

// Set before the autoload require so a failure in there cannot leak either.
// Without this, whether an uncaught PDOException reaches the client as SQL text,
// bound values and absolute filesystem paths is decided solely by the host's
// php.ini -- a control that does not live in this repo.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/../vendor/autoload.php';

use MainzWorld\Router;

// error_log() alone is not enough: it depends on the host's php.ini/pool
// `error_log` path plus php-fpm's `catch_workers_output`. Verified on prod:
// catch_workers_output is off and no error_log path is set, so a bare
// error_log() call is currently a silent no-op there -- not leaked, but not
// recorded either. syslog() bypasses that ini chain entirely and is verified
// (via `logger` -> journalctl) to reach journald on the prod host.
openlog('mainzware-api', LOG_PID, LOG_USER);

$logException = static function (Throwable $e): void {
    $message = sprintf(
        'Uncaught %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    error_log($message);
    syslog(LOG_ERR, $message);
};

$emitServerError = static function (): void {
    // A partially-emitted response can't be turned into a 500, but it must still
    // not have a stack trace appended to it.
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal server error.']);
};

set_exception_handler(static function (Throwable $e) use ($logException, $emitServerError): void {
    $logException($e);
    $emitServerError();
});

// set_exception_handler never sees E_ERROR (OOM, fatal type errors), which would
// otherwise return HTTP 200 with an empty or half-written body.
register_shutdown_function(static function () use ($emitServerError): void {
    $err = error_get_last();
    if ($err !== null && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0) {
        syslog(LOG_ERR, sprintf('Fatal error: %s in %s:%d', $err['message'], $err['file'], $err['line']));
        $emitServerError();
    }
});

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

Router::dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
