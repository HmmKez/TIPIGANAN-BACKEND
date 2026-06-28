<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignedUrlToken extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'thesis_id', 'token', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}