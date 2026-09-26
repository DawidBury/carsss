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

final class BrandControllerTest extends WebTestCase
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

    public function testListsBrandsSortedByName(): void
    {
        $run = $this->createRun($this->connection);
        $this->insertBrand($this->connection, $run, 'skoda', 'Skoda');
        $this->insertBrand($this->connection, $run, 'audi', 'Audi');

        $this->client->request('GET', '/v1/brands', server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertResponseIsSuccessful();
        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertSame(['Audi', 'Skoda'], array_column(self::jsonArray($body['data']), 'name'));
    }

    public function testCursorWithInvalidPublicIdIsBadRequestNotInternalError(): void
    {
        $cursor = new CursorCodec()->encode('Abarth', 'not-a-uuid-at-all', []);

        $this->client->request('GET', "/v1/brands?cursor={$cursor}", server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $firstError = self::jsonArray(self::jsonArray($body['errors'])[0]);
        self::assertSame('cursor', $firstError['parameter']);
        self::assertSame('invalid_cursor', $firstError['reason']);
    }
}
