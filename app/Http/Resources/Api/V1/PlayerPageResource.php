<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Player
 */
class PlayerPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->fullname,
            'image' => $this->image ?? '',
            'teamAndRole' => collect([$this->team?->name, $this->position])->filter()->implode(' · '),
            'facts' => collect([
                ['label' => 'Team', 'value' => $this->team?->name],
                ['label' => 'Role', 'value' => $this->position],
                ['label' => 'Batting style', 'value' => $this->humanise($this->batting_style)],
                ['label' => 'Bowling style', 'value' => $this->humanise($this->bowling_style)],
                ['label' => 'Born', 'value' => $this->date_of_birth?->format('j F Y')],
            ])->filter(fn (array $fact): bool => filled($fact['value']))->values()->all(),
            'career' => $this->careerByFormat(),
            'news' => [],
        ];
    }

    /**
     * Career rows are stored per season and format; the page shows one line per format.
     *
     * @return array<int, array<string, mixed>>
     */
    private function careerByFormat(): array
    {
        return collect($this->career ?? [])
            ->groupBy(fn (array $row): string => (string) ($row['type'] ?? 'Other'))
            ->map(function ($rows, string $format): array {
                $innings = $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.innings', 0));
                $notOuts = $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.not_outs', 0));
                $runs = $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.runs_scored', 0));
                $dismissals = $innings - $notOuts;

                return [
                    'format' => $format,
                    'matches' => $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.matches', 0)),
                    'runs' => number_format($runs),
                    'average' => $dismissals > 0 ? number_format($runs / $dismissals, 2, '.', '') : '—',
                    'hundreds' => $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.hundreds', 0)),
                    'highScore' => (string) ($rows->max(fn (array $row): int => (int) data_get($row, 'batting.highest_inning_score', 0)) ?: '—'),
                ];
            })
            ->values()
            ->all();
    }

    private function humanise(?string $style): ?string
    {
        return $style ? Str::of($style)->replace('-', ' ')->ucfirst()->toString() : null;
    }
}
