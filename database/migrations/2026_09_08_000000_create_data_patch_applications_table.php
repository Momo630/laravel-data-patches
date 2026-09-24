<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_patch_applications', function (Blueprint $table): void {
            $table->string('patch_id')->primary();
            $table->string('description');
            $table->string('outcome');
            $table->json('result')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_patch_applications');
    }
};
