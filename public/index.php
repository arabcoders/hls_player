<?php

declare(strict_types=1);

use App\Bootstrap;

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    print 'System not yet ready. Composer dependencies is not installed.';
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

set_error_handler(function (int $number, mixed $error, mixed $file, int $line) {
    $errno = $number & error_reporting();
    if (0 === $errno) {
        return;
    }

    $message = trim(sprintf('%s: %s (%s:%d)', $number, $error, $file, $line));

    if (env('IN_DOCKER')) {
        fwrite(STDERR, $message);
    } else {
        syslog(LOG_ERR, $message);
    }

    exit(1);
});

set_exception_handler(function (Throwable $e) {
    $message = trim(sprintf("%s: %s (%s:%d).", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    if (env('IN_DOCKER')) {
        fwrite(STDERR, $message);
    } else {
        syslog(LOG_ERR, $message);
    }

    exit(1);
});

emitResponse((new Bootstrap())->onBoot()->run());
