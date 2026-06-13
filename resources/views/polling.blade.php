<script>
/**
 * FLA Polling - Production-ready real-time polling for Flarum 2.0
 * - SPA-aware (handles Flarum's AJAX routing)
 * - Secure API communication
 * - Contextual detection (discussion list vs single discussion)
 */
(function() {
  'use strict';

  var pollingInterval = 10000;
  var errorCount = 0;
  var maxErrors = 5;
  var initialized = false;

  // State tracking
  var currentDiscussionId = null;
  var lastCheckedDiscussionId = 0;
  var lastCheckedPostId = 0;
  var lastNotificationCount = 0;

  // Translations
  var translations = {
    en: {
      new_discussions: 'New discussions available!',
      new_posts: 'New replies in this discussion!',
      reload: 'Reload',
      scroll: 'Scroll to new replies'
    },
    it: {
      new_discussions: 'Nuove discussioni disponibili!',
      new_posts: 'Nuove risposte in questa discussione!',
      reload: 'Ricarica',
      scroll: 'Vai alle nuove risposte'
    }
  };

  function getTranslation(key) {
    var lang = document.documentElement.lang || 'en';
    if (lang.length > 2) lang = lang.substring(0, 2);
    return (translations[lang] && translations[lang][key]) || translations['en'][key] || key;
  }

  /**
   * Robust SPA-aware discussion ID detector
   */
  function getCurrentDiscussionId() {
    var path = window.location.pathname;
    var match = path.match(/^\/d\/(\d+)/);
    return match ? parseInt(match[1]) : null;
  }

  function initPolling() {
    if (initialized) return;
    initialized = true;

    // Set initial state
    currentDiscussionId = getCurrentDiscussionId();

    // Start polling loop
    setInterval(poll, pollingInterval);
    poll();
  }

  function poll() {
    if (errorCount > maxErrors) return;

    // Detect SPA navigation
    var newDiscussionId = getCurrentDiscussionId();
    if (newDiscussionId !== currentDiscussionId) {
      currentDiscussionId = newDiscussionId;
      lastCheckedPostId = 0; // Reset baseline for new discussion
    }

    var url = '/api/realtime-check';
    if (currentDiscussionId) {
      url += '?discussion_id=' + currentDiscussionId;
    }

    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(function(response) {
      if (!response.ok) {
        if (response.status === 401) {
          errorCount = maxErrors + 1; // Stop polling if logged out
          return null;
        }
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    })
    .then(function(data) {
      if (!data) return;

      errorCount = 0; // Reset on success
      if (data.rateLimited) return;

      // Handle new discussions (list view)
      if (!currentDiscussionId) {
        var latestDiscId = data.latestDiscussionId || 0;
        if (latestDiscId > lastCheckedDiscussionId && lastCheckedDiscussionId > 0) {
          showBanner('discussion');
        }
        if (lastCheckedDiscussionId === 0) lastCheckedDiscussionId = latestDiscId;
        else lastCheckedDiscussionId = latestDiscId;
      } 
      // Handle new posts (discussion view)
      else {
        var latestPostId = data.latestPostId || 0;
        if (latestPostId > 0 && latestPostId > lastCheckedPostId) {
          showBanner('post');
        }
        if (lastCheckedPostId === 0) lastCheckedPostId = latestPostId;
        else lastCheckedPostId = latestPostId;
      }

      // Handle notifications
      var notifCount = data.unreadNotifications || 0;
      if (notifCount !== lastNotificationCount) {
        updateNotificationBadge(notifCount);
        lastNotificationCount = notifCount;
      }
    })
    .catch(function() {
      errorCount++;
    });
  }

  function showBanner(type) {
    if (document.querySelector('.FlaPollingBanner')) return;

    var text = type === 'post' ? getTranslation('new_posts') : getTranslation('new_discussions');
    var btnText = type === 'post' ? getTranslation('scroll') : getTranslation('reload');

    var banner = document.createElement('div');
    banner.className = 'FlaPollingBanner';
    banner.innerHTML = '<div class="FlaPollingBanner-content">' +
      '<i class="fas fa-info-circle"></i> ' + text + ' ' +
      '<button class="FlaPollingBanner-reload">' + btnText + '</button>' +
      '</div>';

    var btn = banner.querySelector('.FlaPollingBanner-reload');
    btn.addEventListener('click', function() {
      if (type === 'post') {
        // Smooth scroll to bottom for new replies
        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        banner.style.opacity = '0';
        setTimeout(function() { if (banner.parentNode) banner.remove(); }, 300);
      } else {
        window.location.reload();
      }
    });

    document.body.insertBefore(banner, document.body.firstChild);

    // Auto-hide
    setTimeout(function() {
      if (banner.parentNode) {
        banner.style.opacity = '0';
        setTimeout(function() { if (banner.parentNode) banner.remove(); }, 300);
      }
    }, 30000);
  }

  function updateNotificationBadge(count) {
    var icon = document.querySelector('.NotificationsDropdown .Button-icon');
    if (!icon) return;

    var oldBadge = icon.parentElement.querySelector('.FlaPollingBadge');
    if (oldBadge) oldBadge.remove();

    if (count > 0) {
      var badge = document.createElement('span');
      badge.className = 'FlaPollingBadge';
      badge.textContent = count > 99 ? '99+' : count;
      icon.parentElement.appendChild(badge);
    }
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPolling);
  } else {
    initPolling();
  }
})();
</script>
