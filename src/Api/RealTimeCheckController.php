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
 * Checks for new discussions, new posts, and notifications
 */
class RealTimeCheckController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        // Only allow authenticated users
        if (!$actor->exists) {
            return new JsonResponse([
                'error' => 'Unauthorized',
                'latestDiscussionId' => 0,
                'latestPostId' => 0,
                'unreadNotifications' => 0
            ], 401);
        }

        // Rate limiting
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

        // Get latest discussion ID (visible to user)
        $latestDiscussionId = 0;
        try {
            $latestDiscussionId = (int) Discussion::query()
                ->whereVisibleTo($actor)
                ->max('id');
        } catch (\Exception $e) {
            error_log('FLA Polling Error: ' . $e->getMessage());
        }

        // Get latest post ID (visible to user)
        $latestPostId = 0;
        try {
            $latestPostId = (int) Post::query()
                ->where('type', 'comment') // Only count comment posts, not events
                ->whereHas('discussion', function ($query) use ($actor) {
                    $query->whereVisibleTo($actor);
                })
                ->max('id');
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
