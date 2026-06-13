<?php

namespace PeopleInside\FlaPolling\Api;

use Flarum\Discussion\Discussion;
use Flarum\Notification\Notification;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;

/**
 * Controller that handles real-time polling requests
 * Returns the latest discussion ID and unread notification count
 */
class RealTimeCheckController implements RequestHandlerInterface
{
    /**
     * Handle the request and return a response
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        // Get the ID of the most recently created discussion
        $latestDiscussionId = 0;
        try {
            $latestDiscussionId = (int) Discussion::query()->max('id');
        } catch (\Exception $e) {
            // Silently fail
        }

        // Count unread notifications (only for logged-in users)
        $unreadNotifications = 0;
        if ($actor->exists) {
            try {
                $unreadNotifications = (int) Notification::query()
                    ->where('user_id', $actor->id)
                    ->whereNull('read_at')
                    ->count();
            } catch (\Exception $e) {
                // Silently fail
            }
        }

        // Return simple JSON response
        return new JsonResponse([
            'latestDiscussionId' => $latestDiscussionId,
            'unreadNotifications' => $unreadNotifications
        ]);
    }
}
