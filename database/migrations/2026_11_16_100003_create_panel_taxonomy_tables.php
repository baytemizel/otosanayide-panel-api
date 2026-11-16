<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Urun plani: "Kategoriler, es anlamlilar, marka listeleri ... panelden yonetilir."
 * Ana API bu listeleri bugun `resources/data/*.json` dosyalarindan okur; bu tablolar duzenleme alanidir.
 * Canliya gecis `panel:taxonomy-export` ciktisinin ana API deposuna alinmasiyla olur (otomatik degil).
 * Kategori kimligi `business_values.value` ve `business_search_facets.value` ile ayni anahtardir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_taxonomy_categories', function (Blueprint $t) {
            $t->ascii('id', 64)->primary();
            $t->string('name', 120);
            $t->unsignedSmallInteger('position')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->cid('updated_by')->nullable();
            $t->ms('created_at')->useCurrent();
            $t->ms('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('panel_taxonomy_services', function (Blueprint $t) {
            $t->id();
            $t->ascii('category_id', 64);
            $t->string('name', 120);
            $t->unsignedSmallInteger('position');
            $t->unique(['category_id', 'position']);
            $t->foreign('category_id')->references('id')->on('panel_taxonomy_categories')->cascadeOnDelete();
        });

        // aliases (TR yazim varyanti) ve englishExtra (EN arama terimi) ayni tabloda, `kind` ile ayrilir.
        Schema::create('panel_taxonomy_aliases', function (Blueprint $t) {
            $t->string('term', 120)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->enum('kind', ['alias', 'english'])->charset('ascii')->collation('ascii_bin');
            $t->string('target', 120);
            $t->cid('updated_by')->nullable();
            $t->ms('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->primary(['kind', 'term']);
        });

        // coverage.json: kapsamdaki iller, buyuksehir bayragi, merkez ve ilce koordinatlari.
        Schema::create('panel_taxonomy_cities', function (Blueprint $t) {
            $t->string('name', 100)->charset('utf8mb4')->collation('utf8mb4_bin')->primary();
            $t->unsignedTinyInteger('plate_order');
            $t->boolean('in_coverage')->default(false);
            $t->boolean('metropolitan')->default(false);
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->cid('updated_by')->nullable();
            $t->ms('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->index(['in_coverage', 'plate_order']);
        });

        Schema::create('panel_taxonomy_districts', function (Blueprint $t) {
            $t->string('city', 100)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->string('name', 100);
            $t->unsignedSmallInteger('position');
            $t->double('latitude');
            $t->double('longitude');
            $t->primary(['city', 'position']);
            $t->foreign('city')->references('name')->on('panel_taxonomy_cities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['panel_taxonomy_districts', 'panel_taxonomy_cities', 'panel_taxonomy_aliases', 'panel_taxonomy_services', 'panel_taxonomy_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
