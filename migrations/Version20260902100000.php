<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Taksonomia (brand, car_model, version, variant) oraz przebiegi importu. Uzasadnienia: docs/database.md.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");

        $this->addSql(<<<'SQL'
            CREATE TABLE import_run (
                id          BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                source      TEXT        NOT NULL,
                status      TEXT        NOT NULL,
                started_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                finished_at TIMESTAMPTZ,

                CONSTRAINT import_run_source_not_blank
                    CHECK (length(btrim(source)) > 0),
                CONSTRAINT import_run_status_allowed
                    CHECK (status IN ('running', 'completed', 'failed')),
                CONSTRAINT import_run_finished_at_matches_status
                    CHECK ((status = 'running') = (finished_at IS NULL))
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX import_run_one_running_per_source
                ON import_run (source) WHERE status = 'running'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE brand (
                id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                public_id         UUID   NOT NULL DEFAULT gen_random_uuid(),
                source_ref        TEXT   NOT NULL,
                name              TEXT   NOT NULL,
                first_seen_run_id BIGINT NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,

                CONSTRAINT brand_source_ref_not_blank CHECK (length(btrim(source_ref)) > 0),
                CONSTRAINT brand_name_not_blank       CHECK (length(btrim(name)) > 0)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX brand_public_id_key  ON brand (public_id)');
        $this->addSql('CREATE UNIQUE INDEX brand_source_ref_key ON brand (source_ref)');

        $this->addSql(<<<'SQL'
            CREATE TABLE car_model (
                id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                public_id         UUID   NOT NULL DEFAULT gen_random_uuid(),
                brand_id          BIGINT NOT NULL REFERENCES brand (id) ON DELETE RESTRICT,
                source_ref        TEXT   NOT NULL,
                name              TEXT   NOT NULL,
                first_seen_run_id BIGINT NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,

                CONSTRAINT car_model_source_ref_not_blank CHECK (length(btrim(source_ref)) > 0),
                CONSTRAINT car_model_name_not_blank       CHECK (length(btrim(name)) > 0)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX car_model_public_id_key  ON car_model (public_id)');
        $this->addSql('CREATE UNIQUE INDEX car_model_source_ref_key ON car_model (source_ref)');
        $this->addSql('CREATE        INDEX car_model_brand_id_idx   ON car_model (brand_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE version (
                id                      BIGINT  GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                public_id               UUID    NOT NULL DEFAULT gen_random_uuid(),
                car_model_id            BIGINT  NOT NULL REFERENCES car_model (id) ON DELETE RESTRICT,
                source_ref              TEXT    NOT NULL,
                name                    TEXT    NOT NULL,
                fuel_type               TEXT    NOT NULL,
                generation              TEXT,
                body_style              TEXT,
                unresolved_segment      TEXT,
                power_hp                NUMERIC(5,1),
                engine_displacement_ccm INTEGER,
                boot_capacity_l         INTEGER,
                first_seen_run_id       BIGINT  NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,
                last_seen_run_id        BIGINT  NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,

                CONSTRAINT version_source_ref_not_blank
                    CHECK (length(btrim(source_ref)) > 0),
                CONSTRAINT version_name_not_blank
                    CHECK (length(btrim(name)) > 0),
                CONSTRAINT version_fuel_type_allowed
                    CHECK (fuel_type IN ('petrol', 'diesel', 'hybrid', 'electric')),
                CONSTRAINT version_generation_not_blank
                    CHECK (generation IS NULL OR length(btrim(generation)) > 0),
                CONSTRAINT version_body_style_not_blank
                    CHECK (body_style IS NULL OR length(btrim(body_style)) > 0),
                CONSTRAINT version_unresolved_segment_not_blank
                    CHECK (unresolved_segment IS NULL OR length(btrim(unresolved_segment)) > 0),
                CONSTRAINT version_unresolved_segment_excludes_resolved
                    CHECK (unresolved_segment IS NULL OR (generation IS NULL AND body_style IS NULL)),
                CONSTRAINT version_power_hp_positive
                    CHECK (power_hp IS NULL OR power_hp > 0),
                CONSTRAINT version_displacement_positive
                    CHECK (engine_displacement_ccm IS NULL OR engine_displacement_ccm > 0),
                CONSTRAINT version_boot_capacity_positive
                    CHECK (boot_capacity_l IS NULL OR boot_capacity_l > 0)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX version_public_id_key    ON version (public_id)');
        $this->addSql('CREATE UNIQUE INDEX version_source_ref_key   ON version (source_ref)');
        $this->addSql('CREATE        INDEX version_car_model_id_idx ON version (car_model_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE variant (
                id                       BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                public_id                UUID         NOT NULL DEFAULT gen_random_uuid(),
                version_id               BIGINT       NOT NULL REFERENCES version (id) ON DELETE CASCADE,
                source_ref               TEXT         NOT NULL,
                acceleration_0_100_s     NUMERIC(4,1) NOT NULL,
                fuel_consumption_l_100km NUMERIC(4,1),
                fuel_tank_l              INTEGER,
                gearbox                  TEXT,
                gearbox_unmapped_label   TEXT,
                drive                    TEXT,
                drive_unmapped_label     TEXT,
                first_seen_run_id        BIGINT       NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,

                CONSTRAINT variant_source_ref_not_blank
                    CHECK (length(btrim(source_ref)) > 0),
                CONSTRAINT variant_acceleration_positive
                    CHECK (acceleration_0_100_s > 0),
                CONSTRAINT variant_fuel_consumption_positive
                    CHECK (fuel_consumption_l_100km IS NULL OR fuel_consumption_l_100km > 0),
                CONSTRAINT variant_fuel_tank_positive
                    CHECK (fuel_tank_l IS NULL OR fuel_tank_l > 0),
                CONSTRAINT variant_gearbox_allowed
                    CHECK (gearbox IS NULL OR gearbox IN ('manual', 'automatic')),
                CONSTRAINT variant_drive_allowed
                    CHECK (drive IS NULL OR drive IN ('front', 'rear', 'all')),
                CONSTRAINT variant_gearbox_mapped_or_labelled
                    CHECK (gearbox IS NULL OR gearbox_unmapped_label IS NULL),
                CONSTRAINT variant_drive_mapped_or_labelled
                    CHECK (drive IS NULL OR drive_unmapped_label IS NULL),
                CONSTRAINT variant_gearbox_label_not_blank
                    CHECK (gearbox_unmapped_label IS NULL OR length(btrim(gearbox_unmapped_label)) > 0),
                CONSTRAINT variant_drive_label_not_blank
                    CHECK (drive_unmapped_label IS NULL OR length(btrim(drive_unmapped_label)) > 0)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX variant_public_id_key             ON variant (public_id)');
        $this->addSql('CREATE UNIQUE INDEX variant_version_id_source_ref_key ON variant (version_id, source_ref)');
        $this->addSql('CREATE        INDEX variant_acceleration_idx          ON variant (acceleration_0_100_s, public_id)');

        $this->addSql(<<<'SQL'
            CREATE FUNCTION version_must_keep_variant(p_version_id BIGINT) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                v_source_ref TEXT;
            BEGIN
                SELECT source_ref INTO v_source_ref
                FROM version WHERE id = p_version_id FOR NO KEY UPDATE;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                IF NOT EXISTS (SELECT 1 FROM variant WHERE version_id = p_version_id) THEN
                    RAISE EXCEPTION 'version % has no variant', v_source_ref
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
            END;
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION version_requires_variant() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM version_must_keep_variant(NEW.id);
                RETURN NULL;
            END;
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER version_requires_variant_check
                AFTER INSERT ON version
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION version_requires_variant()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION variant_keeps_version_populated() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM version_must_keep_variant(OLD.version_id);

                IF TG_OP = 'UPDATE' AND NEW.version_id IS DISTINCT FROM OLD.version_id THEN
                    PERFORM version_must_keep_variant(NEW.version_id);
                END IF;

                RETURN NULL;
            END;
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER variant_delete_keeps_version_populated_check
                AFTER DELETE ON variant
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION variant_keeps_version_populated()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE CONSTRAINT TRIGGER variant_move_keeps_version_populated_check
                AFTER UPDATE OF version_id ON variant
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION variant_keeps_version_populated()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION variant_truncate_keeps_versions_populated() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM version) THEN
                    RAISE EXCEPTION 'truncating variant would leave versions without variants'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NULL;
            END;
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER variant_truncate_keeps_versions_populated_check
                AFTER TRUNCATE ON variant
                FOR EACH STATEMENT EXECUTE FUNCTION variant_truncate_keeps_versions_populated()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '3s'");

        $this->addSql('DROP TRIGGER variant_truncate_keeps_versions_populated_check ON variant');
        $this->addSql('DROP TRIGGER variant_move_keeps_version_populated_check ON variant');
        $this->addSql('DROP TRIGGER variant_delete_keeps_version_populated_check ON variant');
        $this->addSql('DROP TRIGGER version_requires_variant_check ON version');
        $this->addSql('DROP FUNCTION variant_truncate_keeps_versions_populated()');
        $this->addSql('DROP FUNCTION variant_keeps_version_populated()');
        $this->addSql('DROP FUNCTION version_requires_variant()');
        $this->addSql('DROP FUNCTION version_must_keep_variant(BIGINT)');

        $this->addSql('DROP TABLE variant');
        $this->addSql('DROP TABLE version');
        $this->addSql('DROP TABLE car_model');
        $this->addSql('DROP TABLE brand');
        $this->addSql('DROP TABLE import_run');
    }
}
