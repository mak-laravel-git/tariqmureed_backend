<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Article;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @mixin Player
 */
class PlayerPageResource extends JsonResource
{
    /** Formats in the order the page lists them; any other format follows. */
    private const FORMAT_ORDER = ['Test/5day', 'ODI', 'T20I', 'T20', '4day'];

    /** SportMonks format names that read better another way. */
    private const FORMAT_LABELS = ['Test/5day' => 'Test', '4day' => '4-day'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $career = $this->careerByFormat();

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
                ['label' => 'Age', 'value' => $this->date_of_birth ? (string) $this->date_of_birth->age : null],
                ['label' => 'Gender', 'value' => match ($this->gender) {
                    'm' => 'Male',
                    'f' => 'Female',
                    default => null,
                }],
            ])->filter(fn (array $fact): bool => filled($fact['value']))->values()->all(),
            'career' => $career->map(fn (Collection $rows, string $format): array => $this->batting($format, $rows))->values()->all(),
            'bowling' => $career
                ->map(fn (Collection $rows, string $format): ?array => $this->bowling($format, $rows))
                ->filter()
                ->values()
                ->all(),
            'news' => $this->news($request),
        ];
    }

    /**
     * Career rows are stored per season and format; the page shows one line per format.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function careerByFormat(): Collection
    {
        return collect($this->career ?? [])
            ->groupBy(fn (array $row): string => (string) ($row['type'] ?? 'Other'))
            ->sortBy(function (Collection $rows, string $format): int {
                $position = array_search($format, self::FORMAT_ORDER, true);

                return $position === false ? count(self::FORMAT_ORDER) : $position;
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function batting(string $format, Collection $rows): array
    {
        $innings = $this->total($rows, 'batting.innings');
        $notOuts = $this->total($rows, 'batting.not_outs');
        $runs = $this->total($rows, 'batting.runs_scored');
        $balls = $this->total($rows, 'batting.balls_faced');
        $dismissals = $innings - $notOuts;

        return [
            'format' => self::FORMAT_LABELS[$format] ?? $format,
            'matches' => $this->total($rows, 'batting.matches'),
            'innings' => $innings,
            'notOuts' => $notOuts,
            'runs' => number_format($runs),
            'average' => $this->ratio($runs, $dismissals),
            'strikeRate' => $this->ratio($runs * 100, $balls),
            'hundreds' => $this->total($rows, 'batting.hundreds'),
            'fifties' => $this->total($rows, 'batting.fifties'),
            'fours' => $this->total($rows, 'batting.four_x'),
            'sixes' => $this->total($rows, 'batting.six_x'),
            'highScore' => (string) ($rows->max(fn (array $row): int => (int) data_get($row, 'batting.highest_inning_score', 0)) ?: '—'),
        ];
    }

    /**
     * Null for a format the player has not bowled in.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function bowling(string $format, Collection $rows): ?array
    {
        // Overs are written as overs.balls: 3.4 is three overs and four balls.
        $balls = $rows->sum(function (array $row): int {
            $overs = (float) data_get($row, 'bowling.overs', 0);

            return (int) floor($overs) * 6 + (int) round(($overs - floor($overs)) * 10);
        });

        if ($balls === 0) {
            return null;
        }

        $wickets = $this->total($rows, 'bowling.wickets');
        $conceded = $this->total($rows, 'bowling.runs');

        return [
            'format' => self::FORMAT_LABELS[$format] ?? $format,
            'matches' => $this->total($rows, 'bowling.matches'),
            'innings' => $this->total($rows, 'bowling.innings'),
            'overs' => intdiv($balls, 6).($balls % 6 > 0 ? '.'.($balls % 6) : ''),
            'runs' => number_format($conceded),
            'wickets' => $wickets,
            'average' => $this->ratio($conceded, $wickets),
            'economy' => $this->ratio($conceded * 6, $balls),
            'strikeRate' => $this->ratio($balls, $wickets),
            'fourWickets' => $this->total($rows, 'bowling.four_wickets'),
            'fiveWickets' => $this->total($rows, 'bowling.five_wickets'),
        ];
    }

    /**
     * The latest stories that name the player in full.
     *
     * @return array<int, array<string, mixed>>
     */
    private function news(Request $request): array
    {
        $name = '%'.str_replace(['%', '_'], '', $this->fullname).'%';

        $stories = Article::query()
            ->visible()
            ->with(['category', 'writer'])
            ->where(fn ($query) => $query->where('title', 'like', $name)->orWhere('summary', 'like', $name)->orWhere('body', 'like', $name))
            ->latest('published_at')
            ->limit(3)
            ->get();

        return ArticleResource::collection($stories)->resolve($request);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function total(Collection $rows, string $key): int
    {
        return $rows->sum(fn (array $row): int => (int) data_get($row, $key, 0));
    }

    /** Two decimal places, or a dash when there is nothing to divide by. */
    private function ratio(int $value, int $divisor): string
    {
        return $divisor > 0 ? number_format($value / $divisor, 2, '.', '') : '—';
    }

    private function humanise(?string $style): ?string
    {
        return $style ? Str::of($style)->replace('-', ' ')->ucfirst()->toString() : null;
    }
}
