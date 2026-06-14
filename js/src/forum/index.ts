import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';
import Alert from 'flarum/common/components/Alert';
import Button from 'flarum/common/components/Button';
import m from 'flarum/common/mithril';

app.initializers.add('peopleinside-fla-polling', () => {
    // SECURITY: Polling only runs if the user is authenticated
    if (!app.session.user) return;

    let pollingInterval = 10000; // Check every 10 seconds
    let currentDiscussionId: number | null = null;
    let baselineDiscussionId = 0;
    let baselinePostId = 0;
    let baselineDiscussionIdSet = false;
    let baselinePostIdSet = false;
    let firstCheckCompleted = false;
    let lastNotificationCount = 0;
    let errorCount = 0;
    const maxErrors = 5;
    let snoozedUntil = 0;
    let lastKnownPath = window.location.pathname;
    let activeAlertId: any = null;

    const showAlert = (type: 'post' | 'discussion' | 'content') => {
        // If an alert is already active, dismiss it to avoid cluttering the screen
        if (activeAlertId !== null) {
            app.alerts.dismiss(activeAlertId);
            activeAlertId = null;
        }

        let translationKey = 'fla-polling.forum.banner.new_content';
        if (type === 'post') {
            translationKey = 'fla-polling.forum.banner.new_posts';
        } else if (type === 'discussion') {
            translationKey = 'fla-polling.forum.banner.new_discussions';
        }

        activeAlertId = app.alerts.show(Alert, {
            type: 'info',
            controls: [
                m(Button, {
                    className: 'Button Button--link',
                    onclick: () => {
                        window.location.reload();
                    }
                }, app.translator.trans('fla-polling.forum.banner.reload')),
                m(Button, {
                    className: 'Button Button--link',
                    onclick: () => {
                        snoozedUntil = Date.now() + 30000; // Snooze for 30s
                        if (activeAlertId !== null) {
                            app.alerts.dismiss(activeAlertId);
                            activeAlertId = null;
                        }
                    }
                }, app.translator.trans('fla-polling.forum.banner.snooze'))
            ]
        }, app.translator.trans(translationKey));
    };

    const getCurrentDiscussionId = () => {
        const match = window.location.pathname.match(/\/d\/(\d+)/);
        return match ? parseInt(match[1]) : null;
    };

    /**
     * Extracts the maximum post ID currently present on the page via the DOM
     */
    const getLastPostIdFromDOM = () => {
        const posts = document.querySelectorAll('[id^="Post-"]');
        if (posts.length > 0) {
            return Math.max(...Array.from(posts).map(el => parseInt(el.id.replace('Post-', ''))).filter(id => !isNaN(id)));
        }
        const items = document.querySelectorAll('.PostStream-item[data-id]');
        if (items.length > 0) {
            return Math.max(...Array.from(items).map(el => parseInt(el.getAttribute('data-id') || '0')).filter(id => !isNaN(id)));
        }
        return 0;
    };

    /**
     * Extracts the maximum discussion ID currently present on the page via the DOM
     */
    const getLastDiscussionIdFromDOM = () => {
        const items = document.querySelectorAll('.DiscussionListItem[data-id], li[data-id].DiscussionListItem');
        if (items.length > 0) {
            return Math.max(...Array.from(items).map(el => parseInt(el.getAttribute('data-id') || '0')).filter(id => !isNaN(id)));
        }
        return 0;
    };

    /**
     * Collects and updates current frontend baselines based on what is loaded on the page.
     * Keeps cross-page data strictly separate to prevent route pollution.
     */
    const updateBaselinesFromPage = () => {
        const currentUrlId = getCurrentDiscussionId();

        if (currentUrlId !== null) {
            // Logged-in user is reading a dedicated, specific discussion
            currentDiscussionId = currentUrlId;
            
            const discussion = app.current ? app.current.get('discussion') : null;
            // Ensure the active discussion context matches the navigated URL to avoid race conditions during SPA loading
            if (discussion && parseInt(discussion.id() || '0') === currentUrlId) {
                let maxPostId = 0;
                const postIds = discussion.postIds ? discussion.postIds() : [];
                if (postIds && postIds.length > 0) {
                    maxPostId = Math.max(...postIds.map(id => parseInt(id)).filter(id => !isNaN(id)));
                }
                
                // Fallback: Scraping visible DOM posts
                const domPostId = getLastPostIdFromDOM();
                if (domPostId > maxPostId) {
                    maxPostId = domPostId;
                }

                if (maxPostId > 0) {
                    if (!baselinePostIdSet) {
                        baselinePostId = maxPostId;
                        baselinePostIdSet = true;
                    } else {
                        baselinePostId = Math.max(baselinePostId, maxPostId);
                    }
                }
            }
        } else {
            // Logged-in user is browsing discussion lists (All Discussions, tags, etc.)
            currentDiscussionId = null;
            
            // Collect the maximum discussion ID present in Flarum's global client store
            let maxDiscId = 0;
            const discussions = app.store.all('discussions');
            if (discussions.length > 0) {
                maxDiscId = Math.max(...discussions.map(d => parseInt(d.id() || '0')).filter(id => !isNaN(id)));
            }
            
            // Fallback: Scraping visible list DOM
            const domDiscId = getLastDiscussionIdFromDOM();
            if (domDiscId > maxDiscId) {
                maxDiscId = domDiscId;
            }

            if (maxDiscId > 0) {
                if (!baselineDiscussionIdSet) {
                    baselineDiscussionId = maxDiscId;
                    baselineDiscussionIdSet = true;
                } else {
                    baselineDiscussionId = Math.max(baselineDiscussionId, maxDiscId);
                }
            }

            // CRITICAL LOGIC FIX:
            // Never check app.store.all('posts') globally when on list pages!
            // When browsing lists, the client store contains older posts from previously visited topics
            // or notification preloads. This leaks future-state high IDs and silences real-time post updates.
            // When on listings, we only update baselinePostId if the DOM specifically renders post items.
            const domPostId = getLastPostIdFromDOM();
            if (domPostId > 0) {
                if (!baselinePostIdSet) {
                    baselinePostId = domPostId;
                    baselinePostIdSet = true;
                } else {
                    baselinePostId = Math.max(baselinePostId, domPostId);
                }
            }
        }
    };

    /**
     * Resets absolute baselines every time the route changes.
     * Guarantees old baseline numbers never carry over to new routes.
     */
    const resetBaseline = () => {
        snoozedUntil = 0;
        firstCheckCompleted = false; // Reset firstCheckCompleted to establish clean initial thresholds from the DB
        baselineDiscussionId = 0;
        baselinePostId = 0;
        baselineDiscussionIdSet = false;
        baselinePostIdSet = false;
        
        // Dismiss preexisting alerts from the previous route
        if (activeAlertId !== null) {
            app.alerts.dismiss(activeAlertId);
            activeAlertId = null;
        }

        currentDiscussionId = getCurrentDiscussionId();
        updateBaselinesFromPage();
    };

    // Self post detection listeners
    const setupSelfPostDetection = () => {
        document.addEventListener('submit', (e) => {
            const target = e.target as HTMLElement;
            if (target && target.matches && target.matches('.Composer form')) {
                try { sessionStorage.setItem('flaPolling_selfPostTs', Date.now().toString()); } catch (_) {}
            }
        });
        document.addEventListener('click', (e) => {
            const target = e.target as HTMLElement;
            if (target && target.closest && target.closest('.Composer .Button--primary')) {
                try { sessionStorage.setItem('flaPolling_selfPostTs', Date.now().toString()); } catch (_) {}
            }
        });
    };

    /**
     * Checks if the user is writing or creating content themselves to suppress redundant popups
     */
    const checkAndClearSelfPostFlag = () => {
        try {
            const ts = parseInt(sessionStorage.getItem('flaPolling_selfPostTs') || '0');
            if (ts > 0 && (Date.now() - ts) < 25000) {
                sessionStorage.removeItem('flaPolling_selfPostTs');
                return true;
            }
        } catch (_) {}
        return false;
    };

    // Initialize baseline state
    resetBaseline();
    setupSelfPostDetection();

    /**
     * Periodically queries the optimized real-time endpoint for new data
     */
    const checkForUpdates = () => {
        if (errorCount > maxErrors) return;
        if (Date.now() < snoozedUntil) return;

        updateBaselinesFromPage();

        let apiUrl = app.forum.attribute('apiUrl') || '/api';
        apiUrl += '/realtime-check';
        
        if (currentDiscussionId) {
            apiUrl += '?discussionId=' + currentDiscussionId;
        }

        fetch(apiUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                if (response.status === 401 || response.status === 429) {
                    errorCount = maxErrors + 1; // Unauthorize or heavy limit: stop polling
                    return null;
                }
                throw new Error('HTTP status ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (!data) return;
            errorCount = 0;

            updateBaselinesFromPage();

            // Establish fresh baselines on the initial payload or route change payload
            if (!firstCheckCompleted) {
                firstCheckCompleted = true;
                baselineDiscussionId = Math.max(baselineDiscussionId, data.latestDiscussionId || 0);
                baselinePostId = Math.max(baselinePostId, data.latestPostId || 0);
                baselineDiscussionIdSet = true;
                baselinePostIdSet = true;
                return;
            }

            const isNewDisc = data.latestDiscussionId > baselineDiscussionId;
            const isNewPost = data.latestPostId > baselinePostId;
            const isSelf = checkAndClearSelfPostFlag();

            if (currentDiscussionId) {
                // Inside a dedicated discussion: alert user of new replies in this thread
                if (isNewPost) {
                    if (isSelf) {
                        baselinePostId = data.latestPostId;
                        return;
                    }
                    baselinePostId = data.latestPostId;
                    showAlert('post');
                }
            } else {
                // In main lists: alert of new topics, or other posts on the forum
                if (isNewDisc) {
                    if (isSelf) {
                        baselineDiscussionId = data.latestDiscussionId;
                        baselinePostId = data.latestPostId;
                        return;
                    }
                    baselineDiscussionId = data.latestDiscussionId;
                    baselinePostId = data.latestPostId;
                    showAlert('discussion');
                } else if (isNewPost) {
                    if (isSelf) {
                        baselinePostId = data.latestPostId;
                        return;
                    }
                    baselinePostId = data.latestPostId;
                    showAlert('content');
                }
            }

            // Sync user unread notification counts natively with zero DOM hacking!
            const count = data.unreadNotifications || 0;
            if (count !== lastNotificationCount) {
                lastNotificationCount = count;
                app.session.user.pushAttributes({
                    unreadNotificationCount: count
                });
                m.redraw(); // Trigger Flarum/Mithril native redraw for unread badge
            }
        })
        .catch(() => {
            errorCount++;
        });
    };

    /**
     * SPA Page change-listener and immediate updater.
     */
    const onNavigate = () => {
        const currentPath = window.location.pathname;
        if (currentPath !== lastKnownPath) {
            lastKnownPath = currentPath;
            resetBaseline();
            // Instantly poll on navigation to get a fresh threshold baseline
            checkForUpdates();
        }
    };

    // SPA routing observers
    window.addEventListener('popstate', onNavigate);

    const originalPush = history.pushState;
    history.pushState = function() {
        originalPush.apply(this, arguments as any);
        onNavigate();
    };

    const originalReplace = history.replaceState;
    history.replaceState = function() {
        originalReplace.apply(this, arguments as any);
        onNavigate();
    };

    // High-performance passive path-change check (foolproof fallback for Mithril route-switching edge-cases)
    const checkRouteChange = () => {
        const currentPath = window.location.pathname;
        if (currentPath !== lastKnownPath) {
            lastKnownPath = currentPath;
            resetBaseline();
            checkForUpdates();
        }
    };
    setInterval(checkRouteChange, 500);

    // Delay initial checking to keep startup footprint light
    setTimeout(() => {
        setInterval(checkForUpdates, pollingInterval);
        checkForUpdates();
    }, 2000);
});
