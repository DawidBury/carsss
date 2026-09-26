<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'import_run')]
#[ORM\UniqueConstraint(
    name: 'import_run_one_running_per_source',
    columns: ['source'],
    options: ['where' => "(status = 'running'::text)"],
)]
final class ImportRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'source', type: Types::TEXT)]
    public readonly string $source;

    #[ORM\Column(name: 'status', type: Types::TEXT, enumType: ImportRunStatus::class)]
    public readonly ImportRunStatus $status;

    #[ORM\Column(name: 'started_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    public readonly DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public readonly ?DateTimeImmutable $finishedAt;
}
