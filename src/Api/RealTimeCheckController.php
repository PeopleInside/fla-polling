<?php

namespace PeopleInside\FlaPolling\Api;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Notification\Notification;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class RealTimeCheckController implements RequestHandlerInterface
{
    /**
     * @var CacheRepository
     */
    protected $cache;

    /**
     * Rate limiting settings
     */
    private const RATE_LIMIT_MAX_REQUESTS = 100;
    private const RATE_LIMIT_WINDOW = 60; // seconds

    /**
     * Constructor injection for required dependencies
     */
    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            
            // SECURITY: Only allow authenticated users
            if (!$actor->exists) {
                return new JsonResponse(['latestDiscussionId' => 0, 'latestPostId' => 0, 'unreadNotifications' => 0], 401);
            }

            // SECURITY: Rate limiting per user (Fixed window)
            if (!$this->checkRateLimit($actor->id)) {
                return new JsonResponse(['error' => 'Too many requests'], 429);
            }

            // SECURITY: Validate and sanitize discussionId parameter
            $queryParams = $request->getQueryParams();
            $discussionId = 0;
            if (isset($queryParams['discussionId'])) {
                $discussionId = filter_var($queryParams['discussionId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($discussionId === false) {
                    $discussionId = 0;
                }
            }

            $latestDiscussionId = 0;
            try { 
                $latestDiscussionId = (int) Discussion::query()->whereVisibleTo($actor)->max('id'); 
            } catch (\Exception $e) {
                error_log('FLA Polling Discussion Error: ' . $e->getMessage());
            }

            $latestPostId = 0;
            try {
                if ($discussionId > 0) {
                    // SECURITY: Verify user can see this specific discussion before checking posts
                    $discussion = Discussion::where('id', $discussionId)->whereVisibleTo($actor)->first();
                    if ($discussion) {
                        $latestPostId = (int) Post::where('discussion_id', $discussionId)
                            ->where('type', 'comment')
                            ->max('id');
                    }
                } else {
                    // PERFORMANCE FIXED: Avoid unbounded pluck('id')->all() which causes memory exhaustion on larger forums.
                    // Instead, use an efficient SQL subquery. Safe, index-optimized, and database-native.
                    $discussionQuery = Discussion::query()->whereVisibleTo($actor)->select('id');
                    $latestPostId = (int) Post::where('type', 'comment')
                        ->whereIn('discussion_id', $discussionQuery)
                        ->max('id');
                }
            } catch (\Exception $e) {
                error_log('FLA Polling Post Error: ' . $e->getMessage());
            }

            $unreadNotifications = 0;
            try { 
                $unreadNotifications = (int) Notification::where('user_id', $actor->id)->whereNull('read_at')->count(); 
            } catch (\Exception $e) {
                error_log('FLA Polling Notification Error: ' . $e->getMessage());
            }

            return new JsonResponse([
                'latestDiscussionId' => $latestDiscussionId,
                'latestPostId' => $latestPostId,
                'unreadNotifications' => $unreadNotifications
            ]);
        } catch (\Exception $e) {
            error_log('FLA Polling Critical Error: ' . $e->getMessage());
            return new JsonResponse(['latestDiscussionId' => 0, 'latestPostId' => 0, 'unreadNotifications' => 0], 500);
        }
    }

    /**
     * Check rate limit for user (Fixed-window implementation)
     * Resets window timestamp relative to the absolute start of the block, preventing TTL slides.
     */
    private function checkRateLimit(int $userId): bool
    {
        try {
            $cacheKey = 'fla_polling_rate_' . $userId;
            $cacheKeyTime = $cacheKey . '_time';
            
            $firstRequestTime = $this->cache->get($cacheKeyTime);
            
            if (!$firstRequestTime) {
                // First request in this window. Set initial limit and expiry timestamp.
                $this->cache->put($cacheKey, 1, self::RATE_LIMIT_WINDOW);
                $this->cache->put($cacheKeyTime, time(), self::RATE_LIMIT_WINDOW);
                return true;
            }
            
            $elapsed = time() - (int) $firstRequestTime;
            $timeLeft = self::RATE_LIMIT_WINDOW - $elapsed;
            
            if ($timeLeft <= 0) {
                // Window has fully elapsed. Start a fresh rate limit cycle.
                $this->cache->put($cacheKey, 1, self::RATE_LIMIT_WINDOW);
                $this->cache->put($cacheKeyTime, time(), self::RATE_LIMIT_WINDOW);
                return true;
            }
            
            $requestCount = (int) $this->cache->get($cacheKey, 0);
            
            if ($requestCount >= self::RATE_LIMIT_MAX_REQUESTS) {
                return false;
            }
            
            // Increment the request count without sliding the expiration window key.
            $this->cache->put($cacheKey, $requestCount + 1, $timeLeft);
            return true;
        } catch (\Exception $e) {
            // If cache fails, fail open to prevent blocking forum activity.
            return true;
        }
    }
}
