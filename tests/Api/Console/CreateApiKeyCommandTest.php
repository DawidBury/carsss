<?php

declare(strict_types=1);

namespace App\Tests\Api\Console;

use App\Api\Console\CreateApiKeyCommand;
use App\Tests\Api\ApiKeyFixture;
use App\Tests\Api\DatabaseValues;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateApiKeyCommandTest extends KernelTestCase
{
    use ApiKeyFixture;
    use DatabaseValues;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->resetApiKeys($this->connection);
    }

    protected function tearDown(): void
    {
        $this->resetApiKeys($this->connection);
        parent::tearDown();
    }

    public function testPrintsTheSecretOnceAndStoresOnlyItsHash(): void
    {
        $tester = new CommandTester(new CreateApiKeyCommand($this->connection));

        $tester->execute(['name' => 'reporting-worker']);

        self::assertSame(0, $tester->getStatusCode());
        $secret = trim($tester->getDisplay());
        self::assertNotSame('', $secret);

        $storedHashHex = self::asString($this->connection->fetchOne(
            "SELECT encode(key_hash, 'hex') FROM api_key WHERE name = :name",
            ['name' => 'reporting-worker'],
        ));
        self::assertStringNotContainsString($secret, $storedHashHex);
        self::assertSame(hash('sha256', $secret), $storedHashHex);
    }

    public function testDuplicateNameFailsWithoutOverwritingTheExistingKey(): void
    {
        $this->createApiKey($this->connection, 'reporting-worker');

        $tester = new CommandTester(new CreateApiKeyCommand($this->connection));
        $tester->execute(['name' => 'reporting-worker']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(1, self::asInt($this->connection->fetchOne(
            'SELECT count(*) FROM api_key WHERE name = :name',
            ['name' => 'reporting-worker'],
        )));
    }

    public function testBlankNameIsRejected(): void
    {
        $tester = new CommandTester(new CreateApiKeyCommand($this->connection));
        $tester->execute(['name' => '   ']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(0, self::asInt($this->connection->fetchOne('SELECT count(*) FROM api_key')));
    }
}
