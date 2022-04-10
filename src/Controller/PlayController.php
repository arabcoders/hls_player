<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attributes\Route\Get;
use App\Libs\HttpStatus;
use App\Responses\EmptyResponse;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Twig\Environment;

#[Get(pattern: '/play/{path:.+}')]
final class PlayController
{
    public const URL = '/play/';

    private string $basePath = '';

    public function __construct(
        private ResponseInterface $response,
        private Environment $renderer,
        private LoggerInterface $logger
    ) {
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

        try {
            $fileSystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));
            $userPath = rawurldecode($file);

            if (!$fileSystem->has($userPath)) {
                return message(sprintf('Invalid path was given. \'%s\'.', $userPath));
            }
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return new EmptyResponse(HttpStatus::INTERNAL_SERVER_ERROR);
        }

        $arr = [
            'page' => [
                'title' => IndexController::makeShortName(beforeLast(basename($userPath), '.')),
                'download' => DownloadController::URL . urlSafe($userPath),
                'hls' => M3u8Controller::URL . urlSafe($userPath) . '?' . http_build_query(
                        $request->getQueryParams()
                    ),
            ],
        ];

        return view($this->response, $this->renderer, __CLASS__, $arr);
    }
}
