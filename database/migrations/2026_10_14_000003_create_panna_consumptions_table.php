<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fabric consumption per PRODUCT CATEGORY instead of per product:
 *   "a 3pc Kurta Trouser cut from 44" PANNA needs 2.75 m per piece"
 *   size_value_id = optional size/age value with its own consumption (null = all sizes)
 *
 * Existing per-product lines (fabric_articles) are copied over once: each line's
 * article category + PANNA + size becomes a rule. When two products of the same
 * category had different numbers, the most common value is kept (ties → higher).
 * The old table is left untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('panna_consumptions')) {
            Schema::create('panna_consumptions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
                $t->foreignId('panna_value_id')->constrained('attribute_values')->cascadeOnDelete();
                $t->foreignId('size_value_id')->nullable()->constrained('attribute_values')->cascadeOnDelete();
                $t->decimal('consumption', 10, 3);
                $t->timestamps();
                $t->unique(['category_id', 'panna_value_id', 'size_value_id'], 'panna_cons_unique');
            });
        }

        if (!Schema::hasTable('fabric_articles') || DB::table('panna_consumptions')->exists()) return;

        $pannaAttr = DB::table('attributes')->where('slug', 'panna')->value('id');
        if (!$pannaAttr) return;

        $rows = DB::table('fabric_articles as fa')
            ->join('products as a', 'a.id', '=', 'fa.article_id')
            ->leftJoin('product_variation_attribute_values as fp', 'fp.product_variation_id', '=', 'fa.fabric_variation_id')
            ->leftJoin('attribute_values as pv', function ($j) use ($pannaAttr) {
                $j->on('pv.id', '=', 'fp.attribute_value_id')->where('pv.attribute_id', $pannaAttr);
            })
            ->whereNotNull('pv.id')
            ->select('a.category_id', 'pv.id as panna_value_id', 'fa.article_variation_id', 'fa.consumption')
            ->get();

        $groups = [];
        foreach ($rows as $r) {
            $size = null;
            if ($r->article_variation_id) {
                $size = DB::table('product_variation_attribute_values as x')
                    ->join('attribute_values as v', 'v.id', '=', 'x.attribute_value_id')
                    ->where('x.product_variation_id', $r->article_variation_id)
                    ->where('v.attribute_id', '!=', $pannaAttr)
                    ->orderBy('v.attribute_id')->value('v.id');
            }
            $groups["{$r->category_id}|{$r->panna_value_id}|" . ($size ?? '')][] = round((float) $r->consumption, 3);
        }

        foreach ($groups as $key => $values) {
            [$cat, $panna, $size] = explode('|', $key);
            $counts = array_count_values(array_map('strval', $values));
            uksort($counts, fn ($a, $b) => [$counts[$b], (float) $b] <=> [$counts[$a], (float) $a]);
            $pick = (float) array_key_first($counts);
            if (count($counts) > 1) {
                Log::warning("PANNA consumption: category {$cat} / PANNA {$panna} had different values " . implode(', ', array_keys($counts)) . " — kept {$pick}. Check it on Products → PANNA Consumption.");
            }
            DB::table('panna_consumptions')->insert([
                'category_id'    => (int) $cat,
                'panna_value_id' => (int) $panna,
                'size_value_id'  => $size !== '' ? (int) $size : null,
                'consumption'    => $pick,
                'created_at'     => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('panna_consumptions');
    }
};
