<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attributes\Route\Get;
use App\Libs\HttpStatus;
use App\Responses\EmptyResponse;
use App\Responses\HtmlResponse;
use App\Responses\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

#[Get(pattern: '/assets/{hash:[\w]{8,10}}/{file:.+}')]
final class AssetsController
{
    public const URL = '/assets/';

    private string $path;

    public function __construct(private LoggerInterface $logger)
    {
        $this->path = realpath(__DIR__ . '/../../assets');
    }

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (!($file = $args['file'] ?? null)) {
            return new EmptyResponse(HttpStatus::NOT_FOUND);
        }

        if (null !== ($request->getHeader('if-modified-since')[0] ?? null)) {
            return new EmptyResponse(HttpStatus::NOT_MODIFIED, [
                'Cache-Control' => sprintf(
                    'public, max-age=%s',
                    time() + 31536000
                ),
            ]);
        }

        $file = str_replace('..', '', $file);

        try {
            $realFile = realpath($this->path . '/' . $file);

            if (false === $realFile) {
                return new EmptyResponse(HttpStatus::BAD_REQUEST);
            }

            if (false === str_starts_with($realFile, $this->path)) {
                return new EmptyResponse(HttpStatus::BAD_REQUEST);
            }

            if (!file_exists($realFile)) {
                return new HtmlResponse('404 - NOT FOUND.', HttpStatus::NOT_FOUND);
            }

            $mimeType = null;

            if (str_ends_with($realFile, 'css')) {
                $mimeType = 'text/css';
            }

            if (null === $mimeType && str_ends_with($realFile, 'js')) {
                $mimeType = 'text/javascript';
            }

            return new Response(Stream::create(fopen($realFile, 'rb')), HttpStatus::OK, [
                'Content-Disposition' => sprintf('inline; filename="%s"', basename($file)),
                'Content-Type' => $mimeType ?? mime_content_type($realFile),
                'Content-Length' => filesize($realFile),
                'Pragma' => 'public',
                'Cache-Control' => sprintf('public, max-age=%s', time() + 31536000),
                'Expires' => sprintf('Expires: %s GMT', gmdate('D, d M Y H:i:s', time() + 31536000)),
            ]);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return new EmptyResponse(HttpStatus::INTERNAL_SERVER_ERROR);
        }
    }
}
