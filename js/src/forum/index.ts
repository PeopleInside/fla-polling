import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';

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

    const getCurrentDiscussionId = () => {
        const match = window.location.pathname.match(/\/d\/(\d+)/);
        return match ? parseInt(match[1]) : null;
    };

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

    const getLastDiscussionIdFromDOM = () => {
        const items = document.querySelectorAll('.DiscussionListItem[data-id], li[data-id].DiscussionListItem');
        if (items.length > 0) {
            return Math.max(...Array.from(items).map(el => parseInt(el.getAttribute('data-id') || '0')).filter(id => !isNaN(id)));
        }
        return 0;
    };

    const updateBaselinesFromPage = () => {
        const discussion = app.current.get('discussion');
        if (discussion) {
            // Logged-in user is reading a dedicated discussion
            currentDiscussionId = parseInt(discussion.id() || '0') || null;
            
            // Try fetching loaded post IDs natively from the discussion model
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
        } else {
            // Logged-in user is browsing lists or dashboards
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
        }
    };

    const resetBaseline = () => {
        snoozedUntil = 0;
        // Keep firstCheckCompleted = true if it was already configured during SPA route changes
        // to prevent ignoring updates upon tag navigation
        baselineDiscussionIdSet = false;
        baselinePostIdSet = false;
        
        if (activeAlertId !== null) {
            app.alerts.dismiss(activeAlertId);
            activeAlertId = null;
        }

        currentDiscussionId = getCurrentDiscussionId();
        updateBaselinesFromPage();
    };

    // Self post detection helpers
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

        // Route transition track (Flarum handles content updates in-app via SPA)
        const currentPath = window.location.pathname;
        if (currentPath !== lastKnownPath) {
            lastKnownPath = currentPath;
            resetBaseline();
        }

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
                    errorCount = maxErrors + 1; // Terminate poller
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
                // Inside a discussion: alert user of new posts in this thread
                if (isNewPost) {
                    if (isSelf) {
                        baselinePostId = data.latestPostId;
                        return;
                    }
                    baselinePostId = data.latestPostId;
                    showAlert('post');
                }
            } else {
                // In main lists: alert of new topics, or generalized activity updates
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
                m.redraw(); // Trigger Flarum's UI to load the native unread badge
            }
        })
        .catch(() => {
            errorCount++;
        });
    };

    /**
     * Renders a native, theme-aligned Flarum alert bar with custom controls
     */
    const showAlert = (type: 'post' | 'discussion' | 'content') => {
        if (activeAlertId !== null) return;

        let messageKey = '';
        if (type === 'post') {
            messageKey = 'fla-polling.forum.banner.new_posts';
        } else if (type === 'content') {
            messageKey = 'fla-polling.forum.banner.new_content';
        } else {
            messageKey = 'fla-polling.forum.banner.new_discussions';
        }

        const message = app.translator.trans(messageKey) || 'New content available!';

        // Use Flarum's native alerts model to show the bar beautifully
        activeAlertId = app.alerts.show(
            {
                type: 'info',
                controls: [
                    m('button', {
                        className: 'Button Button--link',
                        onclick: () => window.location.reload()
                    }, app.translator.trans('fla-polling.forum.banner.reload') || 'Reload'),
                    m('button', {
                        className: 'Button Button--link',
                        onclick: () => {
                            snoozedUntil = Date.now() + 30000; // Snooze for 30s
                            if (activeAlertId !== null) {
                                app.alerts.dismiss(activeAlertId);
                                activeAlertId = null;
                            }
                        }
                    }, app.translator.trans('fla-polling.forum.banner.snooze') || 'Snooze')
                ],
                ondismiss: () => {
                    activeAlertId = null;
                }
            },
            message
        );
    };

    // Delay initial checking to keep startup footprint light
    setTimeout(() => {
        setInterval(checkForUpdates, pollingInterval);
        checkForUpdates();
    }, 2000);
});
