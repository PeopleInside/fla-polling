<script>
/**
 * FLA Polling - Real-time polling for Flarum 2.0
 * Injected inline to bypass webpack module system
 */
(function() {
  'use strict';

  var pollingInterval = 10000;
  var lastDiscussionId = 0;
  var lastNotificationCount = 0;
  var initialized = false;

  // Translations (loaded from Flarum's locale system)
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

    // Get initial discussion ID
    if (app.store.all('discussions')) {
      var discussions = app.store.all('discussions');
      if (discussions.length > 0) {
        lastDiscussionId = Math.max.apply(null, discussions.map(function(d) {
          return parseInt(d.id());
        }));
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
        'Accept': 'application/vnd.api+json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(function(response) {
      if (!response.ok) throw new Error('Network error');
      return response.json();
    })
    .then(function(result) {
      if (!result.data || !result.data[0]) return;
      
      var data = result.data[0].attributes;
      
      // Check for new discussions
      if (data.latestDiscussionId > lastDiscussionId && lastDiscussionId > 0) {
        showBanner();
      }
      lastDiscussionId = data.latestDiscussionId;

      // Check for new notifications
      if (data.unreadNotifications !== lastNotificationCount) {
        updateNotificationBadge(data.unreadNotifications);
        lastNotificationCount = data.unreadNotifications;
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
