<?php

namespace MiniCog\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class Json
{
    public static function response(array|object $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
