<?php

namespace App\Models;

use App\Support\SafeCache;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    public const ACTIVE_TERM_SEMESTER    = 'active_term.semester';
    public const ACTIVE_TERM_SCHOOL_YEAR = 'active_term.school_year';

    /** Storage path of the Super-Admin-chosen landing page hero image. */
    public const LANDING_HERO_IMAGE = 'landing.hero_image_path';

    /**
     * JSON array of the category IDs featured on the landing page. ABSENT means
     * "show them all" — which is different from an empty array, meaning "show
     * none". Keep that distinction: it's what lets a fresh install display
     * everything without a Super Admin having to opt in first.
     */
    public const LANDING_CATEGORIES = 'landing.visible_categories';

    private const CACHE_KEY      = 'settings:active_term';
    private const HERO_CACHE_KEY = 'settings:landing_hero';

    /**
     * The semesters a Super Admin may pick. Kept here — not in the controller
     * or the frontend — so the dropdown, the validation rule, and the stored
     * value can never disagree about what a valid semester is.
     */
    public const SEMESTERS = ['1st Semester', '2nd Semester', 'Summer'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::find($key)?->value ?? $default;
    }

    public static function set(string $key, string $value, ?int $userId = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
    }

    public static function forget(string $key): void
    {
        static::where('key', $key)->delete();
    }

    public static function forgetLandingHero(): void
    {
        SafeCache::forget(self::HERO_CACHE_KEY);
    }

    /**
     * The active academic term, as the navbar renders it. Falls back to a term
     * derived from today's date rather than a hardcoded one, so a fresh install
     * (or a wiped settings row) still shows something sensible instead of a
     * stale year — but once a Super Admin sets it, their value always wins.
     */
    public static function activeTerm(): array
    {
        return SafeCache::remember(self::CACHE_KEY, 3600, function () {
            $defaults = self::defaultTerm();

            $semester   = self::get(self::ACTIVE_TERM_SEMESTER, $defaults['semester']);
            $schoolYear = self::get(self::ACTIVE_TERM_SCHOOL_YEAR, $defaults['school_year']);

            return [
                'semester'    => $semester,
                'school_year' => $schoolYear,
                'label'       => "{$semester} AY {$schoolYear}",
            ];
        });
    }

    public static function forgetActiveTerm(): void
    {
        SafeCache::forget(self::CACHE_KEY);
    }

    /**
     * Philippine academic calendar: the school year starts around August, so
     * Jan–Jul belongs to the year that began the previous August.
     */
    private static function defaultTerm(): array
    {
        $now   = now();
        $start = $now->month >= 8 ? $now->year : $now->year - 1;

        return [
            'semester'    => $now->month >= 8 || $now->month === 1 ? '1st Semester' : '2nd Semester',
            'school_year' => $start . '-' . ($start + 1),
        ];
    }
}
