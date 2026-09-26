<?php

declare(strict_types=1);

namespace App\Source\Autocentrum\Parser;

use App\Source\Autocentrum\Url\EnginePath;
use App\Source\Contract\SourceTaxonomy;
use Psr\Log\LoggerInterface;

final readonly class TaxonomyReader
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function read(EnginePath $path, BreadcrumbNames $breadcrumb, string $ref): SourceTaxonomy
    {
        $depth = $path->depth();

        $names = [];
        for ($segmentIndex = 0; $segmentIndex < $depth; ++$segmentIndex) {
            $names[$segmentIndex] = $this->segmentDisplayName($segmentIndex, $path, $breadcrumb, $ref);
        }

        return new SourceTaxonomy(
            brandRef: $path->brandSlug(),
            brandName: $names[0],
            modelRef: $path->modelSlug(),
            modelName: $names[1],
            versionName: $names[$depth - 1],
            generation: 5 === $depth ? $names[2] : null,
            bodyStyle: 5 === $depth ? $names[3] : null,
            unresolvedSegment: 4 === $depth ? $names[2] : null,
        );
    }

    private function segmentDisplayName(int $segmentIndex, EnginePath $path, BreadcrumbNames $breadcrumb, string $ref): string
    {
        $ownDepth = $segmentIndex + 1;
        $ownName = $breadcrumb->at($ownDepth);

        if (null === $ownName) {
            return str_replace('-', ' ', $path->segments[$segmentIndex]);
        }

        $prefix = $this->nearestKnownPrefix($ownDepth, $breadcrumb);
        if (!str_starts_with($ownName, $prefix)) {
            $this->logger->warning('autocentrum: breadcrumb name does not extend the shallower prefix, keeping the full name', [
                'ref' => $ref,
                'segmentIndex' => $segmentIndex,
                'name' => $ownName,
                'prefix' => $prefix,
            ]);

            return trim($ownName);
        }

        return trim(substr($ownName, strlen($prefix)));
    }

    private function nearestKnownPrefix(int $ownDepth, BreadcrumbNames $breadcrumb): string
    {
        for ($prefixDepth = $ownDepth - 1; $prefixDepth >= 0; --$prefixDepth) {
            $name = $breadcrumb->at($prefixDepth);
            if (null !== $name) {
                return $name;
            }
        }

        return '';
    }
}
