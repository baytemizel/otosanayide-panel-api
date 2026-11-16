<?php

namespace App\Console\Commands;

use App\Panel\Audit\Audit;
use App\Panel\Auth\Permissions;
use App\Panel\Members\MemberAdmin;
use App\Panel\Support\PanelDb;
use Illuminate\Console\Command;

/**
 * Ilk yoneticiyi (veya acil durumda bir uyeyi) konsoldan panel uyesi yapar. Ikinci kontrolu atlar; bu yuzden
 * etkin super yonetici varken ayricalikli rol icin --force ister. Sifre olusturmaz: hesap ana API'de vardir.
 */
final class MemberGrant extends Command
{
    protected $signature = 'panel:member-grant {email : Ana API hesabinin e-postasi}
        {--role=* : Rol anahtari (birden fazla verilebilir)}
        {--name= : Panelde gorunen ad}
        {--reason= : Denetim kaydina yazilan gerekce}
        {--force : Etkin super yonetici varken ayricalikli rol vermeye izin ver}
        {--apply : Yaz (yoksa yalniz kontrol)}';

    protected $description = 'Var olan platform hesabini panel uyesi yapar (varsayilan kuru calisma).';

    public function handle(): int
    {
        $roles = array_values(array_unique((array) $this->option('role')));
        $reason = (string) $this->option('reason');
        if ($roles === [] || mb_strlen($reason) < 10) {
            $this->error('En az bir --role ve 10+ karakter --reason gerekli.');

            return self::INVALID;
        }
        $account = MemberAdmin::eligibleAccount((string) $this->argument('email'));
        MemberAdmin::assertRoles($roles);
        if (PanelDb::table('panel_members')->where('account_id', $account->id)->exists()) {
            $this->error('Bu hesap zaten panel üyesi. Rol değişikliği panelden yapılır.');

            return self::FAILURE;
        }
        $activeSupers = PanelDb::table('panel_member_roles as mr')->join('panel_roles as r', 'r.id', '=', 'mr.role_id')->join('panel_members as m', 'm.account_id', '=', 'mr.account_id')
            ->where('r.key', 'super_admin')->where('m.status', 'active')->count();
        $privileged = array_filter($roles, static fn (string $r): bool => Permissions::isPrivilegedRole($r));
        if ($activeSupers > 0 && $privileged !== [] && ! $this->option('force')) {
            $this->error('Etkin süper yönetici var: ayrıcalıklı rol panelden ikinci kontrolle verilir. Acil durumda --force.');

            return self::FAILURE;
        }
        $name = (string) ($this->option('name') ?: strstr($account->email, '@', true));
        $this->table(['Hesap', 'E-posta', 'Ad', 'Roller'], [[$account->id, $account->email, $name, implode(', ', $roles)]]);
        if (! $this->option('apply')) {
            $this->warn('Kuru çalışma: yazılmadı. Yazmak için --apply.');

            return self::SUCCESS;
        }
        PanelDb::tx(fn () => MemberAdmin::create($account->id, $name, $roles, [], 'console', 'console'));
        Audit::console('member.created', 'panel_member', $account->id, $reason, ['roles' => $roles, 'force' => (bool) $this->option('force')]);
        $this->info('Panel üyesi oluşturuldu. İlk girişte TOTP kurulumu '.config('panel.mfa.enrollment_hours').' saat içinde yapılmalı.');

        return self::SUCCESS;
    }
}
