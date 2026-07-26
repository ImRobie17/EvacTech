<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemAlert extends Model
{
    protected $fillable = [
        'level', 'type', 'title', 'message', 'source', 'email_sent', 'is_resolved', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'email_sent' => 'boolean',
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }
}
