<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attributes\Route\Get;
use App\Libs\HttpStatus;
use App\Responses\EmptyResponse;
use App\Responses\Response;
use App\Responses\TextResponse;
use Exception;
use JsonException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

#[Get('/segments/{segment}/{path:.*}')]
final class SegmentsController
{
    private array $overlay = [
        'hdmv_pgs_subtitle',
        'dvd_subtitle',
    ];

    public const URL = '/segments/';

    private string $basePath;

    public function __construct(private LoggerInterface $logger)
    {
        if (null === ($basePath = env('VP_MEDIA_PATH'))) {
            throw new RuntimeException('No valid media path was given in ENV:VP_MEDIA_PATH.');
        }

        $this->basePath = $basePath;
    }

    /**
     * @throws Exception
     */
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (null === ($file = $args['path'] ?? null)) {
            return new TextResponse('e1', HttpStatus::NOT_FOUND);
        }

        if (null === ($segment = $args['segment'] ?? null)) {
            return new TextResponse('e2', HttpStatus::NOT_FOUND);
        }

        $segment = (int)$segment;

        $params = $request->getQueryParams();

        $fileSystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));
        $userPath = rawurldecode($file);

        try {
            if (!$fileSystem->has($userPath)) {
                return message(sprintf('Invalid path was given. \'%s\'.', $userPath));
            }

            $file = $this->basePath . '/' . $userPath;
            $realPath = $file;

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
            } catch (RuntimeException|JsonException $e) {
                return message($e->getMessage(), opts: [
                    'httpcode' => HttpStatus::BAD_REQUEST
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return new EmptyResponse(HttpStatus::INTERNAL_SERVER_ERROR);
        }

        $subInc = $audioInc = 0;
        $audioIndexer = [];
        $subIndexer = [];
        $subCodec = [];
        $defaultAudio = null;

        foreach (ag($ffprobe, 'streams', []) as $stream) {
            if ('audio' !== ag($stream, 'codec_type') && 'subtitle' !== ag($stream, 'codec_type')) {
                continue;
            }

            if ('audio' === ag($stream, 'codec_type')) {
                $audioIndex = ag($stream, 'index');

                $audioIndexer[$audioIndex] = $audioInc;

                if (1 === (int)ag($stream, 'disposition.default')) {
                    $defaultAudio = ag($stream, 'index');
                }

                $audioInc++;
            }

            if ('subtitle' === ag($stream, 'codec_type')) {
                $subIndex = ag($stream, 'index');

                $subIndexer[$subIndex] = $subInc;
                $subCodec[$subIndex] = ag($stream, 'codec_name');

                $subInc++;
            }
        }

        $audio = ag($params, 'audio');
        $subtitle = ag($params, 'subtitle');

        if (null !== ($external = ag($params, 'external', null))) {
            $external = rawurldecode((string)$external);

            try {
                if (!$fileSystem->has($external)) {
                    $message = sprintf('Path not found. \'%s\'.', $external);
                    return message($message, opts: [
                        'httpcode' => HttpStatus::NOT_FOUND,
                        'headers' => [
                            'Access-Control-Allow-Origin' => '*',
                        ]
                    ]);
                }
            } catch (FilesystemException $e) {
                return message($e->getMessage(), opts: [
                    'httpcode' => HttpStatus::BAD_REQUEST,
                    'headers' => [
                        'Access-Control-Allow-Origin' => '*',
                    ]
                ]);
            }

            $external = $this->basePath . '/' . $external;
        }

        if (null !== $subtitle && !array_key_exists((int)$subtitle, $subIndexer)) {
            $message = sprintf('Invalid subtitle stream id \'%d\' was given.', $subtitle);
            return message($message, opts: [
                'httpcode' => HttpStatus::BAD_REQUEST,
                'headers' => [
                    'Access-Control-Allow-Origin' => '*',
                ]
            ]);
        }

        if (null !== $audio && !array_key_exists((int)$audio, $audioIndexer)) {
            $message = sprintf('Invalid audio stream id \'%d\' was given.', $subtitle);
            return message($message, opts: [
                'httpcode' => HttpStatus::BAD_REQUEST,
                'headers' => [
                    'Access-Control-Allow-Origin' => '*',
                ]
            ]);
        }

        $cmd = [
            'ffmpeg',
            '-xerror',
            '-hide_banner',
            '-loglevel',
            'error',
        ];

        if ('h264_vaapi' === ag($params, 'video_codec')) {
            $cmd[] = '-hwaccel';
            $cmd[] = 'vaapi';
            $cmd[] = '-hwaccel_device';
            $cmd[] = '/dev/dri/renderD128';
            $cmd[] = '-hwaccel_output_format';
            $cmd[] = 'vaapi';
        }

        $tmpSubFile = null;
        $tmpVidFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ffmpeg-' . bin2hex(random_bytes(8));

        if (!file_exists($tmpVidFile)) {
            symlink($realPath, $tmpVidFile);
        }

        // -- segments timing.
        $cmd[] = '-ss';
        $cmd[] = $segment === 0 ? 0 : (M3u8Controller::SEGMENT_DUR * $segment);
        $cmd[] = '-t';
        $cmd[] = ag($params, 'sd', M3u8Controller::SEGMENT_DUR);

        $cmd[] = '-copyts';

        $cmd[] = '-i';
        $cmd[] = 'file:' . $tmpVidFile;

        $cmd[] = '-pix_fmt';
        $cmd[] = 'yuv420p';
        $cmd[] = '-g';
        $cmd[] = '52';

        // -- video section. overlay picture based subs.
        if (empty($external) && null !== $subtitle && in_array($subCodec[(int)$subtitle], $this->overlay)) {
            $cmd[] = '-filter_complex';
            $cmd[] = "[0:v:0][0:s:" . $subIndexer[$subtitle] . "]overlay[v]";
            $cmd[] = '-map';
            $cmd[] = '[v]';
        } else {
            $cmd[] = '-map';
            $cmd[] = '0:v:0';
        }

        $cmd[] = '-strict';
        $cmd[] = '-2';

        $videoCodec = ag($params, 'video_codec', 'libx264');
        $cmd[] = '-codec:v';
        $cmd[] = $videoCodec;

        if ('copy' !== $videoCodec) {
            $cmd[] = '-crf';
            $cmd[] = ag($params, 'video_crf', '23');
            $cmd[] = '-preset:v';
            $cmd[] = ag($params, 'video_preset', 'fast');

            if (0 !== (int)ag($params, 'video_bitrate', 0)) {
                $cmd[] = '-b:v';
                $cmd[] = ag($params, 'video_bitrate', '192k');
            }

            $cmd[] = '-level';
            $cmd[] = ag($params, 'video_level', '4.1');
            $cmd[] = '-profile:v';
            $cmd[] = ag($params, 'video_profile', 'main');
        }

        // -- audio section.
        $cmd[] = '-map';
        if (null === $audio) {
            $cmd[] = '0:a:' . (null === $defaultAudio ? 0 : $audioIndexer[$defaultAudio]);
        } else {
            $cmd[] = '0:a:' . $audioIndexer[(int)$audio];
        }

        $audioCodec = ag($params, 'audio_codec', 'aac');
        $cmd[] = '-codec:a';
        $cmd[] = ag($params, 'audio_codec', 'aac');

        if ('copy' !== $audioCodec) {
            $cmd[] = '-b:a';
            $cmd[] = ag($params, 'audio_bitrate', '192k');
            $cmd[] = '-ar';
            $cmd[] = ag($params, 'audio_sampling_rate', '22050');
            $cmd[] = '-ac';
            $cmd[] = ag($params, 'audio_channels', '2');
        }

        // -- sub titles.
        if (null !== $external) {
            $tmpSubFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ffmpeg-external-sub-' . bin2hex(random_bytes(8));
            if (!file_exists($tmpSubFile)) {
                symlink($external, $tmpSubFile);
            }
            $cmd[] = '-vf';
            $cmd[] = "subtitles=$tmpSubFile";
        } elseif (null !== $subtitle && !in_array($subCodec[(int)$subtitle] ?? '', $this->overlay)) {
            $cmd[] = '-vf';
            $cmd[] = "subtitles=$tmpVidFile:stream_index=" . $subIndexer[(int)$subtitle];
        } else {
            $cmd[] = '-sn';
        }

        // -- output
        $cmd[] = '-muxdelay';
        $cmd[] = '0';
        $cmd[] = '-f';
        $cmd[] = 'mpegts';
        $cmd[] = 'pipe:1';

        try {
            $process = new Process($cmd);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                return message($process->getErrorOutput(), opts: [
                    'httpcode' => HttpStatus::INTERNAL_SERVER_ERROR,
                    'headers' => [
                        'Access-Control-Allow-Origin' => '*',
                        'X-FFmpeg' => $process->getCommandLine()
                    ],
                ]);
            }

            $response = new Response(body: Stream::create($process->getOutput()), status: HttpStatus::OK, headers: [
                'Content-Type' => 'video/mpegts',
                'Connection' => 'keep-alive',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Access-Control-Allow-Origin' => '*',
            ]);

            if ('dev' === env('VP_ENV')) {
                $response = $response->withHeader('X-FFmpeg', $process->getCommandLine());
            }

            return $response->withAddedHeader('Content-Size', (string)$response->getBody()->getSize());
        } catch (Throwable $e) {
            return message($e->getMessage(), opts: [
                'httpcode' => HttpStatus::INTERNAL_SERVER_ERROR,
                'headers' => [
                    'Access-Control-Allow-Origin' => '*',
                ],
            ]);
        } finally {
            if (file_exists($tmpVidFile) && is_link($tmpVidFile)) {
                unlink($tmpVidFile);
            }

            if (null !== $tmpSubFile && file_exists($tmpSubFile) && is_link($tmpSubFile)) {
                unlink($tmpSubFile);
            }
        }
    }
}
