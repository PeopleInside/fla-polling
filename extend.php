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
    
    // Inject JavaScript into the forum frontend
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/extension.js')
        ->css(__DIR__.'/resources/less/extension.less'),
    
    // Load language translations
    (new Extend\Locales(__DIR__.'/locale')),
];
