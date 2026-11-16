<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel kimligi ve yetkisi. Kimlik ana veritabanindaki `auth_accounts` satiridir (ayni kisi, ayni sifre);
 * panel uyeligi, rolleri, ayri TOTP faktoru ve oturumlari panel veritabaninda tutulur. Iki veritabani arasinda
 * yabanci anahtar yoktur: hesap varligi uyelik olusturulurken ve her giriste/istekte ana veritabanindan denetlenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_roles', function (Blueprint $t) {
            $t->id();
            $t->ascii('key', 64)->unique();
            $t->string('name', 120);
            $t->string('description', 500)->default('');
            $t->boolean('is_system')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->ms('created_at')->useCurrent();
            $t->ms('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('panel_permissions', function (Blueprint $t) {
            $t->id();
            $t->ascii('key', 96)->unique();
            $t->ascii('group', 48);
            $t->string('description', 300)->default('');
            $t->ms('created_at')->useCurrent();
        });

        Schema::create('panel_role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained('panel_roles')->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained('panel_permissions')->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::create('panel_members', function (Blueprint $t) {
            $t->cid('account_id')->primary();
            $t->string('display_name', 120);
            $t->enum('status', ['active', 'suspended'])->charset('ascii')->collation('ascii_bin')->default('active');
            $t->json('ip_allowlist')->nullable(); // CIDR listesi; bos: yalniz genel liste.
            $t->ms('mfa_enroll_until')->nullable(); // Ilk TOTP kurulumu yalniz bu ana kadar.
            $t->text('suspended_reason')->nullable();
            $t->ms('last_login_at')->nullable();
            $t->sha256('last_login_ip_hash')->nullable();
            $t->cid('created_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->ms('created_at')->useCurrent();
            $t->ms('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->index(['status', 'account_id']);
        });

        Schema::create('panel_member_roles', function (Blueprint $t) {
            $t->cid('account_id');
            $t->foreignId('role_id')->constrained('panel_roles')->restrictOnDelete();
            $t->cid('granted_by')->nullable();
            $t->ms('granted_at')->useCurrent();
            $t->primary(['account_id', 'role_id']);
            $t->index('role_id');
            $t->foreign('account_id')->references('account_id')->on('panel_members')->cascadeOnDelete();
        });

        // Panel TOTP faktoru: ana API faktorunden ayri, panel APP_KEY ile sifreli.
        Schema::create('panel_mfa_factors', function (Blueprint $t) {
            $t->ascii('id')->primary();
            $t->cid('account_id');
            $t->text('secret_ciphertext');
            $t->unsignedBigInteger('last_used_step')->nullable();
            $t->ms('verified_at')->nullable();
            $t->ms('disabled_at')->nullable();
            $t->ms('created_at')->useCurrent();
            $t->index(['account_id', 'disabled_at']);
            $t->foreign('account_id')->references('account_id')->on('panel_members')->cascadeOnDelete();
        });

        // Sifre adimindan sonra verilen tek kullanimlik MFA veya kurulum jetonu (yalniz ozet).
        Schema::create('panel_login_challenges', function (Blueprint $t) {
            $t->sha256('token_hash')->primary();
            $t->cid('account_id');
            $t->enum('purpose', ['mfa', 'enroll'])->charset('ascii')->collation('ascii_bin');
            $t->ascii('factor_id');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->sha256('ip_hash')->nullable();
            $t->ms('created_at')->useCurrent();
            $t->ms('expires_at')->index();
            $t->ms('consumed_at')->nullable();
            $t->foreign('account_id')->references('account_id')->on('panel_members')->cascadeOnDelete();
        });

        // Yenileme jetonu basina bir satir; donen jeton ailesi `family_id` ile izlenir, tekrar kullanimda aile kapanir.
        Schema::create('panel_sessions', function (Blueprint $t) {
            $t->ascii('id')->primary();
            $t->cid('account_id');
            $t->ascii('family_id')->index();
            $t->sha256('refresh_token_hash')->unique();
            $t->sha256('ip_hash')->nullable();
            $t->string('user_agent', 255)->default('');
            $t->ms('mfa_at');
            $t->ms('created_at')->useCurrent();
            $t->ms('idle_expires_at');
            $t->ms('expires_at')->index();
            $t->ms('revoked_at')->nullable();
            $t->ascii('revoke_reason', 32)->nullable();
            $t->ascii('replaced_by')->nullable();
            $t->index(['account_id', 'revoked_at']);
            $t->foreign('account_id')->references('account_id')->on('panel_members')->cascadeOnDelete();
        });

        Schema::create('panel_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->ascii('session_id');
            $t->cid('account_id');
            $t->sha256('token_hash')->unique();
            $t->ms('expires_at')->index();
            $t->ms('last_used_at')->nullable();
            $t->ms('created_at')->useCurrent();
            $t->index('account_id');
            $t->foreign('session_id')->references('id')->on('panel_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['panel_access_tokens', 'panel_sessions', 'panel_login_challenges', 'panel_mfa_factors', 'panel_member_roles', 'panel_members', 'panel_role_permissions', 'panel_permissions', 'panel_roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
