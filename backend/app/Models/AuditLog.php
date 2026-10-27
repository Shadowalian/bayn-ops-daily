<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'entity',
        'entity_id',
        'field',
        'old_value',
        'new_value',
        'note',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        ?int $userId,
        string $entity,
        int $entityId,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        ?string $note = null
    ): void {
        static::query()->create([
            'user_id' => $userId,
            'entity' => $entity,
            'entity_id' => $entityId,
            'field' => $field,
            'old_value' => is_scalar($oldValue) || $oldValue === null ? $oldValue : json_encode($oldValue),
            'new_value' => is_scalar($newValue) || $newValue === null ? $newValue : json_encode($newValue),
            'note' => $note,
            'changed_at' => now(),
        ]);
    }
}
