<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'variant')]
#[ORM\UniqueConstraint(name: 'variant_public_id_key', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'variant_version_id_source_ref_key', columns: ['version_id', 'source_ref'])]
#[ORM\Index(name: 'variant_acceleration_idx', columns: ['acceleration_0_100_s', 'public_id'])]
final class Variant
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'public_id', type: 'uuid', updatable: false, options: ['default' => 'gen_random_uuid()'])]
    public readonly Uuid $publicId;

    #[ORM\ManyToOne(targetEntity: Version::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(name: 'version_id', nullable: false, onDelete: 'CASCADE')]
    public readonly Version $version;

    #[ORM\Column(name: 'source_ref', type: Types::TEXT, updatable: false)]
    public readonly string $sourceRef;

    #[ORM\Column(name: 'acceleration_0_100_s', type: Types::DECIMAL, precision: 4, scale: 1)]
    private string $acceleration0To100S;

    #[ORM\Column(name: 'fuel_consumption_l_100km', type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $fuelConsumptionL100Km;

    #[ORM\Column(name: 'fuel_tank_l', type: Types::INTEGER, nullable: true)]
    private ?int $fuelTankL;

    #[ORM\Column(name: 'gearbox', type: Types::TEXT, enumType: Gearbox::class, nullable: true)]
    private ?Gearbox $gearbox;

    #[ORM\Column(name: 'gearbox_unmapped_label', type: Types::TEXT, nullable: true)]
    private ?string $gearboxUnmappedLabel;

    #[ORM\Column(name: 'drive', type: Types::TEXT, enumType: Drive::class, nullable: true)]
    private ?Drive $drive;

    #[ORM\Column(name: 'drive_unmapped_label', type: Types::TEXT, nullable: true)]
    private ?string $driveUnmappedLabel;

    #[ORM\ManyToOne(targetEntity: ImportRun::class)]
    #[ORM\JoinColumn(name: 'first_seen_run_id', nullable: false, onDelete: 'RESTRICT')]
    public readonly ImportRun $firstSeenRun;

    public function __construct(
        Version $version,
        string $sourceRef,
        string $acceleration0To100S,
        ?string $fuelConsumptionL100Km,
        ?int $fuelTankL,
        ?Gearbox $gearbox,
        ?string $gearboxUnmappedLabel,
        ?Drive $drive,
        ?string $driveUnmappedLabel,
        ImportRun $firstSeenRun,
    ) {
        $this->publicId = Uuid::v4();
        $this->version = $version;
        $this->sourceRef = $sourceRef;
        $this->acceleration0To100S = $acceleration0To100S;
        $this->fuelConsumptionL100Km = $fuelConsumptionL100Km;
        $this->fuelTankL = $fuelTankL;
        $this->setGearbox($gearbox, $gearboxUnmappedLabel);
        $this->setDrive($drive, $driveUnmappedLabel);
        $this->firstSeenRun = $firstSeenRun;
    }

    public function update(
        string $acceleration0To100S,
        ?string $fuelConsumptionL100Km,
        ?int $fuelTankL,
        ?Gearbox $gearbox,
        ?string $gearboxUnmappedLabel,
        ?Drive $drive,
        ?string $driveUnmappedLabel,
    ): void {
        $this->acceleration0To100S = $acceleration0To100S;
        $this->fuelConsumptionL100Km = $fuelConsumptionL100Km;
        $this->fuelTankL = $fuelTankL;
        $this->setGearbox($gearbox, $gearboxUnmappedLabel);
        $this->setDrive($drive, $driveUnmappedLabel);
    }

    public function setGearbox(?Gearbox $gearbox, ?string $unmappedLabel): void
    {
        $this->gearbox = $gearbox;
        $this->gearboxUnmappedLabel = $unmappedLabel;
    }

    public function setDrive(?Drive $drive, ?string $unmappedLabel): void
    {
        $this->drive = $drive;
        $this->driveUnmappedLabel = $unmappedLabel;
    }

    public function getAcceleration0To100S(): string
    {
        return $this->acceleration0To100S;
    }

    public function getFuelConsumptionL100Km(): ?string
    {
        return $this->fuelConsumptionL100Km;
    }

    public function getFuelTankL(): ?int
    {
        return $this->fuelTankL;
    }

    public function getGearbox(): ?Gearbox
    {
        return $this->gearbox;
    }

    public function getGearboxUnmappedLabel(): ?string
    {
        return $this->gearboxUnmappedLabel;
    }

    public function getDrive(): ?Drive
    {
        return $this->drive;
    }

    public function getDriveUnmappedLabel(): ?string
    {
        return $this->driveUnmappedLabel;
    }
}
