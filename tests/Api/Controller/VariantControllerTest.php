<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Tests\Api\ApiKeyFixture;
use App\Tests\Api\DecodesJsonResponses;
use App\Tests\Catalog\TaxonomyFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VariantControllerTest extends WebTestCase
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

    public function testCollectionShapeNestsVersionModelAndBrandWithNumericAccelerationField(): void
    {
        $this->seedOneVariant();

        $this->client->request('GET', '/v1/variants', server: $this->authHeader());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $raw = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/"acceleration0To100S":9\.3(?!")/', $raw);

        $body = self::decodeJson($raw);
        $data = self::jsonArray($body['data']);
        $variant = self::jsonArray($data[0]);
        $version = self::jsonArray($variant['version']);
        $model = self::jsonArray($version['model']);
        $brand = self::jsonArray($model['brand']);
        $page = self::jsonArray($body['page']);

        self::assertIsFloat($variant['acceleration0To100S']);
        self::assertSame(9.3, $variant['acceleration0To100S']);
        self::assertSame('manual', $variant['gearbox']);
        self::assertSame('front', $variant['drive']);
        self::assertSame('diesel', $version['fuelType']);
        self::assertSame('b8', $version['generation']);
        self::assertSame('sedan', $version['bodyStyle']);
        self::assertNull($version['unresolvedSegment']);
        self::assertIsFloat($version['powerHp']);
        self::assertSame('A4', $model['name']);
        self::assertSame('Audi', $brand['name']);
        self::assertSame(50, $page['limit']);
        self::assertNull($page['next']);
    }

    public function testSingleVariantEndpointReturnsUnwrappedObject(): void
    {
        $this->seedOneVariant();
        $publicId = self::asString($this->connection->fetchOne('SELECT public_id FROM variant'));

        $this->client->request('GET', "/v1/variants/{$publicId}", server: $this->authHeader());

        self::assertResponseIsSuccessful();
        $body = self::decodeJson($this->client->getResponse()->getContent());

        self::assertSame($publicId, $body['id']);
        self::assertArrayNotHasKey('data', $body);
    }

    public function testCursorPaginationRoundTripsThroughHttpWithoutSkippingOrDuplicating(): void
    {
        $this->seedAccelerationSpread();

        $this->client->request('GET', '/v1/variants?limit=2', server: $this->authHeader());
        $first = self::decodeJson($this->client->getResponse()->getContent());
        $firstData = self::jsonArray($first['data']);
        $firstPage = self::jsonArray($first['page']);
        self::assertCount(2, $firstData);
        self::assertNotNull($firstPage['next']);

        $this->client->request(
            'GET',
            '/v1/variants?limit=2&cursor=' . self::jsonString($firstPage['next']),
            server: $this->authHeader(),
        );
        $second = self::decodeJson($this->client->getResponse()->getContent());
        $secondData = self::jsonArray($second['data']);
        $secondPage = self::jsonArray($second['page']);
        self::assertCount(2, $secondData);
        self::assertNotNull($secondPage['next']);

        $this->client->request(
            'GET',
            '/v1/variants?limit=2&cursor=' . self::jsonString($secondPage['next']),
            server: $this->authHeader(),
        );
        $third = self::decodeJson($this->client->getResponse()->getContent());
        $thirdData = self::jsonArray($third['data']);
        $thirdPage = self::jsonArray($third['page']);
        self::assertCount(1, $thirdData);
        self::assertNull($thirdPage['next']);

        $ids = array_merge(
            array_column($firstData, 'id'),
            array_column($secondData, 'id'),
            array_column($thirdData, 'id'),
        );
        self::assertCount(5, array_unique(array_map(self::jsonString(...), $ids)));
    }

    public function testChangingFilterWhileReusingCursorIsRejected(): void
    {
        $this->seedAccelerationSpread();

        $this->client->request('GET', '/v1/variants?limit=2', server: $this->authHeader());
        $first = self::decodeJson($this->client->getResponse()->getContent());
        $next = self::jsonString(self::jsonArray($first['page'])['next']);

        $this->client->request(
            'GET',
            '/v1/variants?limit=2&gearbox=manual&cursor=' . $next,
            server: $this->authHeader(),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $body = self::decodeJson($this->client->getResponse()->getContent());
        $firstError = self::jsonArray(self::jsonArray($body['errors'])[0]);
        self::assertSame('cursor_mismatch', $firstError['reason']);
    }

    /**
     * @return array<string, string>
     */
    private function authHeader(): array
    {
        return ['HTTP_X_API_KEY' => $this->apiKey];
    }

    private function seedOneVariant(): void
    {
        $run = $this->createRun($this->connection);
        $brandId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $modelId = $this->insertModel($this->connection, $run, $brandId, 'audi/a4', 'A4');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/v1',
            '2.0 TDI',
            'diesel',
            ['generation' => 'b8', 'bodyStyle' => 'sedan', 'powerHp' => 140.0],
            [['ref' => 'a', 'acceleration' => 9.3, 'fuelConsumption' => 6.3, 'fuelTankL' => 65, 'gearbox' => 'manual', 'drive' => 'front']],
        );
    }

    private function seedAccelerationSpread(): void
    {
        $run = $this->createRun($this->connection);
        $brandId = $this->insertBrand($this->connection, $run, 'audi', 'Audi');
        $modelId = $this->insertModel($this->connection, $run, $brandId, 'audi/a4', 'A4');
        $this->insertVersionWithVariants(
            $this->connection,
            $run,
            $modelId,
            'audi/a4/v1',
            '2.0 TDI',
            'diesel',
            [],
            [
                ['ref' => 'a', 'acceleration' => 9.0],
                ['ref' => 'b', 'acceleration' => 7.1],
                ['ref' => 'c', 'acceleration' => 12.0],
                ['ref' => 'd', 'acceleration' => 5.5],
                ['ref' => 'e', 'acceleration' => 8.0],
            ],
        );
    }
}
