<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_id_uuid_v7_check '.
            'CHECK ((uuid_extract_version(id) = 7) IS TRUE)'
        );
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_name_canonical_check '.
            'CHECK (name = btrim(name) AND char_length(name) > 0)'
        );
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_email_canonical_check '.
            'CHECK (email = lower(btrim(email)) AND char_length(email) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
