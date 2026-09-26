<?php

declare(strict_types=1);

namespace App\Tests\Api\Error;

use App\Api\Error\InvalidParameterException;
use App\Api\Error\ProblemResponseFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

final class ProblemResponseFactoryTest extends TestCase
{
    public function testInvalidParameterResponseCarriesDetailAndErrors(): void
    {
        $factory = new ProblemResponseFactory(new RecordingLogger());
        $request = Request::create('/v1/variants');

        $exception = new InvalidParameterException(
            "Parameter 'sort' must be one of: acceleration, -acceleration.",
            [['parameter' => 'sort', 'reason' => 'not_allowed_value']],
        );

        $response = $factory->invalidParameter($request, $exception);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $body = self::decode($response->getContent());
        self::assertSame('https://api.example.com/problems/invalid-parameter', $body['type']);
        self::assertSame(400, $body['status']);
        self::assertSame('/v1/variants', $body['instance']);
        self::assertSame("Parameter 'sort' must be one of: acceleration, -acceleration.", $body['detail']);
        self::assertSame([['parameter' => 'sort', 'reason' => 'not_allowed_value']], $body['errors']);
    }

    public function testMethodNotAllowedCopiesAllowHeaderFromException(): void
    {
        $factory = new ProblemResponseFactory(new RecordingLogger());
        $request = Request::create('/v1/variants');

        $response = $factory->methodNotAllowed($request, new MethodNotAllowedHttpException(['GET']));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
    }

    public function testInternalErrorHidesTheExceptionMessageAndCarriesATraceIdThatMatchesTheLog(): void
    {
        $logger = new RecordingLogger();
        $factory = new ProblemResponseFactory($logger);
        $request = Request::create('/v1/variants');

        $response = $factory->internalError($request, new RuntimeException('database password is hunter2'));

        $body = self::decode($response->getContent());

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('hunter2', (string) $response->getContent());
        self::assertArrayNotHasKey('detail', $body);
        self::assertArrayHasKey('traceId', $body);
        self::assertNotSame('', $body['traceId']);

        self::assertCount(1, $logger->records);
        self::assertSame($body['traceId'], $logger->records[0]['context']['traceId']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string|false $content): array
    {
        if ($content === false) {
            self::fail('Response has no content.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
