<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class WebhookSetting extends Model
{
    protected $table = 'webhook_settings';

    protected $fillable = [
        'webhook_type',
        'url',
        'secret',
        'is_enabled',
        'last_test_at',
        'last_test_status',
        'last_test_message',
        'last_test_response_code',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }

    public function setSecretAttribute(?string $value): void
    {
        $this->attributes['secret'] = (!empty($value)) ? Crypt::encryptString($value) : null;
    }

    public function getSecretAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
