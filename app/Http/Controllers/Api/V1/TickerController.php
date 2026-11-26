<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Fixture;
use App\Support\Scoreboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class TickerController extends Controller
{
    private const MAX_ITEMS = 12;

    /**
     * The alert bar: stories marked "Breaking News" first, then matches in play, the latest results, the matches
     * starting soon and, to keep the bar full, the newest headlines. 404 only when there is nothing at all, so the
     * site keeps its sample ticker on an empty install.
     */
    public function __invoke(): JsonResponse
    {
        $items = collect()
            ->concat($this->breaking())
            ->concat($this->liveMatches())
            ->concat($this->results())
            ->concat($this->upcoming());

        if ($items->count() < 6) {
            $items = $items->concat($this->headlines());
        }

        $items = $items->unique('text')->take(self::MAX_ITEMS)->values();

        abort_if($items->isEmpty(), 404);

        return response()->json(['data' => $items]);
    }

    /**
     * @return Collection<int, array{id: string, text: string, href: string}>
     */
    private function breaking(): Collection
    {
        return Article::query()
            ->visible()
            ->where('is_breaking', true)
            ->latest('published_at')
            ->limit(4)
            ->get(['id', 'title', 'slug'])
            ->map(fn (Article $article): array => ['id' => 'breaking-'.$article->id, 'text' => $article->title, 'href' => '/news/'.$article->slug]);
    }

    /**
     * @return Collection<int, array{id: string, text: string, href: string}>
     */
    private function liveMatches(): Collection
    {
        return $this->fixtures()->inPlay()->orderBy('starting_at')->limit(5)->get()
            ->map(fn (Fixture $fixture): array => $this->entry($fixture, 'LIVE', $fixture->status));
    }

    /**
     * @return Collection<int, array{id: string, text: string, href: string}>
     */
    private function results(): Collection
    {
        return $this->fixtures()->finished()
            ->where('starting_at', '>=', now()->subDays(3))
            ->latest('starting_at')
            ->limit(3)
            ->get()
            ->map(fn (Fixture $fixture): array => $this->entry($fixture, 'RESULT', $fixture->note));
    }

    /**
     * @return Collection<int, array{id: string, text: string, href: string}>
     */
    private function upcoming(): Collection
    {
        $timezone = (string) config('sportmonks.display_timezone');

        return $this->fixtures()->upcoming()
            ->whereBetween('starting_at', [now(), now()->addDays(2)])
            ->orderBy('starting_at')
            ->limit(3)
            ->get()
            ->map(fn (Fixture $fixture): array => $this->entry($fixture, 'NEXT', $fixture->starting_at->setTimezone($timezone)->format('D H:i')));
    }

    /**
     * @return Collection<int, array{id: string, text: string, href: string}>
     */
    private function headlines(): Collection
    {
        return Article::query()
            ->visible()
            ->latest('published_at')
            ->limit(4)
            ->get(['id', 'title', 'slug'])
            ->map(fn (Article $article): array => ['id' => 'article-'.$article->id, 'text' => $article->title, 'href' => '/news/'.$article->slug]);
    }

    /**
     * @return Builder<Fixture>
     */
    private function fixtures(): Builder
    {
        return Fixture::query()->with(['localTeam', 'visitorTeam']);
    }

    /**
     * "LIVE · India 148/3 v Australia 191/6 · 2nd Innings" (a side that has not batted has no score).
     *
     * @return array{id: string, text: string, href: string}
     */
    private function entry(Fixture $fixture, string $label, ?string $detail): array
    {
        $side = function (string $name, int $teamId) use ($fixture): string {
            $score = Scoreboard::score(Scoreboard::innings($fixture, $teamId));

            return trim($name.' '.$score);
        };

        $home = $side($fixture->localTeam?->name ?? 'TBC', (int) $fixture->local_team_id);
        $away = $side($fixture->visitorTeam?->name ?? 'TBC', (int) $fixture->visitor_team_id);

        return [
            'id' => 'match-'.$fixture->id,
            'text' => $label.' · '.$home.' v '.$away.(filled($detail) ? ' · '.$detail : ''),
            'href' => '/matches/'.$fixture->slug,
        ];
    }
}
