<?php

namespace App\Jobs;

use App\Domain\Content\MediaCdn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** K205: isletmenin kamu fotograf yansisini guncel duruma getirir. Idempotent; islem tamamlaninca kuyruga girer. */
final class SyncBusinessMediaCdn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $businessId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        if (MediaCdn::enabled()) {
            MediaCdn::sync($this->businessId);
        }
    }
}
