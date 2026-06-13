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
 * Secure controller for real-time polling
 * Checks for new discussions, new posts in specific discussion, and notifications
 */
class RealTimeCheckController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
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

        // SECURITY: Rate limiting
        $session = $request->getAttribute('session');
        $lastRequest = $session->get('fla_polling_last_request', 0);
        $now = time();
        
        if ($now - $lastRequest < 3) {
            return new JsonResponse([
                'latestDiscussionId' => 0,
                'latestPostId' => 0,
                'unreadNotifications' => 0,
                'rateLimited' => true
            ]);
        }
        
        $session->set('fla_polling_last_request', $now);

        // Get optional discussion ID from query params
        $queryParams = $request->getQueryParams();
        $discussionId = isset($queryParams['discussionId']) ? (int) $queryParams['discussionId'] : 0;

        // Get latest discussion ID (visible to user)
        $latestDiscussionId = 0;
        try {
            $latestDiscussionId = (int) Discussion::query()
                ->whereVisibleTo($actor)
                ->max('id');
        } catch (\Exception $e) {
            error_log('FLA Polling Error: ' . $e->getMessage());
        }

        // Get latest post ID
        $latestPostId = 0;
        try {
            if ($discussionId > 0) {
                // SECURITY: Verify user can see this discussion
                $discussion = Discussion::find($discussionId);
                if ($discussion && $discussion->isVisibleTo($actor)) {
                    // Get latest post for THIS specific discussion only
                    $latestPostId = (int) Post::query()
                        ->where('discussion_id', $discussionId)
                        ->where('type', 'comment')
                        ->max('id');
                }
            } else {
                // Get latest post globally (for discussion list view)
                $latestPostId = (int) Post::query()
                    ->where('type', 'comment')
                    ->whereHas('discussion', function ($query) use ($actor) {
                        $query->whereVisibleTo($actor);
                    })
                    ->max('id');
            }
        } catch (\Exception $e) {
            error_log('FLA Polling Post Error: ' . $e->getMessage());
        }

        // Count unread notifications
        $unreadNotifications = 0;
        try {
            $unreadNotifications = (int) Notification::query()
                ->where('user_id', $actor->id)
                ->whereNull('read_at')
                ->count();
        } catch (\Exception $e) {
            error_log('FLA Polling Notification Error: ' . $e->getMessage());
        }

        return new JsonResponse([
            'latestDiscussionId' => $latestDiscussionId,
            'latestPostId' => $latestPostId,
            'unreadNotifications' => $unreadNotifications,
            'timestamp' => $now
        ]);
    }
}
