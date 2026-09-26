<?php

declare(strict_types=1);

namespace App\Api\Response;

use App\Catalog\Result\Variant;
use JsonSerializable;

final readonly class VariantView implements JsonSerializable
{
    public function __construct(private Variant $variant)
    {
    }

    /**
     * @return array{
     *     id: string,
     *     acceleration0To100S: float,
     *     fuelConsumptionL100Km: ?float,
     *     fuelTankL: ?int,
     *     gearbox: ?string,
     *     drive: ?string,
     *     version: VersionView,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->variant->id,
            'acceleration0To100S' => (float) $this->variant->acceleration0To100S,
            'fuelConsumptionL100Km' => $this->variant->fuelConsumptionL100Km === null
                ? null
                : (float) $this->variant->fuelConsumptionL100Km,
            'fuelTankL' => $this->variant->fuelTankL,
            'gearbox' => $this->variant->gearbox,
            'drive' => $this->variant->drive,
            'version' => new VersionView($this->variant->version),
        ];
    }
}
