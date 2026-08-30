<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            $table->uuid('operation_id')->nullable()->after('id')->index();
            $table->string('risk_level', 16)->default('low')->after('status');
            $table->json('before_snapshot')->nullable()->after('summary');
            $table->json('after_snapshot')->nullable()->after('before_snapshot');
            $table->string('backup_reference', 255)->nullable()->after('after_snapshot');
            $table->timestamp('restored_at')->nullable()->after('backup_reference');
        });
    }

    public function down(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            $table->dropColumn(['operation_id', 'risk_level', 'before_snapshot', 'after_snapshot', 'backup_reference', 'restored_at']);
        });
    }
};
