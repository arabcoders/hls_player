<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attributes\Route\Get;
use App\Libs\HttpStatus;
use App\Responses\EmptyResponse;
use App\Responses\Response;
use JsonException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

#[Get(pattern: '/m3u8/{path:.+}')]
final class M3u8Controller
{
    public const SEGMENT_DUR = 6.000;

    public const URL = '/m3u8/';

    private string $basePath;

    public function __construct(private LoggerInterface $logger)
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
        $userPath = rawurldecode($file);

        try {
            if (!$fileSystem->has($userPath)) {
                return message(sprintf('Invalid path was given. \'%s\'.', $userPath));
            }

            $file = $this->basePath . '/' . $userPath;

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

            try {
                $ffprobe = ffprobe_file($file);
                $duration = ag($ffprobe, 'format.duration');
            } catch (RuntimeException|JsonException $e) {
                return message($e->getMessage(), opts: [
                    'httpcode' => HttpStatus::BAD_REQUEST
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return new EmptyResponse(HttpStatus::INTERNAL_SERVER_ERROR);
        }

        $segmentParams = $request->getQueryParams();
        $segmentUrl = SegmentsController::URL . "{segment_index}/" . urlSafe($userPath);
        $hasParameters = !empty((new Uri($segmentUrl))->getQuery());

        $m3u8 = "#EXTM3U\n";
        $m3u8 .= "#EXT-X-VERSION:3\n";
        $m3u8 .= "#EXT-X-TARGETDURATION:" . self::SEGMENT_DUR . "\n";
        $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        $m3u8 .= "#EXT-X-PLAYLIST-TYPE:VOD\n";

        $segmentSize = number_format(self::SEGMENT_DUR, 6);
        $splits = (int)ceil($duration / self::SEGMENT_DUR);

        for ($i = 0; $i < $splits; $i++) {
            if (($i + 1) === $splits) {
                $segmentParams['sd'] = number_format($duration - (($i * self::SEGMENT_DUR)), 6);
                $m3u8 .= "#EXTINF:" . $segmentParams['sd'] . ", nodesc\n";
            } else {
                $m3u8 .= "#EXTINF:$segmentSize, nodesc\n";
            }

            $m3u8 .= str_replace('{segment_index}', (string)$i, $segmentUrl);

            if (!empty($segmentParams)) {
                $m3u8 .= ($hasParameters ? '&' : '?') . http_build_query($segmentParams);
            }

            $m3u8 .= "\n";
        }

        $m3u8 .= "#EXT-X-ENDLIST\n";

        $response = new Response(body: Stream::create($m3u8), status: HttpStatus::OK, headers: [
            'Content-Type' => 'application/x-mpegURL',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
        ]);

        return $response->withAddedHeader('Content-Size', (string)$response->getBody()->getSize());
    }
}
