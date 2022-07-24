<?php

declare(strict_types=1);

use App\Controller\AssetsController;
use App\Libs\Container;
use App\Libs\Extenders\Cache;
use App\Libs\HttpStatus;
use App\Responses\HtmlResponse;
use App\Responses\JsonResponse;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\Emitter\SapiStreamEmitter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Twig\Environment;

if (!defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'rb'));
}

if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'wb'));
}

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default instanceof Closure ? $default() : $default;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        if (mb_strlen($value) > 1
            && (str_starts_with($value, '"') || str_starts_with($value, "'"))
            && (str_ends_with($value, '"') || str_ends_with($value, "'"))) {
            return mb_substr($value, 1, -1);
        }

        return $value;
    }
}

if (!function_exists('ag')) {
    function ag(array|object $array, string|array|int|null $path, mixed $default = null, string $separator = '.'): mixed
    {
        if (empty($path)) {
            return $array;
        }

        if (!is_array($array)) {
            $array = get_object_vars($array);
        }

        if (is_array($path)) {
            foreach ($path as $key) {
                $val = ag($array, $key, '_not_set');
                if ('_not_set' === $val) {
                    continue;
                }
                return $val;
            }
            return getValue($default);
        }

        if (null !== ($array[$path] ?? null)) {
            return $array[$path];
        }

        if (!str_contains($path, $separator)) {
            return $array[$path] ?? getValue($default);
        }

        foreach (explode($separator, $path) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return getValue($default);
            }
        }

        return $array;
    }
}

if (!function_exists('ag_set')) {
    /**
     * Set an array item to a given value using "dot" notation.
     *
     * If no key is given to the method, the entire array will be replaced.
     *
     * @param array $array
     * @param string $path
     * @param mixed $value
     * @param string $separator
     *
     * @return array return modified array.
     */
    function ag_set(array $array, string $path, mixed $value, string $separator = '.'): array
    {
        $keys = explode($separator, $path);

        $at = &$array;

        while (count($keys) > 0) {
            if (1 === count($keys)) {
                if (is_array($at)) {
                    $at[array_shift($keys)] = $value;
                } else {
                    throw new RuntimeException("Can not set value at this path ($path) because its not array.");
                }
            } else {
                $path = array_shift($keys);
                if (!isset($at[$path])) {
                    $at[$path] = [];
                }
                $at = &$at[$path];
            }
        }

        return $array;
    }
}

