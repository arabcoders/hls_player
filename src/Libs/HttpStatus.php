<?php

declare(strict_types=1);

namespace App\Libs;

enum HttpStatus: int
{
    case OK = 200;
    case ACCEPTED = 202;
    case ALREADY_REPORTED = 208;
    case NOT_FOUND = 404;
    case BAD_REQUEST = 400;
    case UNAUTHORIZED = 401;
    case FORBIDDEN = 403;
    case NOT_ACCEPTABLE = 406;
    case METHOD_NOT_ALLOWED = 405;
    case GONE = 410;
    case UNSUPPORTED_MEDIA_TYPE = 415;
    case UNPROCESSABLE_ENTITY = 422;
    case PAYLOAD_TOO_LARGE = 413;
    case INTERNAL_SERVER_ERROR = 500;
    case GATEWAY_TIMEOUT = 504;
    case NO_CONTENT = 204;
    case FOUND = 302;
    case TEMP_REDIRECT = 307;
    case NOT_MODIFIED = 304;
    case MOVED_PERMANENTLY = 301;
    case PARTIAL_CONTENT = 206;
    case REQUESTED_RANGE_NOT_SATISFIABLE = 416;
}
