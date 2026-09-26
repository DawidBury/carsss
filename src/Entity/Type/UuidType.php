<?php

declare(strict_types=1);

namespace App\Entity\Type;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\Uuid;

#[AsDbalType(name: 'uuid')]
final class UuidType extends AbstractUidType
{
    public function getName(): string
    {
        return 'uuid';
    }

    protected function getUidClass(): string
    {
        return Uuid::class;
    }
}
