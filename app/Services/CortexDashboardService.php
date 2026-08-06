<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fetches and aggregates social monitoring data from the remote PostgreSQL
 * (cortex) connection, targeting the authenticated user's schema and only
 * the platform tables they are subscribed to.
 *
 * Normalisation contract — every platform row is reduced to the same
 * flat array shape that DashboardDataService currently returns, so the
 * existing aggregation logic (statistics, trend, etc.) can be reused.
 */
class CortexDashboardService
{
    /** Upper bound on `per_page`, so a crafted request cannot ask for the whole table back. */
    public const MAX_PER_PAGE = 200;

    /**
     * Province list used both by extractRegion() in PHP and by regionSql()
     * in Postgres. Order is significant — the first match wins — so the two
     * must stay in sync.
     */
    private const PROVINCES = [
        'Aceh', 'Sumatera Utara', 'Sumatera Barat', 'Riau', 'Jambi',
        'Sumatera Selatan', 'Bengkulu', 'Lampung', 'Bangka Belitung',
        'Kepulauan Riau', 'DKI Jakarta', 'Jakarta', 'Jawa Barat',
        'Jawa Tengah', 'DI Yogyakarta', 'Yogyakarta', 'Jawa Timur',
        'Banten', 'Bali', 'Nusa Tenggara Barat', 'Nusa Tenggara Timur',
        'Kalimantan Barat', 'Kalimantan Tengah', 'Kalimantan Selatan',
        'Kalimantan Timur', 'Kalimantan Utara', 'Sulawesi Utara',
        'Sulawesi Tengah', 'Sulawesi Selatan', 'Sulawesi Tenggara',
        'Gorontalo', 'Sulawesi Barat', 'Maluku', 'Maluku Utara',
        'Papua Barat', 'Papua',
    ];

    /**
     * Bumped whenever the shape of anything cached here changes, so a deploy
     * can never serve an entry written by the previous version.
     */
    private const CACHE_VERSION = 'v2';

    public function __construct(private readonly CortexConnectionService $cortex) {}

    /**
     * Memoise a derived result on the file store, scoped to the active schema.
     *
     * Always the file store: CACHE_STORE points at the same remote Postgres
     * these caches exist to avoid, so the default store would add a round trip
     * instead of removing one. A ttl of 0 or less bypasses the cache.
     *
     * @param  string  $kind       Namespace for the key, e.g. 'aggregates'.
     * @param  array<mixed>  $keyParts  Everything the result depends on.
     */
    private function remember(string $kind, int $ttl, array $keyParts, \Closure $compute): mixed
    {
        if ($ttl <= 0) {
            return $compute();
        }

        $key = sprintf(
            'cortex_%s_%s_%s_%s',
            $kind,
            self::CACHE_VERSION,
            $this->cortex->currentSchema(),
            md5(json_encode($keyParts)),
        );

        return Cache::store('file')->remember($key, now()->addSeconds($ttl), $compute);
    }

    /**
     * Reduce a filter set to a stable cache key component: drop empties, fix
     * the ordering, so ['b','a'] and ['a','b'] land on the same entry.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function canonicalFilters(array $filters): array
    {
        $keyword = $filters['keyword'] ?? null;
        if (is_array($keyword)) {
            sort($keyword);
        }

        $canonical = array_filter([
            'start_date' => $filters['start_date'] ?? null,
            'end_date'   => $filters['end_date'] ?? null,
            'platform'   => $filters['platform'] ?? null,
            'region'     => $filters['region'] ?? null,
            'keyword'    => $keyword,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        ksort($canonical);

        return $canonical;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return all normalised rows for the given platforms, optionally filtered.
     *
     * @param  array<string>  $platforms  Subscribed platforms already verified to exist.
     * @param  array{start_date?:string|null, end_date?:string|null, keyword?:string|null, platform?:string|null, region?:string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getFilteredData(array $platforms, array $filters = []): array
    {
        $platformFilter = $filters['platform'] ?? null;

        // If a specific platform is requested and it is not available, return nothing.
        if ($platformFilter && ! in_array($platformFilter, $platforms, true)) {
            return [];
        }

        $targets = $platformFilter ? [$platformFilter] : $platforms;

        $rows = [];

        foreach ($targets as $platform) {
            $query = $this->cortex->table($platform);

            // Date range filter on post_created_at (actual publish date).
            // Plain comparisons rather than whereDate(), which wraps the column
            // in `::date` and makes a btree index on it unusable.
            if (! empty($filters['start_date'])) {
                $query->where('post_created_at', '>=', $filters['start_date']);
            }
            if (! empty($filters['end_date'])) {
                $query->where('post_created_at', '<', Carbon::parse($filters['end_date'])->addDay()->toDateString());
            }

            // Keyword filter: support both text search and array of keywords
            if (! empty($filters['keyword'])) {
                if (is_array($filters['keyword'])) {
                    // Multi-select: filter by exact keyword match using IN clause
                    $query->whereIn('keyword', $filters['keyword']);
                } else {
                    // Text search: match against content column and metrics->>'caption'
                    $kw = '%' . $filters['keyword'] . '%';
                    $query->where(function ($q) use ($kw) {
                        $q->where('content', 'ilike', $kw)
                          ->orWhere('keyword', 'ilike', $kw)
                          ->orWhereRaw("metrics->>'caption' ilike ?", [$kw]);
                    });
                }
            }

            $rawRows = $query->selectRaw($this->selectSql($platform))->get();

            foreach ($rawRows as $record) {
                $normalised = $this->normalise($platform, (array) $record);
                if ($normalised === null) {
                    continue;
                }

                // Region filter (applied after normalisation because region
                // may be derived from the metrics JSONB)
                if (! empty($filters['region']) && $normalised['region'] !== $filters['region']) {
                    continue;
                }

                $rows[] = $normalised;
            }
        }

        return $rows;
    }

    /**
     * Metrics JSONB keys each platform normalizer actually reads.
     *
     * The raw `metrics` column averages 2.3-4 KB per row (25 MB for instagram
     * alone), but the normalizers only ever touch these keys. Projecting them
     * with jsonb_build_object keeps the wire format identical — normalise()
     * still receives a `metrics` object — while leaving the rest in Postgres.
     */
    private const METRIC_KEYS = [
        'facebook'  => ['post_id', 'author', 'reactions', 'message', 'timestamp',
                        'reactions_count', 'comments_count', 'reshare_count', 'url', 'type', 'image'],
        'instagram' => ['id', 'hashtags', 'caption', 'ownerFullName', 'ownerUsername', 'timestamp',
                        'likesCount', 'commentsCount', 'url', 'type', 'displayUrl'],
        'tiktok'    => ['id', 'hashtags', 'authorMeta', 'text', 'createTimeISO',
                        'diggCount', 'commentCount', 'shareCount', 'playCount', 'webVideoUrl'],
        'twitter'   => ['id', 'author', 'entities', 'fullText', 'text', 'place', 'createdAt',
                        'likeCount', 'replyCount', 'retweetCount', 'viewCount', 'url', 'twitterUrl',
                        'type', 'media'],
    ];

