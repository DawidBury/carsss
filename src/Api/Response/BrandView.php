<?php

declare(strict_types=1);

namespace App\Api\Response;

use App\Catalog\Result\Brand;
use JsonSerializable;

final readonly class BrandView implements JsonSerializable
{
    public function __construct(private Brand $brand)
    {
    }

    /**
     * @return array{id: string, name: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->brand->id,
            'name' => $this->brand->name,
        ];
    }
}
