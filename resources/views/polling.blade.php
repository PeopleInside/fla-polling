<script>
/**
 * FLA Polling - Real-time polling for Flarum 2.0
 * Detects new discussions AND new posts in current discussion
 */
(function() {
  'use strict';

  var pollingInterval = 10000;
  var initialDiscussionId = 0;
  var initialPostId = 0;
  var lastCheckedDiscussionId = 0;
  var lastCheckedPostId = 0;
  var currentDiscussionId = null; // ID of discussion being viewed (if any)
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
    if (attempts > 50) return;
    if (typeof app !== 'undefined' && app.forum && app.store) {
      callback();
    } else {
      setTimeout(function() {
        waitForApp(callback, attempts + 1);
      }, 200);
    }
  }

  /**
   * Extract discussion ID from current URL or route
   */
  function getCurrentDiscussionId() {
    // Method 1: Check URL path
    var path = window.location.pathname;
    var match = path.match(/\/d\/(\d+)/);
    if (match && match[1]) {
      return parseInt(match[1]);
    }

    // Method 2: Check Flarum's current route
    if (app && app.current && app.current.discussion) {
      return parseInt(app.current.discussion.id());
    }

    // Method 3: Check if we're on a discussion page via data attributes
    var discussionList = document.querySelector('[data-id]');
    if (discussionList && path.indexOf('/d/') !== -1) {
      var idMatch = path.match(/\/d\/(\d+)/);
      if (idMatch) return parseInt(idMatch[1]);
    }

    return null;
  }

  /**
   * Get the last post ID visible in the current discussion
   */
  function getCurrentPostId() {
    if (!currentDiscussionId) return 0;

    var posts = app.store.all('posts') || [];
    if (posts.length === 0) return 0;

    var discussionPosts = posts.filter(function(post) {
      return post.relationship('discussion') && 
             parseInt(post.relationship('discussion').id()) === currentDiscussionId;
    });

    if (discussionPosts.length === 0) return 0;

    return Math.max.apply(null, discussionPosts.map(function(p) {
      return parseInt(p.id());
    }));
  }

  function initPolling() {
    if (initialized) return;
    initialized = true;

    // Detect if we're viewing a specific discussion
    currentDiscussionId = getCurrentDiscussionId();

    if (currentDiscussionId) {
      // We're in a discussion - track both discussion and post IDs
      initialDiscussionId = currentDiscussionId;
      initialPostId = getCurrentPostId();
      lastCheckedDiscussionId = currentDiscussionId;
      lastCheckedPostId = initialPostId;
    } else {
      // We're on discussion list - track only discussion IDs
      if (app.store.all('discussions')) {
        var discussions = app.store.all('discussions');
        if (discussions.length > 0) {
          var maxId = Math.max.apply(null, discussions.map(function(d) {
            return parseInt(d.id());
          }));
          initialDiscussionId = maxId;
          lastCheckedDiscussionId = maxId;
        }
      }
    }

    setInterval(checkForUpdates, pollingInterval);
    checkForUpdates();
  }

  function checkForUpdates() {
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
      
      if (data.rateLimited) return;
      
      var latestDiscussionId = data.latestDiscussionId || 0;
      var latestPostId = data.latestPostId || 0;
      
      // Check for new discussions (only if not in a specific discussion)
      if (!currentDiscussionId && 
          latestDiscussionId > initialDiscussionId && 
          latestDiscussionId > lastCheckedDiscussionId) {
        showBanner('discussion');
      }
      
      // Check for new posts in current discussion
      if (currentDiscussionId && latestPostId > lastCheckedPostId) {
        showBanner('post');
      }
      
      lastCheckedDiscussionId = latestDiscussionId;
      lastCheckedPostId = latestPostId;

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
      '</div>';
    
    var button = banner.querySelector('.FlaPollingBanner-reload');
    button.addEventListener('click', function() {
      if (type === 'post' && currentDiscussionId) {
        // Scroll to bottom to see new posts
        window.scrollTo({
          top: document.body.scrollHeight,
          behavior: 'smooth'
        });
        // Remove banner
        if (banner.parentNode) {
          banner.style.opacity = '0';
          setTimeout(function() {
            if (banner.parentNode) banner.parentNode.removeChild(banner);
          }, 300);
        }
      } else {
        // Reload page
        window.location.reload();
      }
    });

    document.body.insertBefore(banner, document.body.firstChild);

    // Auto-hide after 30 seconds
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
