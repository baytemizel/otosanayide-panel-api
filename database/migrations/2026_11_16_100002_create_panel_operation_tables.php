<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panelin kendi operasyon verisi: denetim kaydi, is kuyrugu, ic notlar, ikinci kontrol, destek erisimi, ayarlar.
 * Ana API tablolarina FK verilmez: konu (subject) kaydi silinse de denetim ve not izi kalir.
 */
return new class extends Migration
{
    public function up(): void
    {
        $state = static fn (Blueprint $t, string $c, array $v) => $t->enum($c, $v)->charset('ascii')->collation('ascii_bin');

        // Yalniz ekleme: uygulama guncellemez veya silmez (saklama suresi panel:audit-prune ile, acik onayla).
        Schema::create('panel_audit_logs', function (Blueprint $t) use ($state) {
            $t->id();
            $t->ms('occurred_at')->useCurrent();
            $state($t, 'actor_type', ['member', 'console', 'system']);
            $t->cid('actor_id')->nullable();
            $t->ascii('action', 96);
            $state($t, 'outcome', ['success', 'denied', 'failed'])->default('success');
            $t->ascii('subject_type', 48)->nullable();
            $t->cid('subject_id', 191)->nullable();
            $t->text('reason')->nullable();
            $t->json('details')->nullable();
            $t->ascii('request_id', 64)->nullable(); // X-Request-Id en fazla 64 karakter (SecurityHeaders).
            $t->sha256('ip_hash')->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->index('occurred_at');
            $t->index(['actor_id', 'occurred_at']);
            $t->index(['subject_type', 'subject_id', 'occurred_at']);
            $t->index(['action', 'occurred_at']);
        });

        // Urun plani: her iste sorumlu, oncelik, durum, son islem ve gerekce. Kaynak kayit ana API tablosundadir.
        Schema::create('panel_work_items', function (Blueprint $t) use ($state) {
            $t->ascii('id')->primary();
            $t->ascii('queue', 48);
            $t->cid('subject_id', 191);
            $t->string('title', 300)->default('');
            $state($t, 'priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $state($t, 'status', ['open', 'in_progress', 'waiting', 'done', 'dismissed'])->default('open');
            $t->cid('assignee_id')->nullable();
            $t->ascii('source_state', 32)->default('');
            $t->ms('opened_at')->useCurrent();
            $t->ms('due_at')->nullable();
            $t->ms('closed_at')->nullable();
            $t->ascii('last_action', 64)->default('opened');
            $t->cid('last_actor_id')->nullable();
            $t->text('note')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->ms('created_at')->useCurrent();
            $t->ms('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->unique(['queue', 'subject_id']);
            $t->index(['status', 'queue', 'priority', 'opened_at']);
            $t->index(['assignee_id', 'status']);
            $t->index(['due_at', 'status']);
        });

        Schema::create('panel_work_item_events', function (Blueprint $t) {
            $t->id();
            $t->ascii('work_item_id');
            $t->ascii('action', 64);
            $t->cid('actor_id')->nullable();
            $t->ascii('from_status', 16)->nullable();
            $t->ascii('to_status', 16)->nullable();
            $t->json('details')->nullable();
            $t->ms('created_at')->useCurrent();
            $t->index(['work_item_id', 'id']);
            $t->foreign('work_item_id')->references('id')->on('panel_work_items')->cascadeOnDelete();
        });

        Schema::create('panel_notes', function (Blueprint $t) {
            $t->ascii('id')->primary();
            $t->ascii('subject_type', 48);
            $t->cid('subject_id', 191);
            $t->cid('author_id');
            $t->text('body');
            $t->ms('created_at')->useCurrent();
            $t->ms('deleted_at')->nullable();
            $t->cid('deleted_by')->nullable();
            $t->index(['subject_type', 'subject_id', 'created_at']);
        });

        // Ikinci kontrol (dort goz): talep eden onaylayamaz; onayda islem ayni transaction'da yurur.
        Schema::create('panel_approvals', function (Blueprint $t) use ($state) {
            $t->ascii('id')->primary();
            $t->ascii('action', 64);
            $t->ascii('subject_type', 48);
            $t->cid('subject_id', 191);
            $t->json('payload');
            $t->string('summary', 500);
            $t->cid('requested_by');
            $t->text('request_reason');
            $state($t, 'status', ['pending', 'approved', 'rejected', 'cancelled', 'expired', 'failed'])->default('pending');
            $t->cid('decided_by')->nullable();
            $t->text('decision_reason')->nullable();
            $t->ms('decided_at')->nullable();
            $t->string('failure', 500)->nullable();
            $t->ms('expires_at')->index();
            $t->unsignedInteger('version')->default(1);
            $t->ms('created_at')->useCurrent();
            $t->ms('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->index(['status', 'created_at']);
            $t->index(['subject_type', 'subject_id']);
        });

        // Destek erisimi acik, sureli ve kayitlidir (urun plani); her veri okumasi ayrica denetime yazilir.
        Schema::create('panel_support_grants', function (Blueprint $t) {
            $t->ascii('id')->primary();
            $t->cid('member_id');
            $t->cid('account_id');
            $t->json('scopes');
            $t->text('reason');
            $t->ms('starts_at')->useCurrent();
            $t->ms('expires_at');
            $t->ms('revoked_at')->nullable();
            $t->cid('revoked_by')->nullable();
            $t->ms('created_at')->useCurrent();
            $t->index(['member_id', 'account_id', 'expires_at']);
            $t->index(['account_id', 'expires_at']);
        });

        Schema::create('panel_settings', function (Blueprint $t) {
            $t->ascii('key', 96)->primary();
            $t->json('value');
            $t->string('description', 300)->default('');
            $t->unsignedInteger('version')->default(1);
            $t->cid('updated_by')->nullable();
            $t->ms('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        foreach (['panel_settings', 'panel_support_grants', 'panel_approvals', 'panel_notes', 'panel_work_item_events', 'panel_work_items', 'panel_audit_logs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
