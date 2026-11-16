<?php

namespace App\Panel\Members;

use App\Exceptions\HttpError;
use App\Panel\Auth\Permissions;
use App\Panel\Auth\Sessions;
use App\Panel\Security\Ip;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use App\Panel\Support\Secret;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Panel uyeligi. Uye, ana API'de var olan, e-postasi dogrulanmis ve etkin bir hesaptir; panel sifre olusturmaz.
 * Uyelik panel veritabanindadir, hesap (e-posta, durum) ana veritabaninda. Yazimlar cagiranin PanelDb::tx'inde
 * calisir (Approvals onay aninda ayni kurallari yeniden uygular).
 */
final class MemberAdmin
{
    /** @return array<string,mixed> */
    public static function view(stdClass $m): array
    {
        $roles = PanelDb::table('panel_member_roles as mr')->join('panel_roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.account_id', $m->account_id)->orderBy('r.key')->pluck('r.key')->all();
        $factor = PanelDb::table('panel_mfa_factors')->where('account_id', $m->account_id)->whereNull('disabled_at')->whereNotNull('verified_at')->exists();

        return [
            'accountId' => $m->account_id,
            'email' => $m->email ?? null,
            'displayName' => $m->display_name,
            'status' => $m->status,
            'roles' => $roles,
            'mfaEnabled' => $factor,
            'mfaEnrollUntil' => Clock::iso($m->mfa_enroll_until),
            'ipAllowlist' => json_decode((string) ($m->ip_allowlist ?? 'null'), true) ?? [],
            'suspendedReason' => $m->suspended_reason,
            'lastLoginAt' => Clock::iso($m->last_login_at),
            'createdBy' => $m->created_by,
            'createdAt' => Clock::iso($m->created_at),
            'version' => (int) $m->version,
        ];
    }

    /** Uye satiri (panel) + e-posta (ana veritabani). $lock: cagiranin PanelDb::tx'i icinde satir kilidi. */
    public static function row(string $accountId, bool $lock = false): ?stdClass
    {
        $q = PanelDb::table('panel_members')->where('account_id', $accountId);
        if ($lock) {
            $q->lockForUpdate();
        }
        $m = $q->first();
        if ($m !== null) {
            $m->email = DB::table('auth_accounts')->where('id', $accountId)->value('email');
        }

        return $m;
    }

    /**
     * Uye listesine ana veritabanindan e-posta ekler (tek sorgu).
     *
     * @param  list<stdClass>  $members
     * @return list<stdClass>
     */
    public static function withEmails(array $members): array
    {
        $ids = array_map(static fn (stdClass $m): string => (string) $m->account_id, $members);
        $emails = $ids === [] ? collect() : DB::table('auth_accounts')->whereIn('id', $ids)->pluck('email', 'id');
        foreach ($members as $m) {
            $m->email = $emails->get($m->account_id);
        }

        return $members;
    }

    /** @return list<string> Tum panel uyelerinin hesap kimlikleri (az sayida; ana veritabani filtreleri icin). */
    public static function accountIds(): array
    {
        return array_map('strval', PanelDb::table('panel_members')->pluck('account_id')->all());
    }

    /** @param list<string> $roles */
    public static function assertRoles(array $roles): void
    {
        if ($roles === [] || count($roles) !== count(array_unique($roles))) {
            throw new HttpError(422, 'En az bir rol ve tekrarsız rol listesi gerekli.');
        }
        $known = PanelDb::table('panel_roles')->whereIn('key', $roles)->count();
        if ($known !== count($roles)) {
            throw new HttpError(422, 'Bilinmeyen rol.');
        }
    }

    /** @param list<string> $list */
    public static function assertIpList(array $list): void
    {
        foreach ($list as $cidr) {
            if (! Ip::validCidr($cidr)) {
                throw new HttpError(422, 'IP listesi geçersiz: '.$cidr);
            }
        }
    }

    /** @return stdClass hesap */
    public static function eligibleAccount(string $email): stdClass
    {
        $a = DB::table('auth_accounts')->where('email', Secret::normalizeEmail($email))->first(['id', 'email', 'email_verified_at', 'status']);
        if ($a === null) {
            throw new HttpError(404, 'Bu e-postayla platform hesabı yok. Kişi önce otosanayide.com hesabı açmalı.');
        }
        if ($a->status !== 'active' || $a->email_verified_at === null) {
            throw new HttpError(409, 'Hesap etkin değil veya e-postası doğrulanmamış.');
        }
        if (DB::table('account_fences')->where('owner_uid', $a->id)->exists()) {
            throw new HttpError(409, 'Hesap silme sürecinde.');
        }

        return $a;
    }

