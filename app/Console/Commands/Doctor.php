<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Kurulum denetimi. Varsayilan: yalniz yapilandirma (baglanti yok). --db: salt okunur sorgularla tablo varligi
 * (ana API sema on kosulu ve panel migration'lari). Hicbir sey yazmaz.
 */
final class Doctor extends Command
{
    protected $signature = 'panel:doctor {--db : Veritabanina salt okunur baglanip tablolari denetle}';

    protected $description = 'Panel API yapilandirma ve sema on kosullarini denetler (yazmaz).';

    private const MAIN_TABLES = ['auth_accounts', 'auth_sessions', 'auth_events', 'businesses', 'business_values', 'reviews', 'claims', 'business_changes', 'feature_activations', 'privacy_requests', 'notification_jobs', 'ad_campaigns', 'brand_campaigns', 'scheduled_runs', 'account_fences', 'jobs', 'failed_jobs', 'cache'];

    private const PANEL_TABLES = ['panel_roles', 'panel_permissions', 'panel_role_permissions', 'panel_members', 'panel_member_roles', 'panel_mfa_factors', 'panel_login_challenges', 'panel_sessions', 'panel_access_tokens', 'panel_audit_logs', 'panel_work_items', 'panel_work_item_events', 'panel_notes', 'panel_approvals', 'panel_support_grants', 'panel_settings', 'panel_taxonomy_categories', 'panel_taxonomy_services', 'panel_taxonomy_aliases', 'panel_taxonomy_cities', 'panel_taxonomy_districts', 'panel_stat_snapshots'];

    public function handle(): int
    {
        $checks = [
            ['APP_KEY', config('app.key') ? 'ok' : 'EKSİK'],
            ['APP_ENV', (string) config('app.env')],
            ['APP_DEBUG', config('app.debug') ? (app()->environment('production') ? 'UYARI: üretimde açık' : 'açık') : 'kapalı'],
            ['DB_CONNECTION', (string) config('database.default')],
            ['DB_HOST/DB_DATABASE', config('database.connections.mysql.host') && config('database.connections.mysql.database') ? 'tanımlı' : 'boş'],
            ['Migration defteri', (string) config('database.migrations.table')],
            ['PANEL_WEB_ORIGIN', config('panel.web_origin') ?: 'boş (tarayıcı CORS kapalı)'],
            ['PANEL_IP_ALLOWLIST', config('panel.ip_allowlist') ?: 'boş (genel kısıt yok)'],
            ['Yenileme çerezi secure', config('panel.refresh_cookie.secure') ? 'evet' : 'HAYIR (yalnız yerel)'],
            ['TRUSTED_PROXIES', config('otosanayide.trusted_proxies') ?: 'boş'],
            ['CACHE_STORE / prefix', config('cache.default').' / '.config('cache.prefix')],
            ['FILESYSTEM_DISK', (string) config('filesystems.default')],
        ];
        foreach ((array) config('otosanayide.flags') as $flag => $on) {
            if ($on) {
                $checks[] = ['Bayrak '.$flag, 'açık (ana API ile aynı olmalı)'];
            }
        }
        $this->table(['Denetim', 'Durum'], $checks);

        if (! $this->option('db')) {
            $this->line('Veritabanı denetimi için --db (salt okunur).');

            return config('app.key') ? self::SUCCESS : self::FAILURE;
        }
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('Veritabanına bağlanılamadı: '.$e::class);

            return self::FAILURE;
        }
        $missingMain = array_values(array_filter(self::MAIN_TABLES, static fn (string $t): bool => ! Schema::hasTable($t)));
        $missingPanel = array_values(array_filter(self::PANEL_TABLES, static fn (string $t): bool => ! Schema::hasTable($t)));
        $this->table(['Şema', 'Eksik tablo'], [
            ['Ana API (ön koşul)', $missingMain === [] ? 'yok' : implode(', ', $missingMain)],
            ['Panel', $missingPanel === [] ? 'yok' : implode(', ', $missingPanel)],
        ]);
        if ($missingPanel === []) {
            $this->table(['Panel verisi', 'Sayı'], [
                ['Roller', DB::table('panel_roles')->count()],
                ['Yetkiler', DB::table('panel_permissions')->count()],
                ['Etkin üyeler', DB::table('panel_members')->where('status', 'active')->count()],
                ['Kategoriler', DB::table('panel_taxonomy_categories')->count()],
            ]);
        }

        return $missingMain === [] && $missingPanel === [] ? self::SUCCESS : self::FAILURE;
    }
}
