<?php

declare(strict_types=1);

namespace App\Api\Error;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Uid\Ulid;
use Throwable;

final readonly class ProblemResponseFactory
{
    private const string BASE_URI = 'https://api.example.com/problems/';
    private const string CONTENT_TYPE = 'application/problem+json';

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function invalidParameter(Request $request, InvalidParameterException $exception): JsonResponse
    {
        return $this->build(
            status: 400,
            slug: 'invalid-parameter',
            title: 'Invalid request parameter',
            instance: $request->getPathInfo(),
            detail: $exception->detail,
            errors: $exception->violations,
        );
    }

    public function unauthorized(Request $request): JsonResponse
    {
        $response = $this->build(
            status: 401,
            slug: 'unauthorized',
            title: 'Unauthorized',
            instance: $request->getPathInfo(),
        );
        $response->headers->set('WWW-Authenticate', 'ApiKey realm="cars-api"');

        return $response;
    }

    public function notFound(Request $request, ?string $detail = null): JsonResponse
    {
        return $this->build(
            status: 404,
            slug: 'not-found',
            title: 'Resource not found',
            instance: $request->getPathInfo(),
            detail: $detail,
        );
    }

    public function methodNotAllowed(Request $request, MethodNotAllowedHttpException $exception): JsonResponse
    {
        $response = $this->build(
            status: 405,
            slug: 'method-not-allowed',
            title: 'Method not allowed',
            instance: $request->getPathInfo(),
        );
        foreach ($exception->getHeaders() as $name => $value) {
            if (!\is_string($name) || !\is_string($value)) {
                continue;
            }
            $response->headers->set($name, $value);
        }

        return $response;
    }

    public function internalError(Request $request, Throwable $throwable): JsonResponse
    {
        $traceId = (string) new Ulid();
        $this->logger->error('Unhandled exception while processing request.', [
            'traceId' => $traceId,
            'exception' => $throwable,
        ]);

        return new JsonResponse(
            [
                'type' => self::BASE_URI . 'internal-error',
                'title' => 'Internal server error',
                'status' => 500,
                'instance' => $request->getPathInfo(),
                'traceId' => $traceId,
            ],
            500,
            ['Content-Type' => self::CONTENT_TYPE],
        );
    }

    /**
     * @param list<array{parameter: string, reason: string}> $errors
     */
    private function build(
        int $status,
        string $slug,
        string $title,
        string $instance,
        ?string $detail = null,
        array $errors = [],
    ): JsonResponse {
        $payload = [
            'type' => self::BASE_URI . $slug,
            'title' => $title,
            'status' => $status,
            'instance' => $instance,
        ];

        if ($detail !== null) {
            $payload['detail'] = $detail;
        }

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return new JsonResponse($payload, $status, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
