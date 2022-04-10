<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attributes\Route\Get;
use DateTimeInterface;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Twig\Environment;

#[Get(pattern: '/'), Get(pattern: '/browser/[{path:.+}]')]
final class IndexController
{
    public const URL = '/browser/';

    private string $basePath = '';

    private string $vPattern;
    private string $aPattern;
    private string $sPattern;
    private string $iPattern;
    private bool $cleanNames;

    public function __construct(private ResponseInterface $response, private Environment $renderer)
    {
        if (null === ($basePath = env('VP_MEDIA_PATH'))) {
            throw new RuntimeException('No valid media path was given in ENV:VP_MEDIA_PATH.');
        }

        $this->basePath = $basePath;

        $this->vPattern = '#(' . env('VP_MEDIA_SUBTITLE', 'ts|webm|wmv|mpg|m4v|ogm|mp4|avi|mkv') . ')#i';
        $this->aPattern = '#(' . env('VP_MEDIA_SUBTITLE', 'mp3|aac|ac3|flac') . ')#i';
        $this->sPattern = '#(' . env('VP_MEDIA_SUBTITLE', 'srt|ass|ssa|smi|sub') . ')#i';
        $this->iPattern = '#(' . env('VP_MEDIA_SUBTITLE', 'jpg|jpeg|png|gif') . ')#i';
        $this->cleanNames = !env('VP_DISABLE_CLEAN_NAMES', false);
    }

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $userPath = $args['path'] ?? '.';
        $fileSystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));
        $arr = [];

        try {
            $userPath = rawurldecode($userPath);
            if (!$fileSystem->has($userPath)) {
                return message(sprintf('Invalid path was given. \'%s\'.', $userPath));
            }

            /** @var array<StorageAttributes> $list */
            $list = $fileSystem->listContents($userPath)->sortByPath();

            foreach ($list as $item) {
                $ext = $item->isFile() ? getExtension($item->path()) : null;
                $time = $item->lastModified();
                $name = basename($item->path());
                $isVideo = $ext && preg_match($this->vPattern, $ext);
                $path = urlSafe($item->path());

                if ($isVideo) {
                    $url = StreamerController::URL . $path;
                } elseif ($item->isDir()) {
                    $url = self::URL . $path;
                } else {
                    $url = DownloadController::URL . $path;
                }

                $arr['page']['files'][] = [
                    'isDir' => $item->isDir(),
                    'isFile' => $item->isFile(),
                    'isImage' => $ext && preg_match($this->iPattern, $ext),
                    'isVideo' => $isVideo,
                    'isAudio' => $ext && preg_match($this->aPattern, $ext),
                    'isSubtitle' => $ext && preg_match($this->sPattern, $ext),
                    'size' => ($item instanceof FileAttributes) ? fsize($item->fileSize()) : 'Dir',
                    'time' => $time ? gmdate(DateTimeInterface::ATOM, $time) : null,
                    'name' => $this->cleanNames ? self::makeShortName($name) : $name,
                    'fullName' => $name,
                    'url' => $url,
                ];
            }
        } catch (FilesystemException $e) {
            return message($e->getMessage());
        }

        $arr += [
            'context' => [
                'title' => 'Browsing > ' . (('.' === $userPath) ? '/' : $userPath),
                'breadcrumb' => self::makeBreadcrumb($userPath, self::URL),
            ],
        ];

        return view($this->response, $this->renderer, __CLASS__, $arr);
    }

    public static function makeBreadcrumb(string $path, ?string $url = null): array
    {
        if (null === $url) {
            $url = rtrim(self::URL, '/');
        }

        $baseLink = '/';

        $path = ltrim($path, '/');

        $links = [];

        $links[] = [
            'link' => $url . '/',
            'name' => 'Home'
        ];

        if ('.' === $path) {
            return $links;
        }

        $parts = explode('/', $path);

        foreach ($parts as $part) {
            if ('/' === $part || empty($part)) {
                continue;
            }

            $baseLink .= '/' . $part;
            $baseLink = str_replace('//', '/', $baseLink);

            $links[] = [
                'name' => trim($part),
                'link' => $url . urlSafe($baseLink),
            ];
        }

        return $links;
    }

    public static function makeShortName(string $name): string
    {
        return preg_replace('#\s+\.(\w+)#', '.$1', preg_replace('#\[.+?]#', '', $name));
    }

}
