<?php

namespace App\Domain\Account;

use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * businessMemberships okumalari. Transaction icinde $lock=true kullanilir.
 *
 * v2-06 rol matrisi (Figma B08): owner = Yonetici, staff = Calisan, finance = Finans, appointments = Randevu.
 * Calisan'in profil duzenleme yetkisi portal-01'deki davranisi korumak icin surer (profil degisiklikleri zaten incelemeye gider);
 * ekip, ozellik aktivasyonu ve kapasite ayari yalniz owner'dadir.
 */
final class Membership
{
    public const ROLES = ['owner', 'staff', 'finance', 'appointments'];

    /** Davetle veya rol degisimiyle verilebilen roller. */
    public const ASSIGNABLE = ['staff', 'finance', 'appointments'];

    public const PERMISSIONS = ['profile', 'replies', 'quotes', 'appointments', 'campaigns', 'team'];

    private const MATRIX = [
        'owner' => ['profile', 'replies', 'quotes', 'appointments', 'campaigns', 'team'],
        'staff' => ['profile', 'replies', 'quotes', 'appointments'],
        'finance' => ['campaigns'],
        'appointments' => ['appointments'],
    ];

    /** Figma: "İki adımlı doğrulama zorunlu" (Yonetici ve Finans). BUSINESS_MFA_REQUIRED ile zorlanir. */
    public const MFA_ROLES = ['owner', 'finance'];

    public static function find(string $uid, string $businessId, bool $lock = false): ?stdClass
    {
        $q = DB::table('business_memberships')->where('user_id', $uid)->where('business_id', $businessId);

        return ($lock ? $q->sharedLock() : $q)->first();
    }

    /** requestMember(): bilinen bir rolde aktif uye. Yetki icin permits() kullanilir. */
    public static function isMember(string $uid, ?stdClass $m): bool
    {
        return $m !== null && $m->user_id === $uid && (bool) $m->active && in_array($m->role, self::ROLES, true);
    }

    public static function roleAllows(mixed $role, string $permission): bool
    {
        return is_string($role) && in_array($permission, self::MATRIX[$role] ?? [], true);
    }

    /** @return array<string,bool> */
    public static function permissionMap(mixed $role): array
    {
        $out = [];
        foreach (self::PERMISSIONS as $p) {
            $out[$p] = self::roleAllows($role, $p);
        }

        return $out;
    }

    /** packages/core v1 semalari yalniz owner|staff bilir: yeni roller v1 ciktilarinda staff gorunur. */
    public static function legacyRole(mixed $role): ?string
    {
        return match ($role) {
            'owner' => 'owner',
            'staff', 'finance', 'appointments' => 'staff',
            default => null,
        };
    }

    public static function mfaRequired(mixed $role): bool
    {
        return is_string($role) && in_array($role, self::MFA_ROLES, true);
    }

    /** v2-06: rol bazli yetki. $permission: profile | replies | quotes | appointments | campaigns | team. */
    public static function permits(string $uid, ?stdClass $m, string $permission): bool
    {
        return self::isMember($uid, $m) && self::roleAllows($m->role, $permission);
    }

    /** server.ts membership(): degilse 403. */
    public static function require(string $uid, string $businessId): stdClass
    {
        $m = self::find($uid, $businessId);
        if (! self::isMember($uid, $m)) {
            throw new HttpError(403, 'Bu işletme için yetkiniz yok.');
        }

        return $m;
    }
}
