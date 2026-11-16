<?php

namespace App\Console\Commands;

use App\Panel\Audit\Audit;
use App\Panel\Auth\Sessions;
use App\Panel\Members\MemberAdmin;
use App\Panel\Support\Secret;
use App\Support\Tx;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Acil durum: panele girilemezken uyeyi askiya alma, yeniden acma, MFA sifirlama ve oturum kapatma. */
final class MemberSecurity extends Command
{
    protected $signature = 'panel:member-security {action : suspend|reactivate|mfa-reset|revoke-sessions}
        {email : Uyenin e-postasi}
        {--reason= : Denetim gerekcesi (10+ karakter)}
        {--apply : Yaz (yoksa yalniz kontrol)}';

    protected $description = 'Panel uyesi icin acil guvenlik islemi (varsayilan kuru calisma).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $reason = (string) $this->option('reason');
        if (! in_array($action, ['suspend', 'reactivate', 'mfa-reset', 'revoke-sessions'], true) || mb_strlen($reason) < 10) {
            $this->error('Geçerli bir işlem ve 10+ karakter --reason gerekli.');

            return self::INVALID;
        }
        $accountId = DB::table('auth_accounts')->where('email', Secret::normalizeEmail((string) $this->argument('email')))->value('id');
        $member = $accountId === null ? null : MemberAdmin::row((string) $accountId);
        if ($member === null) {
            $this->error('Panel üyesi bulunamadı.');

            return self::FAILURE;
        }
        $this->line(sprintf('%s · %s · durum=%s · sürüm=%d', $member->account_id, $member->display_name, $member->status, $member->version));
        if (! $this->option('apply')) {
            $this->warn('Kuru çalışma: yazılmadı. Yazmak için --apply.');

            return self::SUCCESS;
        }
        $id = (string) $member->account_id;
        $version = (int) $member->version;
        Tx::run(function () use ($action, $id, $version, $reason): void {
            match ($action) {
                'suspend' => MemberAdmin::setStatus($id, 'suspended', $reason, $version),
                'reactivate' => MemberAdmin::setStatus($id, 'active', null, $version),
                'mfa-reset' => MemberAdmin::resetMfa($id, $version),
                default => Sessions::revokeAccount($id, 'console_revoked'),
            };
        });
        Audit::console('member.'.str_replace('-', '_', $action), 'panel_member', $id, $reason);
        $this->info('Tamamlandı.');

        return self::SUCCESS;
    }
}
