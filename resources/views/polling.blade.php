<script>
/**
 * FLA Polling - Real-time polling for Flarum 2.0
 * Final version: Only shows relevant banners per context
 */
(function() {
  'use strict';

  var pollingInterval = 10000;
  var currentDiscussionId = null;
  var baselineDiscussionId = 0;
  var baselinePostId = 0;
  var baselineSet = false;
  var lastNotificationCount = 0;
  var initialized = false;
  var errorCount = 0;
  var maxErrors = 5;
  var bannerDismissed = false;
  var snoozedUntil = 0;
  var bannerShown = false;
  var lastKnownPath = window.location.pathname;

  var translations = {
    en: {
      new_discussions: 'New discussions available!',
      new_posts: 'New posts in this discussion!',
      new_content: 'New content available!',
      reload: 'Reload',
      snooze: 'Remind me in 30s',
      dismiss: '✕'
    },
    it: {
      new_discussions: 'Nuove discussioni disponibili!',
      new_posts: 'Nuovi messaggi in questa discussione!',
      new_content: 'Nuovi contenuti disponibili!',
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

  function resetBaseline() {
    currentDiscussionId = getCurrentDiscussionId();
    bannerDismissed = false;
    snoozedUntil = 0;
    bannerShown = false;
    baselineSet = false;
    
    if (currentDiscussionId) {
      // In a discussion: only track post ID
      baselineDiscussionId = 0;
      baselinePostId = getLastPostIdFromDOM();
    } else {
      // In discussion list: track both
      baselineDiscussionId = getLastDiscussionIdFromPage();
      baselinePostId = 0;
    }
  }

  function monitorStorage() {
    window.addEventListener('storage', function(e) {
      if (e.key === 'flaPolling_bannerDismissed') {
        bannerDismissed = true;
        var banner = document.querySelector('.FlaPollingBanner');
        if (banner) removeBanner(banner);
      }
      if (e.key === 'flaPolling_bannerSnoozed') {
        snoozedUntil = parseInt(e.newValue) || 0;
        var banner = document.querySelector('.FlaPollingBanner');
        if (banner) removeBanner(banner);
      }
    });
  }

  function initPolling() {
    if (initialized) return;
    initialized = true;

    resetBaseline();
    monitorStorage();

    setInterval(checkForUpdates, pollingInterval);
    checkForUpdates();
  }

  function checkForUpdates() {
    if (errorCount > maxErrors || bannerDismissed || bannerShown) return;
    if (Date.now() < snoozedUntil) return;

    var currentPath = window.location.pathname;
    if (currentPath !== lastKnownPath) {
      lastKnownPath = currentPath;
      resetBaseline();
    }

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
      
      if (!baselineSet) {
        baselineSet = true;
        baselineDiscussionId = latestDiscussionId;
        baselinePostId = latestPostId;
        return;
      }
      
      // CORRECT LOGIC: Context-specific checks only
      if (currentDiscussionId) {
        // ONLY check for new posts in this discussion
        if (latestPostId > baselinePostId) {
          showBanner('post', latestDiscussionId, latestPostId);
          return;
        }
      } else {
        // Check for new discussions OR new posts globally
        if (latestDiscussionId > baselineDiscussionId) {
          showBanner('discussion', latestDiscussionId, latestPostId);
          return;
        }
        if (latestPostId > baselinePostId && baselinePostId > 0) {
          showBanner('content', latestDiscussionId, latestPostId);
          return;
        }
      }
      
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

    var text, reloadText, snoozeText, dismissText;
    if (type === 'post') {
      text = getTranslation('new_posts');
    } else if (type === 'content') {
      text = getTranslation('new_content');
    } else {
      text = getTranslation('new_discussions');
    }
    reloadText = getTranslation('reload');
    snoozeText = getTranslation('snooze');
    dismissText = getTranslation('dismiss');

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
      try {
        localStorage.setItem('flaPolling_bannerSnoozed', snoozeTime.toString());
      } catch (e) {}
      removeBanner(banner);
    });

    banner.querySelector('.FlaPollingBanner-close').addEventListener('click', function() {
      bannerDismissed = true;
      bannerShown = false;
      baselineDiscussionId = latestDiscussionId;
      baselinePostId = latestPostId;
      try {
        localStorage.setItem('flaPolling_bannerDismissed', Date.now().toString());
      } catch (e) {}
      removeBanner(banner);
    });

    var insertAfter = document.querySelector('.WelcomeHero') || document.querySelector('.App-header');
    if (insertAfter && insertAfter.parentNode) {
      insertAfter.parentNode.insertBefore(banner, insertAfter.nextSibling);
    } else {
      document.body.insertBefore(banner, document.body.firstChild);
    }
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
