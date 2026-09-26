<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\DBAL\Connection;

trait ApiKeyFixture
{
    private function resetApiKeys(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM api_key');
    }

    private function createApiKey(Connection $connection, string $name = 'test-key'): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hashHex = hash('sha256', $secret);

        $connection->executeStatement(
            "INSERT INTO api_key (name, key_hash) VALUES (:name, decode(:hash, 'hex'))",
            ['name' => $name, 'hash' => $hashHex],
        );

        return $secret;
    }
}
