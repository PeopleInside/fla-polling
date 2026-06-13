<?php

/*
 * FLA Polling - Lightweight real-time polling for Flarum 2.0
 * 
 * This extension provides real-time updates for new discussions and notifications
 * using HTTP polling instead of WebSockets. No SSH or Node.js required.
 * 
 * Supports multiple languages: English and Italian
 */

namespace PeopleInside\FlaPolling;

use Flarum\Extend;
use PeopleInside\FlaPolling\Api\RealTimeCheckController;

return [
    // Register custom API route for polling checks
    (new Extend\Routes('api'))
        ->get('/realtime-check', 'realtime.check', RealTimeCheckController::class),
    
    // Inject CSS into the forum frontend
    (new Extend\Frontend('forum'))
        ->css(__DIR__.'/resources/less/extension.less'),
    
    // Inject JavaScript inline into the forum body (bypasses webpack module system)
    (new Extend\Frontend('forum'))
        ->body(function (\Flarum\Frontend\Document $document) {
            $document->payload['flaPolling'] = [
                'enabled' => true,
                'apiUrl' => '/api/realtime-check'
            ];
        }),
    
    // Load language translations
    (new Extend\Locales(__DIR__.'/locale')),
    
    // Add inline JavaScript via a view
    (new Extend\View())
        ->namespace('fla-polling', __DIR__.'/resources/views'),
    
    // Inject the script tag into the frontend
    (new Extend\Frontend('forum'))
        ->content(function (\Flarum\Frontend\Document $document) {
            $document->head[] = '<script id="fla-polling-init" data-api-url="/api/realtime-check"></script>';
        }),
];
