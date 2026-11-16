<?php

namespace App\Panel\Members;

use App\Exceptions\HttpError;
use App\Panel\Auth\Permissions;
use App\Panel\Auth\Sessions;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;

/** Rol guncellemesi; dogrudan uygulama ve ikinci kontrol onayi ayni kurali kullanir (cagiranin transaction'inda). */
final class RoleAdmin
{
    /** @return list<string> */
    public static function permissionsOf(int $roleId): array
    {
        return PanelDb::table('panel_role_permissions as rp')->join('panel_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $roleId)->orderBy('p.key')->pluck('p.key')->all();
    }

    /** @param list<string> $keys @return list<int> */
    public static function permissionIds(array $keys): array
    {
        $unknown = array_diff($keys, Permissions::catalog());
        if ($unknown !== []) {
            throw new HttpError(422, 'Bilinmeyen yetki: '.implode(', ', $unknown));
        }

        return array_map('intval', PanelDb::table('panel_permissions')->whereIn('key', array_unique($keys))->pluck('id')->all());
    }

    /** Eklenecek hassas yetkiler (ikinci kontrol gerektirir). @param list<string> $permissions @return list<string> */
    public static function sensitiveAdditions(string $key, array $permissions): array
    {
        $roleId = PanelDb::table('panel_roles')->where('key', $key)->value('id');
        $current = $roleId === null ? [] : self::permissionsOf((int) $roleId);

        return array_values(array_intersect(array_diff($permissions, $current), Permissions::sensitive()));
    }

    /**
     * @param  array{version:int,name?:string,description?:string,permissions?:list<string>}  $in
     * @return array{id:int,before:list<string>,after:list<string>,revoked:int}
     */
    public static function update(string $key, array $in): array
    {
        $role = PanelDb::table('panel_roles')->where('key', $key)->lockForUpdate()->first();
        if ($role === null) {
            throw new HttpError(404, 'Rol bulunamadı.');
        }
        if (isset($in['permissions']) && $role->is_system) {
            // Sistem rolleri config/panel_permissions.php ile esitlenir (panel:permissions-sync).
            throw new HttpError(422, 'Sistem rolünün yetkileri katalogdan gelir. Farklı bir yetki seti için yeni rol oluşturun.');
        }
        if ((int) $role->version !== $in['version']) {
            throw new HttpError(409, 'Rol değişti. Yenileyip tekrar deneyin.');
        }
        $ids = isset($in['permissions']) ? self::permissionIds($in['permissions']) : null;
        $before = self::permissionsOf((int) $role->id);
        PanelDb::table('panel_roles')->where('id', $role->id)->update(array_filter(['name' => $in['name'] ?? null, 'description' => $in['description'] ?? null], static fn ($v) => $v !== null) + ['version' => $in['version'] + 1, 'updated_at' => Clock::db()]);
        $revoked = 0;
        if ($ids !== null) {
            PanelDb::table('panel_role_permissions')->where('role_id', $role->id)->delete();
            PanelDb::table('panel_role_permissions')->insert(array_map(static fn (int $p): array => ['role_id' => $role->id, 'permission_id' => $p], $ids));
            // Yetki degisimi rol degisimi sayilir: bu role sahip uyelerin oturumlari kapanir.
            foreach (PanelDb::table('panel_member_roles')->where('role_id', $role->id)->pluck('account_id') as $accountId) {
                $revoked += Sessions::revokeAccount((string) $accountId, 'roles_changed');
            }
        }

        return ['id' => (int) $role->id, 'before' => $before, 'after' => self::permissionsOf((int) $role->id), 'revoked' => $revoked];
    }
}
