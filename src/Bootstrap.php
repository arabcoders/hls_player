<?php

declare(strict_types=1);

namespace App;

use App\Libs\Container;
use App\Libs\Extenders\Cache;
use App\Libs\GenerateRoutes;
use App\Libs\HttpStatus;
use App\Responses\EmptyResponse;
use App\Responses\JsonResponse;
use App\Responses\TextResponse;
use Composer\InstalledVersions;
use League\Route\Http\Exception as RouteException;
use League\Route\Http\Exception\MethodNotAllowedException;
use League\Route\Http\Exception\NotFoundException;
use League\Route\RouteGroup;
use League\Route\Router;
use League\Route\Strategy\ApplicationStrategy;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;
use Whoops\Handler\CallbackHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

final class Bootstrap
{
    private Logger $logger;
    private Router $router;

    public function __construct()
    {
        // -- Load user custom environment variables.
        (function (string $dataPath) {
            if (file_exists(__DIR__ . '/../.env')) {
                (new Dotenv())->usePutenv(true)->overload(__DIR__ . '/../.env');
            }

            if (file_exists($dataPath . '/.env')) {
                (new Dotenv())->usePutenv(true)->overload($dataPath . '/.env');
            }
        })(
            getDataPath()
        );

        $this->createDirectories();

        $this->logger = new Logger('logger');

        Container::init();

        Container::add(LoggerInterface::class, $this->logger);

        foreach ((array)require __DIR__ . '/../config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }

        $this->router = new Router();
        $strategy = new ApplicationStrategy();
        $strategy->setContainer(Container::getContainer());
        $this->router->setStrategy($strategy);
    }

    public function onBoot(): self
    {
        $this->logger->pushHandler(new StreamHandler(STDERR, Logger::DEBUG));

        if (!inContainer()) {
            $this->logger->pushHandler(new SyslogHandler('hls_player', LOG_USER, Logger::DEBUG));
        }

        $this->registerErrorHandlers();

        if (null !== ($logfile = env('VP_LOG_FILE'))) {
            try {
                $this->logger->pushHandler(new StreamHandler($logfile, Logger::INFO));
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage(), []);
            }
        }

        (function () {
            $mw = require __DIR__ . '/../config/middlewares.php';
            $middlewares = (array)$mw(Container::getContainer());
            $mw = null;

            foreach ($middlewares as $middleware) {
                $this->router->middleware($middleware(Container::getContainer()));
            }
        })();

        $fn = function (Router|RouteGroup $r, array $route): void {
            foreach ($route['method'] as $method) {
                $f = $r->map($method, $route['path'], $route['callable']);

                if (!empty($route['lazymiddlewares'])) {
                    $f->lazyMiddlewares($route['lazymiddlewares']);
                }

                if (!empty($route['middlewares'])) {
                    $f->middlewares($route['middlewares']);
                }

                if (!empty($route['host'])) {
                    $f->setHost($route['host']);
                }

                if (!empty($route['port'])) {
                    $f->setPort($route['port']);
                }

                if (!empty($route['scheme'])) {
                    $f->setScheme($route['scheme']);
                }
            }
        };

        (function () use ($fn) {
            $cache = Container::get(Cache::class);
            if ($cache->has('routes') && 'dev' !== env('VP_ENV')) {
                $routes = $cache->get('routes');
            } else {
                $routes = Container::get(GenerateRoutes::class)->generate();
                $cache->set('routes', $routes, 240);
            }

            foreach ($routes as $route) {
                if (!empty($route['middlewares'])) {
                    $route['lazymiddlewares'] = $route['middlewares'];
                    unset($route['middlewares']);
                }
                $fn($this->router, $route);
            }
        })();

        unset($fn);

        return $this;
    }

