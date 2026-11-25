<?php

namespace App\Console\Commands;

use App\Panel\Members\MemberAdmin;
use App\Panel\Support\PanelDb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Kurulum denetimi. Varsayilan: yalniz yapilandirma (baglanti yok). --db: iki veritabanina salt okunur baglanip
 * tablo varligi (ana veritabani: ana API sema on kosulu ve cekirdegin istedigi ana API migration'lari; panel
 * veritabani: panel migration'lari) ve ana
 * veritabaninda hesabi bulunmayan panel uyeleri. Hicbir sey yazmaz.
 */
final class Doctor extends Command
{
    protected $signature = 'panel:doctor {--db : Veritabanina salt okunur baglanip tablolari denetle}';

    protected $description = 'Panel API yapilandirma ve sema on kosullarini denetler (yazmaz).';

    /**
     * Vendor edilen cekirdegin (ana API 26b9e23) okudugu/yazdigi kolonlar bu ana API migration'lariyla gelir
     * (yorum yon puanlari, bildirim nedeni, ekip rolleri...). Uygulanmamissa panelden yorum onayi hata verir.
     */
    private const MAIN_MIGRATIONS = ['2026_11_21_100001_v2_requests', '2026_11_21_100002_v2_garage', '2026_11_21_100003_v2_reviews', '2026_11_21_100004_v2_account', '2026_11_21_100005_v2_ads_insights', '2026_11_21_100006_v2_team_roles_mfa'];

    private const MAIN_TABLES = ['migrations', 'auth_accounts', 'auth_sessions', 'auth_events', 'businesses', 'business_values', 'reviews', 'claims', 'business_changes', 'feature_activations', 'privacy_requests', 'notification_jobs', 'ad_campaigns', 'brand_campaigns', 'scheduled_runs', 'account_fences', 'jobs', 'failed_jobs', 'cache'];

    private const PANEL_TABLES = ['panel_roles', 'panel_permissions', 'panel_role_permissions', 'panel_members', 'panel_member_roles', 'panel_mfa_factors', 'panel_login_challenges', 'panel_sessions', 'panel_access_tokens', 'panel_audit_logs', 'panel_work_items', 'panel_work_item_events', 'panel_notes', 'panel_approvals', 'panel_support_grants', 'panel_settings', 'panel_taxonomy_categories', 'panel_taxonomy_services', 'panel_taxonomy_aliases', 'panel_taxonomy_cities', 'panel_taxonomy_districts', 'panel_stat_snapshots'];

    public function handle(): int
    {
        $checks = [
            ['APP_KEY', config('app.key') ? 'ok' : 'EKSİK'],
            ['APP_ENV', (string) config('app.env')],
            ['APP_DEBUG', config('app.debug') ? (app()->environment('production') ? 'UYARI: üretimde açık' : 'açık') : 'kapalı'],
            ['Ana veritabanı (DB_*)', self::describe((string) config('database.default'))],
            ['Panel veritabanı (PANEL_DB_*)', self::describe(PanelDb::CONNECTION)],
            ['Veritabanları ayrı', self::separate() ? 'evet' : 'HAYIR: panel ve ana veritabanı aynı'],
            ['Migration defteri', PanelDb::CONNECTION.' / '.config('database.migrations.table')],
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

            return config('app.key') && self::configured((string) config('database.default')) && self::configured(PanelDb::CONNECTION) ? self::SUCCESS : self::FAILURE;
        }
        foreach (['Ana' => DB::connection(), 'Panel' => PanelDb::connection()] as $label => $connection) {
            try {
                $connection->getPdo();
            } catch (Throwable $e) {
                $this->error($label.' veritabanına bağlanılamadı: '.$e::class);

                return self::FAILURE;
            }
        }
        $missingMain = array_values(array_filter(self::MAIN_TABLES, static fn (string $t): bool => ! Schema::hasTable($t)));
        $missingPanel = array_values(array_filter(self::PANEL_TABLES, static fn (string $t): bool => ! Schema::connection(PanelDb::CONNECTION)->hasTable($t)));
        $missingMigrations = in_array('migrations', $missingMain, true) ? self::MAIN_MIGRATIONS
            : array_values(array_diff(self::MAIN_MIGRATIONS, DB::table('migrations')->whereIn('migration', self::MAIN_MIGRATIONS)->pluck('migration')->all()));
        $this->table(['Şema', 'Eksik'], [
            ['Ana veritabanı tabloları (ön koşul)', $missingMain === [] ? 'yok' : implode(', ', $missingMain)],
            ['Ana API migration\'ları (çekirdek ön koşulu)', $missingMigrations === [] ? 'yok' : implode(', ', $missingMigrations)],
            ['Panel veritabanı tabloları', $missingPanel === [] ? 'yok' : implode(', ', $missingPanel)],
        ]);
        $orphans = [];
        if ($missingPanel === []) {
            // Veritabanlari arasi yabanci anahtar yok: hesabi ana veritabaninda olmayan uye giris yapamaz, burada raporlanir.
            $ids = MemberAdmin::accountIds();
            $known = $missingMain === [] ? DB::table('auth_accounts')->whereIn('id', $ids)->pluck('id')->all() : $ids;
            $orphans = array_values(array_diff($ids, array_map('strval', $known)));
            $this->table(['Panel verisi', 'Sayı'], [
                ['Roller', PanelDb::table('panel_roles')->count()],
                ['Yetkiler', PanelDb::table('panel_permissions')->count()],
                ['Etkin üyeler', PanelDb::table('panel_members')->where('status', 'active')->count()],
                ['Hesabı bulunmayan üyeler', count($orphans)],
                ['Kategoriler', PanelDb::table('panel_taxonomy_categories')->count()],
            ]);
            if ($orphans !== []) {
                $this->warn('Ana veritabanında hesabı olmayan panel üyeleri: '.implode(', ', $orphans));
            }
        }

        return $missingMain === [] && $missingMigrations === [] && $missingPanel === [] && $orphans === [] ? self::SUCCESS : self::FAILURE;
    }

    private static function describe(string $connection): string
    {
        return self::configured($connection)
            ? 'tanımlı ('.$connection.' · '.config('database.connections.'.$connection.'.database').')'
            : 'EKSİK ('.$connection.': sunucu veya veritabanı adı boş)';
    }

    private static function configured(string $connection): bool
    {
        $c = (array) config('database.connections.'.$connection);

        return ($c['host'] ?? '') !== '' && ($c['database'] ?? '') !== '';
    }

    private static function separate(): bool
    {
        $main = (array) config('database.connections.'.config('database.default'));
        $panel = (array) config('database.connections.'.PanelDb::CONNECTION);

        return [$main['host'] ?? null, (string) ($main['port'] ?? ''), $main['database'] ?? null] !== [$panel['host'] ?? null, (string) ($panel['port'] ?? ''), $panel['database'] ?? null];
    }
}
