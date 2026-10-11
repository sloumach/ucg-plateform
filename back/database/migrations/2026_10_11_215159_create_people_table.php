<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('HUM-001 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('people', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('pseudonym', 80);
            $table->string('language', 12);
            $table->string('timezone', 64);
            $table->enum('status', ['candidate', 'trial', 'active', 'unavailable', 'substitute', 'former']);
            $table->text('biography_public')->nullable();
            foreach (['first_name', 'last_name', 'contact_email', 'contact_phone', 'biography_internal'] as $column) {
                $table->text($column)->nullable();
            }
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'people_tenant_identity');
            $table->unique(['organization_id', 'user_id'], 'people_tenant_account');
            $table->index(['organization_id', 'status', 'id'], 'people_tenant_status');
            $table->index('user_id', 'people_account_reference');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE FUNCTION ucg_people_identity_immutable() RETURNS trigger AS $$ BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN
                    RAISE EXCEPTION \'Person identity is immutable\' USING ERRCODE=\'23514\';
                END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER people_identity_immutable BEFORE UPDATE ON people FOR EACH ROW EXECUTE FUNCTION ucg_people_identity_immutable()');
            DB::unprepared('CREATE FUNCTION ucg_people_delete_guard() RETURNS trigger AS $$ BEGIN
                RAISE EXCEPTION \'Person history must be retained\' USING ERRCODE=\'23514\'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER people_delete_guard BEFORE DELETE ON people FOR EACH ROW EXECUTE FUNCTION ucg_people_delete_guard()');
        } else {
            DB::unprepared("CREATE TRIGGER people_identity_immutable BEFORE UPDATE OF id, organization_id ON people
                WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id
                BEGIN SELECT RAISE(ABORT, 'Person identity is immutable'); END");
            DB::unprepared("CREATE TRIGGER people_delete_guard BEFORE DELETE ON people BEGIN SELECT RAISE(ABORT, 'Person history must be retained'); END");
        }
    }

    public function down(): void
    {
        if (DB::table('people')->exists()) {
            throw new RuntimeException('HUM-001 rollback cannot discard person records. Use a forward fix.');
        }
        Schema::dropIfExists('people');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ucg_people_identity_immutable()');
            DB::unprepared('DROP FUNCTION IF EXISTS ucg_people_delete_guard()');
        }
    }
};
