<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    // `created_at` is fillable even though $timestamps is false. The column
    // defaults to CURRENT_TIMESTAMP, so application code never needs to pass it
    // — but without it here, mass-assigning a created_at was SILENTLY DISCARDED
    // and the row was stamped "now" instead. That made it impossible to write a
    // backdated entry, which is exactly what retention and date-filter tests
    // depend on: they looked like they were creating year-old rows and were
    // really creating rows one second old.
    protected $fillable = [
        'user_id', 'action', 'target_type',
        'target_id', 'description', 'metadata', 'ip_address', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'metadata'   => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The search term behind a `search` entry.
     *
     * Reads the structured `metadata` written at log time. The regex is a
     * fallback for rows written before `metadata` existed and for anything the
     * backfill migration couldn't parse — NOT the primary path. Keeping the
     * parse in one place means the display sentence can be reworded freely
     * without silently corrupting the "Most Searched" report, which is exactly
     * what used to happen when both callers regexed it independently.
     */
    public function searchQuery(): ?string
    {
        $query = $this->metadata['query'] ?? null;

        if (is_string($query) && $query !== '') {
            return $query;
        }

        if (preg_match('/searched for: (.+)$/s', (string) $this->description, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}