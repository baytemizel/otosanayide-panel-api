<?php

namespace Database\Seeders;

use App\Panel\Members\MemberAdmin;
use App\Panel\Support\Clock;
use App\Support\Tx;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Yalniz local/testing: her sistem rolu icin sentetik hesap (`<rol>@panel.test`) ve panel uyeligi.
 * Sifre ortamdan gelir (LOCAL_PANEL_PASSWORD, 12+); depoda sabit sifre yoktur. Uretimde calismaz.
 * On kosul: yerel veritabaninda ana API migration'lari ve DatabaseSeeder uygulanmis olmali.
 */
final class LocalPanelFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('LocalPanelFixtureSeeder yalnız local ve testing ortamında çalışır.');
        }
        $password = getenv('LOCAL_PANEL_PASSWORD') ?: null;
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('LOCAL_PANEL_PASSWORD (en az 12 karakter) gerekli.');
        }
        $hash = Hash::make($password);
        $created = 0;
        Tx::run(function () use ($hash, &$created): void {
            foreach (array_keys((array) config('panel_permissions.roles')) as $role) {
                $email = str_replace('_', '-', $role).'@panel.test';
                $id = 'panelfixture'.substr(hash('sha256', $email), 0, 16);
                if (! DB::table('auth_accounts')->where('id', $id)->exists()) {
                    $now = Clock::db();
                    DB::table('auth_accounts')->insert(['id' => $id, 'email' => $email, 'email_verified_at' => $now, 'password_hash' => $hash, 'password_changed_at' => $now, 'tokens_valid_after' => $now]);
                }
                if (! DB::table('panel_members')->where('account_id', $id)->exists()) {
                    MemberAdmin::create($id, 'Sentetik '.$role, [$role], [], 'console', 'console');
                    $created++;
                }
            }
        });
        $this->command?->info('Sentetik panel üyeleri: +'.$created.' (<rol>@panel.test). TOTP kurulumu ilk girişte.');
    }
}
