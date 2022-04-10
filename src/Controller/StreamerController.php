<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attributes\Route\Get;
use App\Libs\HttpStatus;
use App\Responses\EmptyResponse;
use JsonException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Twig\Environment;

#[Get(pattern: '/streamer/{path:.+}')]
final class StreamerController
{
    public const URL = '/streamer/';

    private string $basePath;

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

        $fileSystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));
        $userPath = rawurldecode($file);

        try {
            if (!$fileSystem->has($userPath)) {
                return message(sprintf('Invalid path was given. \'%s\'.', $userPath));
            }

            $file = $this->basePath . '/' . $userPath;

            try {
                $ffprobe = ffprobe_file($file);
            } catch (RuntimeException|JsonException $e) {
                return message($e->getMessage(), opts: [
                    'httpcode' => HttpStatus::BAD_REQUEST
                ]);
            }

            $data = $this->analyzeFile($ffprobe, $file);

            $mimeType = $fileSystem->mimeType($userPath);

            if (!str_starts_with($mimeType, 'video/')) {
                return message(
                    sprintf(
                        'Unable to analyze file \'%s\' as it has \'%s\' mimetype.',
                        basename($userPath),
                        $mimeType
                    )
                );
            }
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return new EmptyResponse(HttpStatus::INTERNAL_SERVER_ERROR);
        }


        $arr = [
            'IS_AJAX' => $request->hasHeader('x-fetch-request') || array_key_exists('test', $request->getQueryParams()),
            'page' => [
                'file' => [
                    'path' => dirname($userPath),
                    'name' => basename($userPath),
                ],
                'stream' => PlayController::URL . urlSafe($userPath),
                'direct' => DownloadController::URL . urlSafe($userPath),
                'info' => $data,
            ],
            'context' => [
                'breadcrumb' => IndexController::makeBreadcrumb($userPath),
                'header' => [
                    'title' => basename(rawurldecode($userPath)),
                ],
            ],
        ];

        return view($this->response, $this->renderer, __CLASS__, $arr);
    }

    private function analyzeFile(array $ffprobe, string $path): array
    {
        $arr = [
            'video' => [],
            'audio' => [],
            'subtitle' => [],
            'external' => [],
            'container' => [
                'format' => [
                    'short' => ag($ffprobe, 'format.format_name'),
                    'long' => ag($ffprobe, 'format.format_long_name'),
                ],
                'start_time' => ag($ffprobe, 'format.start_time'),
                'duration' => ag($ffprobe, 'format.duration'),
                'size' => ag($ffprobe, 'format.size'),
                'bit_rate' => ag($ffprobe, 'format.bit_rate'),
            ],
        ];

        $videoSid = $audioSid = $subSid = 0;

        foreach (ag($ffprobe, 'streams', []) as $stream) {
            $streamIndex = ag($stream, 'index');
            $streamType = ag($stream, 'codec_type');

            if ('video' !== $streamType && 'audio' !== $streamType && 'subtitle' !== $streamType) {
                continue;
            }

            $ref = [];

            if ('video' === $streamType) {
                $arr['video'][$videoSid] = [
                    'ref' => [
                        'type' => $videoSid,
                    ],
                    'pix_fmt' => ag($stream, 'pix_fmt'),
                    'profile' => ag($stream, 'profile'),
                    'width' => ag($stream, 'width'),
                    'height' => ag($stream, 'height'),
                ];
                $ref = &$arr['video'][$videoSid];
                $videoSid++;
            }

            if ('audio' === $streamType) {
                $arr['audio'][$audioSid] = [
                    'ref' => [
                        'type' => $audioSid,
                    ],
                    'channels' => ag($stream, 'channels'),
                    'title' => ag($stream, 'tags.title', null),
                    'language' => strtoupper(ag($stream, 'tags.language', 'UND')),
                ];

                $ref = &$arr['audio'][$audioSid];
                $audioSid++;
            }

            if ('subtitle' === $streamType) {
                $arr['subtitle'][$subSid] = [
                    'ref' => [
                        'type' => $subSid,
                    ],
                    'kind' => 'internal',
                    'title' => ag($stream, 'tags.title', null),
                    'language' => strtolower(ag($stream, 'tags.language', 'und')),
                    'forced' => (bool)ag($stream, 'disposition.forced', false),
                ];

                $ref = &$arr['subtitle'][$subSid];

                $subSid++;
            }

            $ref['ref']['container'] = $streamIndex;

            $ref['codec'] = [
                'short' => ag($stream, 'codec_name'),
                'long' => ag($stream, 'codec_long_name'),
            ];

            $ref['default'] = (bool)ag($stream, 'disposition.default');

            unset($ref);
        }

        $subs = '#(' . env('VP_MEDIA_SUBTITLE', 'srt|ass|ssa|smi|sub') . ')#i';

        foreach (findSimilarFiles($path) as $extra) {
            if (1 !== preg_match($subs, getExtension($extra))) {
                continue;
            }

            preg_match('#\.(\w{2,3})\.\w{3}$#', $extra, $lang);

            $arr['subtitle'][] = [
                'kind' => 'external',
                'ref' => [
                    'path' => str_replace(rtrim($this->basePath, DIRECTORY_SEPARATOR), '', $extra),
                ],
                'title' => 'External',
                'language' => strtolower($lang[1] ?? 'und'),
                'forced' => false,
                'codec' => [
                    'short' => afterLast($extra, '.'),
                    'long' => 'text/' . afterLast($extra, '.'),
                ],
            ];

            $arr['external'][] = [
                'path' => $extra,
                'title' => 'External',
                'language' => strtolower($lang[1] ?? 'und'),
                'forced' => false,
                'codec' => [
                    'short' => afterLast($extra, '.'),
                    'long' => 'text/' . afterLast($extra, '.'),
                ],
            ];
        }

        return $arr;
    }
}
