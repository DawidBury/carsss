<?php

declare(strict_types=1);

namespace App\Api\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

final class JsonResponseFactory
{
    public static function create(mixed $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_PRESERVE_ZERO_FRACTION);
        $response->setData($data);

        return $response;
    }
}
