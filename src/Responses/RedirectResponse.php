<?php

declare(strict_types=1);

namespace App\Responses;

use App\Libs\HttpStatus;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

class RedirectResponse extends Response
{
    /**
     * Create a redirect response.
     *
     * Produces a redirect response with a Location header and the given status
     * (302 by default).
     *
     * Note: this method overwrites the `location` $headers value.
     *
     * @param string|UriInterface $uri URI for the Location header.
     * @param HttpStatus $status Integer status code for the redirect; 302 by default.
     * @param array $headers Array of headers to use at initialization.
     */
    public function __construct($uri, HttpStatus $status = HttpStatus::FOUND, array $headers = [])
    {
        if (!is_string($uri) && !$uri instanceof UriInterface) {
            throw new InvalidArgumentException(
                sprintf(
                    'Uri provided to %s MUST be a string or Psr\Http\Message\UriInterface instance; received "%s"',
                    __CLASS__,
                    get_debug_type($uri)
                )
            );
        }

        $headers['location'] = [(string)$uri];

        parent::__construct(status: $status, headers: $headers);
    }
}
