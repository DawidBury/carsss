<?php

declare(strict_types=1);

namespace App\Catalog\Reader;

use App\Catalog\Criteria\ModelListCriteria;
use App\Catalog\Result\Brand;
use App\Catalog\Result\Model;
use App\Catalog\Result\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class ModelReader
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return Page<Model>
     */
    public function list(ModelListCriteria $criteria): Page
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($criteria->brandId !== null) {
            $conditions[] = 'b.public_id = :brandId';
            $params['brandId'] = $criteria->brandId;
        }

        if ($criteria->cursor !== null) {
            $conditions[] = 'ROW(m.name, m.public_id) > ROW(:afterValue, :afterPublicId)';
            $params['afterValue'] = $criteria->cursor->afterValue;
            $params['afterPublicId'] = $criteria->cursor->afterPublicId;
        }

        $sql = 'SELECT m.public_id AS model_public_id, m.name AS model_name, '
            . 'b.public_id AS brand_public_id, b.name AS brand_name '
            . 'FROM car_model m JOIN brand b ON b.id = m.brand_id';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY m.name, m.public_id LIMIT :fetchLimit';
        $params['fetchLimit'] = $criteria->limit + 1;
        $types['fetchLimit'] = ParameterType::INTEGER;

        /** @var list<array{model_public_id: string, model_name: string, brand_public_id: string, brand_name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $hasMore = count($rows) > $criteria->limit;
        $rows = \array_slice($rows, 0, $criteria->limit);

        $items = array_map(
            static fn (array $row): Model => new Model(
                $row['model_public_id'],
                $row['model_name'],
                new Brand($row['brand_public_id'], $row['brand_name']),
            ),
            $rows,
        );

        return new Page($items, $hasMore);
    }
}
