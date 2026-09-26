<?php

declare(strict_types=1);

namespace App\Catalog\Reader;

use App\Catalog\Criteria\BrandListCriteria;
use App\Catalog\Result\Brand;
use App\Catalog\Result\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class BrandReader
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return Page<Brand>
     */
    public function list(BrandListCriteria $criteria): Page
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($criteria->cursor !== null) {
            $conditions[] = 'ROW(name, public_id) > ROW(:afterValue, :afterPublicId)';
            $params['afterValue'] = $criteria->cursor->afterValue;
            $params['afterPublicId'] = $criteria->cursor->afterPublicId;
        }

        $sql = 'SELECT public_id, name FROM brand';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY name, public_id LIMIT :fetchLimit';
        $params['fetchLimit'] = $criteria->limit + 1;
        $types['fetchLimit'] = ParameterType::INTEGER;

        /** @var list<array{public_id: string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $hasMore = count($rows) > $criteria->limit;
        $rows = \array_slice($rows, 0, $criteria->limit);

        $items = array_map(
            static fn (array $row): Brand => new Brand($row['public_id'], $row['name']),
            $rows,
        );

        return new Page($items, $hasMore);
    }
}
