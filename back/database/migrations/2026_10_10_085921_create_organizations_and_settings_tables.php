<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 80)->unique();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['active', 'suspended', 'closing', 'archived'])->default('active');
            $table->string('timezone', 64);
            $table->string('language', 10);
            $table->char('country', 2);
            $table->timestamps();
            $table->index(['owner_user_id', 'id']);
            $table->index(['status', 'id']);
        });
        Schema::create('organization_settings', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->primary()->constrained('organizations')->restrictOnDelete();
            $table->enum('week_starts_on', ['1', '2', '3', '4', '5', '6', '7'])->default('1');
            $table->enum('date_format', ['d/m/Y', 'Y-m-d'])->default('d/m/Y');
            $table->timestamps();
        });
        // Guard immutable identity even when bulk SQL bypasses Eloquent events.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION ucg_organization_identity_guard() RETURNS trigger AS $$
                BEGIN
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.slug IS DISTINCT FROM OLD.slug
                        OR NEW.owner_user_id IS DISTINCT FROM OLD.owner_user_id THEN
                        RAISE EXCEPTION 'Organization identity is immutable' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER organizations_identity_guard BEFORE UPDATE ON organizations
                    FOR EACH ROW EXECUTE FUNCTION ucg_organization_identity_guard();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER organizations_identity_guard BEFORE UPDATE ON organizations
                WHEN NEW.id IS NOT OLD.id OR NEW.slug IS NOT OLD.slug OR NEW.owner_user_id IS NOT OLD.owner_user_id
                BEGIN SELECT RAISE(ABORT, 'Organization identity is immutable'); END;
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
        Schema::dropIfExists('organizations');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ucg_organization_identity_guard()');
        }
    }
};
