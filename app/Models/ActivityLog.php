<?php

namespace App\Models;

use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One action taken in the admin dashboard.
 */
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    /** Entries shown in a record's history. */
    private const HISTORY_LIMIT = 10;

    protected $guarded = [];

    /**
     * Record an action of the signed-in administrator, e.g.
     * ActivityLog::record($user, 'created', 'article', 'My title', $article->id).
     * The id ties the entry to the record's history; leave it out for actions on no single record.
     */
    public static function record(?User $user, string $action, string $subject, string $target, ?int $subjectId = null): self
    {
        return self::query()->create([
            'user_id' => $user?->id,
            'actor' => $user?->name ?? 'System',
            'action' => $action,
            'subject' => $subject,
            'subject_id' => $subjectId,
            'target' => mb_substr($target, 0, 190),
        ]);
    }

    /**
     * The latest actions on one record, newest first, down to when it was created. Entries saved without a
     * record id are matched by the record's name, counting only those made since the record exists.
     *
     * @return array<int, array{title: string, meta: string}>
     */
    public static function historyOf(string $subject, Model $record, string $label): array
    {
        $createdAt = $record->getAttribute('created_at');

        $entries = self::query()
            ->where('subject', $subject)
            ->where(fn (Builder $query) => $query
                ->where('subject_id', $record->getKey())
                ->orWhere(fn (Builder $unnumbered) => $unnumbered
                    ->whereNull('subject_id')
                    ->where('target', mb_substr($label, 0, 190))
                    ->when($createdAt, fn (Builder $since) => $since->where('created_at', '>=', $createdAt))))
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $history = $entries->map(fn (self $entry): array => [
            'title' => Str::ucfirst($entry->action),
            'meta' => "{$entry->actor} · {$entry->created_at->diffForHumans()}",
        ]);

        // Records made by a seeder or an import have no "created" entry, only their creation date.
        if ($createdAt && $entries->count() < self::HISTORY_LIMIT && ! $entries->contains('action', 'created')) {
            $history->push(['title' => 'Created', 'meta' => $createdAt->format('j M Y')]);
        }

        return $history->values()->all();
    }
}
