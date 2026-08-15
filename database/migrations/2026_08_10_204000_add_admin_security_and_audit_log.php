<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 24)->default('administrator')->index()->after('email');
            $table->text('mfa_secret')->nullable()->after('password');
            $table->json('mfa_recovery_codes')->nullable()->after('mfa_secret');
            $table->timestamp('mfa_enabled_at')->nullable()->after('mfa_recovery_codes');
        });
        DB::table('users')->whereNull('role')->update(['role' => 'administrator']);

        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 120)->index();
            $table->string('route_name')->nullable()->index();
            $table->string('method', 12);
            $table->string('path', 500);
            $table->string('target_type')->nullable();
            $table->string('target_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'mfa_secret', 'mfa_recovery_codes', 'mfa_enabled_at']);
        });
    }
};
