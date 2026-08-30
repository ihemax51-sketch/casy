<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerProfile extends Model
{
    protected $fillable = [
        'user_id', 'name', 'host', 'port', 'username', 'password',
        'account_database', 'shard_database', 'log_database', 'proxy_database',
        'icon_root', 'encrypt_connection', 'trust_server_certificate', 'is_active',
        'connection_status', 'connection_error', 'schema_stats', 'schema_fingerprint',
        'last_tested_at', 'last_connected_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'encrypt_connection' => 'boolean',
            'trust_server_certificate' => 'boolean',
            'is_active' => 'boolean',
            'schema_stats' => 'array',
            'last_tested_at' => 'datetime',
            'last_connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function icons(): HasMany
    {
        return $this->hasMany(IconAsset::class);
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }
}
