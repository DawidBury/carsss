<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'version')]
#[ORM\UniqueConstraint(name: 'version_public_id_key', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'version_source_ref_key', columns: ['source_ref'])]
#[ORM\Index(name: 'version_car_model_id_idx', columns: ['car_model_id'])]
final class Version
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'public_id', type: 'uuid', updatable: false, options: ['default' => 'gen_random_uuid()'])]
    public readonly Uuid $publicId;

    #[ORM\ManyToOne(targetEntity: CarModel::class)]
    #[ORM\JoinColumn(name: 'car_model_id', nullable: false, onDelete: 'RESTRICT')]
    private CarModel $carModel;

    #[ORM\Column(name: 'source_ref', type: Types::TEXT, updatable: false)]
    public readonly string $sourceRef;

    #[ORM\Column(name: 'name', type: Types::TEXT)]
    private string $name;

    #[ORM\Column(name: 'fuel_type', type: Types::TEXT, enumType: FuelType::class)]
    private FuelType $fuelType;

    #[ORM\Column(name: 'generation', type: Types::TEXT, nullable: true)]
    private ?string $generation;

    #[ORM\Column(name: 'body_style', type: Types::TEXT, nullable: true)]
    private ?string $bodyStyle;

    #[ORM\Column(name: 'unresolved_segment', type: Types::TEXT, nullable: true)]
    private ?string $unresolvedSegment;

    #[ORM\Column(name: 'power_hp', type: Types::DECIMAL, precision: 5, scale: 1, nullable: true)]
    private ?string $powerHp;

    #[ORM\Column(name: 'engine_displacement_ccm', type: Types::INTEGER, nullable: true)]
    private ?int $engineDisplacementCcm;

    #[ORM\Column(name: 'boot_capacity_l', type: Types::INTEGER, nullable: true)]
    private ?int $bootCapacityL;

    #[ORM\ManyToOne(targetEntity: ImportRun::class)]
    #[ORM\JoinColumn(name: 'first_seen_run_id', nullable: false, onDelete: 'RESTRICT')]
    public readonly ImportRun $firstSeenRun;

    #[ORM\ManyToOne(targetEntity: ImportRun::class)]
    #[ORM\JoinColumn(name: 'last_seen_run_id', nullable: false, onDelete: 'RESTRICT')]
    private ImportRun $lastSeenRun;

    /**
     * @var Collection<string, Variant>
     */
    #[ORM\OneToMany(
        targetEntity: Variant::class,
        mappedBy: 'version',
        cascade: ['persist'],
        orphanRemoval: true,
        indexBy: 'sourceRef',
    )]
    private Collection $variants;

    public function __construct(
        CarModel $carModel,
        string $sourceRef,
        string $name,
        FuelType $fuelType,
        ?string $generation,
        ?string $bodyStyle,
        ?string $unresolvedSegment,
        ?string $powerHp,
        ?int $engineDisplacementCcm,
        ?int $bootCapacityL,
        ImportRun $firstSeenRun,
    ) {
        $this->publicId = Uuid::v4();
        $this->carModel = $carModel;
        $this->sourceRef = $sourceRef;
        $this->name = $name;
        $this->fuelType = $fuelType;
        $this->generation = $generation;
        $this->bodyStyle = $bodyStyle;
        $this->unresolvedSegment = $unresolvedSegment;
        $this->powerHp = $powerHp;
        $this->engineDisplacementCcm = $engineDisplacementCcm;
        $this->bootCapacityL = $bootCapacityL;
        $this->firstSeenRun = $firstSeenRun;
        $this->lastSeenRun = $firstSeenRun;
        $this->variants = new ArrayCollection();
    }

    public function applyRecord(
        CarModel $carModel,
        string $name,
        FuelType $fuelType,
        ?string $generation,
        ?string $bodyStyle,
        ?string $unresolvedSegment,
        ?string $powerHp,
        ?int $engineDisplacementCcm,
        ?int $bootCapacityL,
    ): void {
        $this->carModel = $carModel;
        $this->name = $name;
        $this->fuelType = $fuelType;
        $this->generation = $generation;
        $this->bodyStyle = $bodyStyle;
        $this->unresolvedSegment = $unresolvedSegment;
        $this->powerHp = $powerHp;
        $this->engineDisplacementCcm = $engineDisplacementCcm;
        $this->bootCapacityL = $bootCapacityL;
    }

    public function markSeen(ImportRun $run): void
    {
        $this->lastSeenRun = $run;
    }

    /**
     * @return Collection<string, Variant>
     */
    public function variants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(Variant $variant): void
    {
        $this->variants->set($variant->sourceRef, $variant);
    }

    public function removeVariant(Variant $variant): void
    {
        $this->variants->removeElement($variant);
    }

    public function getCarModel(): CarModel
    {
        return $this->carModel;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFuelType(): FuelType
    {
        return $this->fuelType;
    }

    public function getGeneration(): ?string
    {
        return $this->generation;
    }

    public function getBodyStyle(): ?string
    {
        return $this->bodyStyle;
    }

    public function getUnresolvedSegment(): ?string
    {
        return $this->unresolvedSegment;
    }

    public function getPowerHp(): ?string
    {
        return $this->powerHp;
    }

    public function getEngineDisplacementCcm(): ?int
    {
        return $this->engineDisplacementCcm;
    }

    public function getBootCapacityL(): ?int
    {
        return $this->bootCapacityL;
    }

    public function getLastSeenRun(): ImportRun
    {
        return $this->lastSeenRun;
    }
}
