<?php

namespace Database\Seeders;

use App\Panel\Audit\Audit;
use App\Panel\Members\MemberAdmin;
use App\Support\Tx;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ilk super yonetici. Kimlik yalniz ortamdan gelir ve repoya girmez:
 *   SEED_PANEL_ADMIN_EMAIL=... php artisan db:seed --class=PanelBootstrapAdminSeeder --force
 * Hesap ana API'de var olmali ve e-postasi dogrulanmis olmali (sifre panelde olusturulmaz).
 * Etkin super yonetici varsa calismaz; sonraki uyeler panelden ikinci kontrolle eklenir.
 */
final class PanelBootstrapAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = getenv('SEED_PANEL_ADMIN_EMAIL') ?: null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SEED_PANEL_ADMIN_EMAIL gerekli.');
        }
        $supers = DB::table('panel_member_roles as mr')->join('panel_roles as r', 'r.id', '=', 'mr.role_id')->join('panel_members as m', 'm.account_id', '=', 'mr.account_id')
            ->where('r.key', 'super_admin')->where('m.status', 'active')->count();
        if ($supers > 0) {
            throw new RuntimeException('Etkin süper yönetici var. Yeni üye panelden eklenir (ikinci kontrol).');
        }
        $account = MemberAdmin::eligibleAccount($email);
        $name = (string) (getenv('SEED_PANEL_ADMIN_NAME') ?: strstr($account->email, '@', true));
        Tx::run(fn () => MemberAdmin::create($account->id, $name, ['super_admin'], [], 'console', 'console'));
        Audit::console('member.created', 'panel_member', $account->id, 'PanelBootstrapAdminSeeder: ilk süper yönetici', ['roles' => ['super_admin']]);
        $this->command?->info('İlk süper yönetici hazır. TOTP kurulumu ilk girişte, '.config('panel.mfa.enrollment_hours').' saat içinde.');
    }
}
