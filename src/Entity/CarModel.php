<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'car_model')]
#[ORM\UniqueConstraint(name: 'car_model_public_id_key', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'car_model_source_ref_key', columns: ['source_ref'])]
#[ORM\Index(name: 'car_model_brand_id_idx', columns: ['brand_id'])]
final class CarModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'public_id', type: 'uuid', updatable: false, options: ['default' => 'gen_random_uuid()'])]
    public readonly Uuid $publicId;

    #[ORM\ManyToOne(targetEntity: Brand::class)]
    #[ORM\JoinColumn(name: 'brand_id', nullable: false, onDelete: 'RESTRICT')]
    public readonly Brand $brand;

    #[ORM\Column(name: 'source_ref', type: Types::TEXT)]
    public readonly string $sourceRef;

    #[ORM\Column(name: 'name', type: Types::TEXT)]
    public readonly string $name;

    #[ORM\ManyToOne(targetEntity: ImportRun::class)]
    #[ORM\JoinColumn(name: 'first_seen_run_id', nullable: false, onDelete: 'RESTRICT')]
    public readonly ImportRun $firstSeenRun;
}
