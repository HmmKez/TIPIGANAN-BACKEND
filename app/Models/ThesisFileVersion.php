<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThesisFileVersion extends Model
{
    protected $fillable = [
        'thesis_id', 'file_path', 'replaced_by', 'replaced_at',
        'purge_after', 'purged_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'replaced_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at'   => 'datetime',
        ];
    }

    public function thesis()
    {
        return $this->belongsTo(Thesis::class);
    }

    public function replacer()
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }
}