    private function registerErrorHandlers(): void
    {
        if (InstalledVersions::isInstalled('filp/whoops')) {
            $whoops = Container::get(Run::class);

            $whoops->clearHandlers();

            $whoops->appendHandler(
                new CallbackHandler(
                    function (Throwable $exception) {
                        $this->logger->error($exception->getMessage(), ['exception' => $exception]);
                    }
                )
            );

            $f = new PrettyPageHandler();

            foreach ($_ENV as $_key => $_val) {
                $f->blacklist('_ENV', $_key);
                $f->blacklist('_SERVER', $_key);
            }

            $whoops->appendHandler($f);

            $whoops->register();
            return;
        }

        set_error_handler(
            function ($severity, $message, $file, $line) {
                if (!(error_reporting() & $severity)) {
                    return;
                }

                Container::get(LoggerInterface::class)->error(
                    r("{severity}: {error} ({file}:{line})." . PHP_EOL, [
                        'severity' => $severity,
                        'error' => $message,
                        'file' => $file,
                        'line' => $line,
                    ])
                );

                exit(1);
            }
        );

        set_exception_handler(function (Throwable $e) {
            Container::get(LoggerInterface::class)->error(
                r("{class}: {error} ({file}:{line})." . PHP_EOL, [
                    'class' => get_class($e),
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ])
            );
            exit(1);
        });
    }

    public function run(ServerRequestInterface|null $request = null): ResponseInterface
    {
        try {
            if (null === $request) {
                $request = (fn(): ServerRequestInterface => Container::getNew(ServerRequestInterface::class))();
            }

            return $this->router->dispatch($request);
        } catch (NotFoundException) {
            $message = r('NotFoundException: {ip} - {method}: {uri}', [
                'ip' => ag($request->getServerParams(), 'REMOTE_ADDR', '??'),
                'method' => $request->getMethod(),
                'uri' => ag($request->getServerParams(), 'REQUEST_URI', '/'),
            ]);

            $this->logger->notice($message);

            if (isJsonRequest($request)) {
                return new JsonResponse([
                    'type' => 'error',
                    'message' => 'Endpoint not found.'
                ], HttpStatus::NOT_FOUND);
            }

            return new TextResponse('ERROR 404 - Page not found.', HttpStatus::NOT_FOUND);
        } catch (MethodNotAllowedException) {
            $message = r('MethodNotAllowedException: {ip} - {method}: {uri}', [
                'ip' => ag($request->getServerParams(), 'REMOTE_ADDR', '??'),
                'method' => $request->getMethod(),
                'uri' => ag($request->getServerParams(), 'REQUEST_URI', '/'),
            ]);

            $this->logger->notice($message);

            if (isJsonRequest($request)) {
                return new JsonResponse([
                    'type' => 'error',
                    'message' => 'Invalid request method.',
                ], HttpStatus::BAD_REQUEST);
            }

            return new TextResponse('ERROR 400 - Invalid request method.', HttpStatus::BAD_REQUEST);
        } catch (RouteException $e) {
            $message = r('{class}: {ip} - {method}: {uri} - {message}', [
                'class' => get_class($e),
                'ip' => ag($request->getServerParams(), 'REMOTE_ADDR', '??'),
                'method' => $request->getMethod(),
                'uri' => ag($request->getServerParams(), 'REQUEST_URI', '/'),
                'message' => $e->getMessage(),
            ]);

            $this->logger->notice($message);

            return new EmptyResponse(HttpStatus::BAD_REQUEST);
        }
    }

    private function createDirectories(): void
    {
        $directories = __DIR__ . '/../config/directories.php';

        $dataPath = getDataPath();

        if (!is_writable($dataPath)) {
            throw new RuntimeException(
                r('Unable to write to data path \'{path}\'.', [
                    'path' => $dataPath
                ])
            );
        }

        foreach (require $directories as $directory) {
            $path = $dataPath . DIRECTORY_SEPARATOR . $directory;

            if (!file_exists($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
                throw new RuntimeException(
                    r('Unable to create \'{path}\' path.', [
                        'path' => $path
                    ])
                );
            }
        }
    }
}
