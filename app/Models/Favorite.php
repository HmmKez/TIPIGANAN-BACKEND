<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    // The table is `bookmarks`; Eloquent would otherwise infer `favorites`
    // from the class name. The class keeps its old name on purpose - renaming
    // it would churn every import and the /api/favorites routes for something
    // no user can see, whereas the TABLE name appears in the capstone data
    // dictionary and so was worth aligning with the rest of the system.
    protected $table = 'bookmarks';

    public $timestamps = false;

    protected $fillable = ['user_id', 'thesis_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function thesis()
    {
        return $this->belongsTo(Thesis::class);
    }
}