<?php

declare(strict_types=1);

namespace App\Api\Response;

use App\Catalog\Result\Model;
use JsonSerializable;

final readonly class ModelView implements JsonSerializable
{
    public function __construct(private Model $model)
    {
    }

    /**
     * @return array{id: string, name: string, brand: BrandView}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->model->id,
            'name' => $this->model->name,
            'brand' => new BrandView($this->model->brand),
        ];
    }
}
