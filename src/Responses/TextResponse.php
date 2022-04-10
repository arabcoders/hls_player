<?php

declare(strict_types=1);

namespace App\Responses;

use App\Libs\HttpStatus;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

class TextResponse extends Response
{
    /**
     * Create a plain text response.
     *
     * Produces a text response with a Content-Type of text/plain and a default
     * status of 200.
     *
     * @param string|StreamInterface $text String or stream for the message body.
     * @param HttpStatus $status Integer status code for the response; 200 by default.
     * @param array $headers Array of headers to use at initialization.
     * @throws InvalidArgumentException if $text is neither a string nor stream.
     */
    public function __construct(
        string|StreamInterface $text,
        HttpStatus $status = HttpStatus::OK,
        array $headers = []
    ) {
        parent::__construct(
            body:    $this->createBody($text),
            status:  $status,
            headers: $this->injectContentType('text/plain; charset=utf-8', $headers),
        );
    }
}
