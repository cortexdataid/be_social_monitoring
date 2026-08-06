<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\CortexConnectionService;
use App\Services\CortexDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CortexConnectionService $cortex,
        private readonly CortexDashboardService  $service,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/dashboard",
     *     summary="Get dashboard data with optional filters",
     *     tags={"Dashboard"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="start_date", in="query", required=false, @OA\Schema(type="string", format="date"), description="Filter from date (YYYY-MM-DD)"),
     *     @OA\Parameter(name="end_date", in="query", required=false, @OA\Schema(type="string", format="date"), description="Filter to date (YYYY-MM-DD)"),
     *     @OA\Parameter(name="keyword", in="query", required=false, @OA\Schema(type="string"), description="Search keyword in content"),
     *     @OA\Parameter(name="platform", in="query", required=false, @OA\Schema(type="string", enum={"facebook","instagram","tiktok","twitter"}), description="Filter by platform"),
     *     @OA\Parameter(name="region", in="query", required=false, @OA\Schema(type="string"), description="Filter by region"),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer", default=1), description="Page of the `table` result set"),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=50, maximum=200), description="Rows per page, capped at 200"),
     *     @OA\Parameter(name="sort_by", in="query", required=false, @OA\Schema(type="string", enum={"date","platform","region","sentiment","author","likes","comments","shares","views"}), description="Sort column for `table` (default: date)"),
     *     @OA\Parameter(name="sort_dir", in="query", required=false, @OA\Schema(type="string", enum={"asc","desc"}, default="desc"), description="Sort direction for `table`"),
     *     @OA\Response(
     *         response=200,
     *         description="Dashboard data",
     *         @OA\JsonContent(
     *             @OA\Property(property="filters", type="object",
     *                 @OA\Property(property="platforms", type="array", @OA\Items(type="string")),
     *                 @OA\Property(property="regions", type="array", @OA\Items(type="string"))
     *             ),
     *             @OA\Property(property="net_sentiment", type="number", example=42.5),
     *             @OA\Property(property="sentiment_percentage", type="object",
     *                 @OA\Property(property="positive", type="number"),
     *                 @OA\Property(property="neutral", type="number"),
     *                 @OA\Property(property="negative", type="number")
     *             ),
     *             @OA\Property(property="negative_words", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="word", type="string"),
     *                 @OA\Property(property="count", type="integer")
     *             )),
     *             @OA\Property(property="positive_words", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="word", type="string"),
     *                 @OA\Property(property="count", type="integer")
     *             )),
     *             @OA\Property(property="trend", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="date", type="string", format="date"),
     *                 @OA\Property(property="positive", type="integer"),
     *                 @OA\Property(property="neutral", type="integer"),
     *                 @OA\Property(property="negative", type="integer"),
     *                 @OA\Property(property="total", type="integer")
     *             )),
     *             @OA\Property(property="trend_by_platform", type="array", description="Mentions per date and platform, for the multi-line trend chart", @OA\Items(type="object",
     *                 @OA\Property(property="date", type="string", format="date"),
     *                 @OA\Property(property="platform", type="string"),
     *                 @OA\Property(property="total", type="integer")
     *             )),
     *             @OA\Property(property="platform_sentiment", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="platform", type="string"),
     *                 @OA\Property(property="positive", type="integer"),
     *                 @OA\Property(property="neutral", type="integer"),
     *                 @OA\Property(property="negative", type="integer"),
     *                 @OA\Property(property="total", type="integer")
     *             )),
     *             @OA\Property(property="mention_by_platform", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="platform", type="string"),
     *                 @OA\Property(property="count", type="integer")
     *             )),
     *             @OA\Property(property="mention_by_media", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="media", type="string"),
     *                 @OA\Property(property="count", type="integer")
     *             )),
     *             @OA\Property(property="mention_by_province", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="province", type="string"),
     *                 @OA\Property(property="count", type="integer")
     *             )),
     *             @OA\Property(property="top_topics", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="topic", type="string"),
     *                 @OA\Property(property="count", type="integer")
     *             )),
     *             @OA\Property(property="engagement", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="platform", type="string"),
     *                 @OA\Property(property="likes", type="integer"),
     *                 @OA\Property(property="comments", type="integer"),
     *                 @OA\Property(property="shares", type="integer"),
     *                 @OA\Property(property="views", type="integer"),
     *                 @OA\Property(property="total", type="integer")
     *             )),
     *             @OA\Property(property="table", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="id", type="string"),
     *                 @OA\Property(property="platform", type="string"),
     *                 @OA\Property(property="text", type="string"),
     *                 @OA\Property(property="author", type="string"),
     *                 @OA\Property(property="date", type="string", format="date"),
     *                 @OA\Property(property="sentiment", type="string", enum={"positive","neutral","negative"}),
     *                 @OA\Property(property="region", type="string"),
     *                 @OA\Property(property="likes", type="integer"),
     *                 @OA\Property(property="comments", type="integer"),
     *                 @OA\Property(property="shares", type="integer"),
     *                 @OA\Property(property="views", type="integer"),
     *                 @OA\Property(property="hashtags", type="array", @OA\Items(type="string")),
     *                 @OA\Property(property="url", type="string")
     *             )),
     *             @OA\Property(property="meta", type="object", description="Pagination state for `table`",
     *                 @OA\Property(property="total", type="integer"),
     *                 @OA\Property(property="page", type="integer"),
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="pages", type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Point the cortex connection at this user's schema.
        // The username doubles as the PostgreSQL schema name.
        $this->cortex->setSchema($user->name);

        // Discover which platform tables actually exist for this client.
        $platforms = $this->cortex->availablePlatforms();

        if (empty($platforms)) {
            return response()->json([
                'message' => 'No platform data available for this account.',
                'filters' => ['platforms' => [], 'regions' => []],
            ]);
        }

        $filters       = $this->filtersFrom($request);
        $stats         = $this->service->getAggregates($platforms, $filters);
        $filterOptions = $this->service->getFilterOptions($platforms);

        $page = $this->service->getTablePage(
            $platforms,
            $filters,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 50),
            $request->query('sort_by'),
            (string) $request->query('sort_dir', 'desc'),
            $stats['total'],
        );

        return response()->json([
            'filters'              => $filterOptions,
            'net_sentiment'        => $stats['net_sentiment'],
            'sentiment_percentage' => $stats['sentiment_percentage'],
            'negative_words'       => $stats['negative_words'],
            'positive_words'       => $stats['positive_words'],
            'trend'                => $stats['trend'],
            'trend_by_platform'    => $stats['trend_by_platform'],
            'platform_sentiment'   => $stats['platform_sentiment'],
            'mention_by_platform'  => $stats['mention_by_platform'],
            'mention_by_media'     => $stats['mention_by_media'],
            'mention_by_province'  => $stats['mention_by_province'],
            'top_topics'           => $stats['top_topics'],
            'engagement'           => $stats['engagement'],
            'table'                => $page['data'],
            'meta'                 => $page['meta'],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/dashboard/export",
     *     summary="Every row matching the current filters, for report download",
     *     description="Same filters as /api/dashboard but without pagination. Kept as its own endpoint so the dashboard itself can stay paginated — do not call it to render the table.",
     *     tags={"Dashboard"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="start_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="keyword", in="query", required=false, @OA\Schema(type="string"), description="Comma-separated keywords"),
     *     @OA\Parameter(name="platform", in="query", required=false, @OA\Schema(type="string", enum={"facebook","instagram","tiktok","twitter"})),
     *     @OA\Parameter(name="region", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="All matching rows",
     *         @OA\JsonContent(
     *             @OA\Property(property="table", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->cortex->setSchema($user->name);

        $platforms = $this->cortex->availablePlatforms();

        if (empty($platforms)) {
            return response()->json(['table' => [], 'total' => 0]);
        }

        $rows = $this->service->getFilteredData($platforms, $this->filtersFrom($request));

        return response()->json([
            'table' => $rows,
            'total' => count($rows),
        ]);
    }

    /**
     * Read the shared filter set off the query string.
     *
     * @return array{start_date:string|null, end_date:string|null, keyword:array<string>|null, platform:string|null, region:string|null}
     */
    private function filtersFrom(Request $request): array
    {
        return [
            'start_date' => $request->query('start_date'),
            'end_date'   => $request->query('end_date'),
            'keyword'    => $request->query('keyword') ? explode(',', $request->query('keyword')) : null,
            'platform'   => $request->query('platform'),
            'region'     => $request->query('region'),
        ];
    }
}
