<?php

namespace App\Panel\Auth;

use RuntimeException;

/** Denetime yazilmak uzere hesap kimligini tasir; denetimden sonra denetleyici 401'e cevirir. */
final class RefreshReused extends RuntimeException
{
    public function __construct(public readonly ?string $accountId)
    {
        parent::__construct('RefreshReused');
    }
}
