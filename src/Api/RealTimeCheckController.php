<?php

namespace PeopleInside\FlaPolling\Api;

use Flarum\Api\Controller\AbstractSerializeController;
use Flarum\Discussion\Discussion;
use Flarum\Notification\Notification;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * Controller that handles real-time polling requests
 * Returns the latest discussion ID and unread notification count
 */
class RealTimeCheckController extends AbstractSerializeController
{
    /**
     * {@inheritdoc}
     */
    public $serializer = 'PeopleInside\FlaPolling\Api\Serializers\RealTimeSerializer';

    /**
     * {@inheritdoc}
     */
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);

        // Get the ID of the most recently created discussion
        $latestDiscussionId = Discussion::query()->max('id') ?? 0;

        // Count unread notifications (only for logged-in users)
        $unreadNotifications = 0;
        if ($actor->exists) {
            $unreadNotifications = Notification::query()
                ->where('user_id', $actor->id)
                ->whereNull('read_at')
                ->count();
        }

        // Return data as an array
        return [
            'latestDiscussionId' => $latestDiscussionId,
            'unreadNotifications' => $unreadNotifications
        ];
    }
}
