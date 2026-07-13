<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    // `code` is the short label (CAST, CABM-B, IP); `name` is the full title.
    // Both are authored — nothing derives one from the other. See the
    // add_code_to_categories migration for why.
    protected $fillable = ['code', 'name', 'cover_image_path', 'created_by'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function theses()
    {
        return $this->hasMany(Thesis::class);
    }
}