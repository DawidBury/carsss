<?php

declare(strict_types=1);

namespace App\Api\Response;

use App\Catalog\Result\SearchHit;
use JsonSerializable;

final readonly class SearchHitView implements JsonSerializable
{
    public function __construct(private SearchHit $hit)
    {
    }

    /**
     * @return array{
     *     id: string,
     *     brand: string,
     *     model: string,
     *     version: string,
     *     fuelType: string,
     *     generation: ?string,
     *     bodyStyle: ?string,
     *     unresolvedSegment: ?string,
     *     gearbox: ?string,
     *     drive: ?string,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->hit->id,
            'brand' => $this->hit->brandName,
            'model' => $this->hit->modelName,
            'version' => $this->hit->versionName,
            'fuelType' => $this->hit->fuelType,
            'generation' => $this->hit->generation,
            'bodyStyle' => $this->hit->bodyStyle,
            'unresolvedSegment' => $this->hit->unresolvedSegment,
            'gearbox' => $this->hit->gearbox,
            'drive' => $this->hit->drive,
        ];
    }
}
