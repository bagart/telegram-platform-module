<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engine-owned tables (doc 40 §15, doc 33 Part 1):
 *  - bot_module_activations: per-bot desired enablement bindings with an
 *    integer revision for optimistic locking; UNIQUE(bot_id, module_id)
 *    guarantees one logical module instance per bot.
 *  - bot_module_routes: the dispatcher routing table built on the control
 *    path and stored in PostgreSQL; the engine only reads it at runtime.
 * module_id is a stable logical string — no FK on any installed-modules
 * table (Composer state, not DB state).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('bot_module_activations', function (Blueprint $table): void {
            $table->id();
            $table->string('bot_id');
            $table->string('module_id');
            $table->string('status'); // 'enabled' | 'disabled' (desired state)
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['bot_id', 'module_id']);
            $table->index('bot_id');
        });

        Schema::create('bot_module_routes', function (Blueprint $table): void {
            $table->id();
            $table->string('bot_id');
            $table->string('module_id');
            $table->string('entry_type'); // typed entry kind, e.g. "command", "callback"
            $table->string('entry_key'); // dispatch key within the type
            $table->unsignedInteger('priority')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['bot_id', 'module_id', 'entry_type', 'entry_key']);
            $table->index(['bot_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_module_routes');
        Schema::dropIfExists('bot_module_activations');
    }
};
