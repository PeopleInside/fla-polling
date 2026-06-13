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
use Illuminate\Support\Arr;

/**
 * Secure controller for real-time polling
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
            error_log('FLA Polling Discussion Error: ' . $e->getMessage());
        }

        // Get latest post ID
        $latestPostId = 0;
        try {
            if ($discussionId > 0) {
                // SECURITY: Verify discussion exists and user can see it
                $discussion = Discussion::query()
                    ->whereVisibleTo($actor)
                    ->find($discussionId);
                
                if ($discussion) {
                    // Get latest post for THIS specific discussion
                    $latestPostId = (int) Post::query()
                        ->where('discussion_id', $discussionId)
                        ->where('type', 'comment')
                        ->max('id');
                }
            } else {
                // Get latest post globally (only from visible discussions)
                $latestPostId = (int) Post::query()
                    ->where('type', 'comment')
                    ->whereIn('discussion_id', function($query) use ($actor) {
                        $query->select('id')
                            ->from('discussions')
                            ->whereVisibleTo($actor);
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
