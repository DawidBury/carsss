<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Tests\Api\ApiKeyFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiKeyAuthenticationTest extends WebTestCase
{
    use ApiKeyFixture;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetApiKeys($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetApiKeys($this->connection);
        parent::tearDown();
    }

    public function testMissingHeaderIsRejectedWithProblemDetailsAndWwwAuthenticate(): void
    {
        $this->client->request('GET', '/v1/brands');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'ApiKey realm="cars-api"');
    }

    public function testMissingHeaderAndWrongKeyProduceIdenticalResponses(): void
    {
        $this->createApiKey($this->connection, 'real-key');

        $this->client->request('GET', '/v1/brands');
        $missingBody = (string) $this->client->getResponse()->getContent();
        $missingStatus = $this->client->getResponse()->getStatusCode();

        $this->client->request('GET', '/v1/brands', server: ['HTTP_X_API_KEY' => 'totally-wrong-key']);
        $wrongBody = (string) $this->client->getResponse()->getContent();
        $wrongStatus = $this->client->getResponse()->getStatusCode();

        self::assertSame($missingStatus, $wrongStatus);
        self::assertJsonStringEqualsJsonString($missingBody, $wrongBody);
    }

    public function testValidKeyIsAccepted(): void
    {
        $secret = $this->createApiKey($this->connection, 'valid-key');

        $this->client->request('GET', '/v1/brands', server: ['HTTP_X_API_KEY' => $secret]);

        self::assertResponseIsSuccessful();
    }

    public function testHealthDoesNotRequireAuthentication(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseIsSuccessful();
    }
}
