<script>
/**
 * FLA Polling - Real-time polling for Flarum 2.0
 * Production ready: no console logs, secure, with snooze feature.
 */
(function() {
  'use strict';

  var pollingInterval = 10000;
  var lastSeenDiscussionId = 0;
  var lastSeenPostId = 0;
  var currentDiscussionId = null;
  var lastNotificationCount = 0;
  var initialized = false;
  var errorCount = 0;
  var maxErrors = 5;
  var currentUrl = window.location.href;
  var bannerDismissed = false;
  var snoozedUntil = 0; // Timestamp until which polling is paused

  var translations = {
    en: {
      new_discussions: 'New discussions available!',
      new_posts: 'New posts in this discussion!',
      reload: 'Reload',
      snooze: 'Remind me in 30s',
      dismiss: ''
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

  function updateReferenceValues() {
    currentDiscussionId = getCurrentDiscussionId();
    bannerDismissed = false;
    snoozedUntil = 0; // Reset snooze on page change
    
    if (currentDiscussionId) {
      lastSeenPostId = getLastPostIdFromDOM();
      lastSeenDiscussionId = 0;
    } else {
      lastSeenDiscussionId = getLastDiscussionIdFromPage();
      lastSeenPostId = 0;
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
    if (errorCount > maxErrors || bannerDismissed) return;
    
    // SNOOZE LOGIC: If we are in the snooze period, skip this check
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
      
      if (!currentDiscussionId && latestDiscussionId > lastSeenDiscussionId && lastSeenDiscussionId > 0) {
        showBanner('discussion');
      }
      
      if (currentDiscussionId && latestPostId > lastSeenPostId && lastSeenPostId > 0) {
        showBanner('post');
      }
      
      lastSeenDiscussionId = latestDiscussionId;
      lastSeenPostId = latestPostId;

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

  function showBanner(type) {
    if (document.querySelector('.FlaPollingBanner')) return;

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
    
    // Reload button
    banner.querySelector('.FlaPollingBanner-reload').addEventListener('click', function() {
      window.location.reload();
    });

    // Snooze button (Hide for 30 seconds)
    banner.querySelector('.FlaPollingBanner-snooze').addEventListener('click', function() {
      snoozedUntil = Date.now() + 30000; // 30 seconds
      removeBanner(banner);
    });

    // Dismiss button (Hide permanently for this session)
    banner.querySelector('.FlaPollingBanner-close').addEventListener('click', function() {
      bannerDismissed = true;
      removeBanner(banner);
    });

    document.body.insertBefore(banner, document.body.firstChild);

    // Auto-hide after 30 seconds if not interacted with
    setTimeout(function() {
      if (banner.parentNode) removeBanner(banner);
    }, 30000);
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