    /** @param list<string> $roles @param list<string> $ipAllowlist */
    public static function create(string $accountId, string $displayName, array $roles, array $ipAllowlist, string $grantedBy, string $createdBy): void
    {
        if (PanelDb::table('panel_members')->where('account_id', $accountId)->exists()) {
            throw new HttpError(409, 'Bu hesap zaten panel üyesi.');
        }
        // Veritabanlari arasi yabanci anahtar yok: hesabin ana veritabaninda varligi burada denetlenir.
        if (! DB::table('auth_accounts')->where('id', $accountId)->exists()) {
            throw new HttpError(404, 'Platform hesabı bulunamadı.');
        }
        self::assertRoles($roles);
        $now = Clock::now();
        PanelDb::table('panel_members')->insert([
            'account_id' => $accountId,
            'display_name' => $displayName,
            'ip_allowlist' => $ipAllowlist === [] ? null : json_encode($ipAllowlist),
            'mfa_enroll_until' => Clock::db($now->addHours((int) config('panel.mfa.enrollment_hours'))),
            'created_by' => $createdBy,
            'created_at' => Clock::db($now),
            'updated_at' => Clock::db($now),
        ]);
        self::insertRoles($accountId, $roles, $grantedBy);
    }

    /** @param list<string> $roles */
    private static function insertRoles(string $accountId, array $roles, string $grantedBy): void
    {
        $ids = PanelDb::table('panel_roles')->whereIn('key', $roles)->pluck('id')->all();
        PanelDb::table('panel_member_roles')->insert(array_map(static fn (int $id): array => ['account_id' => $accountId, 'role_id' => $id, 'granted_by' => $grantedBy, 'granted_at' => Clock::db()], array_map('intval', $ids)));
    }

    /** @return list<string> */
    public static function rolesOf(string $accountId): array
    {
        return PanelDb::table('panel_member_roles as mr')->join('panel_roles as r', 'r.id', '=', 'mr.role_id')->where('mr.account_id', $accountId)->pluck('r.key')->all();
    }

    /** @param list<string> $roles @return list<string> eklenen ayricalikli roller */
    public static function privilegedAdditions(string $accountId, array $roles): array
    {
        return array_values(array_filter(array_diff($roles, self::rolesOf($accountId)), static fn (string $r): bool => Permissions::isPrivilegedRole($r)));
    }

    /** @param list<string> $roles */
    public static function setRoles(string $accountId, array $roles, int $version, string $grantedBy): void
    {
        $m = self::row($accountId, true);
        if ($m === null) {
            throw new HttpError(404, 'Panel üyesi bulunamadı.');
        }
        if ((int) $m->version !== $version) {
            throw new HttpError(409, 'Üye kaydı değişti. Yenileyip tekrar deneyin.');
        }
        self::assertRoles($roles);
        if (! in_array('super_admin', $roles, true)) {
            self::assertNotLastSuperAdmin($accountId);
        }
        PanelDb::table('panel_member_roles')->where('account_id', $accountId)->delete();
        self::insertRoles($accountId, $roles, $grantedBy);
        PanelDb::table('panel_members')->where('account_id', $accountId)->update(['version' => $version + 1, 'updated_at' => Clock::db()]);
        // Urun karari (K169): rol degisiminde oturum kapanir.
        Sessions::revokeAccount($accountId, 'roles_changed');
    }

    public static function assertNotLastSuperAdmin(string $accountId): void
    {
        $others = PanelDb::table('panel_member_roles as mr')->join('panel_roles as r', 'r.id', '=', 'mr.role_id')
            ->join('panel_members as m', 'm.account_id', '=', 'mr.account_id')
            ->where('r.key', 'super_admin')->where('m.status', 'active')->where('mr.account_id', '!=', $accountId)->count();
        $isSuper = in_array('super_admin', self::rolesOf($accountId), true);
        if ($isSuper && $others === 0) {
            throw new HttpError(409, 'En az bir etkin süper yönetici kalmalı.');
        }
    }

    public static function setStatus(string $accountId, string $status, ?string $reason, int $version): void
    {
        $m = self::row($accountId, true);
        if ($m === null) {
            throw new HttpError(404, 'Panel üyesi bulunamadı.');
        }
        if ((int) $m->version !== $version) {
            throw new HttpError(409, 'Üye kaydı değişti. Yenileyip tekrar deneyin.');
        }
        if ($m->status === $status) {
            throw new HttpError(409, $status === 'suspended' ? 'Üye zaten askıda.' : 'Üye zaten etkin.');
        }
        if ($status === 'suspended') {
            self::assertNotLastSuperAdmin($accountId);
        }
        PanelDb::table('panel_members')->where('account_id', $accountId)->update(['status' => $status, 'suspended_reason' => $status === 'suspended' ? $reason : null, 'version' => $version + 1, 'updated_at' => Clock::db()]);
        if ($status === 'suspended') {
            Sessions::revokeAccount($accountId, 'suspended');
        }
    }

    public static function resetMfa(string $accountId, int $version): void
    {
        $m = self::row($accountId, true);
        if ($m === null) {
            throw new HttpError(404, 'Panel üyesi bulunamadı.');
        }
        if ((int) $m->version !== $version) {
            throw new HttpError(409, 'Üye kaydı değişti. Yenileyip tekrar deneyin.');
        }
        $now = Clock::now();
        PanelDb::table('panel_mfa_factors')->where('account_id', $accountId)->whereNull('disabled_at')->update(['disabled_at' => Clock::db($now)]);
        PanelDb::table('panel_members')->where('account_id', $accountId)->update(['mfa_enroll_until' => Clock::db($now->addHours((int) config('panel.mfa.enrollment_hours'))), 'version' => $version + 1, 'updated_at' => Clock::db($now)]);
        Sessions::revokeAccount($accountId, 'mfa_reset');
    }
}
