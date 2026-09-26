<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Odwzorowanie adresu z sitemapu na forme kanoniczna w pamieci podrecznej adaptera. Uzasadnienie: docs/database.md par. 5, ADR 0019.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");

        $this->addSql('DELETE FROM autocentrum_page_cache');

        $this->addSql('ALTER TABLE autocentrum_page_cache ADD COLUMN canonical_ref TEXT NOT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE autocentrum_page_cache
                ADD CONSTRAINT autocentrum_page_cache_canonical_ref_not_blank
                CHECK (length(btrim(canonical_ref)) > 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");

        $this->addSql('ALTER TABLE autocentrum_page_cache DROP CONSTRAINT autocentrum_page_cache_canonical_ref_not_blank');
        $this->addSql('ALTER TABLE autocentrum_page_cache DROP COLUMN canonical_ref');
    }
}
