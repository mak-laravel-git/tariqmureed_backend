<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Category;
use App\Models\Writer;
use Illuminate\Database\Seeder;

/**
 * Sample categories, authors and stories, so the public news pages are never empty.
 *
 * Safe to run again: anything that already exists (matched by name or slug) is left exactly as the
 * editors changed it. Every sample story says so in its internal notes, so it is easy to find and
 * replace with real reporting from the admin dashboard.
 */
class ContentSeeder extends Seeder
{
    private const NOTE = 'Sample story added by ContentSeeder. Edit or delete it from the dashboard.';

    /** @var array<int, array{name: string, slug: string, title: string, bio: string, avatar: string, since: int}> */
    private const WRITERS = [
        ['name' => 'Arif Hossain', 'slug' => 'arif-hossain', 'title' => 'Senior Cricket Correspondent', 'bio' => 'Arif has covered four World Cups and reported from 38 Test venues. He leads TMN Sport\'s subcontinent coverage and writes the weekly match column.', 'avatar' => '/images/writers/arif-hossain.jpg', 'since' => 2016],
        ['name' => 'Lena Ford', 'slug' => 'lena-ford', 'title' => 'Chief Features Writer', 'bio' => 'Lena writes long-form profiles and investigations and edits the weekend features desk.', 'avatar' => '/images/writers/lena-ford.jpg', 'since' => 2018],
        ['name' => 'Daniel Okoye', 'slug' => 'daniel-okoye', 'title' => 'Data & Statistics Editor', 'bio' => 'Daniel builds the numbers behind TMN Sport\'s match coverage, from expected-run models to bowler-matchup tools and the live stats centre.', 'avatar' => '/images/writers/daniel-okoye.jpg', 'since' => 2019],
        ['name' => 'Saad Mehmood', 'slug' => 'saad-mehmood', 'title' => 'Pakistan Super League Reporter', 'bio' => 'Based in Lahore, Saad covers the PSL and Pakistan\'s international calendar, with a focus on domestic talent and franchise strategy.', 'avatar' => '/images/writers/saad-mehmood.jpg', 'since' => 2020],
        ['name' => 'Priya Nair', 'slug' => 'priya-nair', 'title' => 'Live Match Editor', 'bio' => 'Priya runs the TMN Sport live blog on match days and writes the tactical review published within an hour of the final ball.', 'avatar' => '/images/writers/priya-nair.jpg', 'since' => 2021],
        ['name' => 'Tom Whitfield', 'slug' => 'tom-whitfield', 'title' => 'Australia & Ashes Correspondent', 'bio' => 'Tom has followed every Ashes series since 2009 and files from the press box at every Test on the Australian summer calendar.', 'avatar' => '/images/writers/tom-whitfield.jpg', 'since' => 2014],
        ['name' => 'Ayaan Rahman', 'slug' => 'ayaan-rahman', 'title' => 'Cricket Analyst', 'bio' => 'Ayaan writes about tactics and the way modern teams are changing how the game is played.', 'avatar' => '', 'since' => 2022],
        ['name' => 'TMN Desk', 'slug' => 'tmn-desk', 'title' => 'Newsroom', 'bio' => 'Stories written by the TMN Sport newsroom.', 'avatar' => '', 'since' => 2016],
    ];

