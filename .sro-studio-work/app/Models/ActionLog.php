<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActionLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'restored_at' => 'datetime',
        ];
    }
}
