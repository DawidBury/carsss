<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Klucze API do uwierzytelniania nagłówkiem X-API-Key. Uzasadnienia: docs/adr/0011.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");

        $this->addSql(<<<'SQL'
            CREATE TABLE api_key (
                id         BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name       TEXT        NOT NULL,
                key_hash   BYTEA       NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT api_key_name_not_blank CHECK (length(btrim(name)) > 0),
                CONSTRAINT api_key_hash_is_sha256 CHECK (octet_length(key_hash) = 32)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX api_key_key_hash_key ON api_key (key_hash)');
        $this->addSql('CREATE UNIQUE INDEX api_key_name_key     ON api_key (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");
        $this->addSql('DROP TABLE api_key');
    }
}
