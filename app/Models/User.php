<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name', 'id_number', 'email', 'password', 'role', 'status', 'avatar_path',
        'directory_status', 'directory_checked_at', 'directory_person_key', 'directory_exempt',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    // Serialised with every user, so the frontend never has to decide what to
    // show when a name is absent - and more importantly, so the PDF watermark
    // always has something that identifies the reader.
    protected $appends = ['display_name'];

    // Names are no longer collected at registration; the school's API supplies
    // them from the ID number later. Until then the ID number IS the identity,
    // so everything that used to print a name prints this instead.
    //
    // This is not cosmetic. The watermark stamped on every page of every PDF
    // exists so a leaked screenshot can be traced back to one account. If it
    // rendered an empty name, the whole mechanism would quietly stop
    // identifying anyone while still looking like it worked.
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: (string) $this->id_number;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'directory_checked_at' => 'datetime',
            'directory_exempt'     => 'boolean',
        ];
    }

    /**
     * Has the school's own system said this person is no longer part of the
     * school - graduated, resigned, dropped?
     *
     * Reads only what was recorded locally by the last check; it never calls
     * the school. A null status means "never checked", which covers every
     * account created before verification existed and must NOT lock anyone out.
     */
    public function isRevokedByDirectory(): bool
    {
        if ($this->directory_exempt || $this->directory_status === null) {
            return false;
        }

        return ! in_array($this->directory_status, ['enrolled', 'active', 'employed'], true);
    }

    public function theses()
    {
        return $this->hasMany(Thesis::class, 'uploaded_by');
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function readingHistory()
    {
        return $this->hasMany(ReadingHistory::class);
    }

    public function citationLogs()
    {
        return $this->hasMany(CitationLog::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }
}