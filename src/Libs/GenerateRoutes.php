<?php

declare(strict_types=1);

namespace App\Libs;

use App\Attributes\Route\Route;
use App\Libs\Extenders\Storage;
use InvalidArgumentException;
use PhpToken;
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class GenerateRoutes
{
    private array $dirs = [];

    private LoggerInterface|null $logger = null;

    /**
     * @param array $dirs List of directories to scan for php files.
     */
    public function __construct(array $dirs = [])
    {
        $this->dirs = $dirs;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function setDirs(array $dirs): self
    {
        $this->dirs = $dirs;

        return $this;
    }

    public function getDirs(): array
    {
        return $this->dirs;
    }

    public function generate(): array
    {
        $routes = [];

        foreach ($this->dirs as $path) {
            array_push($routes, ...$this->scanDirectory($path));
        }

        return $routes;
    }

    private function scanDirectory(string $dir): array
    {
        $classes = $routes = [];
        $files = Storage::init(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
        $files = $files->listContents('.', true, []);

        foreach ($files as $file) {
            if (!$file->isReadable() || 'php' !== $file->getExtension()) {
                continue;
            }

            $class = $this->parseFile($file->getFullpath());

            if (false === $class) {
                continue;
            }

            array_push($classes, ...$class);
        }

        foreach ($classes as $className) {
            if (!class_exists($className)) {
                continue;
            }

            array_push($routes, ...$this->getRoutes(new ReflectionClass($className)));
        }

        return $routes;
    }

    protected function getRoutes(ReflectionClass $class): array
    {
        $routes = [];

        $attributes = $class->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);

        $invokable = false;

        foreach ($class->getMethods() as $method) {
            if ($method->getName() === '__invoke') {
                $invokable = true;
            }
        }

        foreach ($attributes as $attribute) {
            try {
                $attributeClass = $attribute->newInstance();
            } catch (Throwable $e) {
                $this->logger?->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                continue;
            }

            if (!$attributeClass instanceof Route) {
                continue;
            }

            if (false === $invokable && !$attributeClass->isCli) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Trying to route \'%s\' to un-invokable class. \'%s\'.',
                        $attributeClass->pattern,
                        $class->getName()
                    )
                );
            }

            $routes[] = [
                'path' => $attributeClass->pattern,
                'method' => $attributeClass->methods,
                'callable' => $class->getName(),
                'host' => $attributeClass->host,
                'middlewares' => $attributeClass->middleware,
                'name' => $attributeClass->name,
                'port' => $attributeClass->port,
                'scheme' => $attributeClass->scheme,
            ];
        }

        foreach ($class->getMethods() as $method) {
            $attributes = $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attribute) {
                try {
                    $attributeClass = $attribute->newInstance();
                } catch (Throwable) {
                    continue;
                }

                if (!$attributeClass instanceof Route) {
                    continue;
                }

                $call = $method->getName() === '__invoke' ? $class->getName() : [$class->getName(), $method->getName()];

                $routes[] = [
                    'path' => $attributeClass->pattern,
                    'method' => $attributeClass->methods,
                    'callable' => $call,
                    'host' => $attributeClass->host,
                    'middlewares' => $attributeClass->middleware,
                    'name' => $attributeClass->name,
                    'port' => $attributeClass->port,
                    'scheme' => $attributeClass->scheme,
                ];
            }
        }

        return $routes;
    }

    private function parseFile(string $file): array|false
    {
        $classes = [];
        $namespace = '';

        if (false === ($content = @file_get_contents($file))) {
            throw new RuntimeException(
                sprintf('Unable to read \'%s\' - \'%s\' .', $file, error_get_last()['message'] ?? 'unknown')
            );
        }

        $tokens = PhpToken::tokenize($content);
        $count = count($tokens);

        /** @noinspection ForeachInvariantsInspection */
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->getTokenName() === 'T_NAMESPACE') {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->getTokenName() === 'T_NAME_QUALIFIED') {
                        $namespace = $tokens[$j]->text;
                        break;
                    }
                }
            }

            if ($tokens[$i]->getTokenName() === 'T_CLASS') {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->getTokenName() === 'T_WHITESPACE') {
                        continue;
                    }

                    if ($tokens[$j]->getTokenName() === 'T_STRING') {
                        $classes[] = $namespace . '\\' . $tokens[$j]->text;
                    } else {
                        break;
                    }
                }
            }
        }

        return count($classes) >= 1 ? $classes : false;
    }
}
