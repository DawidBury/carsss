<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Request\CursorCodec;
use App\Tests\Api\ApiKeyFixture;
use App\Tests\Api\DecodesJsonResponses;
use App\Tests\Catalog\TaxonomyFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ModelControllerTest extends WebTestCase
{
    use ApiKeyFixture;
    use DecodesJsonResponses;
    use TaxonomyFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private string $apiKey;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetTaxonomySchema($this->connection);
        $this->resetApiKeys($this->connection);
        $this->apiKey = $this->createApiKey($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetTaxonomySchema($this->connection);
        $this->resetApiKeys($this->connection);
        parent::tearDown();
    }

    public function testFiltersModelsByBrandIdWithNestedBrand(): void
    {
        $run = $this->createRun($this->connection);
        $audiId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $bmwId = $this->insertBrand($this->connection, $run, 'bmw', 'BMW');
        $this->insertModel($this->connection, $run, $audiId, 'audi/a4', 'A4');
        $this->insertModel($this->connection, $run, $bmwId, 'bmw/3', '3 Series');

        $audiPublicId = self::publicId($this->connection, 'brand', $audiId);

        $this->client->request(
            'GET',
            "/v1/models?brandId={$audiPublicId}",
            server: ['HTTP_X_API_KEY' => $this->apiKey],
        );

        self::assertResponseIsSuccessful();
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $data = self::jsonArray($body['data']);
        self::assertCount(1, $data);

        $model = self::jsonArray($data[0]);
        self::assertSame('A4', $model['name']);
        self::assertSame('Audi', self::jsonArray($model['brand'])['name']);
    }

    public function testInvalidBrandIdIsBadRequest(): void
    {
        $this->client->request(
            'GET',
            '/v1/models?brandId=not-a-uuid',
            server: ['HTTP_X_API_KEY' => $this->apiKey],
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testCursorWithInvalidPublicIdIsBadRequestNotInternalError(): void
    {
        $cursor = new CursorCodec()->encode('A4', 'not-a-uuid-at-all', []);

        $this->client->request('GET', "/v1/models?cursor={$cursor}", server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $firstError = self::jsonArray(self::jsonArray($body['errors'])[0]);
        self::assertSame('cursor', $firstError['parameter']);
        self::assertSame('invalid_cursor', $firstError['reason']);
    }
}
