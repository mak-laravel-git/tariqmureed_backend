<?php

namespace App\Models;

use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One action taken in the admin dashboard.
 */
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Record an action of the signed-in administrator, e.g. ActivityLog::record($user, 'created', 'article', 'My title').
     */
    public static function record(?User $user, string $action, string $subject, string $target): self
    {
        return self::query()->create([
            'user_id' => $user?->id,
            'actor' => $user?->name ?? 'System',
            'action' => $action,
            'subject' => $subject,
            'target' => mb_substr($target, 0, 190),
        ]);
    }
}