    /** Columns every normalizer reads straight off the row. */
    private const BASE_COLUMNS = 'id, post_id, keyword, content, sentiment, post_created_at, created_at';

    /**
     * Build the SELECT list for a platform table: the base columns plus a
     * trimmed-down `metrics` object. Unknown platforms fall back to the whole
     * blob, since normaliseGeneric() cannot know which keys matter.
     */
    private function selectSql(string $platform): string
    {
        $keys = self::METRIC_KEYS[$platform] ?? null;

        if ($keys === null) {
            return self::BASE_COLUMNS . ', metrics';
        }

        // Keys come from the constant above, never from user input.
        $pairs = array_map(fn (string $key) => "'{$key}', metrics->'{$key}'", $keys);

        return self::BASE_COLUMNS . ', jsonb_build_object(' . implode(', ', $pairs) . ') AS metrics';
    }

    /**
     * Return the filter dropdown options.
     *
     * This used to take the full unfiltered dataset and pluck the distinct
     * values out of it in PHP, which meant every dashboard request pulled all
     * ~9.5k rows a second time (65s, 102 MB) purely to build a handful of
     * short lists. Everything here is derived from the platform list the
     * caller already has, or from DISTINCT queries that return at most a few
     * dozen rows.
     *
     * @param  array<string>  $platforms  Platforms already discovered by the caller.
     * @return array{platforms: array<string>, regions: array<string>, keywords: array<string>}
     */
    public function getFilterOptions(array $platforms): array
    {
        return [
            'platforms' => array_values($platforms),
            'regions'   => $this->getDistinctRegions($platforms),
            'keywords'  => $this->getDistinctKeywords($platforms),
        ];
    }

    /**
     * Fetch distinct keywords across the given platform tables.
     *
     * @param  array<string>  $platforms
     * @return array<string>
     */
    public function getDistinctKeywords(array $platforms): array
    {
        return $this->remember('keywords', (int) config('cortex.filter_cache_ttl'), [$platforms], function () use ($platforms) {
            $keywords = [];

            foreach ($platforms as $platform) {
                $rows = $this->cortex->table($platform)
                    ->select('keyword')
                    ->whereNotNull('keyword')
                    ->where('keyword', '!=', '')
                    ->distinct()
                    ->get();

                foreach ($rows as $row) {
                    $kw = trim((string) $row->keyword);
                    if ($kw !== '') {
                        // Array keys dedup in O(1); in_array() made this O(n²).
                        $keywords[$kw] = true;
                    }
                }
            }

            $keywords = array_keys($keywords);
            sort($keywords);

            return $keywords;
        });
    }

    /**
     * Fetch the distinct regions present in the data.
     *
     * Region is derived from the post text rather than stored as a column, so
     * the match runs as a CASE expression inside Postgres and only the ≤36
     * distinct results cross the wire — instead of every row's content.
     *
     * @param  array<string>  $platforms
     * @return array<string>
     */
    public function getDistinctRegions(array $platforms): array
    {
        return $this->remember('regions', (int) config('cortex.filter_cache_ttl'), [$platforms], function () use ($platforms) {
            $regions = [];

            foreach ($platforms as $platform) {
                $rows = $this->cortex->table($platform)
                    ->selectRaw('DISTINCT ' . $this->regionSql($platform) . ' AS region')
                    ->get();

                foreach ($rows as $row) {
                    $region = trim((string) ($row->region ?? ''));
                    if ($region !== '') {
                        $regions[$region] = true;
                    }
                }
            }

            $regions = array_keys($regions);
            sort($regions);

            return $regions;
        });
    }

    /**
     * SQL mirror of extractRegion(): first province name found in the text
     * wins, in the same order as the PHP list. Twitter additionally prefers
     * the `place` object, exactly like normaliseTwitter() does.
     */
    private function regionSql(string $platform): string
    {
        $cases = '';
        foreach (self::PROVINCES as $province) {
            $escaped = str_replace("'", "''", $province);
            $cases .= " WHEN content ILIKE '%{$escaped}%' THEN '{$escaped}'";
        }
        $case = "CASE{$cases} ELSE '' END";

        if ($platform === 'twitter') {
            return "COALESCE("
                 . "NULLIF(metrics->'place'->>'full_name', ''), "
                 . "NULLIF(metrics->'place'->>'name', ''), "
                 . $case
                 . ")";
        }

        return $case;
    }

    // ──────────────────────────────────────────────────────────────────────
    // SQL-side aggregation
    //
    // Every figure on the dashboard is a count, a sum, or a top-N. Computing
    // them in PHP meant shipping all ~9.5k rows (content text included) across
    // a link where a full instagram fetch alone costs 46s. These queries do
    // the same arithmetic in Postgres and return tens of rows instead.
    //
    // The projections below must stay behaviourally identical to the PHP
    // normalizers — resolveSentiment(), parseDate(), extractRegion() and the
    // per-platform hashtag/engagement mapping all have a mirror here.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Aggregate statistics without materialising the rows in PHP.
     *
     * @param  array<string>  $platforms
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getAggregates(array $platforms, array $filters): array
    {
        return $this->remember(
            'aggregates',
            (int) config('cortex.aggregate_cache_ttl'),
            [$platforms, $this->canonicalFilters($filters)],
            fn () => $this->computeAggregates($platforms, $filters),
        );
    }

