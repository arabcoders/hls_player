<?php

declare(strict_types=1);

namespace App\Responses;

use App\Libs\HeaderSecurity;
use App\Libs\HttpStatus;
use InvalidArgumentException;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class Response implements ResponseInterface
{
    protected const MIN_STATUS_CODE_VALUE = 100;
    protected const MAX_STATUS_CODE_VALUE = 599;

    /**
     * Map of standard HTTP status code/reason phrases
     *
     * @var array
     *
     * @psalm-var array<positive-int, non-empty-string>
     */
    private array $phrases = [
        // INFORMATIONAL CODES
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        // SUCCESS CODES
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        // REDIRECTION CODES
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy', // Deprecated to 306 => '(Unused)'
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        // CLIENT ERROR
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        444 => 'Connection Closed Without Response',
        451 => 'Unavailable For Legal Reasons',
        // SERVER ERROR
        499 => 'Client Closed Request',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
        599 => 'Network Connect Timeout Error',
    ];

    /**
     * @var string
     */
    private string $reasonPhrase = '';

    /**
     * @var int
     */
    private int $statusCode = 200;

    /**
     * List of all registered headers, as key => array of values.
     *
     * @var array<string,array<string>>
     */
    protected array $headers = [];

    /**
     * Map of normalized header name to original name used to register header.
     *
     * @var array<string,mixed>
     */
    protected array $headerNames = [];

    /**
     * @var string
     */
    private string $protocol = '1.1';

    /**
     * @var StreamInterface
     */
    private StreamInterface $stream;

    /**
     * @param string|resource|StreamInterface $body Stream identifier and/or actual stream resource
     * @param HttpStatus $status Status code for the response, if any.
     * @param array $headers Headers for the response, if any.
     * @throws InvalidArgumentException on any invalid element.
     */
    public function __construct(
        mixed $body = 'php://memory',
        HttpStatus $status = HttpStatus::OK,
        array $headers = []
    ) {
        $this->setStatusCode($status->value);
        $this->stream = $this->getStream($body);
        $this->setHeaders($headers);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function withStatus($code, $reasonPhrase = ''): Response
    {
        $new = clone $this;
        $new->setStatusCode($code, $reasonPhrase);
        return $new;
    }

    /**
     * Set a valid status code.
     *
     * @param int $code
     * @param string $reasonPhrase
     * @throws InvalidArgumentException on an invalid status code.
     */
    private function setStatusCode(int $code, string $reasonPhrase = ''): void
    {
        if ($code < static::MIN_STATUS_CODE_VALUE || $code > static::MAX_STATUS_CODE_VALUE) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid status code "%s"; must be an integer between %d and %d, inclusive',
                    $code,
                    static::MIN_STATUS_CODE_VALUE,
                    static::MAX_STATUS_CODE_VALUE
                )
            );
        }

        if ($reasonPhrase === '' && isset($this->phrases[$code])) {
            $reasonPhrase = $this->phrases[$code];
        }

        $this->statusCode = $code;
        $this->reasonPhrase = $reasonPhrase;
    }

    /**
     * Retrieves the HTTP protocol version as a string.
     *
     * The string MUST contain only the HTTP version number (e.g., "1.1", "1.0").
     *
     * @return string HTTP protocol version.
     */
    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    /**
     * Return an instance with the specified HTTP protocol version.
     *
     * The version string MUST contain only the HTTP version number (e.g.,
     * "1.1", "1.0").
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new protocol version.
     *
     * @param string $version HTTP protocol version
     * @return static
     */
    public function withProtocolVersion($version): MessageInterface
    {
        $this->validateProtocolVersion($version);
        $new = clone $this;
        $new->protocol = $version;
        return $new;
    }

    /**
     * Retrieves all message headers.
     *
     * The keys represent the header name as it will be sent over the wire, and
     * each value is an array of strings associated with the header.
     *
     *     // Represent the headers as a string
     *     foreach ($message->getHeaders() as $name => $values) {
     *         echo $name . ": " . implode(", ", $values);
     *     }
     *
     *     // Emit headers iteratively:
     *     foreach ($message->getHeaders() as $name => $values) {
     *         foreach ($values as $value) {
     *             header(sprintf('%s: %s', $name, $value), false);
     *         }
     *     }
     *
     * @return array<string,array<string>> Returns an associative array of the message's headers. Each
     *     key MUST be a header name, and each value MUST be an array of strings.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Checks if a header exists by the given case-insensitive name.
     *
     * @param string $name Case-insensitive header name.
     * @return bool Returns true if any header names match the given header
     *     name using a case-insensitive string comparison. Returns false if
     *     no matching header name is found in the message.
     */
    public function hasHeader($name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * Retrieves a message header value by the given case-insensitive name.
     *
     * This method returns an array of all the header values of the given
     * case-insensitive header name.
     *
     * If the header does not appear in the message, this method MUST return an
     * empty array.
     *
     * @param string $name Case-insensitive header field name.
     * @return string[] An array of string values as provided for the given
     *    header. If the header does not appear in the message, this method MUST
     *    return an empty array.
     */
    public function getHeader($name): array
    {
        if (!$this->hasHeader($name)) {
            return [];
        }

        $name = $this->headerNames[strtolower($name)];

        return $this->headers[$name];
    }

    /**
     * Retrieves a comma-separated string of the values for a single header.
     *
     * This method returns all the header values of the given
     * case-insensitive header name as a string concatenated together using
     * a comma.
     *
     * NOTE: Not all header values may be appropriately represented using
     * comma concatenation. For such headers, use getHeader() instead
     * and supply your own delimiter when concatenating.
     *
     * If the header does not appear in the message, this method MUST return
     * an empty string.
     *
     * @param string $name Case-insensitive header field name.
     * @return string A string of values as provided for the given header
     *    concatenated together using a comma. If the header does not appear in
     *    the message, this method MUST return an empty string.
     */
    public function getHeaderLine($name): string
    {
        $value = $this->getHeader($name);
        if (empty($value)) {
            return '';
        }

        return implode(',', $value);
    }

    /**
     * Return an instance with the provided header, replacing any existing
     * values of any headers with the same case-insensitive name.
     *
     * While header names are case-insensitive, the casing of the header will
     * be preserved by this function, and returned from getHeaders().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new and/or updated header and value.
     *
     * @param string $name Case-insensitive header field name.
     * @param string|string[] $value Header value(s).
     * @return static
     * @throws InvalidArgumentException for invalid header names or values.
     */
    public function withHeader($name, $value): MessageInterface
    {
        $this->assertHeader($name);

        $normalized = strtolower($name);

        $new = clone $this;
        if ($new->hasHeader($name)) {
            unset($new->headers[$new->headerNames[$normalized]]);
        }

        $value = $this->filterHeaderValue($value);

        $new->headerNames[$normalized] = $name;
        $new->headers[$name] = $value;

        return $new;
    }

    /**
     * Return an instance with the specified header appended with the
     * given value.
     *
     * Existing values for the specified header will be maintained. The new
     * value(s) will be appended to the existing list. If the header did not
     * exist previously, it will be added.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new header and/or value.
     *
     * @param string $name Case-insensitive header field name to add.
     * @param string|string[] $value Header value(s).
     * @return static
     * @throws InvalidArgumentException for invalid header names or values.
     */
    public function withAddedHeader($name, $value): MessageInterface
    {
        $this->assertHeader($name);

        if (!$this->hasHeader($name)) {
            return $this->withHeader($name, $value);
        }

        $name = $this->headerNames[strtolower($name)];

        $new = clone $this;
        $value = $this->filterHeaderValue($value);
        $new->headers[$name] = array_merge($this->headers[$name], $value);
        return $new;
    }

    /**
     * Return an instance without the specified header.
     *
     * Header resolution MUST be done without case-sensitivity.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that removes
     * the named header.
     *
     * @param string $name Case-insensitive header field name to remove.
     * @return static
     */
    public function withoutHeader($name): MessageInterface
    {
        if (!$this->hasHeader($name)) {
            return clone $this;
        }

        $normalized = strtolower($name);
        $original = $this->headerNames[$normalized];

        $new = clone $this;
        unset($new->headers[$original], $new->headerNames[$normalized]);
        return $new;
    }

    /**
     * Gets the body of the message.
     *
     * @return StreamInterface Returns the body as a stream.
     */
    public function getBody(): StreamInterface
    {
        return $this->stream;
    }

    /**
     * Return an instance with the specified message body.
     *
     * The body MUST be a StreamInterface object.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return a new instance that has the
     * new body stream.
     *
     * @param StreamInterface $body Body.
     * @return static
     * @throws InvalidArgumentException When the body is not valid.
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        $new = clone $this;
        $new->stream = $body;
        return $new;
    }

    private function getStream($stream): StreamInterface
    {
        if ($stream instanceof StreamInterface) {
            return $stream;
        }

        if (!is_string($stream) && !is_resource($stream)) {
            throw new InvalidArgumentException(
                'Stream must be a string stream resource identifier, '
                . 'an actual stream resource, '
                . 'or a Psr\Http\Message\StreamInterface implementation'
            );
        }

        return Stream::create($stream);
    }

    /**
     * Filter a set of headers to ensure they are in the correct internal format.
     *
     * Used by message constructors to allow setting all initial headers at once.
     *
     * @param array $originalHeaders Headers to filter.
     */
    private function setHeaders(array $originalHeaders): void
    {
        $headerNames = $headers = [];

        foreach ($originalHeaders as $header => $value) {
            $value = $this->filterHeaderValue($value);

            $this->assertHeader($header);

            $headerNames[strtolower($header)] = $header;
            $headers[$header] = $value;
        }

        $this->headerNames = $headerNames;
        $this->headers = $headers;
    }

    /**
     * Validate the HTTP protocol version
     *
     * @param string $version
     * @throws InvalidArgumentException on invalid HTTP protocol version
     */
    private function validateProtocolVersion(string $version): void
    {
        if (empty($version)) {
            throw new InvalidArgumentException(
                'HTTP protocol version can not be empty'
            );
        }

        // HTTP/1 uses a "<major>.<minor>" numbering scheme to indicate
        // versions of the protocol, while HTTP/2 does not.
        if (!preg_match('#^(1\.[01]|2(\.0)?)$#', $version)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported HTTP protocol version "%s" provided',
                    $version
                )
            );
        }
    }

    /**
     * @param mixed $values
     * @return string[]
     */
    private function filterHeaderValue(mixed $values): array
    {
        if (!is_array($values)) {
            $values = [$values];
        }

        if ([] === $values) {
            throw new InvalidArgumentException(
                'Invalid header value: must be a string or array of strings; '
                . 'cannot be an empty array'
            );
        }

        return array_map(function ($value) {
            HeaderSecurity::assertValid($value);

            return (string)$value;
        }, array_values($values));
    }

    /**
     * Ensure header name and values are valid.
     *
     * @param string $name
     *
     * @throws InvalidArgumentException
     */
    private function assertHeader(string $name): void
    {
        HeaderSecurity::assertValidName($name);
    }

    /**
     * Inject the provided Content-Type, if none is already present.
     *
     * @return array Headers with injected Content-Type
     */
    protected function injectContentType(string $contentType, array $headers): array
    {
        $hasContentType = array_reduce(
            array_keys($headers),
            function ($carry, $item) {
                return $carry ?: (strtolower($item) === 'content-type');
            },
            false
        );

        if (!$hasContentType) {
            $headers['content-type'] = [$contentType];
        }

        return $headers;
    }

    /**
     * Create the message body.
     *
     * @param string|StreamInterface $text
     * @return StreamInterface
     *
     * @throws InvalidArgumentException if $text is neither a string nor stream.
     */
    protected function createBody(string|StreamInterface $text): StreamInterface
    {
        if ($text instanceof StreamInterface) {
            return $text;
        }

        if (!is_string($text)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid content (%s) provided to %s',
                    (get_debug_type($text)),
                    __CLASS__
                )
            );
        }

        return Stream::create($text);
    }
}
