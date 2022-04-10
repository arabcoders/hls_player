<?php

declare(strict_types=1);

namespace App\Libs\Extenders;

use DateTimeImmutable;
use finfo;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SplFileInfo;

final class StorageFile
{
    public const FILE_PATH = 'path';
    public const FILE_NAME = 'filename';
    public const FILE_TIME_CREATED = 'created';
    public const FILE_TIME_MODIFIED = 'modified';
    public const FILE_SIZE = 'size';
    public const FILE_MIME_TYPE = 'mimetype';
    public const FILE_META_DATA = 'metadata';
    public const FILE_EXT = 'extension';
    public const FILE_HASH = 'hash';
    public const FILE_RELATIVE_PATH_NAME = 'relativePathname';

    private string $path;
    private string $filename;
    private string $extension;
    private string $hash = '';
    private array $metadata = [];
    private int $size = 0;
    private int $created = 0;
    private int $modified = 0;
    private string $mimetype = '';
    private string|null $relativePathname = null;

    public static function init(string $file, array $fileInfo = [], array $opts = []): StorageFile
    {
        return new self($file, $fileInfo, $opts);
    }

    public function __construct(string $file, array $fileInfo = [], array $opts = [])
    {
        if (array_key_exists(self::FILE_META_DATA, $opts)) {
            $this->metadata = (array)$opts[self::FILE_META_DATA];
        }

        if (array_key_exists(self::FILE_SIZE, $fileInfo)) {
            $this->size = (int)$fileInfo[self::FILE_SIZE];
        } else {
            $this->size = (int)filesize($file);
        }

        if (array_key_exists(self::FILE_TIME_CREATED, $fileInfo)) {
            $this->created = (int)$fileInfo[self::FILE_TIME_CREATED];
        } else {
            $this->created = (int)filectime($file);
        }

        if (array_key_exists(self::FILE_TIME_MODIFIED, $fileInfo)) {
            $this->modified = (int)$fileInfo[self::FILE_TIME_MODIFIED];
        } else {
            $this->modified = (int)filemtime($file);
        }

        if (array_key_exists(self::FILE_MIME_TYPE, $fileInfo)) {
            $this->mimetype = $fileInfo[self::FILE_MIME_TYPE];
        }

        if (array_key_exists(self::FILE_HASH, $fileInfo)) {
            $this->hash = $fileInfo[self::FILE_HASH];
        }

        if (array_key_exists(self::FILE_PATH, $fileInfo)) {
            $this->path = (string)$fileInfo[self::FILE_PATH];
        } else {
            $this->path = dirname($file);
        }

        if (array_key_exists(self::FILE_RELATIVE_PATH_NAME, $opts)) {
            $this->relativePathname = (string)$opts[self::FILE_RELATIVE_PATH_NAME];
        }

        if (array_key_exists(self::FILE_NAME, $fileInfo)) {
            $this->filename = (string)$fileInfo[self::FILE_NAME];
        } else {
            $this->filename = basename($file);
        }

        if (array_key_exists(self::FILE_EXT, $fileInfo)) {
            $this->extension = (string)$fileInfo[self::FILE_EXT];
        } else {
            $this->extension = (new SplFileInfo($file))->getExtension();
        }
    }

    public function getCreatedTime(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($this->created);
    }

    public function getModifiedTime(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($this->modified);
    }

    public function getFilesize(): int
    {
        return $this->size;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMimetype(): string
    {
        if (empty($this->mimetype)) {
            $filePath = $this->getFullpath();
            $mimeType = (new finfo())->file($filePath, FILEINFO_MIME_TYPE);
            if (!is_string($mimeType)) {
                throw new RuntimeException(sprintf('Unable to get mimetype for \'%s\'.', $filePath));
            }
            $this->mimetype = $mimeType;
        }

        return $this->mimetype;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHash(): string
    {
        if (empty($this->hash)) {
            $this->hash = (string)hash_file('sha1', $this->getFullpath());
        }

        return $this->hash;
    }

    public function getFullpath(): string
    {
        return rtrim($this->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->filename;
    }

    public function getRelativePathname(): string
    {
        return $this->relativePathname ?? $this->filename;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getStream(string $mode = 'rb'): StreamInterface
    {
        return Stream::create(fopen($this->getFullPath(), $mode));
    }

    /**
     * @param string $mode
     * @return resource
     */
    public function getStreamAsResource(string $mode = 'rb+')
    {
        if (false === ($stream = @fopen($this->getFullpath(), $mode))) {
            throw new RuntimeException('Unable to get file stream.');
        }

        return $stream;
    }

    public function read(): string
    {
        return (string)@file_get_contents($this->getFullPath());
    }

    public function isReadable(): bool
    {
        return is_readable($this->getFullpath());
    }

    public function getFileInfo(): SplFileInfo
    {
        return new SplFileInfo($this->getFullPath());
    }

    public function __toString(): string
    {
        return $this->getFullpath();
    }

    public function __debugInfo(): array
    {
        if (empty($this->mimetype)) {
            $this->getMimetype();
        }

        if (empty($this->hash)) {
            $this->getHash();
        }

        return [
            self::FILE_EXT => $this->{self::FILE_EXT},
            self::FILE_NAME => $this->{self::FILE_NAME},
            self::FILE_TIME_CREATED => $this->{self::FILE_TIME_CREATED},
            self::FILE_TIME_MODIFIED => $this->{self::FILE_TIME_MODIFIED},
            self::FILE_SIZE => $this->{self::FILE_SIZE},
            self::FILE_MIME_TYPE => $this->{self::FILE_MIME_TYPE},
            self::FILE_PATH => $this->{self::FILE_PATH},
            self::FILE_HASH => $this->{self::FILE_HASH},
            self::FILE_META_DATA => $this->{self::FILE_META_DATA},
            self::FILE_RELATIVE_PATH_NAME => $this->{self::FILE_RELATIVE_PATH_NAME},
            'FullPath' => $this->getFullpath(),
        ];
    }

    public static function getOverridableFields(): array
    {
        return [
            self::FILE_EXT => 'string',
            self::FILE_NAME => 'string',
            self::FILE_TIME_CREATED => 'int',
            self::FILE_TIME_MODIFIED => 'int',
            self::FILE_SIZE => 'int',
            self::FILE_MIME_TYPE => 'string',
            self::FILE_PATH => 'string',
            self::FILE_HASH => 'string',
            self::FILE_META_DATA => 'array',
        ];
    }
}