    /**
     * The uncached body of getAggregates().
     *
     * @param  array<string>  $platforms
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function computeAggregates(array $platforms, array $filters): array
    {
        $platforms = $this->targetPlatforms($platforms, $filters);

        if ($platforms === []) {
            return $this->emptyAggregates();
        }

        $conn = $this->cortex->connection();

        // One pass for the platform/sentiment breakdown, which also carries the
        // engagement sums — they share a grouping key, so there is no reason to
        // scan twice for them.
        $bindings = [];
        $base     = $this->baseUnion($platforms, $filters, $bindings);
        $rows     = $conn->select(
            "SELECT platform, sentiment, count(*) AS n,
                    COALESCE(sum(likes), 0)    AS likes,
                    COALESCE(sum(comments), 0) AS comments,
                    COALESCE(sum(shares), 0)   AS shares,
                    COALESCE(sum(views), 0)    AS views
             FROM {$base} AS b GROUP BY 1, 2",
            $bindings
        );

        $total = 0;
        $bySentiment = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $byPlatform  = [];

        foreach ($rows as $row) {
            $n         = (int) $row->n;
            $sentiment = $row->sentiment ?: 'neutral';
            $total    += $n;
            $bySentiment[$sentiment] = ($bySentiment[$sentiment] ?? 0) + $n;

            $p = $byPlatform[$row->platform] ??= [
                'platform' => $row->platform, 'positive' => 0, 'neutral' => 0,
                'negative' => 0, 'total' => 0, 'likes' => 0, 'comments' => 0,
                'shares' => 0, 'views' => 0,
            ];
            $p[$sentiment]  += $n;
            $p['total']     += $n;
            $p['likes']     += (int) $row->likes;
            $p['comments']  += (int) $row->comments;
            $p['shares']    += (int) $row->shares;
            $p['views']     += (int) $row->views;
            $byPlatform[$row->platform] = $p;
        }

        // GROUP BY returns platforms in whatever order Postgres pleases, which
        // reshuffled every chart keyed on platform. Restore the caller's order.
        $ordered = [];
        foreach ($platforms as $platform) {
            if (isset($byPlatform[$platform])) {
                $ordered[$platform] = $byPlatform[$platform];
            }
        }
        $byPlatform = $ordered;

        $positive = $bySentiment['positive'];
        $negative = $bySentiment['negative'];
        $pct = fn (int $n) => $total > 0 ? round($n / $total * 100, 2) : 0;

        return [
            'net_sentiment'        => $total > 0 ? round((($positive - $negative) / $total) * 100, 2) : 0,
            'sentiment_percentage' => [
                'positive' => $pct($positive),
                'neutral'  => $pct($bySentiment['neutral']),
                'negative' => $pct($negative),
            ],
            'negative_words'       => $this->topWordsSql($platforms, $filters, 'negative'),
            'positive_words'       => $this->topWordsSql($platforms, $filters, 'positive'),
            'trend'                => $this->trendSql($platforms, $filters),
            'trend_by_platform'    => $this->trendByPlatformSql($platforms, $filters),
            'platform_sentiment'   => array_values(array_map(
                fn (array $p) => array_intersect_key($p, array_flip(['platform', 'positive', 'neutral', 'negative', 'total'])),
                $byPlatform
            )),
            'mention_by_platform'  => array_values(array_map(
                fn (array $p) => ['platform' => $p['platform'], 'count' => $p['total']],
                $byPlatform
            )),
            'mention_by_media'     => $this->groupCountSql($platforms, $filters, 'type', 'media', true),
            'mention_by_province'  => $this->groupCountSql($platforms, $filters, 'region', 'province', false),
            'top_topics'           => $this->topTopicsSql($platforms, $filters),
            'engagement'           => array_values(array_map(fn (array $p) => [
                'platform' => $p['platform'],
                'likes'    => $p['likes'],
                'comments' => $p['comments'],
                'shares'   => $p['shares'],
                'views'    => $p['views'],
                'total'    => $p['likes'] + $p['comments'] + $p['shares'] + $p['views'],
            ], $byPlatform)),
            'total'                => $total,
        ];
    }

    /**
     * Fetch one page of the table.
     *
     * Two steps on purpose: the union only sorts and pages over the columns it
     * needs to order by, then the ≤200 winning rows are read in full and handed
     * to the existing normalizers. That keeps a single definition of how a row
     * becomes an API object, rather than reimplementing every normalizer in SQL.
     *
     * @param  array<string>  $platforms
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function getTablePage(array $platforms, array $filters, int $page, int $perPage, ?string $sortBy, string $sortDir, int $total): array
    {
        $sortable = ['date', 'platform', 'region', 'sentiment', 'author',
                     'likes', 'comments', 'shares', 'views'];

        $sortBy  = in_array($sortBy, $sortable, true) ? $sortBy : 'date';
        $sortDir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';

        // Sorting by date orders on the raw timestamp, not the formatted string,
        // so the expression index can serve it. NULLS placement is spelled out
        // to match that index in both directions — DESC reads it forward,
        // ASC reads it backward.
        if ($sortBy === 'date') {
            $orderBy = $sortDir === 'ASC' ? 'sort_ts ASC NULLS FIRST' : 'sort_ts DESC NULLS LAST';
        } else {
            $orderBy = "{$sortBy} {$sortDir}";
        }

        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $pages   = (int) max(1, ceil($total / $perPage));
        $page    = max(1, min($page, $pages));

        $meta = ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages];

        $targets = $this->targetPlatforms($platforms, $filters);

        if ($targets === [] || $total === 0) {
            return ['data' => [], 'meta' => $meta];
        }

        $bindings = [];
        $base     = $this->baseUnion($targets, $filters, $bindings, withText: false, withHashtags: false);

        // "id" is the table's own primary key, unique only within a platform,
        // so the pair is what identifies a row. The tiebreaker keeps paging
        // stable when the sort column has duplicates.
        $keys = $this->cortex->connection()->select(
            "SELECT platform, id FROM {$base} AS b
             ORDER BY {$orderBy}, platform ASC, id ASC
             LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $bindings
        );

        if ($keys === []) {
            return ['data' => [], 'meta' => $meta];
        }

        $idsByPlatform = [];
        foreach ($keys as $key) {
            $idsByPlatform[$key->platform][] = $key->id;
        }

        // Read the winning rows in full, then restore the order the union gave us.
        $rowsByKey = [];
        foreach ($idsByPlatform as $platform => $ids) {
            $records = $this->cortex->table($platform)
                ->selectRaw($this->selectSql($platform))
                ->whereIn('id', $ids)
                ->get();

            foreach ($records as $record) {
                $record = (array) $record;
                $normalised = $this->normalise($platform, $record);
                if ($normalised !== null) {
                    $rowsByKey[$platform . ':' . $record['id']] = $normalised;
                }
            }
        }

        $data = [];
        foreach ($keys as $key) {
            $lookup = $key->platform . ':' . $key->id;
            if (isset($rowsByKey[$lookup])) {
                $data[] = $rowsByKey[$lookup];
            }
        }

        return ['data' => $data, 'meta' => $meta];
    }

    /** The shape returned when a client has no data at all. */
    private function emptyAggregates(): array
    {
        return [
            'net_sentiment'        => 0,
            'sentiment_percentage' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
            'negative_words'       => [],
            'positive_words'       => [],
            'trend'                => [],
            'platform_sentiment'   => [],
            'mention_by_platform'  => [],
            'mention_by_media'     => [],
            'mention_by_province'  => [],
            'top_topics'           => [],
            'engagement'           => [],
            'trend_by_platform'    => [],
            'total'                => 0,
        ];
    }

