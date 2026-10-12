<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Thesis extends Model
{
    use SoftDeletes, Searchable;

    protected $fillable = [
        'title', 'authors', 'adviser', 'abstract', 'keywords',
        'year_published', 'category_id', 'pages', 'file_path', 'checksum',
        'cover_image_path', 'status', 'uploaded_by',
        'ocr_status', 'ocr_completed_at',
    ];

    protected function casts(): array
    {
        return ['ocr_completed_at' => 'datetime'];
    }

    public const STATUSES = ['active', 'restricted', 'archived'];

    /**
     * Which thesis states this viewer is allowed to see at all.
     *
     * The ONE definition, for the list, the single-item view, search and PDF
     * reading. They used to decide separately and disagreed in every
     * direction: the list let any caller ask for `?status=archived` (so a
     * guest could read archived titles), the single-item view hid archived
     * records from staff too, search hard-coded active/restricted for
     * everyone, and the PDF token refused staff an archived document they were
     * allowed to open.
     *
     * Guests see published work. Signed-in readers additionally see restricted
     * work. Staff and Super Admins also see archived work, because managing it
     * is their job.
     */
    public static function visibleStatusesFor($user): array
    {
        if ($user && ($user->isStaff() || $user->isSuperAdmin())) {
            return self::STATUSES;
        }

        return $user ? ['active', 'restricted'] : ['active'];
    }

    /**
     * Narrow the visible statuses to the one a caller asked for. A status the
     * viewer may not see leaves an EMPTY list - it returns nothing rather than
     * quietly ignoring the rule.
     */
    public static function statusesRequestedBy($user, ?string $requested): array
    {
        $visible = self::visibleStatusesFor($user);

        return $requested
            ? array_values(array_intersect([$requested], $visible))
            : $visible;
    }

    public function toSearchableArray(): array
    {
        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'authors'        => $this->authors,
            'adviser'        => $this->adviser,
            'abstract'       => $this->abstract,
            'keywords'       => $this->keywords,
            'year_published' => $this->year_published,
            'category_id'    => $this->category_id,
            // Without this, SearchService's whereIn('status', [...]) filter
            // matches nothing at all (the field doesn't exist on any
            // document), so every Meilisearch-backed search silently
            // returns zero results — Meilisearch still responds 200 OK,
            // so the exception-based MySQL fallback never kicks in either.
            'status'         => $this->status,
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function citations()
    {
        return $this->hasMany(Citation::class);
    }

    public function citationLogs()
    {
        return $this->hasMany(CitationLog::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function readingHistory()
    {
        return $this->hasMany(ReadingHistory::class);
    }

    public function signedUrlTokens()
    {
        return $this->hasMany(SignedUrlToken::class);
    }

    public function reports()
    {
        return $this->hasMany(ThesisReport::class);
    }

    public function fileVersions()
    {
        return $this->hasMany(ThesisFileVersion::class);
    }
}