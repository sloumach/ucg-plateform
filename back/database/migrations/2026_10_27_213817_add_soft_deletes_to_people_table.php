<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->softDeletesTz();
            $table->index(['organization_id', 'deleted_at', 'id'], 'people_tenant_archive_index');
        });
    }

    public function down(): void
    {
        if (DB::table('people')->whereNotNull('deleted_at')->exists()) {
            throw new LogicException('Restore person archives explicitly before removing soft deletion metadata.');
        }
        Schema::table('people', function (Blueprint $table): void {
            $table->dropIndex('people_tenant_archive_index');
            $table->dropSoftDeletes();
        });
    }
};
