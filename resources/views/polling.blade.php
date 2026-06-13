<script>
/**
 * FLA Polling - Secure real-time polling for Flarum 2.0
 * Includes CSRF protection and error handling
 */
(function() {
  'use strict';

  var pollingInterval = 10000;
  var initialDiscussionId = 0;
  var lastCheckedId = 0;
  var lastNotificationCount = 0;
  var initialized = false;
  var errorCount = 0;
  var maxErrors = 5; // Stop after 5 consecutive errors

  var translations = {
    en: {
      new_discussions: 'New discussions available!',
      reload: 'Reload',
      error: 'Connection error'
    },
    it: {
      new_discussions: 'Nuove discussioni disponibili!',
      reload: 'Ricarica',
      error: 'Errore di connessione'
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

  function initPolling() {
    if (initialized) return;
    initialized = true;

    if (app.store.all('discussions')) {
      var discussions = app.store.all('discussions');
      if (discussions.length > 0) {
        var maxId = Math.max.apply(null, discussions.map(function(d) {
          return parseInt(d.id());
        }));
        initialDiscussionId = maxId;
        lastCheckedId = maxId;
      }
    }

    setInterval(checkForUpdates, pollingInterval);
    checkForUpdates();
  }

  function checkForUpdates() {
    // Stop polling after too many errors (prevent server overload)
    if (errorCount > maxErrors) {
      console.warn('FLA Polling: Stopped due to too many errors');
      return;
    }

    var apiUrl = '/api/realtime-check';
    
    fetch(apiUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        // Add CSRF token if available
        'X-CSRF-Token': getCsrfToken() || ''
      }
    })
    .then(function(response) {
      if (!response.ok) {
        if (response.status === 401) {
          // User not logged in - stop polling
          errorCount = maxErrors + 1;
          return null;
        }
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    })
    .then(function(data) {
      if (!data) return;
      
      // Reset error count on success
      errorCount = 0;
      
      // Handle rate limiting
      if (data.rateLimited) {
        return;
      }
      
      var latestId = data.latestDiscussionId || 0;
      
      // Show banner only for discussions created by others
      if (latestId > initialDiscussionId && latestId > lastCheckedId) {
        showBanner();
      }
      
      lastCheckedId = latestId;

      // Update notification badge
      var notificationCount = data.unreadNotifications || 0;
      if (notificationCount !== lastNotificationCount) {
        updateNotificationBadge(notificationCount);
        lastNotificationCount = notificationCount;
      }
    })
    .catch(function(error) {
      errorCount++;
      // Silent fail - don't spam console
      // console.error('FLA Polling error:', error);
    });
  }

  function getCsrfToken() {
    // Try to get CSRF token from Flarum's meta tags or cookies
    var token = document.querySelector('meta[name="csrf-token"]');
    if (token) {
      return token.getAttribute('content');
    }
    
    // Fallback: check for Flarum's session token
    if (app && app.session) {
      return app.session.csrfToken;
    }
    
    return null;
  }

  function showBanner() {
    if (document.querySelector('.FlaPollingBanner')) return;

    var banner = document.createElement('div');
    banner.className = 'FlaPollingBanner';
    banner.innerHTML = '<div class="FlaPollingBanner-content">' +
      '<i class="fas fa-info-circle"></i> ' +
      getTranslation('new_discussions') + ' ' +
      '<button class="FlaPollingBanner-reload">' + getTranslation('reload') + '</button>' +
      '</div>';
    
    banner.querySelector('.FlaPollingBanner-reload').addEventListener('click', function() {
      window.location.reload();
    });

    document.body.insertBefore(banner, document.body.firstChild);

    setTimeout(function() {
      if (banner.parentNode) {
        banner.style.opacity = '0';
        setTimeout(function() {
          if (banner.parentNode) banner.parentNode.removeChild(banner);
        }, 300);
      }
    }, 30000);
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
