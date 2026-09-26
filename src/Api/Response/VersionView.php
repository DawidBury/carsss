<?php

declare(strict_types=1);

namespace App\Api\Response;

use App\Catalog\Result\Version;
use JsonSerializable;

final readonly class VersionView implements JsonSerializable
{
    public function __construct(private Version $version)
    {
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     fuelType: string,
     *     generation: ?string,
     *     bodyStyle: ?string,
     *     unresolvedSegment: ?string,
     *     powerHp: ?float,
     *     engineDisplacementCcm: ?int,
     *     bootCapacityL: ?int,
     *     model: ModelView,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->version->id,
            'name' => $this->version->name,
            'fuelType' => $this->version->fuelType,
            'generation' => $this->version->generation,
            'bodyStyle' => $this->version->bodyStyle,
            'unresolvedSegment' => $this->version->unresolvedSegment,
            'powerHp' => $this->version->powerHp === null ? null : (float) $this->version->powerHp,
            'engineDisplacementCcm' => $this->version->engineDisplacementCcm,
            'bootCapacityL' => $this->version->bootCapacityL,
            'model' => new ModelView($this->version->model),
        ];
    }
}
