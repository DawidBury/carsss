<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Tests\Api\ApiKeyFixture;
use App\Tests\Api\DecodesJsonResponses;
use App\Tests\Catalog\RefreshesSearchIndex;
use App\Tests\Catalog\TaxonomyFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SearchControllerTest extends WebTestCase
{
    use ApiKeyFixture;
    use DecodesJsonResponses;
    use RefreshesSearchIndex;
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
        $this->refreshSearchIndex($this->connection);
        $this->apiKey = $this->createApiKey($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetTaxonomySchema($this->connection);
        $this->resetApiKeys($this->connection);
        $this->refreshSearchIndex($this->connection);
        parent::tearDown();
    }

    public function testReturnsSuggestionsCarryingEveryTaxonomyLevel(): void
    {
        $this->givenAudiA4(1);

        $this->client->request('GET', '/v1/search?q=audi+a4', server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertResponseIsSuccessful();
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $data = self::jsonArray($body['data']);
        self::assertCount(1, $data);

        $hit = self::jsonArray($data[0]);
        self::assertSame(
            ['id', 'brand', 'model', 'version', 'fuelType', 'generation', 'bodyStyle', 'unresolvedSegment', 'gearbox', 'drive'],
            array_keys($hit),
        );
        self::assertSame('Audi', $hit['brand']);
        self::assertSame('A4', $hit['model']);
        self::assertSame('2.0 TDI 140KM', $hit['version']);
        self::assertSame('diesel', $hit['fuelType']);
        self::assertSame('sedan', $hit['bodyStyle']);
        self::assertNull($hit['unresolvedSegment']);
    }

    public function testTheIdentifierIsAcceptedByTheVariantResource(): void
    {
        $this->givenAudiA4(1);

        $this->client->request('GET', '/v1/search?q=audi+a4', server: ['HTTP_X_API_KEY' => $this->apiKey]);
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $hit = self::jsonArray(self::jsonArray($body['data'])[0]);

        $id = self::jsonString($hit['id']);

        $this->client->request('GET', '/v1/variants/' . $id, server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertResponseIsSuccessful();
        $variant = self::decodeJson($this->client->getResponse()->getContent());
        self::assertSame($id, $variant['id']);
        self::assertSame(9.3, $variant['acceleration0To100S']);
    }

    public function testTheDefaultLimitIsTen(): void
    {
        $this->givenAudiA4(12);

        $this->client->request('GET', '/v1/search?q=audi+a4', server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertResponseIsSuccessful();
        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertCount(10, self::jsonArray($body['data']));
    }

    public function testTheLimitCanBeRaisedAboveTheDefault(): void
    {
        $this->givenAudiA4(12);

        $this->client->request('GET', '/v1/search?q=audi+a4&limit=12', server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertResponseIsSuccessful();
        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertCount(12, self::jsonArray($body['data']));
    }

    public function testTheLimitCanBeLoweredBelowTheDefault(): void
    {
        $this->givenAudiA4(12);

        $this->client->request('GET', '/v1/search?q=audi+a4&limit=3', server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertResponseIsSuccessful();
        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertCount(3, self::jsonArray($body['data']));
    }

    public function testUnknownPhraseReturnsAnEmptyCollectionNotAnError(): void
    {
        $this->givenAudiA4(1);

        $this->client->request('GET', '/v1/search?q=ferrari', server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertResponseIsSuccessful();
        $body = self::decodeJson($this->client->getResponse()->getContent());
        self::assertSame([], self::jsonArray($body['data']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRequests(): iterable
    {
        yield 'missing q' => ['/v1/search'];
        yield 'blank q' => ['/v1/search?q=+++'];
        yield 'limit below range' => ['/v1/search?q=audi&limit=0'];
        yield 'limit above range' => ['/v1/search?q=audi&limit=21'];
        yield 'limit not a number' => ['/v1/search?q=audi&limit=five'];
        yield 'unknown parameter' => ['/v1/search?q=audi&fuelType=diesel'];
        yield 'phrase too long' => ['/v1/search?q=' . str_repeat('a', 101)];
    }

    #[DataProvider('badRequests')]
    public function testRejectsMalformedRequests(string $uri): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testRequiresAnApiKey(): void
    {
        $this->client->request('GET', '/v1/search?q=audi');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'ApiKey realm="cars-api"');
    }

    public function testRejectsAnyMethodOtherThanGet(): void
    {
        $this->client->request('POST', '/v1/search?q=audi', server: ['HTTP_X_API_KEY' => $this->apiKey]);

        self::assertResponseStatusCodeSame(405);
    }

    private function givenAudiA4(int $variants): void
    {
        $run = $this->createRun($this->connection);
        $audi = $this->insertBrand($this->connection, $run, 'audi', 'Audi');

        $rows = [];
        for ($i = 0; $i < $variants; ++$i) {
            $rows[] = [
                'ref' => 'variant-' . $i,
                'acceleration' => 9.3,
                'gearbox' => 'manual',
                'drive' => 'front',
            ];
        }

        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $this->insertModel($this->connection, $run, $audi, 'audi/a4', 'A4'),
            'audi/a4/b8/sedan/2-0-tdi',
            '2.0 TDI 140KM',
            'diesel',
            ['generation' => 'b8', 'bodyStyle' => 'sedan'],
            $rows,
        );
        $this->refreshSearchIndex($this->connection);
    }
}
