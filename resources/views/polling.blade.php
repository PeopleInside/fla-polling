<script>
/**
 * FLA Polling - Production Version with Security
 * - Skips polling for guests (no 401 errors)
 * - Server-side rate limiting
 * - Input validation
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
    en: { new_discussions: 'New discussions available!', new_posts: 'New posts in this discussion!', new_content: 'New content available!', reload: 'Reload', snooze: 'Remind me in 30s', dismiss: '✕' },
    it: { new_discussions: 'Nuove discussioni disponibili!', new_posts: 'Nuovi messaggi in questa discussione!', new_content: 'Nuovi contenuti disponibili!', reload: 'Ricarica', snooze: 'Ricordami tra 30s', dismiss: '✕' }
  };

  function getTranslation(key) {
    var lang = document.documentElement.lang || 'en';
    if (lang.length > 2) lang = lang.substring(0, 2);
    return (translations[lang] && translations[lang][key]) ? translations[lang][key] : translations['en'][key];
  }

  function waitForApp(callback, attempts) {
    attempts = attempts || 0;
    if (attempts > 50) return;
    if (typeof app !== 'undefined' && app.forum && app.store) { callback(); }
    else { setTimeout(function() { waitForApp(callback, attempts + 1); }, 200); }
  }

  /**
   * Check if user is logged in
   */
  function isLoggedIn() {
    return app && app.session && app.session.user && app.session.user.id();
  }

  function getCurrentDiscussionId() {
    var match = window.location.pathname.match(/\/d\/(\d+)/);
    return match ? parseInt(match[1]) : null;
  }

  function getLastPostIdFromDOM() {
    var posts = document.querySelectorAll('[id^="Post-"]');
    if (posts.length) return Math.max.apply(null, Array.from(posts).map(function(el) { return parseInt(el.id.replace('Post-', '')); }));
    var items = document.querySelectorAll('.PostStream-item[data-id]');
    if (items.length) return Math.max.apply(null, Array.from(items).map(function(el) { return parseInt(el.getAttribute('data-id')); }));
    return 0;
  }

  function getLastDiscussionIdFromPage() {
    if (app && app.store && app.store.all('discussions')) {
      var discs = app.store.all('discussions');
      if (discs.length) return Math.max.apply(null, discs.map(function(d) { return parseInt(d.id()); }));
    }
    return 0;
  }

  function setupSelfPostDetection() {
    document.addEventListener('submit', function(e) {
      if (e.target.matches('.Composer form')) {
        try { sessionStorage.setItem('flaPolling_selfPostTs', Date.now().toString()); } catch(e){}
      }
    });
    document.addEventListener('click', function(e) {
      if (e.target.closest('.Composer .Button--primary')) {
        try { sessionStorage.setItem('flaPolling_selfPostTs', Date.now().toString()); } catch(e){}
      }
    });
  }

  function checkAndClearSelfPostFlag() {
    try {
      var ts = parseInt(sessionStorage.getItem('flaPolling_selfPostTs') || '0');
      if (ts > 0 && (Date.now() - ts) < 25000) {
        sessionStorage.removeItem('flaPolling_selfPostTs');
        return true;
      }
    } catch(e) {}
    return false;
  }

  function resetBaseline() {
    currentDiscussionId = getCurrentDiscussionId();
    bannerDismissed = false;
    snoozedUntil = 0;
    bannerShown = false;
    baselineSet = false;
    
    if (currentDiscussionId) {
      baselineDiscussionId = 0;
      baselinePostId = getLastPostIdFromDOM();
    } else {
      baselineDiscussionId = getLastDiscussionIdFromPage();
      baselinePostId = 0;
    }
  }

  function monitorStorage() {
    window.addEventListener('storage', function(e) {
      if (e.key === 'flaPolling_bannerDismissed') {
        bannerDismissed = true;
        var b = document.querySelector('.FlaPollingBanner'); if(b) removeBanner(b);
      }
      if (e.key === 'flaPolling_bannerSnoozed') {
        snoozedUntil = parseInt(e.newValue) || 0;
        var b = document.querySelector('.FlaPollingBanner'); if(b) removeBanner(b);
      }
    });
  }

  function initPolling() {
    if (initialized) return;
    
    // SECURITY: Don't start polling for guests
    if (!isLoggedIn()) {
      return;
    }
    
    initialized = true;
    resetBaseline();
    setupSelfPostDetection();
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
    if (currentDiscussionId) apiUrl += '?discussionId=' + currentDiscussionId;
    
    fetch(apiUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': getCsrfToken() || '' }
    })
    .then(function(response) {
      if (!response.ok) {
        if (response.status === 401 || response.status === 429) {
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

      if (!baselineSet) {
        baselineSet = true;
        baselineDiscussionId = data.latestDiscussionId || 0;
        baselinePostId = data.latestPostId || 0;
        return;
      }

      var isNewDisc = data.latestDiscussionId > baselineDiscussionId;
      var isNewPost = data.latestPostId > baselinePostId;
      var isSelf = checkAndClearSelfPostFlag();

      if (currentDiscussionId) {
        if (isNewPost) {
          if (isSelf) { baselinePostId = data.latestPostId; return; }
          showBanner('post', data.latestDiscussionId, data.latestPostId);
          return;
        }
      } else {
        if (isNewDisc) {
          if (isSelf) { baselineDiscussionId = data.latestDiscussionId; baselinePostId = data.latestPostId; return; }
          showBanner('discussion', data.latestDiscussionId, data.latestPostId);
          return;
        }
        if (isNewPost) {
          if (isSelf) { baselinePostId = data.latestPostId; return; }
          showBanner('content', data.latestDiscussionId, data.latestPostId);
          return;
        }
      }

      var nCount = data.unreadNotifications || 0;
      if (nCount !== lastNotificationCount) { updateNotificationBadge(nCount); lastNotificationCount = nCount; }
    })
    .catch(function() { errorCount++; });
  }

  function showBanner(type, latestDiscId, latestPostId) {
    if (document.querySelector('.FlaPollingBanner')) return;
    bannerShown = true;

    var text = (type === 'post') ? getTranslation('new_posts') : (type === 'content' ? getTranslation('new_content') : getTranslation('new_discussions'));
    var banner = document.createElement('div');
    banner.className = 'FlaPollingBanner';
    banner.innerHTML = '<div class="FlaPollingBanner-content"><i class="fas fa-info-circle"></i> '+text+' '+
      '<button class="FlaPollingBanner-reload">'+getTranslation('reload')+'</button>'+
      '<button class="FlaPollingBanner-snooze">'+getTranslation('snooze')+'</button>'+
      '<button class="FlaPollingBanner-close" title="Dismiss">'+getTranslation('dismiss')+'</button></div>';
    
    banner.querySelector('.FlaPollingBanner-reload').onclick = function() { window.location.reload(); };
    banner.querySelector('.FlaPollingBanner-snooze').onclick = function() {
      snoozedUntil = Date.now() + 30000; bannerShown = false;
      try { localStorage.setItem('flaPolling_bannerSnoozed', snoozedUntil.toString()); } catch(e){}
      removeBanner(banner);
    };
    banner.querySelector('.FlaPollingBanner-close').onclick = function() {
      bannerDismissed = true; bannerShown = false;
      baselineDiscussionId = latestDiscId; baselinePostId = latestPostId;
      try { localStorage.setItem('flaPolling_bannerDismissed', Date.now().toString()); } catch(e){}
      removeBanner(banner);
    };

    var insertAfter = document.querySelector('.WelcomeHero') || document.querySelector('.App-header');
    if (insertAfter && insertAfter.parentNode) insertAfter.parentNode.insertBefore(banner, insertAfter.nextSibling);
    else document.body.insertBefore(banner, document.body.firstChild);
  }

  function removeBanner(banner) {
    if (banner && banner.parentNode) {
      banner.style.opacity = '0'; banner.style.maxHeight = '0'; banner.style.paddingTop = '0'; banner.style.paddingBottom = '0';
      setTimeout(function() { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 300);
    }
  }

  function getCsrfToken() {
    var t = document.querySelector('meta[name="csrf-token"]'); if(t) return t.getAttribute('content');
    if(app && app.session) return app.session.csrfToken; return null;
  }

  function updateNotificationBadge(count) {
    var icon = document.querySelector('.NotificationsDropdown .Button-icon'); if(!icon) return;
    var ex = icon.parentElement.querySelector('.FlaPollingBadge'); if(ex) ex.parentNode.removeChild(ex);
    if(count > 0) {
      var badge = document.createElement('span'); badge.className = 'FlaPollingBadge';
      badge.textContent = count > 99 ? '99+' : count; icon.parentElement.appendChild(badge);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function() { waitForApp(initPolling); });
  else waitForApp(initPolling);
})();
</script>
