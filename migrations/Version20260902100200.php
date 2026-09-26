<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902100200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pamiec podreczna walidatorow ETag adaptera autocentrum. Nalezy do adaptera, nie do rdzenia — patrz docs/database.md par. 5.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");

        $this->addSql(<<<'SQL'
            CREATE TABLE autocentrum_page_cache (
                url  TEXT PRIMARY KEY,
                etag TEXT NOT NULL,

                CONSTRAINT autocentrum_page_cache_etag_not_blank CHECK (length(btrim(etag)) > 0)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");
        $this->addSql('DROP TABLE autocentrum_page_cache');
    }
}
