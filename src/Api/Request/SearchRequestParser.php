<?php

declare(strict_types=1);

namespace App\Api\Request;

use App\Catalog\Criteria\SearchCriteria;
use Symfony\Component\HttpFoundation\Request;

final readonly class SearchRequestParser
{
    private const array ALLOWED_PARAMETERS = ['q', 'limit'];
    private const int DEFAULT_LIMIT = 10;
    private const int MAX_LIMIT = 20;
    private const int MAX_PHRASE_LENGTH = 100;

    public function parse(Request $request): SearchCriteria
    {
        $query = $request->query->all();

        QueryValidation::rejectUnknownParameters($query, self::ALLOWED_PARAMETERS);

        $phrase = QueryValidation::requiredPhrase($query, 'q', self::MAX_PHRASE_LENGTH);
        $limit = QueryValidation::limitBetween($query, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        return new SearchCriteria($phrase, $limit);
    }
}
