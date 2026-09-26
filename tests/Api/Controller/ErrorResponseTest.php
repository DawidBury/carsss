<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Request\CursorCodec;
use App\Tests\Api\ApiKeyFixture;
use App\Tests\Api\DecodesJsonResponses;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ErrorResponseTest extends WebTestCase
{
    use ApiKeyFixture;
    use DecodesJsonResponses;

    private KernelBrowser $client;
    private Connection $connection;
    private string $apiKey;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetApiKeys($this->connection);
        $this->apiKey = $this->createApiKey($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetApiKeys($this->connection);
        parent::tearDown();
    }

    public function testUnknownPathReturnsNotFoundProblem(): void
    {
        $this->client->request('GET', '/v1/does-not-exist', server: $this->authHeader());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertSame('https://api.example.com/problems/not-found', $body['type']);
        self::assertSame('/v1/does-not-exist', $body['instance']);
    }

    public function testWrongMethodReturnsMethodNotAllowedWithAllowHeader(): void
    {
        $this->client->request('POST', '/v1/variants', server: $this->authHeader());

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        self::assertResponseHeaderSame('Allow', 'GET');

        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertSame('https://api.example.com/problems/method-not-allowed', $body['type']);
    }

    public function testNonUuidVariantIdIsBadRequestNotNotFound(): void
    {
        $this->client->request('GET', '/v1/variants/not-a-uuid', server: $this->authHeader());

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertSame([['parameter' => 'id', 'reason' => 'invalid_uuid']], $body['errors']);
    }

    public function testUnknownButValidUuidVariantIdIsNotFound(): void
    {
        $this->client->request('GET', '/v1/variants/00000000-0000-0000-0000-000000000000', server: $this->authHeader());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownQueryParameterIsBadRequest(): void
    {
        $this->client->request('GET', '/v1/variants?bogus=1', server: $this->authHeader());

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertSame([['parameter' => 'bogus', 'reason' => 'unknown_parameter']], $body['errors']);
    }

    #[DataProvider('invalidLimits')]
    public function testLimitOutOfBoundsIsRejectedNotSilentlyClamped(string $limit): void
    {
        $this->client->request('GET', "/v1/variants?limit={$limit}", server: $this->authHeader());

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $firstError = self::jsonArray(self::jsonArray($body['errors'])[0]);
        self::assertSame('limit', $firstError['parameter']);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'above max' => ['201'];
        yield 'not numeric' => ['abc'];
    }

    public function testUnknownFuelTypeValueIsBadRequestNotEmptyCollection(): void
    {
        $this->client->request('GET', '/v1/variants?fuelType=nuclear', server: $this->authHeader());

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $firstError = self::jsonArray(self::jsonArray($body['errors'])[0]);
        self::assertSame('fuelType', $firstError['parameter']);
        self::assertSame('not_allowed_value', $firstError['reason']);
    }

    public function testUnknownUuidFilterReturnsEmptyCollectionNotNotFound(): void
    {
        $this->client->request(
            'GET',
            '/v1/variants?brandId=00000000-0000-0000-0000-000000000000',
            server: $this->authHeader(),
        );

        self::assertResponseIsSuccessful();
        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertSame([], $body['data']);
    }

    public function testMalformedCursorIsBadRequest(): void
    {
        $this->client->request('GET', '/v1/variants?cursor=not-valid-base64!!!', server: $this->authHeader());

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $firstError = self::jsonArray(self::jsonArray($body['errors'])[0]);
        self::assertSame('invalid_cursor', $firstError['reason']);
    }

    public function testCursorWithNonNumericAccelerationValueIsBadRequestNotInternalError(): void
    {
        $cursor = new CursorCodec()->encode(
            'not-a-number',
            '9f1c0f1e-4b2a-4b60-9a1e-4f7c3a1d55e2',
            ['sort' => 'acceleration'],
        );

        $this->client->request('GET', "/v1/variants?cursor={$cursor}", server: $this->authHeader());

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $firstError = self::jsonArray(self::jsonArray($body['errors'])[0]);
        self::assertSame('cursor', $firstError['parameter']);
        self::assertSame('invalid_cursor', $firstError['reason']);
    }

    public function testCursorWithInvalidPublicIdIsBadRequestNotInternalError(): void
    {
        $cursor = new CursorCodec()->encode('9.3', 'not-a-uuid-at-all', ['sort' => 'acceleration']);

        $this->client->request('GET', "/v1/variants?cursor={$cursor}", server: $this->authHeader());

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $firstError = self::jsonArray(self::jsonArray($body['errors'])[0]);
        self::assertSame('cursor', $firstError['parameter']);
        self::assertSame('invalid_cursor', $firstError['reason']);
    }

    /**
     * @return array<string, string>
     */
    private function authHeader(): array
    {
        return ['HTTP_X_API_KEY' => $this->apiKey];
    }
}
