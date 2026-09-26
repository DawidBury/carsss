<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the search index: variant_search and search_term materialized views';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->addSql(<<<'SQL'
            CREATE MATERIALIZED VIEW variant_search AS
            SELECT
                va.public_id          AS variant_public_id,
                b.name                AS brand_name,
                cm.name               AS model_name,
                ve.name               AS version_name,
                ve.fuel_type          AS fuel_type,
                ve.generation         AS generation,
                ve.body_style         AS body_style,
                ve.unresolved_segment AS unresolved_segment,
                va.gearbox            AS gearbox,
                va.drive              AS drive,
                to_tsvector('simple', concat_ws(' ',
                    b.name, cm.name, ve.generation, ve.body_style, ve.unresolved_segment, ve.name
                ))                    AS document
            FROM variant va
            JOIN version ve   ON ve.id = va.version_id
            JOIN car_model cm ON cm.id = ve.car_model_id
            JOIN brand b      ON b.id = cm.brand_id
            SQL);

        $this->addSql('CREATE UNIQUE INDEX variant_search_variant_public_id_key ON variant_search (variant_public_id)');
        $this->addSql('CREATE INDEX variant_search_document_idx ON variant_search USING gin (document)');

        $this->addSql(<<<'SQL'
            CREATE MATERIALIZED VIEW search_term AS
            SELECT DISTINCT lower(word) AS term
            FROM (
                SELECT regexp_split_to_table(b.name, '[^[:alnum:].]+') AS word FROM brand b
                UNION ALL
                SELECT regexp_split_to_table(cm.name, '[^[:alnum:].]+') FROM car_model cm
                UNION ALL
                SELECT regexp_split_to_table(ve.name, '[^[:alnum:].]+') FROM version ve
                UNION ALL
                SELECT regexp_split_to_table(ve.generation, '[^[:alnum:].]+') FROM version ve
                UNION ALL
                SELECT regexp_split_to_table(ve.body_style, '[^[:alnum:].]+') FROM version ve
                UNION ALL
                SELECT regexp_split_to_table(ve.unresolved_segment, '[^[:alnum:].]+') FROM version ve
            ) words
            WHERE length(word) >= 3
            SQL);

        $this->addSql('CREATE UNIQUE INDEX search_term_term_key ON search_term (term)');
        $this->addSql('CREATE INDEX search_term_trgm_idx ON search_term USING gin (term gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS search_term');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS variant_search');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm');
    }
}
