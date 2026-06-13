<?php

namespace PeopleInside\FlaPolling\Api\Serializers;

use Flarum\Api\Serializer\AbstractSerializer;

/**
 * Serializer for real-time check data
 * Converts the data array into JSON:API format
 */
class RealTimeSerializer extends AbstractSerializer
{
    /**
     * {@inheritdoc}
     */
    protected $type = 'realtime-check';

    /**
     * {@inheritdoc}
     */
    protected function getDefaultAttributes($data)
    {
        return [
            'latestDiscussionId' => $data['latestDiscussionId'],
            'unreadNotifications' => $data['unreadNotifications']
        ];
    }
}
