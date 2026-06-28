<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Citation extends Model
{
    protected $fillable = ['thesis_id', 'format_type', 'citation_text', 'created_by'];

    public function thesis()
    {
        return $this->belongsTo(Thesis::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs()
    {
        return $this->hasMany(CitationLog::class);
    }
}