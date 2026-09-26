<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Request\SearchRequestParser;
use App\Api\Response\JsonResponseFactory;
use App\Api\Response\SearchHitView;
use App\Catalog\Reader\SearchReader;
use App\Catalog\Result\SearchHit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SearchController
{
    public function __construct(
        private SearchRequestParser $requestParser,
        private SearchReader $reader,
    ) {
    }

    #[Route('/v1/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $criteria = $this->requestParser->parse($request);
        $hits = $this->reader->search($criteria);

        return JsonResponseFactory::create([
            'data' => array_map(static fn (SearchHit $hit): SearchHitView => new SearchHitView($hit), $hits),
        ]);
    }
}
