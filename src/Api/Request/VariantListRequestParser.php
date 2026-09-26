<?php

declare(strict_types=1);

namespace App\Api\Request;

use App\Api\Error\InvalidParameterException;
use App\Catalog\Criteria\Drive;
use App\Catalog\Criteria\FuelType;
use App\Catalog\Criteria\Gearbox;
use App\Catalog\Criteria\VariantListCriteria;
use App\Catalog\Criteria\VariantSort;
use Symfony\Component\HttpFoundation\Request;

final readonly class VariantListRequestParser
{
    private const ALLOWED_PARAMETERS = [
        'brandId', 'modelId', 'versionId', 'fuelType', 'gearbox', 'drive',
        'accelerationMin', 'accelerationMax', 'sort', 'limit', 'cursor',
    ];

    public function __construct(private CursorParameter $cursorParameter)
    {
    }

    public function parse(Request $request): VariantListCriteria
    {
        $query = $request->query->all();

        QueryValidation::rejectUnknownParameters($query, self::ALLOWED_PARAMETERS);

        $limit = QueryValidation::limit($query);
        $brandId = QueryValidation::uuid($query, 'brandId');
        $modelId = QueryValidation::uuid($query, 'modelId');
        $versionId = QueryValidation::uuid($query, 'versionId');
        $fuelType = self::fuelType($query);
        $gearbox = self::gearbox($query);
        $drive = self::drive($query);
        $accelerationMin = QueryValidation::positiveDecimal($query, 'accelerationMin');
        $accelerationMax = QueryValidation::positiveDecimal($query, 'accelerationMax');
        $sort = self::sort($query);

        $context = self::contextFor($brandId, $modelId, $versionId, $fuelType, $gearbox, $drive, $accelerationMin, $accelerationMax, $sort);
        $cursor = $this->cursorParameter->parse($query, $context, CursorValueType::PositiveDecimal);

        return new VariantListCriteria(
            $limit,
            $brandId,
            $modelId,
            $versionId,
            $fuelType,
            $gearbox,
            $drive,
            $accelerationMin,
            $accelerationMax,
            $sort,
            $cursor,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function contextFor(
        ?string $brandId,
        ?string $modelId,
        ?string $versionId,
        ?FuelType $fuelType,
        ?Gearbox $gearbox,
        ?Drive $drive,
        ?string $accelerationMin,
        ?string $accelerationMax,
        VariantSort $sort,
    ): array {
        $context = ['sort' => $sort->value];

        if ($brandId !== null) {
            $context['brandId'] = $brandId;
        }
        if ($modelId !== null) {
            $context['modelId'] = $modelId;
        }
        if ($versionId !== null) {
            $context['versionId'] = $versionId;
        }
        if ($fuelType !== null) {
            $context['fuelType'] = $fuelType->value;
        }
        if ($gearbox !== null) {
            $context['gearbox'] = $gearbox->value;
        }
        if ($drive !== null) {
            $context['drive'] = $drive->value;
        }
        if ($accelerationMin !== null) {
            $context['accelerationMin'] = $accelerationMin;
        }
        if ($accelerationMax !== null) {
            $context['accelerationMax'] = $accelerationMax;
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function fuelType(array $query): ?FuelType
    {
        $raw = QueryValidation::string($query, 'fuelType');
        if ($raw === null) {
            return null;
        }

        $fuelType = FuelType::tryFrom($raw);
        if ($fuelType === null) {
            throw new InvalidParameterException(
                "Parameter 'fuelType' must be one of: petrol, diesel, hybrid, electric.",
                [['parameter' => 'fuelType', 'reason' => 'not_allowed_value']],
            );
        }

        return $fuelType;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function gearbox(array $query): ?Gearbox
    {
        $raw = QueryValidation::string($query, 'gearbox');
        if ($raw === null) {
            return null;
        }

        $gearbox = Gearbox::tryFrom($raw);
        if ($gearbox === null) {
            throw new InvalidParameterException(
                "Parameter 'gearbox' must be one of: manual, automatic.",
                [['parameter' => 'gearbox', 'reason' => 'not_allowed_value']],
            );
        }

        return $gearbox;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function drive(array $query): ?Drive
    {
        $raw = QueryValidation::string($query, 'drive');
        if ($raw === null) {
            return null;
        }

        $drive = Drive::tryFrom($raw);
        if ($drive === null) {
            throw new InvalidParameterException(
                "Parameter 'drive' must be one of: front, rear, all.",
                [['parameter' => 'drive', 'reason' => 'not_allowed_value']],
            );
        }

        return $drive;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function sort(array $query): VariantSort
    {
        $raw = QueryValidation::string($query, 'sort');
        if ($raw === null) {
            return VariantSort::AccelerationAscending;
        }

        $sort = VariantSort::tryFrom($raw);
        if ($sort === null) {
            throw new InvalidParameterException(
                "Parameter 'sort' must be one of: acceleration, -acceleration.",
                [['parameter' => 'sort', 'reason' => 'not_allowed_value']],
            );
        }

        return $sort;
    }
}
