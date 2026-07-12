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
    ];

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