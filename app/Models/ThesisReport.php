<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThesisReport extends Model
{
    protected $fillable = ['thesis_id', 'user_id', 'reason', 'status', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function thesis()
    {
        return $this->belongsTo(Thesis::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
