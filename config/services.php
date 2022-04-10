<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Libs\Extenders\Cache;
use App\Libs\GenerateRoutes;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Twig\Cache\FilesystemCache;
use Twig\Cache\NullCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;
use Whoops\Run;

return (function (): array {
    return [
        GenerateRoutes::class => [
            'class' => fn() => new GenerateRoutes([__DIR__ . '/../src/Controller/']),
        ],

        Run::class => [
            'class' => fn() => new Run(),
        ],

        Redis::class => [
            'class' => function () {
                $redis = new Redis();

                if (null !== ($redisSocket = env('VP_REDIS_SOCKET'))) {
                    $redis->connect($redisSocket);
                } else {
                    $redis->connect(env('VP_REDIS_HOST', 'localhost'), env('VP_REDIS_PORT', 6379));
                }

                if (null !== ($redisAuth = env('VP_REDIS_AUTH'))) {
                    $redis->auth($redisAuth);
                }

                if (null !== ($redisDb = env('VP_REDIS_DB'))) {
                    $redis->select((int)$redisDb);
                }

                return $redis;
            }
        ],

        Cache::class => [
            'class' => fn($redis) => new Cache(new Psr16Cache(new RedisAdapter($redis))),
            'args' => [
                Redis::class,
            ],
        ],

        ServerRequestInterface::class => [
            'class' => function () {
                $factory = new Psr17Factory();
                return (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
            },
        ],

        ResponseInterface::class => [
            'class' => fn() => new Response(status: 200, headers: ['Content-Type' => 'text/html; charset=utf-8']),
        ],

        Environment::class => [
            'class' => function () {
                $isDev = 'dev' === env('VP_ENV');
                $dataPath = getDataPath();

                $env = new Environment(
                    new FilesystemLoader([__DIR__ . '/../src/View/']),
                    [
                        'cache' => !empty($dataPath) ? new FilesystemCache($dataPath . '/views/') : new NullCache(),
                        'autoescape' => 'html',
                        'debug' => $isDev,
                        'auto_reload' => $isDev,
                    ]
                );

                $twig = require __DIR__ . '/twig.php';
                $twig = $twig();

                foreach ($twig['filters'] ?? [] as $filterName => $definition) {
                    $env->addFilter(new TwigFilter($filterName, $definition['call'], $definition['opts'] ?? []));
                }

                foreach ($twig['globals'] ?? [] as $key => $value) {
                    $env->addGlobal($key, $value);
                }

                foreach ($twig['functions'] ?? [] as $key => $definition) {
                    $env->addFunction(new TwigFunction($key, $definition['call'], $definition['opts'] ?? []));
                }

                foreach ($twig['tests'] ?? [] as $key => $definition) {
                    $env->addTest(new TwigTest($key, $definition['call'], $definition['opts'] ?? []));
                }

                return $env;
            },
            'shared' => true,
        ],
    ];
})();
