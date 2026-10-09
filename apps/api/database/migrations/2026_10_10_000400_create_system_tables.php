<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sanctum tokens with UUID owners (do not publish Sanctum's default migration).
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        // Protected-table edits that the server refused to auto-merge (D2).
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('branch_id')->nullable();
            $table->string('table_name');
            $table->uuid('row_id');
            $table->unsignedInteger('base_version');
            $table->unsignedInteger('server_version');
            $table->json('client_changes');
            $table->json('server_row');
            $table->uuid('device_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->enum('status', ['open', 'kept_server', 'applied_client', 'merged'])->default('open');
            $table->uuid('resolved_by')->nullable();
            $table->timestamps();
            $table->index(['table_name', 'row_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('school_id')->nullable()->index();
            $table->uuid('user_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->string('action');              // create, update, approve, reverse, impersonate...
            $table->string('table_name')->nullable();
            $table->uuid('row_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('personal_access_tokens');
    }
};
