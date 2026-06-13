<script>
/**
 * FLA Polling - Real-time polling for Flarum 2.0
 * Detects new discussions AND new posts in current discussion
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
    // Method 1: Look for elements with ID "Post-XXX"
    var postElements = document.querySelectorAll('[id^="Post-"]');
    if (postElements.length > 0) {
      var maxId = 0;
      postElements.forEach(function(el) {
        var id = parseInt(el.id.replace('Post-', ''));
        if (id > maxId) maxId = id;
      });
      return maxId;
    }

    // Method 2: Look for PostStream items with data-id
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

  function initPolling() {
    if (initialized) return;
    initialized = true;

    // Detect if we're viewing a specific discussion
    currentDiscussionId = getCurrentDiscussionId();

    if (currentDiscussionId) {
      // We're in a discussion - track post IDs
      lastSeenPostId = getLastPostIdFromDOM();
      console.log('FLA Polling: In discussion', currentDiscussionId, '- Last post ID:', lastSeenPostId);
    } else {
      // We're on discussion list - track discussion IDs
      lastSeenDiscussionId = getLastDiscussionIdFromPage();
      console.log('FLA Polling: On discussion list - Last discussion ID:', lastSeenDiscussionId);
    }

    // Start polling
    setInterval(checkForUpdates, pollingInterval);
    checkForUpdates();
  }

  function checkForUpdates() {
    if (errorCount > maxErrors) {
      console.warn('FLA Polling: Stopped due to too many errors');
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
      
      console.log('FLA Polling check:', {
        currentDiscussionId: currentDiscussionId,
        lastSeenDiscussionId: lastSeenDiscussionId,
        latestDiscussionId: latestDiscussionId,
        lastSeenPostId: lastSeenPostId,
        latestPostId: latestPostId
      });
      
      // Check for new discussions (only if not in a specific discussion)
      if (!currentDiscussionId && latestDiscussionId > lastSeenDiscussionId && lastSeenDiscussionId > 0) {
        console.log('FLA Polling: New discussion detected!');
        showBanner('discussion');
      }
      
      // Check for new posts in current discussion
      if (currentDiscussionId && latestPostId > lastSeenPostId && lastSeenPostId > 0) {
        console.log('FLA Polling: New post detected!');
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
      console.error('FLA Polling error:', error);
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
      '</div>';
    
    var button = banner.querySelector('.FlaPollingBanner-reload');
    button.addEventListener('click', function() {
      if (type === 'post' && currentDiscussionId) {
        // Reload page to see new posts
        window.location.reload();
      } else {
        window.location.reload();
      }
    });

    document.body.insertBefore(banner, document.body.firstChild);
    console.log('FLA Polling: Banner shown!');

    setTimeout(function() {
      if (banner.parentNode) {
        banner.style.opacity = '0';
        setTimeout(function() {
          if (banner.parentNode) banner.parentNode.removeChild(banner);
        }, 300);
      }
    }, 30000);
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
