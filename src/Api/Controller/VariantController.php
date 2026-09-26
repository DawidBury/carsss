<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Error\InvalidParameterException;
use App\Api\Error\NotFoundException;
use App\Api\Request\CursorCodec;
use App\Api\Request\VariantListRequestParser;
use App\Api\Response\JsonResponseFactory;
use App\Api\Response\VariantView;
use App\Catalog\Criteria\VariantIdCriteria;
use App\Catalog\Reader\VariantReader;
use App\Catalog\Result\Variant;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class VariantController
{
    public function __construct(
        private VariantListRequestParser $requestParser,
        private VariantReader $reader,
        private CursorCodec $cursorCodec,
    ) {
    }

    #[Route('/v1/variants', name: 'variants_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $criteria = $this->requestParser->parse($request);
        $page = $this->reader->list($criteria);

        $next = null;
        if ($page->hasMore && $page->items !== []) {
            $last = $page->items[\count($page->items) - 1];
            $context = VariantListRequestParser::contextFor(
                $criteria->brandId,
                $criteria->modelId,
                $criteria->versionId,
                $criteria->fuelType,
                $criteria->gearbox,
                $criteria->drive,
                $criteria->accelerationMin,
                $criteria->accelerationMax,
                $criteria->sort,
            );
            $next = $this->cursorCodec->encode($last->acceleration0To100S, $last->id, $context);
        }

        return JsonResponseFactory::create([
            'data' => array_map(static fn (Variant $variant): VariantView => new VariantView($variant), $page->items),
            'page' => ['limit' => $criteria->limit, 'next' => $next],
        ]);
    }

    #[Route('/v1/variants/{id}', name: 'variant_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new InvalidParameterException(
                "Parameter 'id' must be a valid UUID.",
                [['parameter' => 'id', 'reason' => 'invalid_uuid']],
            );
        }

        $variant = $this->reader->findById(new VariantIdCriteria(strtolower($id)));

        if ($variant === null) {
            throw new NotFoundException(sprintf("Variant '%s' was not found.", $id));
        }

        return JsonResponseFactory::create(new VariantView($variant));
    }
}
