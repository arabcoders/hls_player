<?php

declare(strict_types=1);

namespace App\Libs;

enum HttpRequest: string
{
    case POST = 'POST';
    case GET = 'GET';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case PATCH = 'PATCH';
    case OPTIONS = 'OPTIONS';
    case HEAD = 'HEAD';
}
