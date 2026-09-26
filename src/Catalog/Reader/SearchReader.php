<?php

declare(strict_types=1);

namespace App\Catalog\Reader;

use App\Catalog\Criteria\SearchCriteria;
use App\Catalog\Result\SearchHit;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class SearchReader
{
    private const float CORRECTION_THRESHOLD = 0.3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<SearchHit>
     */
    public function search(SearchCriteria $criteria): array
    {
        $tokens = SearchTokens::fromPhrase($criteria->phrase);

        if ($tokens === []) {
            return [];
        }

        $hits = $this->matching($tokens, $criteria->limit);

        if (\count($hits) >= $criteria->limit) {
            return $hits;
        }

        $corrected = $this->corrected($tokens);

        if ($corrected === $tokens) {
            return $hits;
        }

        return self::merge($hits, $this->matching($corrected, $criteria->limit), $criteria->limit);
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<SearchHit>
     */
    private function matching(array $tokens, int $limit): array
    {
        $sql = 'SELECT variant_public_id, brand_name, model_name, version_name, fuel_type, '
            . 'generation, body_style, unresolved_segment, gearbox, drive '
            . "FROM variant_search, to_tsquery('simple', :tsquery) AS tsq "
            . 'WHERE document @@ tsq '
            . 'ORDER BY ts_rank(document, tsq) DESC, brand_name, model_name, version_name, variant_public_id '
            . 'LIMIT :limit';

        /**
         * @var list<array{
         *     variant_public_id: string,
         *     brand_name: string,
         *     model_name: string,
         *     version_name: string,
         *     fuel_type: string,
         *     generation: ?string,
         *     body_style: ?string,
         *     unresolved_segment: ?string,
         *     gearbox: ?string,
         *     drive: ?string,
         * }> $rows
         */
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['tsquery' => SearchTokens::toTsQuery($tokens), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): SearchHit => new SearchHit(
                $row['variant_public_id'],
                $row['brand_name'],
                $row['model_name'],
                $row['version_name'],
                $row['fuel_type'],
                $row['generation'],
                $row['body_style'],
                $row['unresolved_segment'],
                $row['gearbox'],
                $row['drive'],
            ),
            $rows,
        );
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private function corrected(array $tokens): array
    {
        $candidates = [];

        foreach ($tokens as $token) {
            if (SearchTokens::isCorrectable($token)) {
                $candidates[$token] = true;
            }
        }

        if ($candidates === []) {
            return $tokens;
        }

        $placeholders = [];
        $params = ['threshold' => self::CORRECTION_THRESHOLD];

        foreach (array_keys($candidates) as $index => $token) {
            $placeholders[] = ':token' . $index;
            $params['token' . $index] = $token;
        }

        $sql = 'SELECT candidate.token AS token, ('
            . ' SELECT term FROM search_term'
            . ' WHERE term % candidate.token AND similarity(term, candidate.token) >= :threshold'
            . ' ORDER BY similarity(term, candidate.token) DESC, term'
            . ' LIMIT 1'
            . ') AS correction'
            . ' FROM unnest(ARRAY[' . implode(', ', $placeholders) . ']::text[]) AS candidate(token)'
            . " WHERE NOT EXISTS (SELECT 1 FROM search_term WHERE term LIKE candidate.token || '%')";

        $this->connection->executeStatement(
            sprintf('SET pg_trgm.similarity_threshold = %F', self::CORRECTION_THRESHOLD),
        );

        /** @var list<array{token: string, correction: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $replacements = [];

        foreach ($rows as $row) {
            if ($row['correction'] !== null) {
                $replacements[$row['token']] = $row['correction'];
            }
        }

        if ($replacements === []) {
            return $tokens;
        }

        return array_map(static fn (string $token): string => $replacements[$token] ?? $token, $tokens);
    }

    /**
     * @param list<SearchHit> $exact
     * @param list<SearchHit> $fuzzy
     *
     * @return list<SearchHit>
     */
    private static function merge(array $exact, array $fuzzy, int $limit): array
    {
        $merged = $exact;
        $seen = [];

        foreach ($exact as $hit) {
            $seen[$hit->id] = true;
        }

        foreach ($fuzzy as $hit) {
            if (\count($merged) >= $limit) {
                break;
            }

            if (isset($seen[$hit->id])) {
                continue;
            }

            $merged[] = $hit;
        }

        return $merged;
    }
}
