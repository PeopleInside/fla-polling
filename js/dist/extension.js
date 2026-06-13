/**
 * FLA Polling - Real-time polling extension for Flarum 2.0
 * 
 * This script periodically checks for new discussions and notifications
 * using HTTP polling (no WebSockets required).
 * 
 * Pure vanilla JavaScript - no build process required!
 * Supports multiple languages (English and Italian)
 */

(function() {
  'use strict';

  // Wait for Flarum to be ready
  function waitForFlarum(callback) {
    if (typeof app !== 'undefined' && app.forum) {
      callback();
    } else {
      setTimeout(function() {
        waitForFlarum(callback);
      }, 100);
    }
  }

  // Main initialization
  waitForFlarum(function() {
    // State variables to track changes
    var lastDiscussionId = 0;
    var lastNotificationCount = 0;
    var pollingInterval = 10000; // Check every 10 seconds

    // Initialize with current discussion ID if available
    if (app.store && app.store.all('discussions')) {
      var discussions = app.store.all('discussions');
      if (discussions.length > 0) {
        lastDiscussionId = Math.max.apply(null, discussions.map(function(d) {
          return parseInt(d.id());
        }));
      }
    }

    /**
     * Main polling function - checks for updates
     */
    function checkForUpdates() {
      var apiUrl = app.forum.attribute('apiUrl');
      
      fetch(apiUrl + '/realtime-check', {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/vnd.api+json'
        }
      })
      .then(function(response) {
        return response.json();
      })
      .then(function(result) {
        if (!result.data || !result.data[0]) return;
        
        var data = result.data[0].attributes;
        
        // Check for new discussions
        if (data.latestDiscussionId > lastDiscussionId && lastDiscussionId > 0) {
          showNewDiscussionBanner();
        }
        lastDiscussionId = data.latestDiscussionId;

        // Check for new notifications
        if (data.unreadNotifications !== lastNotificationCount) {
          updateNotificationBadge(data.unreadNotifications);
          lastNotificationCount = data.unreadNotifications;
        }
      })
      .catch(function(error) {
        console.error('FLA Polling error:', error);
      });
    }

    /**
     * Display a banner when new discussions are detected
     */
    function showNewDiscussionBanner() {
      // Don't show multiple banners
      if (document.querySelector('.FlaPollingBanner')) return;

      // Use Flarum's translation system
      var bannerText = app.translator.trans('fla-polling.forum.banner.new_discussions');
      var reloadText = app.translator.trans('fla-polling.forum.banner.reload');

      var banner = document.createElement('div');
      banner.className = 'FlaPollingBanner';
      banner.innerHTML = '<div class="FlaPollingBanner-content">' +
        '<i class="fas fa-info-circle"></i> ' +
        bannerText + ' ' +
        '<button class="FlaPollingBanner-reload">' + reloadText + '</button>' +
        '</div>';
      
      // Add click handler to reload button
      banner.querySelector('.FlaPollingBanner-reload').addEventListener('click', function() {
        window.location.reload();
      });

      document.body.insertBefore(banner, document.body.firstChild);

      // Auto-hide after 30 seconds
      setTimeout(function() {
        if (banner.parentNode) {
          banner.style.opacity = '0';
          setTimeout(function() {
            if (banner.parentNode) {
              banner.parentNode.removeChild(banner);
            }
          }, 300);
        }
      }, 30000);
    }

    /**
     * Update the notification badge with new count
     */
    function updateNotificationBadge(count) {
      var notificationIcon = document.querySelector('.NotificationsDropdown .Button-icon');
      if (notificationIcon) {
        // Remove existing badge if any
        var existingBadge = notificationIcon.parentElement.querySelector('.FlaPollingBadge');
        if (existingBadge) {
          existingBadge.parentNode.removeChild(existingBadge);
        }

        // Add new badge if count > 0
        if (count > 0) {
          var badge = document.createElement('span');
          badge.className = 'FlaPollingBadge';
          badge.textContent = count > 99 ? '99+' : count;
          notificationIcon.parentElement.appendChild(badge);
        }
      }
    }

    // Start polling after a short delay to ensure Flarum is fully loaded
    setTimeout(function() {
      setInterval(checkForUpdates, pollingInterval);
      checkForUpdates(); // Initial check
    }, 2000);
  });
})();
