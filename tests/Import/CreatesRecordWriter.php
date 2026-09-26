<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\Writer\RecordWriter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

trait CreatesRecordWriter
{
    private function createRecordWriter(LoggerInterface $logger): RecordWriter
    {
        return new RecordWriter(
            self::getContainer()->get(Connection::class),
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(ManagerRegistry::class),
            $logger,
        );
    }
}
