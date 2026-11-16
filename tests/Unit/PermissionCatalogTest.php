<?php

use App\Http\Controllers\WorkController;
use App\Panel\Approvals\Approvals;
use App\Panel\Auth\Member;
use App\Panel\Auth\Permissions;
use App\Panel\WorkItems\Queues;
use Illuminate\Support\Facades\Route;

function catalogKeys(): array
{
    return array_keys((array) config('panel_permissions.permissions'));
}

it('defines every role permission in the catalog', function () {
    foreach ((array) config('panel_permissions.roles') as $key => $role) {
        if ($role['permissions'] === ['*']) {
            expect($key)->toBe('super_admin');

            continue;
        }
        expect(array_diff($role['permissions'], catalogKeys()))->toBe([], $key);
        expect(count($role['permissions']))->toBe(count(array_unique($role['permissions'])), $key);
    }
});

it('follows the product rule: moderator cannot touch money, finance cannot moderate content', function () {
    $roles = config('panel_permissions.roles');
    expect(array_filter($roles['moderator']['permissions'], fn ($p) => str_starts_with($p, 'finance.') || str_starts_with($p, 'ads.')))->toBe([]);
    expect(array_filter($roles['finance']['permissions'], fn ($p) => str_contains($p, 'moderate') || str_starts_with($p, 'claims.')))->toBe([]);
    expect(array_filter($roles['auditor']['permissions'], fn ($p) => preg_match('/\.(manage|moderate|review|decide|edit|publish|write|work|retry|access|claims|tariffs|refunds|brand|payment_cases|brand_review|evidence|ai)$/', $p) === 1))->toBe([]);
});

it('uses only catalog permissions on routes', function () {
    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'perm:')) {
                foreach (explode('|', substr($mw, 5)) as $perm) {
                    expect(catalogKeys())->toContain($perm);
                }
            }
        }
    }
});

it('maps every work queue to a catalog permission and a note subject type', function () {
    foreach (Queues::all() as $key => $q) {
        expect(catalogKeys())->toContain($q['permission']);
        expect(WorkController::SUBJECT_TYPES)->toContain($q['subject']);
    }
});

it('gives super admins every permission and maps finance permissions to the core actor', function () {
    $super = new Member('a1', 'a@example.test', 'A', ['super_admin'], [], 's', 'f', 1_700_000_000_000);
    foreach (catalogKeys() as $perm) {
        expect($super->can($perm))->toBeTrue();
    }
    $mod = new Member('m1', 'm@example.test', 'M', ['moderator'], ['reviews.moderate' => true], 's', 'f', 1_700_000_000_000);
    expect($mod->can('finance.refunds'))->toBeFalse();
    $actor = $mod->actor();
    expect($actor->admin)->toBeTrue()->and($actor->brandFinance)->toBeFalse()->and($actor->uid)->toBe('m1')->and($actor->authTime)->toBe(1_700_000_000);
    $fin = new Member('f1', 'f@example.test', 'F', ['finance'], ['finance.brand' => true], 's', 'f', 1_700_000_000_000);
    expect($fin->actor()->brandFinance)->toBeTrue();
});

it('marks roles privileged by sensitive permission, consistently with the default privileged list', function () {
    $sensitive = config('panel_permissions.sensitive');
    expect(array_diff($sensitive, catalogKeys()))->toBe([]);
    $roles = config('panel_permissions.roles');
    foreach (['moderator', 'catalogue_editor', 'support', 'auditor'] as $plain) {
        expect(array_intersect($roles[$plain]['permissions'], $sensitive))->toBe([], $plain);
    }
    foreach (['operations_admin', 'finance'] as $privileged) {
        expect(array_intersect($roles[$privileged]['permissions'], $sensitive))->not->toBe([], $privileged);
        expect(config('panel.defaults')['approvals.privileged_roles'])->toContain($privileged);
    }
});

it('gates every second-control action with a catalog permission', function () {
    foreach (Approvals::ACTIONS as $action => $def) {
        expect(catalogKeys())->toContain($def['permission']);
        expect(Permissions::sensitive())->toContain($def['permission']);
    }
});