    /**
     * Stories: slug, title, summary, image, category, writer slug, minutes since publishing, views.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int}>
     */
    private const STORIES = [
        ['mumbais-chase-goes-deep-wankhede-final-over-finish', 'Mumbai\'s chase goes deep: Wankhede set for a final-over finish', 'Suryakumar Yadav and Tilak Varma drag Mumbai to the brink before Pathirana returns for the death overs', '/images/news/mumbai-chase.jpg', 'IPL 2026', 'priya-nair', 1, 3200],
        ['tamims-58-sets-169-for-rangpur-riders', 'Tamim\'s 58 sets 169 for Rangpur Riders in BPL crunch', 'Fortune Barishal recovered from a slow start to post a competitive total in Dhaka', '/images/news/bpl-blue-helmet.jpg', 'BPL 2026', 'arif-hossain', 2, 1800],
        ['smiths-96-keeps-australia-in-it-adelaide', 'Smith\'s 96 keeps Australia in it as England\'s lead grows in Adelaide', 'A composed, unlovely innings from Steve Smith', '/images/news/stadium-night.jpg', 'International', 'tom-whitfield', 240, 2400],
        ['hardik-pandya-captaincy-judged-by-standard-nobody-set', 'Hardik Pandya\'s captaincy is being judged by a standard nobody set', 'The expectation gap around Mumbai\'s captain says more about the audience than the player', '/images/news/stadium-crowd.jpg', 'Analysis', 'lena-ford', 420, 1500],
        ['lahore-qalandars-building-psl-most-complete-squad', 'Lahore Qalandars are building the PSL\'s most complete squad', 'Depth in every department has turned Lahore into the side to beat', '/images/news/keeper-appeal.jpg', 'PSL 2026', 'saad-mehmood', 480, 1100],
        ['india-name-15-member-squad-new-zealand-odi-series', 'India name 15-member squad for New Zealand ODI series', 'Gill to lead, with two uncapped players included and Jadeja rested for the first two matches', '/images/news/dialog-batsman.jpg', 'International', 'arif-hossain', 1440, 2100],
        ['chinnaswamy-chase-how-rcb-hunted-down-187', 'Chinnaswamy chase: how RCB hunted down 187 with 8 balls left', 'A 96-run second-wicket stand and a death-overs plan that never involved a six', '/images/news/green-yellow-batsman.jpg', 'IPL 2026', 'priya-nair', 2880, 900],
        ['adelaide-pitch-debate-is-really-about-scheduling', 'The Adelaide pitch debate is really a question about scheduling', 'Three-day Tests are not a format problem, they are a calendar problem', '/images/news/stadium-night.jpg', 'International', 'tom-whitfield', 1440, 700],
        ['psl-2026-halfway-mark-five-things-the-table-tells-us', 'PSL 2026 at the halfway mark: five things the table tells us', 'Lahore\'s net run rate, Karachi\'s powerplay problem and the fight for fourth', '/images/news/floodlit-stadium.jpg', 'PSL 2026', 'saad-mehmood', 2880, 800],
        ['gills-92-completes-the-sweep-rewrites-odi-record-books', 'Gill\'s 92 completes the sweep and quietly rewrites the ODI record books', 'India\'s captain now averages 58.4 after 61 matches, a rate only three batters have matched', '/images/news/india-batsman.jpg', 'International', 'daniel-okoye', 1140, 1300],
        ['smriti-mandhana-125-anchors-india-chase-australia-w', 'Smriti Mandhana\'s 125 anchors India\'s chase against Australia (W)', 'Thirteen boundaries, one hundred and a chase completed with fourteen balls remaining', '/images/news/portrait-smriti.jpg', 'Women\'s Cricket', 'priya-nair', 2880, 1000],
        ['t20-world-cup-2026-host-cities-venues-and-what-to-expect', 'T20 World Cup 2026: the host cities, venues and what to expect', 'Twenty teams, 55 matches, eight cities and a scheduling puzzle across two countries', '/images/news/stadium-lights.jpg', 'T20 World Cup', 'tom-whitfield', 4320, 4100],
        ['what-the-next-twelve-months-could-mean-for-bangladesh-cricket', 'What the next twelve months could mean for Bangladesh cricket', 'A closer look at the fixtures, form and emerging talent', '/images/stadiums/kajaria-crowd.jpg', 'Bangladesh', 'tmn-desk', 10, 600],
        ['a-new-era-begins-tactical-shifts-shaping-modern-international-cricket', 'A new era begins: the tactical shifts shaping modern international cricket', 'Teams are rewriting the playbook on tempo, roles and match-ups', '/images/stadiums/kajaria-crowd.jpg', 'International', 'tmn-desk', 18, 500],
        ['bangladesh-name-a-balanced-squad-for-the-upcoming-odi-series', 'Bangladesh name a balanced squad for the upcoming ODI series', 'Selectors back form and fitness ahead of a demanding schedule', '/images/stadiums/galle-pavilion.jpg', 'Bangladesh', 'tmn-desk', 42, 650],
        ['five-breakthrough-players-ready-to-own-the-global-stage', 'Five breakthrough players ready to own the global stage', 'The young names forcing their way into every selection debate', '/images/stadiums/kajaria-floodlight.jpg', 'Features', 'tmn-desk', 60, 450],
        ['inside-the-venues-that-will-host-this-summers-biggest-contests', 'Inside the venues that will host this summer\'s biggest contests', 'Pitches, crowds and conditions: what to expect at each ground', '/images/stadiums/kajaria-dusk.jpg', 'International', 'tmn-desk', 120, 400],
        ['a-fearless-generation-is-rewriting-the-rules-of-the-modern-game', 'A fearless generation is rewriting the rules of the modern game', 'Power, purpose and a fresh tactical imagination are changing how elite cricket is played and watched.', '/images/stadiums/kajaria-crowd.jpg', 'Analysis', 'ayaan-rahman', 300, 2600],
    ];

