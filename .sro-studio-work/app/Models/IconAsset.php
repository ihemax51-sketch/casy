<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IconAsset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_modified_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function serverProfile(): BelongsTo
    {
        return $this->belongsTo(ServerProfile::class);
    }
}
