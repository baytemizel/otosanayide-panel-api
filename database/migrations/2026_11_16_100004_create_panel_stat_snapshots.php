<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Gunluk sayim anlik goruntuleri (panel:stats-snapshot); yalniz toplam sayi, kisisel veri yok. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_stat_snapshots', function (Blueprint $t) {
            $t->date('snapshot_date');
            $t->ascii('metric', 64);
            $t->string('dimension', 120)->charset('utf8mb4')->collation('utf8mb4_bin')->default('');
            $t->unsignedBigInteger('value');
            $t->ms('created_at')->useCurrent();
            $t->primary(['snapshot_date', 'metric', 'dimension']);
            $t->index(['metric', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_stat_snapshots');
    }
};
