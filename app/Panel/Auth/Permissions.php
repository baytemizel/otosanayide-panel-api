<?php

namespace App\Panel\Auth;

use App\Panel\Settings\Settings;
use Illuminate\Support\Facades\DB;

/** Uyenin rolleri ve yetkileri (veritabanindan). super_admin katalogdaki her yetkiyi alir. */
final class Permissions
{
    /** @return array{roles:list<string>,permissions:array<string,true>} */
    public static function forAccount(string $accountId): array
    {
        $rows = DB::table('panel_member_roles as mr')
            ->join('panel_roles as r', 'r.id', '=', 'mr.role_id')
            ->leftJoin('panel_role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->leftJoin('panel_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('mr.account_id', $accountId)
            ->get(['r.key as role', 'p.key as permission']);
        $roles = [];
        $permissions = [];
        foreach ($rows as $row) {
            $roles[$row->role] = true;
            if ($row->permission !== null) {
                $permissions[$row->permission] = true;
            }
        }
        $roleList = array_keys($roles);
        sort($roleList);

        return ['roles' => $roleList, 'permissions' => $permissions];
    }

    /** @return list<string> */
    public static function catalog(): array
    {
        return array_keys((array) config('panel_permissions.permissions'));
    }

    /** @return list<string> Verildiginde ikinci kontrol isteyen yetkiler (config/panel_permissions.php 'sensitive'). */
    public static function sensitive(): array
    {
        return array_values((array) config('panel_permissions.sensitive'));
    }

    /**
     * Ayricalikli rol: ayardaki listede, super_admin veya hassas yetki tasiyan rol. Anahtara degil yetkiye bakilir;
     * boylece hassas yetkili ozel rol ya da ayardan cikarilan rol ikinci kontrolu atlatamaz.
     */
    public static function isPrivilegedRole(string $key): bool
    {
        if ($key === 'super_admin' || in_array($key, self::privilegedRoles(), true)) {
            return true;
        }
        $roleId = DB::table('panel_roles')->where('key', $key)->value('id');
        if ($roleId === null) {
            return false;
        }

        return DB::table('panel_role_permissions as rp')->join('panel_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $roleId)->whereIn('p.key', self::sensitive())->exists();
    }

    /** @return list<string> */
    public static function privilegedRoles(): array
    {
        $value = Settings::get('approvals.privileged_roles');

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
