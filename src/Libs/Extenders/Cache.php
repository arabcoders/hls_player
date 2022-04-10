<?php

declare(strict_types=1);

namespace App\Libs\Extenders;

use Psr\SimpleCache\CacheInterface;

final class Cache implements CacheInterface
{
    private CacheInterface $driver;

    private string $prefix;

    public function __construct(CacheInterface $driver, string $prefix = '')
    {
        $this->driver = $driver;
        $this->prefix = $prefix;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int|\DateInterval|null $ttl
     * @return bool
     */
    public function set(string $key, mixed $value, int|null|\DateInterval $ttl = null): bool
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->driver->set($this->prefix . $key, $value, $ttl);
    }

    /**
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->driver->get($this->prefix . $key, $default);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->driver->has($this->prefix . $key);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->driver->delete($this->prefix . $key);
    }

    public function getDriver(): CacheInterface
    {
        return $this->driver;
    }

    public function clear(): bool
    {
        return $this->driver->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->driver->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return $this->driver->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->driver->deleteMultiple($keys);
    }
}