    /**
     * Narrow the platform list by the `platform` filter, matching the
     * "unavailable platform means no rows" rule getFilteredData() applies.
     *
     * @param  array<string>  $platforms
     * @return array<string>
     */
    private function targetPlatforms(array $platforms, array $filters): array
    {
        $requested = $filters['platform'] ?? null;

        if ($requested) {
            return in_array($requested, $platforms, true) ? [$requested] : [];
        }

        return array_values($platforms);
    }

    // ── query builders ────────────────────────────────────────────────────

    /** @param array<string> $platforms */
    private function trendSql(array $platforms, array $filters): array
    {
        $bindings = [];
        $base     = $this->baseUnion($platforms, $filters, $bindings, withText: false);

        $rows = $this->cortex->connection()->select(
            "SELECT \"date\", sentiment, count(*) AS n FROM {$base} AS b GROUP BY 1, 2 ORDER BY 1",
            $bindings
        );

        $trend = [];
        foreach ($rows as $row) {
            $date = (string) $row->date;
            $trend[$date] ??= ['date' => $date, 'positive' => 0, 'neutral' => 0, 'negative' => 0, 'total' => 0];
            $trend[$date][$row->sentiment ?: 'neutral'] += (int) $row->n;
            $trend[$date]['total'] += (int) $row->n;
        }

        ksort($trend);

        return array_values($trend);
    }

    /**
     * Mention counts per date *and* platform, for the multi-line trend chart.
     *
     * Kept separate from trendSql() rather than folded into it: `trend` is also
     * read as one row per date (positive/neutral/negative series), and splitting
     * it by platform would give that consumer four rows per date.
     *
     * The chart used to derive these lines client-side from the full row dump,
     * which silently became one page of results once the table was paginated.
     *
     * @param  array<string>  $platforms
     */
    private function trendByPlatformSql(array $platforms, array $filters): array
    {
        $bindings = [];
        $base     = $this->baseUnion($platforms, $filters, $bindings, withText: false, withHashtags: false);

        $rows = $this->cortex->connection()->select(
            "SELECT \"date\", platform, count(*) AS n FROM {$base} AS b GROUP BY 1, 2 ORDER BY 1, 2",
            $bindings
        );

        return array_map(fn ($row) => [
            'date'     => (string) $row->date,
            'platform' => (string) $row->platform,
            'total'    => (int) $row->n,
        ], $rows);
    }

    /**
     * Count rows grouped by a single projected column.
     *
     * @param  array<string>  $platforms
     * @param  bool  $keepBlank  mention_by_media reports blanks as "unknown";
     *                           mention_by_province drops them entirely.
     */
    private function groupCountSql(array $platforms, array $filters, string $column, string $label, bool $keepBlank): array
    {
        $bindings = [];
        $base     = $this->baseUnion($platforms, $filters, $bindings, withText: false);
        $where    = $keepBlank ? '' : " WHERE COALESCE({$column}, '') <> ''";

        $rows = $this->cortex->connection()->select(
            "SELECT COALESCE({$column}, '') AS k, count(*) AS n FROM {$base} AS b{$where} GROUP BY 1 ORDER BY 2 DESC",
            $bindings
        );

        return array_map(fn ($row) => [
            $label  => $keepBlank ? ($row->k ?: 'unknown') : $row->k,
            'count' => (int) $row->n,
        ], $rows);
    }

    /**
     * Top words in the post text for one sentiment — the SQL twin of topWords():
     * lowercase, split on whitespace, strip everything but a-z0-9, keep >3 chars.
     *
     * @param  array<string>  $platforms
     */
    private function topWordsSql(array $platforms, array $filters, string $sentiment, int $limit = 10): array
    {
        $bindings = [];
        $base     = $this->baseUnion($platforms, $filters, $bindings);
        $bindings[] = $sentiment;

        $rows = $this->cortex->connection()->select(
            "SELECT w, count(*) AS n FROM (
                SELECT regexp_replace(lower(word), '[^a-z0-9]', '', 'g') AS w
                FROM {$base} AS b,
                     LATERAL regexp_split_to_table(b.\"text\", '\\s+') AS word
                WHERE b.sentiment = ?
             ) AS t
             WHERE length(w) > 3
             GROUP BY w ORDER BY n DESC, w ASC LIMIT {$limit}",
            $bindings
        );

        return array_map(fn ($row) => ['word' => $row->w, 'count' => (int) $row->n], $rows);
    }

    /** @param array<string> $platforms */
    private function topTopicsSql(array $platforms, array $filters, int $limit = 10): array
    {
        $bindings = [];
        $base     = $this->baseUnion($platforms, $filters, $bindings, withText: false);

        $rows = $this->cortex->connection()->select(
            "SELECT tag, count(*) AS n FROM (
                SELECT jsonb_array_elements_text(b.hashtags) AS tag FROM {$base} AS b
             ) AS t
             WHERE tag <> ''
             GROUP BY tag ORDER BY n DESC, tag ASC LIMIT {$limit}",
            $bindings
        );

