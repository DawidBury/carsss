<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthReturnsOkWithoutAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertJsonStringEqualsJsonString('{}', (string) $client->getResponse()->getContent());
    }

    public function testHealthRejectsNonGetMethods(): void
    {
        $client = static::createClient();
        $client->request('POST', '/health');

        self::assertSame(405, $client->getResponse()->getStatusCode());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('Allow', 'GET');
    }
}
