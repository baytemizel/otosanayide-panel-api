<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpError;
use App\Panel\Approvals\Approvals;
use App\Panel\Auth\Permissions;
use App\Panel\Http\Respond;
use App\Panel\Members\RoleAdmin;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/roles ve /api/v1/permissions. super_admin yetkileri degismez (her zaman tumu). */
final class RoleController extends PanelController
{
    public function index(Request $request): JsonResponse
    {
        $perms = PanelDb::table('panel_role_permissions as rp')->join('panel_permissions as p', 'p.id', '=', 'rp.permission_id')->get(['rp.role_id', 'p.key'])->groupBy('role_id');
        $members = PanelDb::table('panel_member_roles')->selectRaw('role_id, count(*) as c')->groupBy('role_id')->pluck('c', 'role_id');
        $roles = PanelDb::table('panel_roles')->orderBy('id')->get()->map(fn (\stdClass $r): array => $this->view($r, ($perms->get($r->id) ?? collect())->pluck('key')->sort()->values()->all(), (int) ($members[$r->id] ?? 0)))->all();

        return Respond::data($roles);
    }

    public function permissions(Request $request): JsonResponse
    {
        $rows = PanelDb::table('panel_permissions')->orderBy('group')->orderBy('key')->get(['key', 'group', 'description']);

        return Respond::data($rows->map(static fn (\stdClass $p): array => ['key' => $p->key, 'group' => $p->group, 'description' => $p->description])->all());
    }

    public function store(Request $request): JsonResponse
    {
        $in = $this->input($request, [
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'string', 'max:500'],
            'permissions' => ['required', 'array', 'min:1'], 'permissions.*' => ['string', 'max:96'],
        ]);
        $ids = RoleAdmin::permissionIds($in['permissions']);
        $id = PanelDb::tx(function () use ($in, $ids): int {
            if (PanelDb::table('panel_roles')->where('key', $in['key'])->exists()) {
                throw new HttpError(409, 'Bu anahtarla rol var.');
            }
            $id = (int) PanelDb::table('panel_roles')->insertGetId(['key' => $in['key'], 'name' => $in['name'], 'description' => $in['description'] ?? '', 'is_system' => false, 'created_at' => Clock::db(), 'updated_at' => Clock::db()]);
            PanelDb::table('panel_role_permissions')->insert(array_map(static fn (int $p): array => ['role_id' => $id, 'permission_id' => $p], $ids));

            return $id;
        });
        $this->audit($request, 'role.created', 'panel_role', $in['key'], null, ['permissions' => $in['permissions']]);

        return Respond::created($this->view((object) PanelDb::table('panel_roles')->where('id', $id)->first(), $in['permissions'], 0));
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $in = $this->input($request, [
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array', 'min:1'], 'permissions.*' => ['string', 'max:96'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $role = PanelDb::table('panel_roles')->where('key', $key)->first() ?? $this->notFound('Rol');
        $change = array_intersect_key($in, ['version' => 1, 'name' => 1, 'description' => 1, 'permissions' => 1]);
        $sensitive = isset($in['permissions']) && ! $role->is_system ? RoleAdmin::sensitiveAdditions($key, $in['permissions']) : [];
        if ($sensitive !== []) {
            // Hassas yetki eklemek (uye yonetimi, platform yetkisi, onay, finans ...) ayricalik vermektir: ikinci kontrol.
            RoleAdmin::permissionIds($in['permissions']);
            $approval = Approvals::request($this->member($request), 'role.permissions', $key, ['key' => $key] + $change, 'Rol yetkisi: '.$key.' + '.implode(', ', $sensitive), $in['reason']);
            $this->audit($request, 'approval.requested', 'panel_approval', $approval['id'], $in['reason'], ['action' => 'role.permissions', 'role' => $key, 'sensitive' => $sensitive]);

            return Respond::pendingApproval($approval);
        }
        $result = PanelDb::tx(fn (): array => RoleAdmin::update($key, $change));
        $this->audit($request, 'role.updated', 'panel_role', $key, $in['reason'], ['before' => $result['before'], 'after' => $result['after'], 'sessionsRevoked' => $result['revoked']]);
        $members = PanelDb::table('panel_member_roles')->where('role_id', $result['id'])->count();

        return Respond::data($this->view((object) PanelDb::table('panel_roles')->where('id', $result['id'])->first(), $result['after'], $members));
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $role = PanelDb::table('panel_roles')->where('key', $key)->first() ?? $this->notFound('Rol');
        if ($role->is_system) {
            throw new HttpError(422, 'Sistem rolü silinemez.');
        }
        if (PanelDb::table('panel_member_roles')->where('role_id', $role->id)->exists()) {
            throw new HttpError(409, 'Role atanmış üye varken rol silinemez.');
        }
        PanelDb::table('panel_roles')->where('id', $role->id)->delete();
        $this->audit($request, 'role.deleted', 'panel_role', $key);

        return Respond::data(['deleted' => true]);
    }

    /** @param list<string> $permissions @return array<string,mixed> */
    private function view(\stdClass $r, array $permissions, int $members): array
    {
        return ['key' => $r->key, 'name' => $r->name, 'description' => $r->description, 'system' => (bool) $r->is_system, 'privileged' => Permissions::isPrivilegedRole($r->key), 'permissions' => $r->key === 'super_admin' ? ['*'] : $permissions, 'members' => $members, 'version' => (int) $r->version, 'updatedAt' => Clock::iso($r->updated_at)];
    }
}