    /** Categories that are not used by any sample story but are offered on the news page. */
    private const EXTRA_CATEGORIES = ['Domestic'];

    public function run(): void
    {
        foreach (self::WRITERS as $writer) {
            Writer::query()->firstOrCreate(
                ['slug' => $writer['slug']],
                [
                    'name' => $writer['name'],
                    'title' => $writer['title'],
                    'desk' => 'Cricket desk',
                    'bio' => $writer['bio'],
                    'avatar' => $writer['avatar'] !== '' ? $writer['avatar'] : null,
                    'status' => 'Active',
                    'created_at' => "{$writer['since']}-01-01 00:00:00",
                ],
            );
        }

        foreach ([...array_unique(array_column(self::STORIES, 4)), ...self::EXTRA_CATEGORIES] as $name) {
            Category::query()->firstOrCreate(['name' => $name], ['slug' => Category::uniqueSlug($name), 'status' => 'Active']);
        }

        $writers = Writer::query()->pluck('id', 'slug');
        $categories = Category::query()->pluck('id', 'name');

        foreach (self::STORIES as [$slug, $title, $summary, $image, $category, $writer, $minutesAgo, $views]) {
            Article::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'title' => $title,
                    'summary' => $summary,
                    'body' => $this->body($slug, $summary),
                    'category_id' => $categories[$category],
                    'writer_id' => $writers[$writer],
                    'image' => $image,
                    'tags' => $category,
                    'status' => Article::PUBLISHED,
                    'published_at' => now()->subMinutes($minutesAgo),
                    'views' => $views,
                    'notes' => self::NOTE,
                ],
            );
        }
    }

    /**
     * Plain-text body in the editor format ("## Heading", "> Quote", a blank line between paragraphs).
     */
    private function body(string $slug, string $summary): string
    {
        if ($slug === 'a-fearless-generation-is-rewriting-the-rules-of-the-modern-game') {
            return implode("\n\n", [
                'There is a particular kind of energy around this team now. It is visible in the way they walk onto the field, in the decisions they make under pressure and in a refusal to wait for the game to happen.',
                'The shift has not arrived overnight. It has been built through better preparation, clearer roles and a generation of cricketers who have grown up studying every corner of the global game.',
                '## Intent, backed by intelligence',
                'Aggression alone is not a strategy. The best teams understand when to accelerate and when to absorb pressure. That balance is becoming the defining quality of the modern side.',
                '> The freedom comes from preparation. When every player understands the plan, instinct can take over.',
                'Coaches are now using matchups more deliberately, while players are becoming comfortable switching roles from one game to the next. The result is a style that feels expressive without becoming reckless.',
                '## The road ahead',
                'The coming months will provide a more demanding test. Conditions will change, opponents will adapt and expectations will grow. Yet the underlying direction is clear, and compelling.',
            ]);
        }

        return implode("\n\n", [
            rtrim($summary, '.').'.',
            'This is a sample story that keeps the news pages full until your own reporting is published. Replace this text from the Content Management section of the admin dashboard.',
            '## Why it matters',
            'Write the key facts, the context and the numbers here, one idea per paragraph, with a blank line between paragraphs.',
        ]);
    }
}
