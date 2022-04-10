<?php

declare(strict_types=1);

namespace App\Attributes\Route;

use App\Libs\HttpRequest;
use Attribute;
use Closure;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    public readonly bool $isCli;
    public readonly array $methods;
    public readonly string $pattern;
    public readonly array $middleware;
    public readonly string|null $host;
    public readonly string|null $name;
    public readonly string|null $scheme;
    public readonly string|int|null $port;

    /**
     * Generate Dynamic Route.
     *
     * @param array<HttpRequest> $methods HTTP Methods.
     * @param string $pattern Path pattern.
     * @param array|string $middleware List of middlewares.
     * @param string|null $host Required host. Use %{config.name} for config value
     * @param string|null $name Route name.
     * @param string|null $scheme Request scheme. Use %{config.name} for config value
     * @param string|int|null $port Request Port. Use %{config.name} for config value
     */
    public function __construct(
        array $methods,
        string $pattern,
        array|string $middleware = [],
        string|null $host = null,
        string|null $name = null,
        string|null $scheme = null,
        string|int|null $port = null,
    ) {
        $this->methods = array_map(fn(HttpRequest $val) => $val->value, $methods);
        $this->pattern = $pattern;
        $this->middleware = is_string($middleware) ? [$middleware] : $middleware;
        $this->name = $name;
        $this->port = null !== $port ? $this->fromEnv($port) : $port;
        $this->scheme = null !== $scheme ? $this->fromEnv($scheme) : $scheme;
        $this->host = null !== $host ? $this->fromEnv($host, fn($v) => parse_url($v, PHP_URL_HOST)) : $host;
    }

    private function fromEnv(mixed $value, Closure|null $callback = null): mixed
    {
        if (is_string($value) && preg_match('#%{(.+?)}#s', $value, $match)) {
            $val = env($match[1]);
            return null !== $callback && null !== $val ? $callback($val) : $val;
        }

        return $value;
    }
}
