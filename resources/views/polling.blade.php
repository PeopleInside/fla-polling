<script>
/**
 * FLA Polling - Real-time polling for Flarum 2.0
 * Detects new discussions AND new posts in current discussion
 * Handles page navigation to avoid showing banner to content creators
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

  var translations = {
    en: {
      new_discussions: 'New discussions available!',
      new_posts: 'New posts in this discussion!',
      reload: 'Reload',
      scroll: 'Scroll to new posts'
    },
    it: {
      new_discussions: 'Nuove discussioni disponibili!',
      new_posts: 'Nuovi messaggi in questa discussione!',
      reload: 'Ricarica',
      scroll: 'Vai ai nuovi messaggi'
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
    if (attempts > 50) {
      console.warn('FLA Polling: Flarum app not found after 50 attempts');
      return;
    }
    if (typeof app !== 'undefined' && app.forum && app.store) {
      callback();
    } else {
      setTimeout(function() {
        waitForApp(callback, attempts + 1);
      }, 200);
    }
  }

  /**
   * Extract discussion ID from current URL
   */
  function getCurrentDiscussionId() {
    var path = window.location.pathname;
    var match = path.match(/\/d\/(\d+)/);
    if (match && match[1]) {
      return parseInt(match[1]);
    }
    return null;
  }

  /**
   * Get the last post ID from the DOM
   */
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

  /**
   * Get the last discussion ID from the page
   */
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
   * Update reference values when page changes
   */
  function updateReferenceValues() {
    currentDiscussionId = getCurrentDiscussionId();
    bannerDismissed = false;
    
    if (currentDiscussionId) {
      lastSeenPostId = getLastPostIdFromDOM();
      lastSeenDiscussionId = 0;
      console.log('FLA Polling: Updated - In discussion', currentDiscussionId, '- Last post ID:', lastSeenPostId);
    } else {
      lastSeenDiscussionId = getLastDiscussionIdFromPage();
      lastSeenPostId = 0;
      console.log('FLA Polling: Updated - On discussion list - Last discussion ID:', lastSeenDiscussionId);
    }
  }

  /**
   * Monitor URL changes (for SPA navigation)
   */
  function monitorUrlChanges() {
    // Monitor popstate (browser back/forward)
    window.addEventListener('popstate', function() {
      if (window.location.href !== currentUrl) {
        currentUrl = window.location.href;
        setTimeout(updateReferenceValues, 500);
      }
    });

    // Monitor clicks on links (for SPA navigation)
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

    // Monitor for Flarum route changes
    if (app && app.current) {
      var originalRoute = app.current;
      Object.defineProperty(app, 'current', {
        get: function() {
          return originalRoute;
        },
        set: function(newValue) {
          originalRoute = newValue;
          setTimeout(updateReferenceValues, 500);
        },
        configurable: true
      });
    }
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
    if (errorCount > maxErrors || bannerDismissed) {
      return;
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
      
      // Check for new discussions (only if not in a specific discussion)
      if (!currentDiscussionId && latestDiscussionId > lastSeenDiscussionId && lastSeenDiscussionId > 0) {
        showBanner('discussion');
      }
      
      // Check for new posts in current discussion
      if (currentDiscussionId && latestPostId > lastSeenPostId && lastSeenPostId > 0) {
        showBanner('post');
      }
      
      // Update last seen values
      lastSeenDiscussionId = latestDiscussionId;
      lastSeenPostId = latestPostId;

      // Update notification badge
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

    var text, buttonText;
    if (type === 'post') {
      text = getTranslation('new_posts');
      buttonText = getTranslation('scroll');
    } else {
      text = getTranslation('new_discussions');
      buttonText = getTranslation('reload');
    }

    var banner = document.createElement('div');
    banner.className = 'FlaPollingBanner';
    banner.innerHTML = '<div class="FlaPollingBanner-content">' +
      '<i class="fas fa-info-circle"></i> ' +
      text + ' ' +
      '<button class="FlaPollingBanner-reload">' + buttonText + '</button>' +
      '<button class="FlaPollingBanner-close" style="margin-left:10px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);">✕</button>' +
      '</div>';
    
    var reloadButton = banner.querySelector('.FlaPollingBanner-reload');
    reloadButton.addEventListener('click', function() {
      window.location.reload();
    });

    var closeButton = banner.querySelector('.FlaPollingBanner-close');
    closeButton.addEventListener('click', function() {
      bannerDismissed = true;
      removeBanner(banner);
    });

    document.body.insertBefore(banner, document.body.firstChild);

    // Auto-hide after 30 seconds
    setTimeout(function() {
      removeBanner(banner);
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
    if (token) {
      return token.getAttribute('content');
    }
    if (app && app.session) {
      return app.session.csrfToken;
    }
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