        return array_map(fn ($row) => ['topic' => $row->tag, 'count' => (int) $row->n], $rows);
    }

    // ── per-platform SQL projections ──────────────────────────────────────

    /**
     * The UNION ALL of every platform table, projected into the normalised
     * column set the aggregates work against.
     *
     * @param  array<string>  $platforms
     * @param  array<int, mixed>  $bindings  Appended to in statement order.
     * @param  bool  $withText  Include the post text — only the word counts need it.
     * @param  bool  $withHashtags  Include the hashtag array — only top_topics needs it.
     */
    private function baseUnion(array $platforms, array $filters, array &$bindings, bool $withText = true, bool $withHashtags = true): string
    {
        $schema = $this->cortex->currentSchema();
        $parts  = [];

        foreach ($platforms as $platform) {
            // Order matters: these push bindings in the order they appear in the SQL.
            $sentiment = $this->sentimentSql($platform, $bindings);
            $hashtags  = $withHashtags ? ', ' . $this->hashtagsSql($platform) . ' AS hashtags' : '';
            $text      = $withText ? ", COALESCE(content, '') AS \"text\"" : '';
            $where     = $this->whereSql($platform, $filters, $bindings);

            $parts[] = "SELECT id, '{$platform}'::text AS platform, "
                . "{$sentiment} AS sentiment, "
                . $this->dateSql() . " AS \"date\", "
                . $this->sortTsSql() . " AS sort_ts, "
                . $this->regionSql($platform) . " AS region, "
                . $this->typeSql($platform) . " AS \"type\", "
                . $this->authorSql($platform) . " AS author, "
                . $this->engagementSql($platform, 'likes') . " AS likes, "
                . $this->engagementSql($platform, 'comments') . " AS comments, "
                . $this->engagementSql($platform, 'shares') . " AS shares, "
                . $this->engagementSql($platform, 'views') . " AS views"
                . $hashtags
                . $text
                . " FROM \"{$schema}\".\"{$platform}\"{$where}";
        }

        return '(' . implode(' UNION ALL ', $parts) . ')';
    }

    /**
     * WHERE clause for one platform table.
     *
     * Date bounds are plain range comparisons rather than whereDate()'s
     * `post_created_at::date`, so a btree index on the column can actually be
     * used once one exists.
     */
    private function whereSql(string $platform, array $filters, array &$bindings): string
    {
        $clauses = [];

        if (! empty($filters['start_date'])) {
            $clauses[]  = 'post_created_at >= ?';
            $bindings[] = $filters['start_date'];
        }
        if (! empty($filters['end_date'])) {
            // Exclusive upper bound on the next day, so the whole end day counts.
            $clauses[]  = 'post_created_at < ?';
            $bindings[] = Carbon::parse($filters['end_date'])->addDay()->toDateString();
        }

        if (! empty($filters['keyword'])) {
            if (is_array($filters['keyword'])) {
                $placeholders = implode(', ', array_fill(0, count($filters['keyword']), '?'));
                $clauses[]    = "keyword IN ({$placeholders})";
                foreach ($filters['keyword'] as $keyword) {
                    $bindings[] = $keyword;
                }
            } else {
                $clauses[]  = "(content ILIKE ? OR keyword ILIKE ? OR metrics->>'caption' ILIKE ?)";
                $like       = '%' . $filters['keyword'] . '%';
                $bindings[] = $like;
                $bindings[] = $like;
                $bindings[] = $like;
            }
        }

        if (! empty($filters['region'])) {
            $clauses[]  = $this->regionSql($platform) . ' = ?';
            $bindings[] = $filters['region'];
        }

        return $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
    }

    /**
     * SQL mirror of resolveSentiment(): DB column, then the reference-schema
     * keyword map, then Facebook reactions, then the hardcoded map.
     */
    private function sentimentSql(string $platform, array &$bindings): string
    {
        $branches = ["CASE WHEN sentiment IN ('positive','negative','neutral') THEN sentiment END"];

        $keywordExpr = "lower(trim(COALESCE(keyword, '')))";

        $map = $this->getKeywordSentimentMap();
        if ($map !== []) {
            $case = "CASE {$keywordExpr}";
            foreach ($map as $keyword => $sentiment) {
                $case .= ' WHEN ? THEN ?';
                $bindings[] = (string) $keyword;
                $bindings[] = (string) $sentiment;
            }
            $branches[] = $case . ' END';
        }

        if ($platform === 'facebook') {
            $branches[] = $this->reactionsSql();
        }

        // Class constant — safe to inline.
        $hardcoded = "CASE {$keywordExpr}";
        foreach (self::KEYWORD_SENTIMENTS as $keyword => $sentiment) {
            $hardcoded .= " WHEN '{$keyword}' THEN '{$sentiment}'";
        }
        $branches[] = $hardcoded . ' END';

        $branches[] = "'neutral'";

        return 'COALESCE(' . implode(', ', $branches) . ')';
    }

    /** SQL mirror of sentimentFromReactions(). */
    private function reactionsSql(): string
    {
        $sum = "(SELECT COALESCE(sum(e.value::numeric), 0)
                 FROM jsonb_each_text(metrics->'reactions') AS e(key, value)
                 WHERE e.value ~ '^-?[0-9]+(\\.[0-9]+)?$')";

        $pick = fn (string $key) => "CASE WHEN jsonb_typeof(metrics->'reactions'->'{$key}') = 'number'
                                          THEN (metrics->'reactions'->>'{$key}')::numeric ELSE 0 END";

        $negative = $pick('angry') . ' + ' . $pick('sad');
        $positive = $pick('like') . ' + ' . $pick('love') . ' + ' . $pick('wow') . ' + ' . $pick('care');

        return "CASE WHEN jsonb_typeof(metrics->'reactions') = 'object' THEN
                    CASE
                        WHEN {$sum} = 0 THEN 'neutral'
                        WHEN ({$negative}) > {$sum} * 0.5 THEN 'negative'
                        WHEN ({$positive}) > {$sum} * 0.5 THEN 'positive'
                        ELSE 'neutral'
                    END
                END";
    }

    /** SQL mirror of parseDate(): the DB timestamp wins, created_at backs it up. */
    private function dateSql(): string
    {
        return "to_char(COALESCE(post_created_at, created_at, DATE '2026-01-01'), 'YYYY-MM-DD')";
    }

    /**
     * The raw timestamp behind dateSql(), used for ORDER BY.
     *
     * Sorting on dateSql() itself would mean sorting on `to_char(...)`, which no
     * btree on the column can satisfy — Postgres had to materialise and sort
     * every row. This expression is indexable, and the index must be declared
     * over exactly the same text to be matched:
     *
     *   CREATE INDEX ... ON <table> ((COALESCE(post_created_at, created_at)) DESC NULLS LAST)
     */
    private function sortTsSql(): string
    {
        return 'COALESCE(post_created_at, created_at)';
    }

    /** Per-platform `type`, with the same defaults the normalizers apply. */
    private function typeSql(string $platform): string
    {
        return match ($platform) {
            // COALESCE, not NULLIF: an explicit empty string is kept, matching
            // `?? 'post'`, which only substitutes when the key is absent or null.
            'facebook'  => "COALESCE(metrics->>'type', 'post')",
            'instagram' => "lower(COALESCE(metrics->>'type', 'image'))",
            'tiktok'    => "'video'::text",
            'twitter'   => "COALESCE(metrics->>'type', 'tweet')",
            default     => "''::text",
        };
    }

    /** Per-platform author, used only as a sort key. */
    private function authorSql(string $platform): string
    {
        return match ($platform) {
            'facebook'  => "COALESCE(metrics->'author'->>'name', metrics->>'author', '')",
            'instagram' => "COALESCE(metrics->>'ownerFullName', metrics->>'ownerUsername', '')",
            'tiktok'    => "COALESCE(metrics->'authorMeta'->>'nickName', metrics->'authorMeta'->>'name', '')",
            'twitter'   => "COALESCE(metrics->'author'->>'name', metrics->'author'->>'userName', metrics->>'author', '')",
            default     => "''::text",
        };
    }

    /** Per-platform engagement counter, mirroring the normalizers' field picks. */
    private function engagementSql(string $platform, string $metric): string
    {
        $num = fn (string $path) => "CASE WHEN jsonb_typeof({$path}) = 'number'
                                         THEN trunc(({$path}#>>'{}')::numeric) ELSE 0 END";

        $map = [
            'facebook' => [
                // `??` in the normalizer falls back only when the key is missing,
                // not when it is a genuine 0 — so test for presence, not for zero.
                'likes'    => "CASE WHEN jsonb_typeof(metrics->'reactions'->'like') = 'number'
                                    THEN trunc((metrics->'reactions'->>'like')::numeric)
                                    ELSE {$num("metrics->'reactions_count'")} END",
                'comments' => $num("metrics->'comments_count'"),
                'shares'   => $num("metrics->'reshare_count'"),
                'views'    => '0',
            ],
            'instagram' => [
                'likes'    => $num("metrics->'likesCount'"),
                'comments' => $num("metrics->'commentsCount'"),
                'shares'   => '0',
                'views'    => '0',
            ],
            'tiktok' => [
                'likes'    => $num("metrics->'diggCount'"),
                'comments' => $num("metrics->'commentCount'"),
                'shares'   => $num("metrics->'shareCount'"),
                'views'    => $num("metrics->'playCount'"),
            ],
            'twitter' => [
                'likes'    => $num("metrics->'likeCount'"),
                'comments' => $num("metrics->'replyCount'"),
                'shares'   => $num("metrics->'retweetCount'"),
                'views'    => $num("metrics->'viewCount'"),
            ],
        ];

        return '(' . ($map[$platform][$metric] ?? '0') . ')::bigint';
    }

    /**
     * Hashtags as a flat lowercase jsonb array, whichever shape the platform
     * stores them in: bare strings, `{name}` objects, `entities.hashtags`
     * `{text}` objects, or — for facebook — nothing but the post text.
     */
    private function hashtagsSql(string $platform): string
    {
        $fromArray = function (string $path, string $key) {
            return "CASE WHEN jsonb_typeof({$path}) = 'array' THEN COALESCE((
                        SELECT jsonb_agg(t) FROM (
                            SELECT lower(CASE WHEN jsonb_typeof(h) = 'object'
                                              THEN COALESCE(h->>'{$key}', '')
                                              ELSE ltrim(h#>>'{}', '#') END) AS t
                            FROM jsonb_array_elements({$path}) AS h
                        ) AS x WHERE t <> ''
                    ), '[]'::jsonb) ELSE '[]'::jsonb END";
        };

        // Facebook has no hashtag field; the normalizer regexes the text instead.
        $fromText = "COALESCE((
            SELECT jsonb_agg(lower(m[1]))
            FROM regexp_matches(COALESCE(content, ''), '#(\\w+)', 'g') AS m
        ), '[]'::jsonb)";

        return match ($platform) {
            'instagram', 'tiktok' => $fromArray("metrics->'hashtags'", 'name'),
            'twitter'             => $fromArray("metrics->'entities'->'hashtags'", 'text'),
            default               => $fromText,
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // Normalisation — dispatches to per-platform normalizers
    // Each platform has its own field layout inside the metrics JSONB column.
    // This mirrors the old DashboardDataService per-platform approach, but
    // reads from metrics JSONB instead of raw JSON files.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Keyword → sentiment map.
     * The `keyword` column stores the monitored search term used to collect
     * a post (e.g. 'banjir', 'gempa', 'umkm'). It is the direct sentiment
     * source for platforms that have no objective signal (reactions).
     * For Facebook, reactions are used instead.
     *
     * Used only as an absolute last-resort fallback. Prefer the DB `sentiment`
     * column (Priority 1) or the cached reference-schema map (Priority 2).
     */
    private const KEYWORD_SENTIMENTS = [
        // Negative — disaster / crisis topics
        'banjir'     => 'negative',
        'bencana'    => 'negative',
        'gempa'      => 'negative',
        'kebakaran'  => 'negative',
        'longsor'    => 'negative',
        'tsunami'    => 'negative',
        'erupsi'     => 'negative',
        'kecelakaan' => 'negative',
        'korban'     => 'negative',
        'kriminal'   => 'negative',

        // Positive — development / economic topics
        'umkm'        => 'positive',
        'wisata'      => 'positive',
        'festival'    => 'positive',
        'juara'       => 'positive',
        'prestasi'    => 'positive',
        'investasi'   => 'positive',
        'inovasi'     => 'positive',
        'pembangunan' => 'positive',

        // Neutral — general informational topics
        'desa'         => 'neutral',
        'infrastruktur'=> 'neutral',
        'pendidikan'   => 'neutral',
        'kesehatan'    => 'neutral',
        'lingkungan'   => 'neutral',
        'cuaca'        => 'neutral',
        'bmkg'         => 'neutral',
    ];

    /**
     * The client schema used as the reference source for keyword→sentiment
     * mappings when the current client's schema has no sentiment column.
     */
    private const SENTIMENT_REFERENCE_SCHEMA = 'client_a';

    /**
     * @param  array<string, mixed>  $record  Raw DB row (metrics already decoded as array by PDO)
     * @return array<string, mixed>|null  null means skip this record
     */
    private function normalise(string $platform, array $record): ?array
    {
        $metrics = $record['metrics'] ?? [];
        if (is_string($metrics)) {
            $metrics = json_decode($metrics, true) ?? [];
        }
        if (! is_array($metrics)) {
            $metrics = [];
        }

        return match ($platform) {
            'facebook'  => $this->normaliseFacebook($record, $metrics),
            'instagram' => $this->normaliseInstagram($record, $metrics),
            'tiktok'    => $this->normaliseTikTok($record, $metrics),
            'twitter'   => $this->normaliseTwitter($record, $metrics),
            default     => $this->normaliseGeneric($platform, $record, $metrics),
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // Per-platform normalizers
    // ──────────────────────────────────────────────────────────────────────

    private function normaliseFacebook(array $record, array $m): array
    {
        $author    = is_array($m['author'] ?? null)
            ? (string) ($m['author']['name'] ?? '')
            : (string) ($m['author'] ?? '');

        $reactions = is_array($m['reactions'] ?? null) ? $m['reactions'] : [];
        $text      = (string) ($record['content'] ?? $m['message'] ?? '');

        preg_match_all('/#(\w+)/u', $text, $matches);

        return [
            'id'        => (string) ($record['post_id'] ?? $m['post_id'] ?? ''),
            'platform'  => 'facebook',
            'text'      => $text,
            'author'    => $author,
            'date'      => $this->parseDate($record['post_created_at'] ?? $record['created_at'] ?? null, $m['timestamp'] ?? null, 'unix'),
            'sentiment' => $this->resolveSentiment($record, $reactions),
            'region'    => $this->extractRegion($text),
            'likes'     => (int) ($reactions['like'] ?? $m['reactions_count'] ?? 0),
            'comments'  => (int) ($m['comments_count'] ?? 0),
            'shares'    => (int) ($m['reshare_count'] ?? 0),
            'views'     => 0,
            'hashtags'  => $matches[1] ?? [],
            'url'       => (string) ($m['url'] ?? ''),
            'type'      => (string) ($m['type'] ?? 'post'),
            'images'    => is_array($m['image'] ?? null) ? [$m['image']] : [],
        ];
    }

    private function normaliseInstagram(array $record, array $m): array
    {
        $hashtags = [];
        foreach ((array) ($m['hashtags'] ?? []) as $h) {
            $hashtags[] = is_array($h) ? (string) ($h['name'] ?? '') : ltrim((string) $h, '#');
        }
        $hashtags = array_values(array_filter($hashtags));

        $text = (string) ($record['content'] ?? $m['caption'] ?? '');

        return [
            'id'        => (string) ($record['post_id'] ?? $m['id'] ?? ''),
            'platform'  => 'instagram',
            'text'      => $text,
            'author'    => (string) ($m['ownerFullName'] ?? $m['ownerUsername'] ?? ''),
            'date'      => $this->parseDate($record['post_created_at'] ?? $record['created_at'] ?? null, $m['timestamp'] ?? null, 'iso'),
            'sentiment' => $this->resolveSentiment($record),
            'region'    => $this->extractRegion($text),
            'likes'     => (int) ($m['likesCount'] ?? 0),
            'comments'  => (int) ($m['commentsCount'] ?? 0),
            'shares'    => 0,
            'views'     => 0,
            'hashtags'  => $hashtags,
            'url'       => (string) ($m['url'] ?? ''),
            'type'      => strtolower((string) ($m['type'] ?? 'image')),
            'images'    => $m['displayUrl'] ?? null ? [$m['displayUrl']] : [],
        ];
    }

    private function normaliseTikTok(array $record, array $m): array
    {
        $hashtags = [];
        foreach ((array) ($m['hashtags'] ?? []) as $h) {
            $tag = is_array($h) ? (string) ($h['name'] ?? '') : ltrim((string) $h, '#');
            if ($tag !== '') {
                $hashtags[] = $tag;
            }
        }

        $author = is_array($m['authorMeta'] ?? null)
            ? (string) ($m['authorMeta']['nickName'] ?? $m['authorMeta']['name'] ?? '')
            : '';

        $text = (string) ($record['content'] ?? $m['text'] ?? '');

        return [
            'id'        => (string) ($record['post_id'] ?? $m['id'] ?? ''),
            'platform'  => 'tiktok',
            'text'      => $text,
            'author'    => $author,
            'date'      => $this->parseDate($record['post_created_at'] ?? $record['created_at'] ?? null, $m['createTimeISO'] ?? null, 'iso'),
            'sentiment' => $this->resolveSentiment($record),
            'region'    => $this->extractRegion($text),
            'likes'     => (int) ($m['diggCount'] ?? 0),
            'comments'  => (int) ($m['commentCount'] ?? 0),
            'shares'    => (int) ($m['shareCount'] ?? 0),
            'views'     => (int) ($m['playCount'] ?? 0),
            'hashtags'  => $hashtags,
            'url'       => (string) ($m['webVideoUrl'] ?? ''),
            'type'      => 'video',
            'images'    => [],
        ];
    }

    private function normaliseTwitter(array $record, array $m): array
    {
        $author = is_array($m['author'] ?? null)
            ? (string) ($m['author']['name'] ?? $m['author']['userName'] ?? '')
            : (string) ($m['author'] ?? '');

        $entities = is_array($m['entities'] ?? null) ? $m['entities'] : [];
        $hashtags = [];
        foreach ((array) ($entities['hashtags'] ?? []) as $h) {
            $tag = is_array($h) ? (string) ($h['text'] ?? $h['tag'] ?? '') : ltrim((string) $h, '#');
            if ($tag !== '') {
                $hashtags[] = $tag;
            }
        }

        $text = (string) ($record['content'] ?? $m['fullText'] ?? $m['text'] ?? '');

        // region: try place object first, then text
        $region = '';
        if (is_array($m['place'] ?? null) && ! empty($m['place'])) {
            $region = (string) ($m['place']['full_name'] ?? $m['place']['name'] ?? '');
        }
        if (empty($region)) {
            $region = $this->extractRegion($text);
        }

        return [
            'id'        => (string) ($record['post_id'] ?? $m['id'] ?? ''),
            'platform'  => 'twitter',
            'text'      => $text,
            'author'    => $author,
            'date'      => $this->parseDate($record['post_created_at'] ?? $record['created_at'] ?? null, $m['createdAt'] ?? null, 'rfc'),
            'sentiment' => $this->resolveSentiment($record),
            'region'    => $region,
            'likes'     => (int) ($m['likeCount'] ?? 0),
            'comments'  => (int) ($m['replyCount'] ?? 0),
            'shares'    => (int) ($m['retweetCount'] ?? 0),
            'views'     => (int) ($m['viewCount'] ?? 0),
            'hashtags'  => $hashtags,
            'url'       => (string) ($m['url'] ?? $m['twitterUrl'] ?? ''),
            'type'      => (string) ($m['type'] ?? 'tweet'),
            'images'    => (array) ($m['media'] ?? []),
        ];
    }

    /** Fallback for any unknown platform table. */
    private function normaliseGeneric(string $platform, array $record, array $m): array
    {
        $text = (string) ($record['content'] ?? $m['message'] ?? $m['text'] ?? $m['caption'] ?? '');
        preg_match_all('/#(\w+)/u', $text, $matches);

        return [
            'id'        => (string) ($record['post_id'] ?? $record['id'] ?? ''),
            'platform'  => $platform,
            'text'      => $text,
            'author'    => '',
            'date'      => $this->parseDate($record['post_created_at'] ?? $record['created_at'] ?? null, null, 'iso'),
            'sentiment' => $this->resolveSentiment($record),
            'region'    => $this->extractRegion($text),
            'likes'     => 0,
            'comments'  => 0,
            'shares'    => 0,
            'views'     => 0,
            'hashtags'  => $matches[1] ?? [],
            'url'       => (string) ($m['url'] ?? ''),
            'type'      => '',
            'images'    => [],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Sentiment helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Derive sentiment from the `keyword` column (the monitored search term).
     * This is the primary signal for non-Facebook platforms.
     */
    private function sentimentFromKeyword(string $keyword): string
    {
        return self::KEYWORD_SENTIMENTS[strtolower(trim($keyword))] ?? 'neutral';
    }

    /**
     * Derive sentiment from Facebook reactions — objective engagement signal.
     * angry + sad = negative, like + love + wow + care = positive.
     */
    private function sentimentFromReactions(array $reactions): string
    {
        $total = array_sum($reactions);
        if ($total === 0) {
            return 'neutral';
        }
        $neg = ($reactions['angry'] ?? 0) + ($reactions['sad'] ?? 0);
        $pos = ($reactions['like'] ?? 0) + ($reactions['love'] ?? 0)
             + ($reactions['wow'] ?? 0)  + ($reactions['care'] ?? 0);

        if ($neg > $total * 0.5) {
            return 'negative';
        }
        if ($pos > $total * 0.5) {
            return 'positive';
        }
        return 'neutral';
    }

    /**
     * Unified sentiment resolver — three-tier priority:
     *
     *  1. DB column  : `$record['sentiment']` when the client schema has the column.
     *  2. Cached map : keyword→sentiment loaded once from the reference schema (client_a)
     *                  and stored in the Laravel cache for 1 hour.
     *  3. Reactions  : Facebook-specific objective signal (passed via `$reactions`).
     *  4. Hardcoded  : KEYWORD_SENTIMENTS — absolute last resort.
     *
     * @param  array<string, mixed>  $record     Normalised DB row.
     * @param  array<string, int>|null  $reactions  Facebook reactions map, or null.
     */
    private function resolveSentiment(array $record, ?array $reactions = null): string
    {
        // Priority 1: DB column value (present when schema has the sentiment column)
        $dbSentiment = $record['sentiment'] ?? null;
        if (in_array($dbSentiment, ['positive', 'negative', 'neutral'], true)) {
            return $dbSentiment;
        }

        // Priority 2: Keyword map cached from the reference schema
        $keyword = strtolower(trim((string) ($record['keyword'] ?? '')));
        if ($keyword !== '') {
            $map = $this->getKeywordSentimentMap();
            if (isset($map[$keyword])) {
                return $map[$keyword];
            }
        }

        // Priority 3: Reactions signal (Facebook)
        if ($reactions !== null) {
            return $this->sentimentFromReactions($reactions);
        }

        // Priority 4: Hardcoded fallback
        return self::KEYWORD_SENTIMENTS[$keyword] ?? 'neutral';
    }

    /**
     * Build (and cache for 1 hour) a keyword→sentiment map loaded from all
     * platform tables in SENTIMENT_REFERENCE_SCHEMA that have a `sentiment`
     * column.  Used as fallback when the current client's schema has no
     * sentiment column yet.
     *
     * @return array<string, string>  e.g. ['banjir' => 'negative', ...]
     */
    private function getKeywordSentimentMap(): array
    {
        return Cache::store('file')->remember('cortex_keyword_sentiment_map', now()->addHour(), function () {
            $refSchema = self::SENTIMENT_REFERENCE_SCHEMA;
            $platforms = ['facebook', 'instagram', 'tiktok', 'twitter'];
            $map       = [];
            $conn      = $this->cortex->connection();

            foreach ($platforms as $platform) {
                $hasColumn = $conn->table('information_schema.columns')
                    ->where('table_schema', $refSchema)
                    ->where('table_name', $platform)
                    ->where('column_name', 'sentiment')
                    ->exists();

                if (! $hasColumn) {
                    continue;
                }

                $rows = $conn->table(DB::raw("\"{$refSchema}\".\"{$platform}\""))
                    ->select('keyword', 'sentiment')
                    ->whereNotNull('sentiment')
                    ->whereNotNull('keyword')
                    ->where('keyword', '!=', '')
                    ->distinct()
                    ->get();

                foreach ($rows as $row) {
                    $kw = strtolower(trim((string) $row->keyword));
                    if ($kw !== '' && ! isset($map[$kw])) {
                        $map[$kw] = $row->sentiment;
                    }
                }
            }

            return $map;
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Date parsing helper
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Parse a date from the DB `created_at` column (preferred) or from a
     * platform-specific timestamp field in metrics (fallback).
     *
     * @param  mixed   $dbDate    The `created_at` column value.
     * @param  mixed   $fallback  Platform-specific timestamp value.
     * @param  string  $format    'unix' (epoch), 'iso' (ISO-8601), 'rfc' (RFC-2822).
     */
    private function parseDate(mixed $dbDate, mixed $fallback, string $format): string
    {
        if ($dbDate) {
            try {
                return Carbon::parse($dbDate)->toDateString();
            } catch (\Exception) {}
        }

        if ($fallback === null) {
            return '2026-01-01';
        }

        try {
            return match ($format) {
                'unix' => date('Y-m-d', (int) $fallback),
                default => Carbon::parse($fallback)->toDateString(),
            };
        } catch (\Exception) {
            return '2026-01-01';
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Region extraction (same province list as legacy service)
    // ──────────────────────────────────────────────────────────────────────

    private function extractRegion(string $text): string
    {
        foreach (self::PROVINCES as $province) {
            if (stripos($text, $province) !== false) {
                return $province;
            }
        }

        return '';
    }
}
