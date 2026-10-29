<?php

namespace App\Support;

use App\Models\Thesis;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Two ways an upload can repeat something already in the repository.
 *
 * 1. The same file. Certain - identical bytes give an identical SHA-256 - so
 *    it is refused outright.
 * 2. The same work as a different file (a rescan, a re-export, a
 *    Turnitin-wrapped copy): a matching title with at least one author in
 *    common. Only a likelihood - an annual report or a journal volume can
 *    legitimately share a title - so staff are warned and decide.
 *
 * Soft-deleted items do not count: something deleted may be uploaded again.
 * Archived and restricted items do count - they are still in the repository,
 * and restoring is the right answer, not a second copy.
 */
class DuplicateFinder
{
    // Words that appear in author lists but name nobody.
    private const NOT_A_NAME = ['and', 'the', 'et', 'al', 'jr', 'sr', 'iii', 'iv', 'mr', 'mrs', 'ms', 'dr',
        'prof', 'engr', 'sir', 'maam'];

    /** The item already holding this exact file, if any. */
    public static function sameFile(?string $originalHash, ?int $exceptId = null): ?Thesis
    {
        if (! $originalHash) {
            return null;
        }

        return Thesis::query()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            // `checksum` too: an existing item's stored file, downloaded and
            // uploaded again, hashes to exactly that.
            ->where(fn ($q) => $q->where('original_checksum', $originalHash)->orWhere('checksum', $originalHash))
            ->first(['id', 'title', 'authors', 'year', 'status']);
    }

    /**
     * Items whose title matches and which share at least one author.
     *
     * @return Collection<int, Thesis>
     */
    public static function similar(?string $title, ?string $authors, ?int $exceptId = null): Collection
    {
        $wanted = self::titleForms($title);
        $names = self::names($authors);
        if (! $wanted || ! $names) {
            return collect();
        }

        return Thesis::query()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['id', 'title', 'authors', 'year', 'status'])
            ->filter(fn (Thesis $t) => array_intersect($wanted, self::titleForms($t->title))
                && array_intersect($names, self::names($t->authors)))
            ->take(5)
            ->values();
    }

    /**
     * The title as compared: lowercased, punctuation and spacing flattened, so
     * "TABULUXE: An On-Demand..." and "Tabuluxe - an on-demand..." agree. Also
     * the main title alone (before a colon or dash), because the same work is
     * often filed once with its subtitle and once without.
     *
     * @return string[]
     */
    public static function titleForms(?string $title): array
    {
        $full = self::flatten((string) $title);
        if ($full === '') {
            return [];
        }

        $forms = [$full];
        $parts = preg_split('/\s*(?::|\s[-–—]\s)\s*/u', (string) $title, 2);
        if (count($parts) === 2) {
            $main = self::flatten($parts[0]);
            if ($main !== '' && $main !== $full) {
                $forms[] = $main;
            }
        }

        return $forms;
    }

    /** @return string[] the words in an author list that could be names */
    public static function names(?string $authors): array
    {
        $words = preg_split('/[^\p{L}]+/u', Str::lower(Str::ascii((string) $authors)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(
            $words,
            fn ($w) => strlen($w) >= 3 && ! in_array($w, self::NOT_A_NAME, true)
        )));
    }

    private static function flatten(string $text): string
    {
        $text = Str::lower(Str::ascii($text));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $text)));
    }

    /** The shape both kinds of match are reported in. */
    public static function describe(Thesis $t): array
    {
        return ['id' => $t->id, 'title' => $t->title, 'authors' => $t->authors, 'year' => $t->year, 'status' => $t->status];
    }
}
