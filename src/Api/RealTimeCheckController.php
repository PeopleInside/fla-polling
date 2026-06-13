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

class RealTimeCheckController implements RequestHandlerInterface
{
    /**
     * Rate limiting: max requests per minute per user
     */
    private const RATE_LIMIT_MAX_REQUESTS = 20;
    private const RATE_LIMIT_WINDOW = 60; // seconds

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            
            // SECURITY: Only allow authenticated users
            if (!$actor->exists) {
                return new JsonResponse(['latestDiscussionId' => 0, 'latestPostId' => 0, 'unreadNotifications' => 0], 401);
            }

            // SECURITY: Rate limiting per user
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
                    // SECURITY: Verify user can see this discussion
                    $discussion = Discussion::where('id', $discussionId)->whereVisibleTo($actor)->first();
                    if ($discussion) {
                        $latestPostId = (int) Post::where('discussion_id', $discussionId)
                            ->where('type', 'comment')
                            ->max('id');
                    }
                } else {
                    // List view: get latest post from visible discussions
                    $visibleDiscussionIds = Discussion::whereVisibleTo($actor)->pluck('id')->all();
                    
                    if (!empty($visibleDiscussionIds)) {
                        $latestPostId = (int) Post::whereIn('discussion_id', $visibleDiscussionIds)
                            ->where('type', 'comment')
                            ->max('id');
                    }
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
     * Check rate limit for user
     * Uses cache to track requests per minute
     */
    private function checkRateLimit(int $userId): bool
    {
        try {
            $cache = resolve('cache');
            $cacheKey = 'fla_polling_rate_' . $userId;
            
            $requestCount = (int) $cache->get($cacheKey, 0);
            
            if ($requestCount >= self::RATE_LIMIT_MAX_REQUESTS) {
                return false;
            }
            
            $cache->put($cacheKey, $requestCount + 1, self::RATE_LIMIT_WINDOW);
            return true;
        } catch (\Exception $e) {
            // If cache fails, allow request (fail open)
            return true;
        }
    }
}
