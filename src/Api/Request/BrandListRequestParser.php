<?php

declare(strict_types=1);

namespace App\Api\Request;

use App\Catalog\Criteria\BrandListCriteria;
use Symfony\Component\HttpFoundation\Request;

final readonly class BrandListRequestParser
{
    private const ALLOWED_PARAMETERS = ['limit', 'cursor'];

    public function __construct(private CursorParameter $cursorParameter)
    {
    }

    public function parse(Request $request): BrandListCriteria
    {
        $query = $request->query->all();

        QueryValidation::rejectUnknownParameters($query, self::ALLOWED_PARAMETERS);

        $limit = QueryValidation::limit($query);
        $cursor = $this->cursorParameter->parse($query, [], CursorValueType::Text);

        return new BrandListCriteria($limit, $cursor);
    }
}
