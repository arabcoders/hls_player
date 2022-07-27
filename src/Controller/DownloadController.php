<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attributes\Route\Get;
use App\Libs\HttpStatus;
use App\Responses\EmptyResponse;
use App\Responses\Response;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

#[Get(pattern: '/download/{path:.+}')]
final class DownloadController
{
    public const URL = '/download/';

    private string $basePath = '';

    public function __construct(private readonly LoggerInterface $logger)
    {
        if (null === ($basePath = env('VP_MEDIA_PATH'))) {
            throw new RuntimeException('No valid media path was given in ENV:VP_MEDIA_PATH.');
        }

        $this->basePath = $basePath;
    }

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (!($file = $args['path'] ?? null)) {
            return new EmptyResponse(HttpStatus::NOT_FOUND);
        }

        $fileSystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));

        if (null !== ($request->getHeader('if-modified-since')[0] ?? null)) {
            return new EmptyResponse(HttpStatus::NOT_MODIFIED, [
                'Cache-Control' => sprintf(
                    'public, max-age=%s',
                    time() + 31536000
                ),
            ]);
        }

        try {
            $userPath = rawurldecode($file);
            $isPartial = false;

            if (!$fileSystem->has($userPath)) {
                return message(sprintf('Invalid path was given. \'%s\'.', $userPath));
            }

            $mimeType = $fileSystem->mimeType($userPath);

            if (true === (bool)env('VP_SENDFILE')) {
                $sfHeader = env('VP_SENDFILE_HEADER', 'X-Accel-Redirect');
                $sfValue = env('VP_SENDFILE_BASE', '/') . $userPath;

                return new Response(status: HttpStatus::OK, headers: [
                    'Content-Transfer-Encoding' => 'binary',
                    'Content-Disposition' => sprintf('inline; filename="%s"', basename($userPath)),
                    'X-Accel-Buffering' => 'no',
                    'Content-Type' => '',
                    'Content-Length' => '',
                    'Last-Modified' => '',
                    'Pragma' => 'public',
                    'Cache-Control' => sprintf('public, max-age=%s', time() + 31536000),
                    'Expires' => sprintf('Expires: %s GMT', gmdate('D, d M Y H:i:s', time() + 31536000)),
                    $sfHeader => $sfValue,
                ]);
            }

            $fileSize = $fileSystem->fileSize($userPath);

            $start = 0;
            $end = $fileSize - 1;
            $length = $fileSize;

            $headers = [
                'Content-Type' => $mimeType,
                'Content-Disposition' => sprintf('inline; filename="%s"', basename($userPath)),
                'Accept-Ranges' => '0-' . $fileSize,
            ];

            if ($request->hasHeader('range')) {
                $isPartial = true;
                $range = $request->getHeaderLine('range');

                $c_end = $fileSize - 1;

                [, $range] = explode('=', $range, 2);

                if (str_contains($range, ',')) {
                    return new EmptyResponse(HttpStatus::REQUESTED_RANGE_NOT_SATISFIABLE, [
                        'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $fileSize)
                    ]);
                }

                if ('-' === $range) {
                    $c_start = $fileSize - (int)substr($range, 1);
                } else {
                    $range = explode('-', $range);
                    $c_start = $range[0];
                    $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $fileSize;
                }

                $c_end = ($c_end > ($fileSize - 1)) ? $fileSize - 1 : $c_end;

                if ($c_start > $c_end || $c_start > $fileSize - 1 || $c_end >= $fileSize) {
                    return new EmptyResponse(HttpStatus::REQUESTED_RANGE_NOT_SATISFIABLE, [
                        'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $fileSize)
                    ]);
                }

                $start = $c_start;
                $end = $c_end;
                $length = $end - $start + 1;
            }

            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $fileSize);
            $headers['Content-Length'] = $length;

            return new Response(
                $fileSystem->readStream($userPath),
                $isPartial ? HttpStatus::PARTIAL_CONTENT : HttpStatus::OK,
                $headers
            );
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return new EmptyResponse(HttpStatus::INTERNAL_SERVER_ERROR);
        }
    }


}
