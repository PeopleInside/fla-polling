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

/**
 * Secure controller for real-time polling - Simplified version
 */
class RealTimeCheckController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);

            // SECURITY: Only allow authenticated users
            if (!$actor->exists) {
                return new JsonResponse([
                    'error' => 'Unauthorized',
                    'latestDiscussionId' => 0,
                    'latestPostId' => 0,
                    'unreadNotifications' => 0
                ], 401);
            }

            // Get discussion ID from query string
            $queryParams = $request->getQueryParams();
            $discussionId = isset($queryParams['discussionId']) ? (int) $queryParams['discussionId'] : 0;

            // Get latest discussion ID (visible to user)
            $latestDiscussionId = 0;
            try {
                $latestDiscussionId = (int) Discussion::query()
                    ->whereVisibleTo($actor)
                    ->max('id');
            } catch (\Exception $e) {
                // Silent fail
            }

            // Get latest post ID
            $latestPostId = 0;
            try {
                if ($discussionId > 0) {
                    // Verify discussion exists and user can see it
                    $discussion = Discussion::where('id', $discussionId)
                        ->whereVisibleTo($actor)
                        ->first();
                    
                    if ($discussion) {
                        // Get latest post for THIS specific discussion
                        $latestPostId = (int) Post::where('discussion_id', $discussionId)
                            ->where('type', 'comment')
                            ->max('id');
                    }
                } else {
                    // Get latest post globally (only from visible discussions)
                    $visibleDiscussionIds = Discussion::whereVisibleTo($actor)
                        ->pluck('id')
                        ->toArray();
                    
                    if (!empty($visibleDiscussionIds)) {
                        $latestPostId = (int) Post::whereIn('discussion_id', $visibleDiscussionIds)
                            ->where('type', 'comment')
                            ->max('id');
                    }
                }
            } catch (\Exception $e) {
                // Silent fail
            }

            // Count unread notifications
            $unreadNotifications = 0;
            try {
                $unreadNotifications = (int) Notification::where('user_id', $actor->id)
                    ->whereNull('read_at')
                    ->count();
            } catch (\Exception $e) {
                // Silent fail
            }

            return new JsonResponse([
                'latestDiscussionId' => $latestDiscussionId,
                'latestPostId' => $latestPostId,
                'unreadNotifications' => $unreadNotifications,
                'timestamp' => time()
            ]);

        } catch (\Exception $e) {
            // Catch-all error handler
            error_log('FLA Polling Critical Error: ' . $e->getMessage());
            return new JsonResponse([
                'error' => 'Internal error',
                'latestDiscussionId' => 0,
                'latestPostId' => 0,
                'unreadNotifications' => 0
            ], 500);
        }
    }
}
