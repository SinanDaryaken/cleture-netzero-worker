<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_unit_types', function (Blueprint $table): void {
            $table->uuid('id')->default(DB::raw('uuidv7()'));
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->primary('id');
            $table->unique('name');
        });

        DB::statement(
            'ALTER TABLE organization_unit_types ADD CONSTRAINT organization_unit_types_id_uuid_v7_check '.
            'CHECK ((uuid_extract_version(id) = 7) IS TRUE)'
        );
        DB::statement(
            'ALTER TABLE organization_unit_types ADD CONSTRAINT organization_unit_types_name_canonical_check '.
            'CHECK (name = btrim(name) AND char_length(name) > 0)'
        );
        DB::statement(
            'ALTER TABLE organization_unit_types ADD CONSTRAINT organization_unit_types_sort_order_check '.
            'CHECK (sort_order >= 0)'
        );

        Schema::table('organizational_units', function (Blueprint $table): void {
            $table->foreignUuid('organization_unit_type_id')->nullable();
            $table->foreign('organization_unit_type_id')
                ->references('id')
                ->on('organization_unit_types')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->index('organization_unit_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('organizational_units', function (Blueprint $table): void {
            $table->dropForeign(['organization_unit_type_id']);
            $table->dropIndex(['organization_unit_type_id']);
            $table->dropColumn('organization_unit_type_id');
        });

        Schema::dropIfExists('organization_unit_types');
    }
};
