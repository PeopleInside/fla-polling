<script>
/**
 * FLA Polling - Real-time polling for Flarum 2.0
 * Multi-tab safe with localStorage sync
 */
(function() {
  'use strict';

  var pollingInterval = 10000;
  var currentDiscussionId = null;
  var lastNotificationCount = 0;
  var initialized = false;
  var errorCount = 0;
  var maxErrors = 5;
  var currentUrl = window.location.href;
  var bannerDismissed = false;
  var snoozedUntil = 0;
  var bannerShown = false; // Track if banner is currently shown in this tab

  var translations = {
    en: {
      new_discussions: 'New discussions available!',
      new_posts: 'New posts in this discussion!',
      reload: 'Reload',
      snooze: 'Remind me in 30s',
      dismiss: '✕'
    },
    it: {
      new_discussions: 'Nuove discussioni disponibili!',
      new_posts: 'Nuovi messaggi in questa discussione!',
      reload: 'Ricarica',
      snooze: 'Ricordami tra 30s',
      dismiss: '✕'
    }
  };

  function getTranslation(key) {
    var lang = document.documentElement.lang || 'en';
    if (lang.length > 2) lang = lang.substring(0, 2);
    if (translations[lang] && translations[lang][key]) {
      return translations[lang][key];
    }
    return translations['en'][key] || key;
  }

  function waitForApp(callback, attempts) {
    attempts = attempts || 0;
    if (attempts > 50) return;
    if (typeof app !== 'undefined' && app.forum && app.store) {
      callback();
    } else {
      setTimeout(function() {
        waitForApp(callback, attempts + 1);
      }, 200);
    }
  }

  function getCurrentDiscussionId() {
    var path = window.location.pathname;
    var match = path.match(/\/d\/(\d+)/);
    if (match && match[1]) {
      return parseInt(match[1]);
    }
    return null;
  }

  function getLastPostIdFromDOM() {
    var postElements = document.querySelectorAll('[id^="Post-"]');
    if (postElements.length > 0) {
      var maxId = 0;
      postElements.forEach(function(el) {
        var id = parseInt(el.id.replace('Post-', ''));
        if (id > maxId) maxId = id;
      });
      return maxId;
    }
    var streamItems = document.querySelectorAll('.PostStream-item[data-id]');
    if (streamItems.length > 0) {
      var maxId = 0;
      streamItems.forEach(function(el) {
        var id = parseInt(el.getAttribute('data-id'));
        if (id > maxId) maxId = id;
      });
      return maxId;
    }
    return 0;
  }

  function getLastDiscussionIdFromPage() {
    if (app && app.store && app.store.all('discussions')) {
      var discussions = app.store.all('discussions');
      if (discussions.length > 0) {
        return Math.max.apply(null, discussions.map(function(d) {
          return parseInt(d.id());
        }));
      }
    }
    return 0;
  }

  /**
   * Get last seen IDs from localStorage (shared across tabs)
   */
  function getStoredLastSeenIds() {
    try {
      var stored = localStorage.getItem('flaPolling_lastSeen');
      if (stored) {
        return JSON.parse(stored);
      }
    } catch (e) {
      // Ignore parse errors
    }
    return { discussionId: 0, postId: 0, url: '' };
  }

  /**
   * Store last seen IDs in localStorage (shared across tabs)
   */
  function storeLastSeenIds(discussionId, postId) {
    try {
      localStorage.setItem('flaPolling_lastSeen', JSON.stringify({
        discussionId: discussionId,
        postId: postId,
        url: currentUrl,
        timestamp: Date.now()
      }));
    } catch (e) {
      // Ignore storage errors
    }
  }

  function updateReferenceValues() {
    currentDiscussionId = getCurrentDiscussionId();
    bannerDismissed = false;
    snoozedUntil = 0;
    bannerShown = false;
    
    if (currentDiscussionId) {
      var lastSeenPostId = getLastPostIdFromDOM();
      storeLastSeenIds(0, lastSeenPostId);
    } else {
      var lastSeenDiscussionId = getLastDiscussionIdFromPage();
      storeLastSeenIds(lastSeenDiscussionId, 0);
    }
  }

  function monitorUrlChanges() {
    window.addEventListener('popstate', function() {
      if (window.location.href !== currentUrl) {
        currentUrl = window.location.href;
        setTimeout(updateReferenceValues, 500);
      }
    });

    document.addEventListener('click', function(e) {
      var target = e.target;
      while (target && target.tagName !== 'A') {
        target = target.parentNode;
      }
      if (target && target.tagName === 'A' && target.href) {
        setTimeout(function() {
          if (window.location.href !== currentUrl) {
            currentUrl = window.location.href;
            updateReferenceValues();
          }
        }, 500);
      }
    });

    // Listen for storage changes from other tabs
    window.addEventListener('storage', function(e) {
      if (e.key === 'flaPolling_lastSeen') {
        // Another tab updated the state, check if we need to update too
        var stored = getStoredLastSeenIds();
        if (stored.url !== currentUrl) {
          // Different page, update our reference values
          updateReferenceValues();
        }
      }
      if (e.key === 'flaPolling_bannerDismissed') {
        // Another tab dismissed the banner
        bannerDismissed = true;
        var banner = document.querySelector('.FlaPollingBanner');
        if (banner) removeBanner(banner);
      }
      if (e.key === 'flaPolling_bannerSnoozed') {
        // Another tab snoozed the banner
        snoozedUntil = parseInt(e.newValue) || 0;
        var banner = document.querySelector('.FlaPollingBanner');
        if (banner) removeBanner(banner);
      }
    });
  }

  function initPolling() {
    if (initialized) return;
    initialized = true;

    updateReferenceValues();
    monitorUrlChanges();

    setInterval(checkForUpdates, pollingInterval);
    checkForUpdates();
  }

  function checkForUpdates() {
    if (errorCount > maxErrors || bannerDismissed || bannerShown) return;
    
    // SNOOZE LOGIC
    if (Date.now() < snoozedUntil) return;

    var apiUrl = '/api/realtime-check';
    if (currentDiscussionId) {
      apiUrl += '?discussionId=' + currentDiscussionId;
    }
    
    fetch(apiUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken() || ''
      }
    })
    .then(function(response) {
      if (!response.ok) {
        if (response.status === 401) {
          errorCount = maxErrors + 1;
          return null;
        }
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    })
    .then(function(data) {
      if (!data) return;
      
      errorCount = 0;
      
      var latestDiscussionId = data.latestDiscussionId || 0;
      var latestPostId = data.latestPostId || 0;
      
      var stored = getStoredLastSeenIds();
      var lastSeenDiscussionId = stored.discussionId || 0;
      var lastSeenPostId = stored.postId || 0;
      
      // Check for new discussions (only if not in a specific discussion)
      if (!currentDiscussionId && latestDiscussionId > lastSeenDiscussionId && lastSeenDiscussionId > 0) {
        showBanner('discussion', latestDiscussionId, latestPostId);
        return;
      }
      
      // Check for new posts in current discussion
      if (currentDiscussionId && latestPostId > lastSeenPostId && lastSeenPostId > 0) {
        showBanner('post', latestDiscussionId, latestPostId);
        return;
      }
      
      // Update stored values ONLY if no banner was shown
      storeLastSeenIds(latestDiscussionId, latestPostId);

      var notificationCount = data.unreadNotifications || 0;
      if (notificationCount !== lastNotificationCount) {
        updateNotificationBadge(notificationCount);
        lastNotificationCount = notificationCount;
      }
    })
    .catch(function(error) {
      errorCount++;
    });
  }

  function showBanner(type, latestDiscussionId, latestPostId) {
    if (document.querySelector('.FlaPollingBanner')) return;

    bannerShown = true;

    var text = (type === 'post') ? getTranslation('new_posts') : getTranslation('new_discussions');
    var reloadText = getTranslation('reload');
    var snoozeText = getTranslation('snooze');
    var dismissText = getTranslation('dismiss');

    var banner = document.createElement('div');
    banner.className = 'FlaPollingBanner';
    banner.innerHTML = '<div class="FlaPollingBanner-content">' +
      '<i class="fas fa-info-circle"></i> ' +
      text + ' ' +
      '<button class="FlaPollingBanner-reload">' + reloadText + '</button>' +
      '<button class="FlaPollingBanner-snooze">' + snoozeText + '</button>' +
      '<button class="FlaPollingBanner-close" title="Dismiss">' + dismissText + '</button>' +
      '</div>';
    
    banner.querySelector('.FlaPollingBanner-reload').addEventListener('click', function() {
      window.location.reload();
    });

    banner.querySelector('.FlaPollingBanner-snooze').addEventListener('click', function() {
      var snoozeTime = Date.now() + 30000;
      snoozedUntil = snoozeTime;
      bannerShown = false;
      // Notify other tabs
      try {
        localStorage.setItem('flaPolling_bannerSnoozed', snoozeTime.toString());
      } catch (e) {}
      removeBanner(banner);
    });

    banner.querySelector('.FlaPollingBanner-close').addEventListener('click', function() {
      bannerDismissed = true;
      bannerShown = false;
      // Update stored values to current latest
      storeLastSeenIds(latestDiscussionId, latestPostId);
      // Notify other tabs
      try {
        localStorage.setItem('flaPolling_bannerDismissed', Date.now().toString());
      } catch (e) {}
      removeBanner(banner);
    });

    document.body.insertBefore(banner, document.body.firstChild);

    // NO auto-hide - banner stays until user interacts
  }

  function removeBanner(banner) {
    if (banner && banner.parentNode) {
      banner.style.opacity = '0';
      banner.style.maxHeight = '0';
      banner.style.paddingTop = '0';
      banner.style.paddingBottom = '0';
      setTimeout(function() {
        if (banner.parentNode) banner.parentNode.removeChild(banner);
      }, 300);
    }
  }

  function getCsrfToken() {
    var token = document.querySelector('meta[name="csrf-token"]');
    if (token) return token.getAttribute('content');
    if (app && app.session) return app.session.csrfToken;
    return null;
  }

  function updateNotificationBadge(count) {
    var notificationIcon = document.querySelector('.NotificationsDropdown .Button-icon');
    if (!notificationIcon) return;

    var existingBadge = notificationIcon.parentElement.querySelector('.FlaPollingBadge');
    if (existingBadge) existingBadge.parentNode.removeChild(existingBadge);

    if (count > 0) {
      var badge = document.createElement('span');
      badge.className = 'FlaPollingBadge';
      badge.textContent = count > 99 ? '99+' : count;
      notificationIcon.parentElement.appendChild(badge);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      waitForApp(initPolling);
    });
  } else {
    waitForApp(initPolling);
  }
})();
</script>
