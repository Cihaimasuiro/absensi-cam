<?php

namespace App\Domain\Device\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'level',
        'message',
        'context',
        'logged_at',
        'received_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'logged_at'   => 'datetime',
        'received_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
