<?php

declare(strict_types=1);

namespace App\Catalog\Index;

use Doctrine\DBAL\Connection;

final readonly class SearchIndexRefresher
{
    public function __construct(private Connection $connection)
    {
    }

    public function refresh(): void
    {
        $this->connection->executeStatement('REFRESH MATERIALIZED VIEW CONCURRENTLY search_term');
        $this->connection->executeStatement('REFRESH MATERIALIZED VIEW CONCURRENTLY variant_search');
    }
}
