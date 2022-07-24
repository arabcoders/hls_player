<?php

declare(strict_types=1);

use App\Bootstrap;

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    print 'Dependencies are missing.';
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

set_error_handler(function (int $number, mixed $error, mixed $file, int $line) {
    $errno = $number & error_reporting();
    if (0 === $errno) {
        return;
    }

    $message = trim(sprintf('%s: %s (%s:%d)', $number, $error, $file, $line));
    fwrite(STDERR, $message);

    exit(1);
});

set_exception_handler(function (Throwable $e) {
    $message = trim(sprintf("%s: %s (%s:%d).", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    fwrite(STDERR, $message);
    exit(1);
});

try {
    emitResponse((new Bootstrap())->onBoot()->run());
} catch (Throwable $e) {
    $message = trim(sprintf("%s: %s (%s:%d).", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    fwrite(STDERR, $message);
    if (!headers_sent()) {
        http_response_code(500);
    }
    exit(1);
}
