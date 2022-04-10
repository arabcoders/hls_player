<?php

declare(strict_types=1);

namespace App\Responses;

use App\Libs\HttpStatus;
use InvalidArgumentException;
use JsonException;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

class JsonResponse extends Response
{
    /**
     * @var mixed
     */
    private mixed $payload;

    /**
     * @var int  JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
     */
    private int $encodingOptions = 79;

    /**
     * Create a JSON response with the given data.
     *
     * Default JSON encoding is performed with the following options, which
     * produces RFC4627-compliant JSON, capable of embedding into HTML.
     *
     * - JSON_HEX_TAG
     * - JSON_HEX_APOS
     * - JSON_HEX_AMP
     * - JSON_HEX_QUOT
     * - JSON_UNESCAPED_SLASHES
     *
     * @param mixed $data Data to convert to JSON.
     * @param HttpStatus $status Integer status code for the response; 200 by default.
     * @param array $headers Array of headers to use at initialization.
     * @param int $encodingOptions JSON encoding options to use.
     * @throws InvalidArgumentException if unable to encode the $data to JSON.
     */
    public function __construct(
        mixed $data,
        HttpStatus $status = HttpStatus::OK,
        array $headers = [],
        int $encodingOptions = 79
    ) {
        $this->setPayload($data);
        $this->encodingOptions = $encodingOptions;

        $json = $this->jsonEncode($data, $this->encodingOptions);
        $body = $this->createBodyFromJson($json);

        parent::__construct(
            body:    $body,
            status:  $status,
            headers: $this->injectContentType('application/json', $headers)
        );
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }

    public function withPayload(mixed $data): self
    {
        $new = clone $this;
        $new->setPayload($data);
        return $this->updateBodyFor($new);
    }

    public function getEncodingOptions(): int
    {
        return $this->encodingOptions;
    }

    public function withEncodingOptions(int $encodingOptions): self
    {
        $new = clone $this;
        $new->encodingOptions = $encodingOptions;
        return $this->updateBodyFor($new);
    }

    private function createBodyFromJson(string $json): StreamInterface
    {
        return Stream::create($json);
    }

    private function jsonEncode(mixed $data, int $encodingOptions): string
    {
        if (is_resource($data)) {
            throw new InvalidArgumentException('Cannot JSON encode resources');
        }

        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | $encodingOptions);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to encode data to JSON in %s: %s',
                    __CLASS__,
                    $e->getMessage()
                )
            );
        }

        return $json;
    }

    /**
     * @param mixed $data
     */
    private function setPayload(mixed $data): void
    {
        if (is_object($data)) {
            $data = clone $data;
        }

        $this->payload = $data;
    }

    /**
     * Update the response body for the given instance.
     *
     * @param self $toUpdate Instance to update.
     * @return JsonResponse Returns a new instance with an updated body.
     */
    private function updateBodyFor(self $toUpdate): self
    {
        return $toUpdate->withBody(
            $this->createBodyFromJson(
                $this->jsonEncode($toUpdate->payload, $toUpdate->encodingOptions)
            )
        );
    }
}
