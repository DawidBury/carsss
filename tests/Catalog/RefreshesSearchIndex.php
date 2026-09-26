<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Index\SearchIndexRefresher;
use Doctrine\DBAL\Connection;

trait RefreshesSearchIndex
{
    private function refreshSearchIndex(Connection $connection): void
    {
        new SearchIndexRefresher($connection)->refresh();
    }
}
