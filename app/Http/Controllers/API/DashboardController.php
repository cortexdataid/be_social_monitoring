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
     *             ))
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

        $filters = [
            'start_date' => $request->query('start_date'),
            'end_date'   => $request->query('end_date'),
            'keyword'    => $request->query('keyword') ? explode(',', $request->query('keyword')) : null,
            'platform'   => $request->query('platform'),
            'region'     => $request->query('region'),
        ];

        $allItems      = $this->service->getFilteredData($platforms, []);
        $filtered      = $this->service->getFilteredData($platforms, $filters);
        $stats         = $this->service->getStatistics($filtered);
        $filterOptions = $this->service->getAvailableFilters($allItems);

        return response()->json([
            'filters'              => $filterOptions,
            'net_sentiment'        => $stats['net_sentiment'],
            'sentiment_percentage' => $stats['sentiment_percentage'],
            'negative_words'       => $stats['negative_words'],
            'positive_words'       => $stats['positive_words'],
            'trend'                => $stats['trend'],
            'platform_sentiment'   => $stats['platform_sentiment'],
            'mention_by_platform'  => $stats['mention_by_platform'],
            'mention_by_media'     => $stats['mention_by_media'],
            'mention_by_province'  => $stats['mention_by_province'],
            'top_topics'           => $stats['top_topics'],
            'engagement'           => $stats['engagement'],
            'table'                => $filtered,
        ]);
    }
}

