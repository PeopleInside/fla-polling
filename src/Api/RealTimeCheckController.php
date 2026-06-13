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
 * Secure real-time polling controller for Flarum 2.0
 * - Scoped post queries per discussion
 * - Rate limiting per session
 * - Visibility scope enforcement
 */
class RealTimeCheckController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        // SECURITY: Reject unauthenticated requests
        if (!$actor->exists) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // SECURITY: Rate limiting (max 1 request every 3 seconds)
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

        // Global latest discussion ID (visible to actor)
        $latestDiscussionId = 0;
        try {
            $latestDiscussionId = (int) Discussion::query()
                ->whereVisibleTo($actor)
                ->max('id');
        } catch (\Exception $e) {
            error_log('FLA Polling Discussion Error: ' . $e->getMessage());
        }

        // Context-aware latest post ID
        $latestPostId = 0;
        $params = $request->getQueryParams();
        $discussionId = isset($params['discussion_id']) ? (int) $params['discussion_id'] : 0;

        if ($discussionId > 0) {
            try {
                // Verify user can actually view this discussion
                $discussion = Discussion::find($discussionId);
                if ($discussion && $discussion->isVisibleTo($actor)) {
                    $latestPostId = (int) Post::query()
                        ->where('discussion_id', $discussionId)
                        ->where('type', 'comment') // Exclude system posts
                        ->whereVisibleTo($actor)
                        ->max('id');
                }
            } catch (\Exception $e) {
                error_log('FLA Polling Post Error: ' . $e->getMessage());
            }
        }

        // Unread notifications count
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
            'unreadNotifications' => $unreadNotifications
        ]);
    }
}
