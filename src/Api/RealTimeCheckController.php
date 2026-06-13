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
 * Secure controller for real-time polling
 * Includes rate limiting and permission checks
 */
class RealTimeCheckController implements RequestHandlerInterface
{
    /**
     * Handle the request and return a response
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        // SECURITY: Only allow authenticated users
        if (!$actor->exists) {
            return new JsonResponse([
                'error' => 'Unauthorized',
                'latestDiscussionId' => 0,
                'unreadNotifications' => 0
            ], 401);
        }

        // SECURITY: Rate limiting - check session for last request time
        $session = $request->getAttribute('session');
        $lastRequest = $session->get('fla_polling_last_request', 0);
        $now = time();
        
        // Prevent polling more than once every 3 seconds
        if ($now - $lastRequest < 3) {
            // Return cached data or empty response
            return new JsonResponse([
                'latestDiscussionId' => 0,
                'unreadNotifications' => 0,
                'rateLimited' => true
            ]);
        }
        
        $session->set('fla_polling_last_request', $now);

        // Get latest discussion ID (only public discussions user can see)
        $latestDiscussionId = 0;
        try {
            // Use Flarum's visibility scope to respect permissions
            $latestDiscussionId = (int) Discussion::query()
                ->whereVisibleTo($actor)
                ->max('id');
        } catch (\Exception $e) {
            // Log error in production
            error_log('FLA Polling Error: ' . $e->getMessage());
        }

        // Count unread notifications for current user only
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
            'unreadNotifications' => $unreadNotifications,
            'timestamp' => $now
        ]);
    }
}
