<?php

declare(strict_types=1);

namespace App\Api\Request;

use App\Catalog\Criteria\ModelListCriteria;
use Symfony\Component\HttpFoundation\Request;

final readonly class ModelListRequestParser
{
    private const ALLOWED_PARAMETERS = ['brandId', 'limit', 'cursor'];

    public function __construct(private CursorParameter $cursorParameter)
    {
    }

    public function parse(Request $request): ModelListCriteria
    {
        $query = $request->query->all();

        QueryValidation::rejectUnknownParameters($query, self::ALLOWED_PARAMETERS);

        $limit = QueryValidation::limit($query);
        $brandId = QueryValidation::uuid($query, 'brandId');

        $context = self::contextFor($brandId);
        $cursor = $this->cursorParameter->parse($query, $context, CursorValueType::Text);

        return new ModelListCriteria($limit, $brandId, $cursor);
    }

    /**
     * @return array<string, string>
     */
    public static function contextFor(?string $brandId): array
    {
        return $brandId === null ? [] : ['brandId' => $brandId];
    }
}
