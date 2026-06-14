System.register("peopleinside/fla-polling/forum", ["flarum/common/extend", "flarum/forum/app"], function (_export, _context) {
  "use strict";

  var extend, app;
  return {
    setters: [function (_flarumCommonExtend) {
      extend = _flarumCommonExtend.extend;
    }, function (_flarumForumApp) {
      app = _flarumForumApp.default;
    }],
    execute: function () {
      app.initializers.add('peopleinside-fla-polling', function () {
        // SECURITY: Polling only runs if the user is authenticated
        if (!app.session.user) return;

        var pollingInterval = 10000;
        var baselineDiscussionId = 0;
        var baselinePostId = 0;
        var firstCheckCompleted = false;
        var lastNotificationCount = 0;
        var errorCount = 0;
        var maxErrors = 5;
        var snoozedUntil = 0;
        var lastKnownPath = window.location.pathname;
        var activeAlertId = null;

        var resetBaseline = function () {
          snoozedUntil = 0;
          firstCheckCompleted = false;

          if (activeAlertId !== null) {
            app.alerts.dismiss(activeAlertId);
            activeAlertId = null;
          }

          var currentDiscussion = app.current.get('discussion');
          if (currentDiscussion) {
            baselinePostId = parseInt(currentDiscussion.lastPostNumber() || '0');
            baselineDiscussionId = 0;
          } else {
            baselineDiscussionId = 0;
            baselinePostId = 0;

            var discussions = app.store.all('discussions');
            if (discussions.length > 0) {
              baselineDiscussionId = Math.max.apply(null, discussions.map(function (d) {
                return parseInt(d.id() || '0');
              }));
            }
          }
        };

        resetBaseline();

        var checkForUpdates = function () {
          if (errorCount > maxErrors) return;
          if (Date.now() < snoozedUntil) return;

          var currentPath = window.location.pathname;
          if (currentPath !== lastKnownPath) {
            lastKnownPath = currentPath;
            resetBaseline();
          }

          var currentDiscussion = app.current.get('discussion');
          var discussionId = currentDiscussion ? currentDiscussion.id() : null;

          var apiUrl = app.forum.attribute('apiUrl') || '/api';
          apiUrl += '/realtime-check';

          if (discussionId) {
            apiUrl += '?discussionId=' + discussionId;
          }

          fetch(apiUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
          .then(function (response) {
            if (!response.ok) {
              if (response.status === 401 || response.status === 429) {
                errorCount = maxErrors + 1;
                return null;
              }
              throw new Error('HTTP status ' + response.status);
            }
            return response.json();
          })
          .then(function (data) {
            if (!data) return;
            errorCount = 0;

            if (!firstCheckCompleted) {
              firstCheckCompleted = true;
              baselineDiscussionId = Math.max(baselineDiscussionId, data.latestDiscussionId || 0);
              baselinePostId = Math.max(baselinePostId, data.latestPostId || 0);
              return;
            }

            var isNewDisc = data.latestDiscussionId > baselineDiscussionId;
            var isNewPost = data.latestPostId > baselinePostId;

            if (discussionId) {
              if (isNewPost) {
                baselinePostId = data.latestPostId;
                showAlert('post');
              }
            } else {
              if (isNewDisc) {
                baselineDiscussionId = data.latestDiscussionId;
                baselinePostId = data.latestPostId;
                showAlert('discussion');
              } else if (isNewPost) {
                baselinePostId = data.latestPostId;
                showAlert('content');
              }
            }

            var count = data.unreadNotifications || 0;
            if (count !== lastNotificationCount) {
              lastNotificationCount = count;
              app.session.user.pushAttributes({
                unreadNotificationCount: count
              });
              m.redraw();
            }
          })
          .catch(function () {
            errorCount++;
          });
        };

        var showAlert = function (type) {
          if (activeAlertId !== null) return;

          var messageKey = '';
          if (type === 'post') {
            messageKey = 'fla-polling.forum.banner.new_posts';
          } else if (type === 'content') {
            messageKey = 'fla-polling.forum.banner.new_content';
          } else {
            messageKey = 'fla-polling.forum.banner.new_discussions';
          }

          var message = app.translator.trans(messageKey) || 'New content available!';

          activeAlertId = app.alerts.show(
            {
              type: 'info',
              controls: [
                m('button', {
                  className: 'Button Button--link',
                  onclick: function () {
                    window.location.reload();
                  }
                }, app.translator.trans('fla-polling.forum.banner.reload') || 'Reload'),
                m('button', {
                  className: 'Button Button--link',
                  onclick: function () {
                    snoozedUntil = Date.now() + 30000;
                    if (activeAlertId !== null) {
                      app.alerts.dismiss(activeAlertId);
                      activeAlertId = null;
                    }
                  }
                }, app.translator.trans('fla-polling.forum.banner.snooze') || 'Snooze')
              ],
              ondismiss: function () {
                activeAlertId = null;
              }
            },
            message
          );
        };

        setTimeout(function () {
          setInterval(checkForUpdates, pollingInterval);
          checkForUpdates();
        }, 2000);
      });
    }
  };
});
