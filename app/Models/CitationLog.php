<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CitationLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['thesis_id', 'user_id', 'citation_id', 'cited_at'];

    protected $casts = [
        'cited_at' => 'datetime',
    ];

    public function thesis()
    {
        return $this->belongsTo(Thesis::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function citation()
    {
        return $this->belongsTo(Citation::class);
    }
}