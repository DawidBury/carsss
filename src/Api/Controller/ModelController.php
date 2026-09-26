<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Request\CursorCodec;
use App\Api\Request\ModelListRequestParser;
use App\Api\Response\JsonResponseFactory;
use App\Api\Response\ModelView;
use App\Catalog\Reader\ModelReader;
use App\Catalog\Result\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ModelController
{
    public function __construct(
        private ModelListRequestParser $requestParser,
        private ModelReader $reader,
        private CursorCodec $cursorCodec,
    ) {
    }

    #[Route('/v1/models', name: 'models_list', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $criteria = $this->requestParser->parse($request);
        $page = $this->reader->list($criteria);

        $next = null;
        if ($page->hasMore && $page->items !== []) {
            $last = $page->items[\count($page->items) - 1];
            $context = ModelListRequestParser::contextFor($criteria->brandId);
            $next = $this->cursorCodec->encode($last->name, $last->id, $context);
        }

        return JsonResponseFactory::create([
            'data' => array_map(static fn (Model $model): ModelView => new ModelView($model), $page->items),
            'page' => ['limit' => $criteria->limit, 'next' => $next],
        ]);
    }
}
