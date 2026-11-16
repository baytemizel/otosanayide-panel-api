<?php

namespace App\Panel\Auth;

use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;

/**
 * config/panel_permissions.php -> panel_permissions, panel_roles (sistem), panel_role_permissions.
 * Yeni yetki ve sistem rolu ekler; sistem rolu yetkilerini katalogla esitler. Ozel (sistem disi) rollere,
 * uyelere ve katalogdan kalkan yetkilere dokunmaz (kalkanlar raporlanir, silinmez).
 */
final class PermissionSync
{
    /** @return array{permissionsAdded:list<string>,permissionsUpdated:list<string>,orphanPermissions:list<string>,rolesAdded:list<string>,rolePermissionsChanged:array<string,array{added:list<string>,removed:list<string>}>} */
    public static function run(bool $apply): array
    {
        $catalog = (array) config('panel_permissions.permissions');
        $roles = (array) config('panel_permissions.roles');
        $existing = PanelDb::table('panel_permissions')->get()->keyBy('key');
        $report = ['permissionsAdded' => [], 'permissionsUpdated' => [], 'orphanPermissions' => [], 'rolesAdded' => [], 'rolePermissionsChanged' => []];

        foreach ($catalog as $key => $def) {
            $row = $existing->get($key);
            if ($row === null) {
                $report['permissionsAdded'][] = $key;
                if ($apply) {
                    PanelDb::table('panel_permissions')->insert(['key' => $key, 'group' => $def['group'], 'description' => $def['description'], 'created_at' => Clock::db()]);
                }
            } elseif ($row->group !== $def['group'] || $row->description !== $def['description']) {
                $report['permissionsUpdated'][] = $key;
                if ($apply) {
                    PanelDb::table('panel_permissions')->where('key', $key)->update(['group' => $def['group'], 'description' => $def['description']]);
                }
            }
        }
        $report['orphanPermissions'] = array_values(array_diff($existing->keys()->all(), array_keys($catalog)));

        $ids = $apply ? PanelDb::table('panel_permissions')->pluck('id', 'key') : $existing->map(static fn (\stdClass $p): int => (int) $p->id);
        $roleRows = PanelDb::table('panel_roles')->get()->keyBy('key');
        foreach ($roles as $key => $def) {
            $wanted = $def['permissions'] === ['*'] ? array_keys($catalog) : $def['permissions'];
            $role = $roleRows->get($key);
            $roleId = null;
            if ($role === null) {
                $report['rolesAdded'][] = $key;
                $current = [];
                if ($apply) {
                    $roleId = (int) PanelDb::table('panel_roles')->insertGetId(['key' => $key, 'name' => $def['name'], 'description' => $def['description'], 'is_system' => true, 'created_at' => Clock::db(), 'updated_at' => Clock::db()]);
                }
            } else {
                $roleId = (int) $role->id;
                $current = PanelDb::table('panel_role_permissions as rp')->join('panel_permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $roleId)->pluck('p.key')->all();
                if ($apply) {
                    PanelDb::table('panel_roles')->where('id', $roleId)->update(['name' => $def['name'], 'description' => $def['description'], 'is_system' => true, 'updated_at' => Clock::db()]);
                }
            }
            $added = array_values(array_diff($wanted, $current));
            $removed = array_values(array_diff($current, $wanted));
            if ($added !== [] || $removed !== []) {
                $report['rolePermissionsChanged'][$key] = ['added' => $added, 'removed' => $removed];
            }
            if ($apply) {
                if ($removed !== []) {
                    PanelDb::table('panel_role_permissions')->where('role_id', $roleId)->whereIn('permission_id', array_map(static fn (string $k): int => (int) $ids[$k], $removed))->delete();
                }
                if ($added !== []) {
                    PanelDb::table('panel_role_permissions')->insertOrIgnore(array_map(static fn (string $k): array => ['role_id' => $roleId, 'permission_id' => (int) $ids[$k]], $added));
                }
            }
        }

        return $report;
    }
}
