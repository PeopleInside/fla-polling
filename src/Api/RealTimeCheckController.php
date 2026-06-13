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
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            if (!$actor->exists) {
                return new JsonResponse(['latestDiscussionId' => 0, 'latestPostId' => 0, 'unreadNotifications' => 0], 401);
            }

            $queryParams = $request->getQueryParams();
            $discussionId = isset($queryParams['discussionId']) ? (int) $queryParams['discussionId'] : 0;

            $latestDiscussionId = 0;
            try { $latestDiscussionId = (int) Discussion::query()->whereVisibleTo($actor)->max('id'); } catch (\Exception $e) {}

            $latestPostId = 0;
            try {
                if ($discussionId > 0) {
                    $discussion = Discussion::where('id', $discussionId)->whereVisibleTo($actor)->first();
                    if ($discussion) {
                        $latestPostId = (int) Post::where('discussion_id', $discussionId)->where('type', 'comment')->max('id');
                    }
                } else {
                    $latestPostId = (int) Post::where('type', 'comment')
                        ->whereIn('discussion_id', function($query) use ($actor) {
                            $query->select('id')->from('discussions')->whereVisibleTo($actor);
                        })->max('id');
                }
            } catch (\Exception $e) {}

            $unreadNotifications = 0;
            try { $unreadNotifications = (int) Notification::where('user_id', $actor->id)->whereNull('read_at')->count(); } catch (\Exception $e) {}

            return new JsonResponse([
                'latestDiscussionId' => $latestDiscussionId,
                'latestPostId' => $latestPostId,
                'unreadNotifications' => $unreadNotifications
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['latestDiscussionId' => 0, 'latestPostId' => 0, 'unreadNotifications' => 0], 500);
        }
    }
}
