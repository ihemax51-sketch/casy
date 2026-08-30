<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('server_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Primary server');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(1433);
            $table->string('username');
            $table->text('password');
            $table->string('account_database')->nullable();
            $table->string('shard_database')->nullable();
            $table->string('log_database')->nullable();
            $table->string('proxy_database')->nullable();
            $table->text('icon_root')->nullable();
            $table->boolean('encrypt_connection')->default(false);
            $table->boolean('trust_server_certificate')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('connection_status', 24)->default('not_tested');
            $table->text('connection_error')->nullable();
            $table->json('schema_stats')->nullable();
            $table->string('schema_fingerprint', 64)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_profiles');
    }
};
