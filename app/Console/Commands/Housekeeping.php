<?php

namespace App\Console\Commands;

use App\Panel\Approvals\Approvals;
use App\Panel\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Panel tablolarinin bakimi: suresi dolan erisim jetonu, MFA adimi ve 7 gunden eski kapanmis oturum silinir;
 * suresi dolan ikinci kontrol talebi 'expired' olur. Denetim kaydina ve ana API tablolarina dokunmaz.
 */
final class Housekeeping extends Command
{
    protected $signature = 'panel:housekeeping {--apply : Yaz (yoksa yalniz sayim)}';

    protected $description = 'Suresi dolan panel jetonlari, MFA adimlari, oturumlar ve talepler (varsayilan kuru calisma).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $now = Clock::db();
        $old = Clock::db(Clock::now()->subDays(7));
        $tokens = DB::table('panel_access_tokens')->where('expires_at', '<=', $now);
        $challenges = DB::table('panel_login_challenges')->where('expires_at', '<=', $now);
        $sessions = DB::table('panel_sessions')->where(fn ($w) => $w->where('expires_at', '<=', $old)->orWhere(fn ($x) => $x->whereNotNull('revoked_at')->where('revoked_at', '<=', $old)));
        $counts = [
            'accessTokens' => $apply ? $tokens->delete() : $tokens->count(),
            'loginChallenges' => $apply ? $challenges->delete() : $challenges->count(),
            'sessions' => $apply ? $sessions->delete() : $sessions->count(),
            'approvalsExpired' => Approvals::expire($apply),
        ];
        $this->table(array_keys($counts), [array_values($counts)]);
        if (! $apply) {
            $this->warn('Kuru çalışma: yazılmadı. Yazmak için --apply.');
        }

        return self::SUCCESS;
    }
}
