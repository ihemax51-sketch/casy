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
        Schema::create('icon_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_profile_id')->constrained()->cascadeOnDelete();
            $table->text('relative_path');
            $table->string('path_hash', 64);
            $table->string('filename');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('format', 16)->nullable();
            $table->string('thumbnail_status', 24)->default('pending');
            $table->timestamp('source_modified_at')->nullable();
            $table->timestamps();

            $table->unique(['server_profile_id', 'path_hash']);
            $table->index(['server_profile_id', 'filename']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('icon_assets');
    }
};
