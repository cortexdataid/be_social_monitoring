<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
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
    public function __construct(private readonly CortexConnectionService $cortex) {}

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

            // Date range filter on post_created_at (actual publish date)
            if (! empty($filters['start_date'])) {
                $query->whereDate('post_created_at', '>=', $filters['start_date']);
            }
            if (! empty($filters['end_date'])) {
                $query->whereDate('post_created_at', '<=', $filters['end_date']);
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

            $rawRows = $query->get();

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
     * Compute aggregated statistics from a collection of normalised rows.
     * The shape mirrors what DashboardDataService::getStatistics() returns.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function getStatistics(array $items): array
    {
        $collection = collect($items);

        $total    = $collection->count();
        $positive = $collection->where('sentiment', 'positive')->count();
        $negative = $collection->where('sentiment', 'negative')->count();
        $neutral  = $total - $positive - $negative;

        $netSentiment = $total > 0 ? round((($positive - $negative) / $total) * 100, 2) : 0;

        return [
            'net_sentiment'        => $netSentiment,
            'sentiment_percentage' => [
                'positive' => $total > 0 ? round($positive / $total * 100, 2) : 0,
                'neutral'  => $total > 0 ? round($neutral  / $total * 100, 2) : 0,
                'negative' => $total > 0 ? round($negative / $total * 100, 2) : 0,
            ],
            'negative_words'       => $this->topWords($collection->where('sentiment', 'negative')),
            'positive_words'       => $this->topWords($collection->where('sentiment', 'positive')),
            'trend'                => $this->buildTrend($collection),
            'platform_sentiment'   => $this->buildPlatformSentiment($collection),
            'mention_by_platform'  => $this->buildMentionByPlatform($collection),
            'mention_by_media'     => $this->buildMentionByMedia($collection),
            'mention_by_province'  => $this->buildMentionByProvince($collection),
            'top_topics'           => $this->buildTopTopics($collection),
            'engagement'           => $this->buildEngagement($collection),
        ];
    }

    /**
     * Return the union of all distinct filter option values across all rows.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{platforms: array<string>, regions: array<string>, keywords: array<string>}
     */
    public function getAvailableFilters(array $items): array
    {
        $collection = collect($items);

        return [
            'platforms' => $collection->pluck('platform')->unique()->values()->all(),
            'regions'   => $collection->pluck('region')->filter()->unique()->values()->all(),
            'keywords'   => $this->getDistinctKeywords(),
        ];
    }

    /**
     * Fetch distinct keywords from all platform tables.
     *
     * @return array<string>
     */
    public function getDistinctKeywords(): array
    {
        $platforms = $this->cortex->availablePlatforms();
        $keywords = [];

        foreach ($platforms as $platform) {
            $rows = $this->cortex->table($platform)
                ->select('keyword')
                ->whereNotNull('keyword')
                ->where('keyword', '!=', '')
                ->distinct()
                ->orderBy('keyword', 'asc')
                ->get();

            foreach ($rows as $row) {
                $kw = trim((string) $row->keyword);
                if ($kw !== '' && ! in_array($kw, $keywords, true)) {
                    $keywords[] = $kw;
                }
            }
        }

        return $keywords;
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
    // Aggregation helpers
    // ──────────────────────────────────────────────────────────────────────

    private function topWords(Collection $items, int $limit = 10): array
    {
        $words = [];
        foreach ($items as $item) {
            $text = strtolower((string) ($item['text'] ?? ''));
            foreach (preg_split('/\s+/', $text) as $word) {
                $word = preg_replace('/[^a-z0-9]/u', '', $word);
                if (strlen($word) > 3) {
                    $words[$word] = ($words[$word] ?? 0) + 1;
                }
            }
        }
        arsort($words);
        $top = array_slice($words, 0, $limit, true);
        return array_map(fn ($w, $c) => ['word' => $w, 'count' => $c], array_keys($top), $top);
    }

    private function buildTrend(Collection $items): array
    {
        $grouped = $items->groupBy('date');
        $trend   = [];
        foreach ($grouped->sortKeys() as $date => $group) {
            $trend[] = [
                'date'     => $date,
                'positive' => $group->where('sentiment', 'positive')->count(),
                'neutral'  => $group->where('sentiment', 'neutral')->count(),
                'negative' => $group->where('sentiment', 'negative')->count(),
                'total'    => $group->count(),
            ];
        }
        return $trend;
    }

    private function buildPlatformSentiment(Collection $items): array
    {
        return $items->groupBy('platform')->map(fn ($g, $platform) => [
            'platform' => $platform,
            'positive' => $g->where('sentiment', 'positive')->count(),
            'neutral'  => $g->where('sentiment', 'neutral')->count(),
            'negative' => $g->where('sentiment', 'negative')->count(),
            'total'    => $g->count(),
        ])->values()->all();
    }

    private function buildMentionByPlatform(Collection $items): array
    {
        return $items->groupBy('platform')->map(fn ($g, $p) => [
            'platform' => $p,
            'count'    => $g->count(),
        ])->values()->all();
    }

    private function buildMentionByMedia(Collection $items): array
    {
        return $items->groupBy('type')->map(fn ($g, $type) => [
            'media' => $type ?: 'unknown',
            'count' => $g->count(),
        ])->values()->all();
    }

    private function buildMentionByProvince(Collection $items): array
    {
        return $items->filter(fn ($i) => ! empty($i['region']))
            ->groupBy('region')
            ->map(fn ($g, $region) => ['province' => $region, 'count' => $g->count()])
            ->values()->all();
    }

    private function buildTopTopics(Collection $items, int $limit = 10): array
    {
        $topics = [];
        foreach ($items as $item) {
            foreach ((array) ($item['hashtags'] ?? []) as $tag) {
                $t = strtolower((string) $tag);
                if ($t !== '') {
                    $topics[$t] = ($topics[$t] ?? 0) + 1;
                }
            }
        }
        arsort($topics);
        $top = array_slice($topics, 0, $limit, true);
        return array_map(fn ($t, $c) => ['topic' => $t, 'count' => $c], array_keys($top), $top);
    }

    private function buildEngagement(Collection $items): array
    {
        return $items->groupBy('platform')->map(fn ($g, $platform) => [
            'platform' => $platform,
            'likes'    => $g->sum('likes'),
            'comments' => $g->sum('comments'),
            'shares'   => $g->sum('shares'),
            'views'    => $g->sum('views'),
            'total'    => $g->sum('likes') + $g->sum('comments') + $g->sum('shares') + $g->sum('views'),
        ])->values()->all();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Region extraction (same province list as legacy service)
    // ──────────────────────────────────────────────────────────────────────

    private function extractRegion(string $text): string
    {
        static $provinces = [
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

        foreach ($provinces as $province) {
            if (stripos($text, $province) !== false) {
                return $province;
            }
        }

        return '';
    }
}
