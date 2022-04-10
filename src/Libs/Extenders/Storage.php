<?php

declare(strict_types=1);

namespace App\Libs\Extenders;

use finfo;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class Storage
{
    private ?string $path;

    private LoggerInterface|null $logger = null;

    private Filesystem $fs;

    private function __construct(?string $path = null)
    {
        if (null !== $path) {
            $this->setPath($path);
        }
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public static function init(?string $path = null): Storage
    {
        return new Storage($path);
    }

    /**
     * Set Path.
     *
     * @param string $path path relative to `config.data.storage` if absolute set to false
     *
     * @return Storage
     */
    public function setPath(string $path): Storage
    {
        $this->path = $path;
        $this->fs = new Filesystem(new LocalFilesystemAdapter($this->path));

        return $this;
    }

    /**
     * @param string|StorageFile $file file including path.
     * @param string $location location to store file into.
     * @param string|null $newFileName new file name.
     *
     * @return bool
     * @throws RuntimeException
     */
    public function upload(StorageFile|string $file, string $location = '', ?string $newFileName = null): bool
    {
        if (!($file instanceof StorageFile)) {
            $file = StorageFile::init($file);
        }

        if (!$file->isReadable()) {
            throw new RuntimeException('File is unreadable');
        }

        if (!$this->hasPath()) {
            throw new RuntimeException('No base path is set.');
        }

        try {
            $fileName = $newFileName ?? $file->getFilename();
            if (!empty($location)) {
                $fileName = rtrim($location, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
            }
            $this->fs->writeStream($fileName, $file->getStream()->detach());
        } catch (Throwable $e) {
            $this->logger?->error($e, $e->getTrace());

            return false;
        }

        return true;
    }

    /**
     * Delete file.
     *
     * @param string $file file including path to delete.
     *
     * @return bool
     * @throws RuntimeException
     */
    public function delete(string $file): bool
    {
        if (!$this->path) {
            throw new RuntimeException('No base path is set.');
        }

        try {
            if ($this->has($file)) {
                $this->fs->delete($file);
            }

            return true;
        } catch (Throwable $e) {
            $this->logger?->error($e, $e->getTrace());
        }

        return false;
    }

    /**
     * File Ext.
     *
     * @param string $fileName
     *
     * @return string
     */
    public static function getExtension(string $fileName): string
    {
        $ext = (new SplFileInfo($fileName))->getExtension();

        return !empty($ext) ? $ext : 'none';
    }

    public static function getMimetype(string $file): string
    {
        return (string)(new finfo())->file($file, FILEINFO_MIME_TYPE);
    }

    public function has(string $file): bool
    {
        try {
            return $this->fs->fileExists($file);
        } catch (Throwable) {
            return false;
        }
    }

    public function get(string $file): ?StorageFile
    {
        if (!$this->has($file)) {
            return null;
        }

        return StorageFile::init(rtrim($this->getPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file);
    }

    /**
     * List Contents of a directory.
     *
     * @param string $path
     * @param bool $recursive
     * @param array $opts
     *
     * @return array<StorageFile>
     */
    public function listContents(string $path, bool $recursive = false, array $opts = []): array
    {
        $list = $override = [];

        $overridable = StorageFile::getOverridableFields();

        if (array_key_exists('override', $opts) && is_array($opts['override'])) {
            foreach ($opts['override'] as $key => $val) {
                if (!array_key_exists($key, $overridable)) {
                    continue;
                }
                $override[$key] = $val;
            }
        }

        try {
            $fs = $this->fs->listContents($path, $recursive);
        } catch (FilesystemException $e) {
            $this->logger?->error($e, $e->getTrace());
            $fs = [];
        }

        foreach ($fs as $file) {
            $arr = $override;

            if ($file instanceof DirectoryAttributes) {
                continue;
            }

            if (!isset($arr[StorageFile::FILE_SIZE])) {
                $arr[StorageFile::FILE_SIZE] = $file['fileSize'];
            }

            if (!isset($arr[StorageFile::FILE_TIME_CREATED])) {
                $arr[StorageFile::FILE_TIME_CREATED] = (string)$file['lastModified'];
            }

            if (!isset($arr[StorageFile::FILE_TIME_MODIFIED])) {
                $arr[StorageFile::FILE_TIME_MODIFIED] = (string)$file['lastModified'];
            }

            $list[] = StorageFile::init(
                $this->path . $file['path'],
                $arr,
                [
                    StorageFile::FILE_RELATIVE_PATH_NAME => $file['path']
                ]
            );
        }

        return $list;
    }

    /**
     * Get Working Path.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path ?? '';
    }

    public function getAbsoluteFilePath(string $file): string
    {
        return $this->getPath() . $file;
    }

    /**
     * Do we have a path set?
     *
     * @return bool
     */
    private function hasPath(): bool
    {
        return !empty($this->path);
    }
}
