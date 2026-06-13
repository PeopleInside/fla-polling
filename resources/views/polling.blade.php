<script>
/**
 * FLA Polling - Real-time polling for Flarum 2.0
 * Injected inline to bypass webpack module system
 */
(function() {
  'use strict';

  var pollingInterval = 10000;
  var initialDiscussionId = 0;  // ID al caricamento della pagina
  var lastCheckedId = 0;         // ID dell'ultimo controllo
  var lastNotificationCount = 0;
  var initialized = false;

  // Translations
  var translations = {
    en: {
      new_discussions: 'New discussions available!',
      reload: 'Reload'
    },
    it: {
      new_discussions: 'Nuove discussioni disponibili!',
      reload: 'Ricarica'
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

  function initPolling() {
    if (initialized) return;
    initialized = true;

    // Get the ID of discussions currently visible on the page
    if (app.store.all('discussions')) {
      var discussions = app.store.all('discussions');
      if (discussions.length > 0) {
        var maxId = Math.max.apply(null, discussions.map(function(d) {
          return parseInt(d.id());
        }));
        // This is the baseline - discussions visible when page loaded
        initialDiscussionId = maxId;
        lastCheckedId = maxId;
      }
    }

    // Start polling
    setInterval(checkForUpdates, pollingInterval);
    checkForUpdates();
  }

  function checkForUpdates() {
    var apiUrl = '/api/realtime-check';
    
    fetch(apiUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(function(response) {
      if (!response.ok) {
        throw new Error('Network error: ' + response.status);
      }
      return response.json();
    })
    .then(function(data) {
      if (!data) return;
      
      var latestId = data.latestDiscussionId || 0;
      
      // Show banner ONLY if:
      // 1. There's a newer discussion than what was on the page initially
      // 2. AND it's newer than what we last checked
      // This prevents showing banner for discussions created by current user
      if (latestId > initialDiscussionId && latestId > lastCheckedId) {
        showBanner();
      }
      
      // Always update lastCheckedId to track what we've seen
      lastCheckedId = latestId;

      // Check for new notifications
      var notificationCount = data.unreadNotifications || 0;
      if (notificationCount !== lastNotificationCount) {
        updateNotificationBadge(notificationCount);
        lastNotificationCount = notificationCount;
      }
    })
    .catch(function(error) {
      // Silent fail
    });
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

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      waitForApp(initPolling);
    });
  } else {
    waitForApp(initPolling);
  }
})();
</script>
