<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Drive;
use App\Entity\FuelType;
use App\Entity\Gearbox;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DictionaryEnumsMatchDatabaseCheckConstraintsTest extends KernelTestCase
{
    public function testFuelTypeEnumMatchesTheCheckConstraint(): void
    {
        self::assertSame(
            $this->allowedValues('version_fuel_type_allowed', 'version', 'fuel_type'),
            self::enumValues(FuelType::cases()),
        );
    }

    public function testGearboxEnumMatchesTheCheckConstraint(): void
    {
        self::assertSame(
            $this->allowedValues('variant_gearbox_allowed', 'variant', 'gearbox'),
            self::enumValues(Gearbox::cases()),
        );
    }

    public function testDriveEnumMatchesTheCheckConstraint(): void
    {
        self::assertSame(
            $this->allowedValues('variant_drive_allowed', 'variant', 'drive'),
            self::enumValues(Drive::cases()),
        );
    }

    /**
     * @return list<string>
     */
    private function allowedValues(string $constraintName, string $table, string $column): array
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $definition = $connection->fetchOne(
            'SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = :name AND conrelid = :table::regclass',
            ['name' => $constraintName, 'table' => $table],
        );

        if (!is_string($definition)) {
            self::fail(sprintf('Constraint "%s" was not found on table "%s".', $constraintName, $table));
        }

        $pattern = sprintf('/%s = ANY \(ARRAY\[(.*?)\]\)/', preg_quote($column, '/'));
        if (1 !== preg_match($pattern, $definition, $matches)) {
            self::fail(sprintf('Could not parse the allowed values out of "%s".', $definition));
        }

        if (0 === preg_match_all("/'([^']*)'/", $matches[1], $literalMatches)) {
            self::fail(sprintf('Could not parse any literal out of "%s".', $matches[1]));
        }

        $values = $literalMatches[1];
        sort($values);

        return $values;
    }

    /**
     * @param list<FuelType>|list<Gearbox>|list<Drive> $cases
     *
     * @return list<string>
     */
    private static function enumValues(array $cases): array
    {
        $values = array_map(static fn (FuelType|Gearbox|Drive $case): string => $case->value, $cases);
        sort($values);

        return $values;
    }
}
