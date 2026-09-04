<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizational_units', function (Blueprint $table): void {
            $table->uuid('id')->default(DB::raw('uuidv7()'));
            $table->foreignUuid('parent_id')->nullable();
            $table->string('name');
            $table->boolean('mark_as_company')->default(false);
            $table->boolean('mark_as_facility')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->primary('id');
            $table->foreign('parent_id')
                ->references('id')
                ->on('organizational_units')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->index('parent_id');
        });

        DB::statement(
            'ALTER TABLE organizational_units ADD CONSTRAINT organizational_units_id_uuid_v7_check '.
            'CHECK ((uuid_extract_version(id) = 7) IS TRUE)'
        );
        DB::statement(
            'ALTER TABLE organizational_units ADD CONSTRAINT organizational_units_name_canonical_check '.
            'CHECK (name = btrim(name) AND char_length(name) > 0)'
        );
        DB::statement(
            'ALTER TABLE organizational_units ADD CONSTRAINT organizational_units_classification_check '.
            'CHECK (NOT (mark_as_company AND mark_as_facility))'
        );
        DB::statement(
            'ALTER TABLE organizational_units ADD CONSTRAINT organizational_units_parent_check '.
            'CHECK (parent_id IS NULL OR parent_id <> id)'
        );
        DB::statement(
            'ALTER TABLE organizational_units ADD CONSTRAINT organizational_units_sort_order_check '.
            'CHECK (sort_order >= 0)'
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION enforce_organizational_unit_tree()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                parent_is_facility boolean;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT mark_as_facility
                    INTO parent_is_facility
                    FROM organizational_units
                    WHERE id = NEW.parent_id
                    FOR UPDATE;

                    IF parent_is_facility THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'A facility organizational unit cannot have children.';
                    END IF;

                    IF EXISTS (
                        WITH RECURSIVE ancestors AS (
                            SELECT id, parent_id
                            FROM organizational_units
                            WHERE id = NEW.parent_id

                            UNION

                            SELECT parent.id, parent.parent_id
                            FROM organizational_units AS parent
                            INNER JOIN ancestors ON parent.id = ancestors.parent_id
                        )
                        SELECT 1
                        FROM ancestors
                        WHERE id = NEW.id
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'An organizational unit cannot be moved below one of its descendants.';
                    END IF;
                END IF;

                IF NEW.mark_as_facility AND EXISTS (
                    SELECT 1
                    FROM organizational_units
                    WHERE parent_id = NEW.id
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'An organizational unit with children cannot be marked as a facility.';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER organizational_units_enforce_tree_trigger
            BEFORE INSERT OR UPDATE OF parent_id, mark_as_facility
            ON organizational_units
            FOR EACH ROW
            EXECUTE FUNCTION enforce_organizational_unit_tree()
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS organizational_units_enforce_tree_trigger ON organizational_units'
        );
        DB::statement('DROP FUNCTION IF EXISTS enforce_organizational_unit_tree()');
        Schema::dropIfExists('organizational_units');
    }
};
