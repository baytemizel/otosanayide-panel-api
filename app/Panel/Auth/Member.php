<?php

namespace App\Panel\Auth;

use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use Illuminate\Http\Request;

/** Dogrulanmis panel uyesi: hesap kimligi, roller, yetkiler ve oturum. Istek ozniteligi olarak tasinir. */
final class Member
{
    public const ATTRIBUTE = 'panel.member';

    /**
     * @param  list<string>  $roles
     * @param  array<string,true>  $permissions
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $email,
        public readonly string $displayName,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly string $sessionId,
        public readonly string $familyId,
        public readonly int $mfaAt,
    ) {}

    public static function from(Request $request): self
    {
        $member = $request->attributes->get(self::ATTRIBUTE);
        if (! $member instanceof self) {
            throw new HttpError(401, 'Giriş gerekli.');
        }

        return $member;
    }

    public function isSuperAdmin(): bool
    {
        return in_array('super_admin', $this->roles, true);
    }

    public function can(string $permission): bool
    {
        return $this->isSuperAdmin() || isset($this->permissions[$permission]);
    }

    public function require(string $permission): void
    {
        if (! $this->can($permission)) {
            throw new HttpError(403, 'Bu işlem için panel yetkiniz yok.');
        }
    }

    /**
     * Vendor edilen alan cekirdegine verilen kimlik. Yetki kapisi panel rolleridir (rota `perm:` katmani);
     * cekirdegin icindeki kurallar (kendi icerigini inceleyememe, surum, bariyer) aynen isler.
     */
    public function actor(): Actor
    {
        return new Actor(
            uid: $this->accountId,
            email: $this->email,
            emailVerified: true,
            admin: true,
            accountErasureOperator: $this->can('privacy.manage'),
            brandFinance: $this->can('finance.brand') || $this->can('finance.brand_review') || $this->can('finance.refunds') || $this->can('finance.payment_cases'),
            secondFactor: 'totp',
            authTime: intdiv($this->mfaAt, 1000),
            iat: time(),
            sessionId: $this->sessionId,
        );
    }

    /** @return array<string,mixed> */
    public function view(): array
    {
        $permissions = $this->isSuperAdmin() ? array_keys((array) config('panel_permissions.permissions')) : array_keys($this->permissions);
        sort($permissions);

        return [
            'accountId' => $this->accountId,
            'email' => $this->email,
            'displayName' => $this->displayName,
            'roles' => $this->roles,
            'permissions' => $permissions,
        ];
    }
}
