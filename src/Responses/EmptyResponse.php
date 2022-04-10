<?php

declare(strict_types=1);

namespace App\Responses;

use App\Libs\HttpStatus;

class EmptyResponse extends Response
{
    public function __construct(HttpStatus $status = HttpStatus::NO_CONTENT, array $headers = [])
    {
        parent::__construct(status: $status, headers: $headers);
    }
}
