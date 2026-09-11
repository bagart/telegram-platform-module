<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_module_activations', function (Blueprint $table): void {
            $table->json('module_settings')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bot_module_activations', function (Blueprint $table): void {
            $table->dropColumn('module_settings');
        });
    }
};
