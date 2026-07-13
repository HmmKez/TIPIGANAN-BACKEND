<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A category has a short CODE and a full NAME. It previously had only a name,
     * and the frontend tried to invent the code from it — `name.slice(0, 4)`.
     *
     * That is unreliable in both directions: a name that is already an acronym
     * came out duplicated ("CAST — CAST"), a full name came out as a meaningless
     * stub ("INST — Institutional Publications"), and worst of all the invented
     * code was NOT UNIQUE — CABM-B and CABM-H both truncated to "CABM", as did
     * Special Collections and Special Boholano Creations to "SPEC". Two distinct
     * collections cannot share an identifier.
     *
     * The code is authored, not guessed.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('code', 16)->nullable()->after('id');
        });

        $this->backfill();

        // Unique only AFTER backfilling, so a collision would surface here as a
        // failed migration rather than silently landing two identical codes.
        Schema::table('categories', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    /**
     * Derives a starting code for existing categories. This is the one and only
     * place a code is ever guessed — a human can correct any of them in Category
     * Management afterwards, and every code created from now on is typed by hand.
     */
    private function backfill(): void
    {
        $taken = [];

        foreach (DB::table('categories')->orderBy('id')->get() as $category) {
            $code = $this->derive($category->name);

            // Guarantee uniqueness — the exact thing the old slice(0,4) could not
            // do. Suffix rather than truncate, so the codes stay recognisable.
            $base = $code;
            $n    = 2;
            while (in_array($code, $taken, true)) {
                $code = $base . $n++;
            }
            $taken[] = $code;

            DB::table('categories')->where('id', $category->id)->update(['code' => $code]);
        }
    }

    private function derive(string $name): string
    {
        $name = trim($name);

        // Already a short token ("CAST", "CABM-B") — it IS the code.
        if (! str_contains($name, ' ') && mb_strlen($name) <= 12) {
            return mb_strtoupper($name);
        }

        // Multi-word ("Institutional Publications") — initials: IP.
        $initials = collect(preg_split('/\s+/', $name))
            ->map(fn ($word) => mb_substr(preg_replace('/[^\p{L}]/u', '', $word), 0, 1))
            ->filter()
            ->implode('');

        return mb_strtoupper($initials !== '' ? mb_substr($initials, 0, 12) : mb_substr($name, 0, 4));
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