if (!function_exists('ag_exists')) {
    /**
     * Determine if the given key exists in the provided array.
     *
     * @param array $array
     * @param string|int $path
     * @param string $separator
     *
     * @return bool
     */
    function ag_exists(array $array, string|int $path, string $separator = '.'): bool
    {
        if (is_int($path)) {
            return isset($array[$path]);
        }

        foreach (explode($separator, $path) as $lookup) {
            if (isset($array[$lookup])) {
                $array = $array[$lookup];
            } else {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('ag_delete')) {
    /**
     * Delete given key path.
     *
     * @param array $array
     * @param int|string $path
     * @param string $separator
     * @return array
     */
    function ag_delete(array $array, string|int $path, string $separator = '.'): array
    {
        if (array_key_exists($path, $array)) {
            unset($array[$path]);

            return $array;
        }

        if (is_int($path)) {
            if (isset($array[$path])) {
                unset($array[$path]);
            }
            return $array;
        }

        $items = &$array;

        $segments = explode($separator, $path);

        $lastSegment = array_pop($segments);

        foreach ($segments as $segment) {
            if (!isset($items[$segment]) || !is_array($items[$segment])) {
                continue;
            }

            $items = &$items[$segment];
        }

        if (null !== $lastSegment && array_key_exists($lastSegment, $items)) {
            unset($items[$lastSegment]);
        }

        return $array;
    }
}

if (!function_exists('getValue')) {
    function getValue(mixed $var): mixed
    {
        return ($var instanceof Closure) ? $var() : $var;
    }
}

if (!function_exists('isJsonRequest')) {
    function isJsonRequest(ServerRequestInterface $request): bool
    {
        return str_contains($request->getHeader('accept')[0] ?? 'text/html', 'json');
    }
}

if (!function_exists('emitResponse')) {
    function emitResponse(ResponseInterface $response, ?int $exitCode = null, ?EmitterInterface $emitter = null): void
    {
        $length = $response->hasHeader('X-buffer-length') ? $response->getHeaderLine('X-buffer-length') : 8192;

        $class = $emitter ?? new SapiStreamEmitter($length);

        if (!$response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', $response->getBody()->getSize());
        }

        if ($response->hasHeader('X-buffer-length')) {
            $response = $response->withoutHeader('X-buffer-length');
        }

        $class->emit($response);

        if (null !== $exitCode) {
            exit($exitCode);
        }
    }
}

if (!function_exists('view')) {
    /**
     * Render Twig View into Response Stream.
     * handy shortcut for {@see RenderView}
     *
     * @param ResponseInterface $response Response Object to append to.
     * @param Environment $twig Instance of Twig Environment
     * @param string $view View Filename.
     * @param array $params View parameters.
     *
     * @return ResponseInterface
     */
    function view(ResponseInterface $response, Environment $twig, string $view, array $params): ResponseInterface
    {
        if ('dev' === env('VP_ENV')) {
            $response->getBody()->write(renderView($twig, $view, $params));
            return $response;
        }

        try {
            $response->getBody()->write(renderView($twig, $view, $params));
            return $response;
        } catch (\Twig\Error\Error $e) {
            Container::get(LoggerInterface::class)->error($e->getMessage(), [
                'file' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            $response->getBody()->write('Unable to render view.');

            return $response;
        }
    }
}

if (!function_exists('renderView')) {
    function renderView(Environment $twig, string $view, array $params): string
    {
        $tpl = replaceFirst('App\\Controller\\', '', $view);
        $tpl = str_replace('\\', '/', $tpl);

        if (!str_contains($tpl, '.')) {
            $tpl .= '.html.twig';
        }

        if ('dev' === env('VP_ENV')) {
            /** @noinspection PhpUnhandledExceptionInspection */
            return $twig->render($tpl, $params);
        }

        try {
            return $twig->render($tpl, $params);
        } catch (\Twig\Error\Error $e) {
            Container::get(LoggerInterface::class)->error(
                $e->getMessage(),
                [
                    'file' => $e->getMessage(),
                    'line' => $e->getLine()
                ]
            );

            return 'Unable to render view.';
        }
    }
}

if (!function_exists('replaceFirst')) {
    function replaceFirst(string $search, string $replace, string $subject): string
    {
        if (empty($search)) {
            return $subject;
        }

        $position = strpos($subject, $search);

        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }

        return $subject;
    }
}

if (!function_exists('fixPath')) {
    function fixPath(string $path): string
    {
        return rtrim(implode(DIRECTORY_SEPARATOR, explode(DIRECTORY_SEPARATOR, $path)), DIRECTORY_SEPARATOR);
    }
}


if (!function_exists('message')) {
    function message(string $message, string|null $url = null, array $opts = []): ResponseInterface
    {
        $arr = [
            'context' => [
                'errorPage' => true,
                'message' => [
                    'text' => $message,
                    'url' => $url,
                    'code' => $opts['code'] ?? null,
                    'class' => $opts['class'] ?? null,
                ],
                'header' => [
                    'title' => $opts['title'] ?? 'Error Message',
                ],
            ],
        ];

        if (array_key_exists('raw', $opts) && $opts['raw']) {
            $arr['context']['message']['raw'] = true;
        }

        if (array_key_exists('time', $opts) && $opts['time']) {
            $arr['context']['message']['time'] = $opts['time'];
        }

        if (array_key_exists('json', $opts) && $opts['json']) {
            $response = new JsonResponse(
                array_replace_recursive(
                    [
                        'type' => $opts['type'] ?? 'message',
                        'message' => $arr['context']['message']['text'],
                        'url' => $url,
                        'code' => $opts['code'] ?? $opts['httpcode'] ?? HttpStatus::OK->value,
                    ],
                    $opts['fields'] ?? []
                ),
                $opts['httpcode'] ?? HttpStatus::OK
            );
        } else {
            $response = new HtmlResponse(
                renderView(Container::get(Twig\Environment::class), 'Error.html.twig', $arr),
                $opts['httpcode'] ?? HttpStatus::OK
            );
        }

        if (array_key_exists('callback', $opts) && ($opts['callback'] instanceof Closure)) {
            $response = $opts['callback']($response);
        }

        return $response;
    }
}

if (!function_exists('assetUrl')) {
    function assetUrl(string $asset): ?string
    {
        static $list = [];

        if (array_key_exists($asset, $list)) {
            return $list[$asset];
        }

        $path = __DIR__ . '/../../assets/';

        if (($time = @filemtime($path . $asset))) {
            $list[$asset] = AssetsController::URL . hash(
                    'crc32',
                    $path . $asset . $time
                ) . '/' . $asset;

            return $list[$asset];
        }

        $list[$asset] = null;
        return null;
    }
}
if (!function_exists('getDataPath')) {
    function getDataPath(): string
    {
        static $dataPath = null;

        if (!empty($dataPath)) {
            return $dataPath;
        }

        if (null === ($dataPath = env('VP_DATA_PATH'))) {
            $dataPath = fixPath(realpath(__DIR__ . '/../../var'));
        } else {
            $dataPath = fixPath($dataPath);
        }

        return $dataPath;
    }
}

if (!function_exists('urlSafe')) {
    function urlSafe(string $url): string
    {
        return implode('/', array_map(fn($leaf) => rawurlencode($leaf), explode('/', $url)));
    }
}

if (!function_exists('fsize')) {
    function fsize(string|int $bytes = 0, bool $showUnit = true, int $decimals = 2, int $mod = 1000): string
    {
        $sz = 'BKMGTP';

        $factor = floor((strlen((string)$bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", (int)($bytes) / ($mod ** $factor)) . ($showUnit ? $sz[(int)$factor] : '');
    }
}

if (!function_exists('array_change_key_case_recursive')) {
    function array_change_key_case_recursive(array $input, int $case = CASE_LOWER): array
    {
        if (!in_array($case, [CASE_UPPER, CASE_LOWER], true)) {
            throw new RuntimeException(r('Case parameter [{case}] is invalid.', ['case' => $case]));
        }

        $input = array_change_key_case($input, $case);

        foreach ($input as $key => $array) {
            if (is_array($array)) {
                $input[$key] = array_change_key_case_recursive($array, $case);
            }
        }

        return $input;
    }
}

if (!function_exists('ffprobe_file')) {
    /**
     * Get FFProbe Info.
     *
     * @param string $path
     * @return array
     * @throws RuntimeException if ffprobe call fails.
     * @throws JsonException if decoding fails.
     */
    function ffprobe_file(string $path): array
    {
        $cache = Container::get(Cache::class);

        $cacheKey = 'ffprobe:' . md5($path . filemtime($path));

        if ($cache->has($cacheKey)) {
            return $cache->get($cacheKey);
        }

        $process = new Process(
            [
                'ffprobe',
                '-v',
                'quiet',
                '-print_format',
                'json',
                '-show_format',
                '-show_streams',
                'file:' . $path
            ]
        );

        $process->run();

        if (!$process->isSuccessful()) {
            $text = $process->getErrorOutput();
            if (empty($text)) {
                $text = $process->getOutput();
            }
            throw new RuntimeException('Failed - ' . $text);
        }

        $content = array_change_key_case_recursive(
            json_decode(
                $process->getOutput(),
                true,
                flags: JSON_THROW_ON_ERROR
            ),
            CASE_LOWER
        );

        $cache->set($cacheKey, $content, new DateInterval('PT30M'));

        return $content;
    }
}

if (!function_exists('beforeLast')) {
    function beforeLast(string $haystack, string|int $needle): string
    {
        if (empty($needle)) {
            return $haystack;
        }

        $pos = mb_strrpos($haystack, (string)$needle, 0);

        if (false === $pos) {
            return $haystack;
        }

        return mb_substr($haystack, 0, $pos);
    }
}

if (!function_exists('findSimilarFiles')) {
    function findSimilarFiles(string $path): array
    {
        $fileName = strtolower(basename($path));
        $filePath = dirname($path);
        $search = beforeLast($fileName, '.');

        $list = [];

        foreach (new DirectoryIterator($filePath) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $file = strtolower($item->getFilename());
            $realPath = $item->getRealPath();

            if ($file === $fileName) {
                continue;
            }

            if (str_starts_with($file, $search)) {
                if (!in_array($realPath, $list, true)) {
                    $list[] = $realPath;
                }
            }
        }

        return $list;
    }
}

if (!function_exists('getExtension')) {
    function getExtension(string $filename): string
    {
        return (new SplFileInfo($filename))->getExtension();
    }
}

if (!function_exists('afterLast')) {
    function afterLast(string $subject, string $search): string
    {
        if (empty($search)) {
            return $subject;
        }

        $position = mb_strrpos($subject, $search, 0);

        if (false === $position) {
            return $subject;
        }

        return mb_substr($subject, $position + mb_strlen($search));
    }
}

if (false === function_exists('r')) {
    /**
     * Substitute words enclosed in special tags for values from context.
     *
     * @param string $text text that contains tokens.
     * @param array $context A key/value pairs list.
     * @param string $tagLeft left tag bracket. Default '{'.
     * @param string $tagRight right tag bracket. Default '}'.
     *
     * @return string
     */
    function r(string $text, array $context = [], string $tagLeft = '{', string $tagRight = '}'): string
    {
        if (false === str_contains($text, $tagLeft) || false === str_contains($text, $tagRight)) {
            return $text;
        }

        $pattern = '#' . preg_quote($tagLeft, '#') . '([\w\d_.]+)' . preg_quote($tagRight, '#') . '#is';

        $status = preg_match_all($pattern, $text, $matches);

        if (false === $status || $status < 1) {
            return $text;
        }

        $replacements = [];

        foreach ($matches[1] as $key) {
            $placeholder = $tagLeft . $key . $tagRight;

            if (false === str_contains($text, $placeholder)) {
                continue;
            }

            if (false === ag_exists($context, $key)) {
                continue;
            }

            $val = ag($context, $key);

            $context = ag_delete($context, $key);

            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replacements[$placeholder] = $val;
            } elseif (is_object($val)) {
                $replacements[$placeholder] = implode(',', get_object_vars($val));
            } elseif (is_array($val)) {
                $replacements[$placeholder] = implode(',', $val);
            } else {
                $replacements[$placeholder] = '[' . gettype($val) . ']';
            }
        }

        return strtr($text, $replacements);
    }
}


if (false === function_exists('inContainer')) {
    function inContainer(): bool
    {
        if (true === (bool)env('IN_CONTAINER')) {
            return true;
        }

        if (true === file_exists('/.dockerenv')) {
            return true;
        }

        return false;
    }
}
