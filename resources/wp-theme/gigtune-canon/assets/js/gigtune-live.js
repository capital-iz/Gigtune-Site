(function (window, document) {
  'use strict';

  var cfg = window.GigTuneLiveConfig || {};
  var appId = String(cfg.appId || 'gigtune-main');
  var appName = String(cfg.appName || 'GigTune');
  var installEnabled = cfg.installEnabled !== false;
  var installLabel = String(cfg.installPromptLabel || ('Install ' + appName + ' App'));
  var alertsToggleLabel = String(cfg.alertsToggleLabel || 'Enable Instant Alerts');
  var notificationsEnabled = !!cfg.notificationsEnabled;
  var userId = Number(cfg.userId || 0);
  var pushEnabled = notificationsEnabled && cfg.pushEnabled !== false;
  var pushConfigEndpoint = String(cfg.pushConfigEndpoint || '/wp-json/gigtune/v1/push/config');
  var pushSubscribeEndpoint = String(cfg.pushSubscribeEndpoint || '/wp-json/gigtune/v1/push/subscribe');
  var pushUnsubscribeEndpoint = String(cfg.pushUnsubscribeEndpoint || '/wp-json/gigtune/v1/push/unsubscribe');
  var pollEndpoint = String(cfg.pollEndpoint || '/wp-json/gigtune/v1/notifications?per_page=12&page=1&only_unread=1&include_archived=0');
  var pollIntervalMs = Number(cfg.pollIntervalMs || 20000);
  var storagePrefix = 'gigtune.live.' + appId + '.';
  var installDismissKey = storagePrefix + 'install.dismissed';
  var lastNotificationKey = storagePrefix + 'notifications.last_id.' + userId;
  var alertsOptInKey = storagePrefix + 'alerts.opt_in.' + userId;
  var lastPushEndpointKey = storagePrefix + 'push.endpoint.' + userId;

  var deferredInstallPrompt = null;
  var isPolling = false;
  var firstPollComplete = false;
  var installFallbackShown = false;
  var pushSyncInFlight = false;
  var pushConfigCache = null;

  function inStandaloneMode() {
    try {
      return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    } catch (e) {
      return false;
    }
  }

  function qs(selector) {
    return document.querySelector(selector);
  }

  function qsa(selector) {
    return Array.prototype.slice.call(document.querySelectorAll(selector));
  }

  function getStoredNumber(key) {
    try {
      var raw = window.localStorage.getItem(key);
      var value = Number(raw);
      return Number.isFinite(value) ? value : 0;
    } catch (e) {
      return 0;
    }
  }

  function setStoredValue(key, value) {
    try {
      window.localStorage.setItem(key, String(value));
    } catch (e) {
      return;
    }
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload || {})
    });
  }

  function fetchPushConfig() {
    if (pushConfigCache) {
      return Promise.resolve(pushConfigCache);
    }

    var url = pushConfigEndpoint + '?app_id=' + encodeURIComponent(appId);
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      },
      cache: 'no-store'
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('push_config_failed');
      }
      return response.json();
    }).then(function (payload) {
      pushConfigCache = payload || {};
      return pushConfigCache;
    });
  }

  function unsubscribePushEndpoint(endpoint) {
    if (String(endpoint || '') === '') {
      return Promise.resolve(null);
    }
    return postJson(pushUnsubscribeEndpoint, {
      app_id: appId,
      endpoint: String(endpoint)
    }).catch(function () {
      return null;
    });
  }

  function syncPushSubscription() {
    if (!pushEnabled || userId <= 0 || pushSyncInFlight) {
      return;
    }
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      return;
    }
    if (Notification.permission === 'denied') {
      return;
    }

    pushSyncInFlight = true;
    Promise.all([
      navigator.serviceWorker.ready,
      fetchPushConfig()
    ]).then(function (results) {
      var registration = results[0];
      var pushCfg = results[1] || {};
      var enabled = !!(pushCfg.enabled && pushCfg.configured);
      var publicKey = String(pushCfg.public_key || '').trim();
      if (!enabled || publicKey === '') {
        return registration.pushManager.getSubscription().then(function (existing) {
          if (!existing) {
            return null;
          }
          var endpoint = String((existing.toJSON && existing.toJSON().endpoint) || '');
          return existing.unsubscribe().catch(function () { return false; }).then(function () {
            return unsubscribePushEndpoint(endpoint);
          });
        });
      }

      return registration.pushManager.getSubscription().then(function (existing) {
        if (existing) {
          return existing;
        }
        if (Notification.permission !== 'granted') {
          return null;
        }
        return registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicKey)
        });
      }).then(function (subscription) {
        if (!subscription || typeof subscription.toJSON !== 'function') {
          return null;
        }
        var json = subscription.toJSON();
        if (!json || !json.endpoint) {
          return null;
        }

        var lastEndpoint = '';
        try {
          lastEndpoint = String(window.localStorage.getItem(lastPushEndpointKey) || '');
        } catch (e) {
          lastEndpoint = '';
        }
        if (lastEndpoint === String(json.endpoint)) {
          return null;
        }

        return postJson(pushSubscribeEndpoint, {
          app_id: appId,
          subscription: json
        }).then(function (response) {
          if (!response.ok) {
            throw new Error('push_subscribe_failed');
          }
          setStoredValue(lastPushEndpointKey, String(json.endpoint));
          return null;
        });
      });
    }).catch(function () {
      return null;
    }).finally(function () {
      pushSyncInFlight = false;
    });
  }

  function updateNotificationBadges(total) {
    var count = Number(total || 0);
    qsa('.gt-live-notification-count').forEach(function (node) {
      var hideZero = String(node.getAttribute('data-hide-zero') || '1') === '1';
      if (count > 0) {
        node.textContent = String(count);
        node.classList.remove('hidden');
      } else {
        node.textContent = '';
        if (hideZero) {
          node.classList.add('hidden');
        }
      }
    });
    qsa('.gt-live-notification-label').forEach(function (node) {
      node.textContent = count > 0 ? ('Notifications (' + count + ')') : 'Notifications';
    });
  }

  function ensureFloatingAction(id, label, className) {
    var existing = document.getElementById(id);
    if (existing) {
      return existing;
    }
    var button = document.createElement('button');
    button.type = 'button';
    button.id = id;
    button.textContent = label;
    button.className = className;
    button.style.display = 'none';
    document.body.appendChild(button);
    return button;
  }

  function notifyViaBrowser(title, body, url, tag) {
    if (!('Notification' in window)) {
      return;
    }
    if (Notification.permission !== 'granted') {
      return;
    }

    var payload = {
      type: 'GT_SHOW_NOTIFICATION',
      title: String(title || appName),
      body: String(body || ''),
      url: String(url || '/notifications/'),
      tag: String(tag || 'gigtune-live'),
      icon: '/wp-content/themes/gigtune-canon/assets/img/gigtune-app-icon-192.png',
      badge: '/wp-content/themes/gigtune-canon/assets/img/gigtune-app-icon-192.png'
    };

    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage(payload);
      return;
    }
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistration().then(function (reg) {
        if (reg && typeof reg.showNotification === 'function') {
          reg.showNotification(payload.title, {
            body: payload.body,
            tag: payload.tag,
            icon: payload.icon,
            badge: payload.badge,
            data: { url: payload.url }
          });
        } else {
          var fallback = new Notification(payload.title, {
            body: payload.body,
            tag: payload.tag,
            icon: payload.icon,
            data: { url: payload.url }
          });
          fallback.onclick = function () {
            window.location.href = payload.url;
          };
        }
      }).catch(function () { return null; });
      return;
    }

    var notification = new Notification(payload.title, {
      body: payload.body,
      tag: payload.tag,
      icon: payload.icon,
      data: { url: payload.url }
    });
    notification.onclick = function () {
      window.location.href = payload.url;
    };
  }

  function maybeRenderInstallPrompt() {
    if (!installEnabled) {
      return;
    }
    if (inStandaloneMode()) {
      return;
    }
    if (getStoredNumber(installDismissKey) === 1) {
      return;
    }

    if (!deferredInstallPrompt) {
      if (installFallbackShown) {
        return;
      }
      installFallbackShown = true;
      var fallback = ensureFloatingAction(
        'gtInstallAppButton',
        installLabel,
        'fixed bottom-4 right-4 z-[90] rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg hover:from-blue-500 hover:to-indigo-500'
      );
      fallback.style.display = 'inline-flex';
      fallback.onclick = function () {
        window.alert('Use your browser menu and choose \"Install app\" or \"Add to Home Screen\".');
      };
      return;
    }

    var button = ensureFloatingAction(
      'gtInstallAppButton',
      installLabel,
      'fixed bottom-4 right-4 z-[90] rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg hover:from-blue-500 hover:to-indigo-500'
    );
    button.style.display = 'inline-flex';
    button.onclick = function () {
      deferredInstallPrompt.prompt();
      deferredInstallPrompt.userChoice.then(function (choice) {
        if (!choice || choice.outcome !== 'accepted') {
          setStoredValue(installDismissKey, 1);
        }
        button.style.display = 'none';
      }).catch(function () {
        setStoredValue(installDismissKey, 1);
        button.style.display = 'none';
      });
    };
  }

  function setupInstallPrompt() {
    if (!installEnabled) {
      return;
    }
    window.addEventListener('beforeinstallprompt', function (event) {
      event.preventDefault();
      deferredInstallPrompt = event;
      maybeRenderInstallPrompt();
    });
    window.addEventListener('appinstalled', function () {
      setStoredValue(installDismissKey, 1);
      var button = qs('#gtInstallAppButton');
      if (button) {
        button.style.display = 'none';
      }
    });
  }

  function setupAlertsOptIn() {
    if (!notificationsEnabled || userId <= 0 || !('Notification' in window)) {
      return;
    }
    if (Notification.permission === 'granted') {
      setStoredValue(alertsOptInKey, 1);
      return;
    }

    var optedIn = getStoredNumber(alertsOptInKey) === 1;
    if (optedIn) {
      return;
    }

    var button = ensureFloatingAction(
      'gtEnableAlertsButton',
      alertsToggleLabel,
      'fixed bottom-4 left-4 z-[90] rounded-xl border border-white/20 bg-slate-900/90 px-4 py-2 text-sm font-semibold text-slate-100 shadow-lg hover:bg-slate-800'
    );
    button.style.display = 'inline-flex';
    button.onclick = function () {
      Notification.requestPermission().then(function (permission) {
        if (permission === 'granted') {
          setStoredValue(alertsOptInKey, 1);
          button.style.display = 'none';
          notifyViaBrowser(appName, 'Instant alerts enabled.', '/notifications/', 'gigtune-alerts-enabled');
          syncPushSubscription();
        } else {
          setStoredValue(alertsOptInKey, 0);
        }
      }).catch(function () {
        setStoredValue(alertsOptInKey, 0);
      });
    };
  }

  function maybeNotifyForNewItems(items) {
    if (!notificationsEnabled || userId <= 0 || !Array.isArray(items) || items.length === 0) {
      return;
    }
    if (getStoredNumber(alertsOptInKey) !== 1 && Notification.permission !== 'granted') {
      return;
    }

    var lastId = getStoredNumber(lastNotificationKey);
    var latestId = lastId;
    var fresh = [];

    items.forEach(function (item) {
      var itemId = Number(item && item.id ? item.id : 0);
      if (itemId > latestId) {
        latestId = itemId;
      }
      if (itemId > lastId) {
        fresh.push(item);
      }
    });

    if (latestId > lastId) {
      setStoredValue(lastNotificationKey, latestId);
    }

    if (!firstPollComplete || fresh.length === 0) {
      return;
    }

    var newest = fresh[0] || {};
    var message = String(newest.message || newest.title || 'You have a new notification.');
    var openUrl = String(newest.open_url || '/notifications/');
    var title = fresh.length > 1
      ? (appName + ': ' + fresh.length + ' new notifications')
      : (appName + ': New notification');

    notifyViaBrowser(title, message, openUrl, 'gigtune-notification-' + String(newest.id || Date.now()));
  }

  function emitLiveUpdate(data) {
    try {
      window.dispatchEvent(new CustomEvent('gigtune:notifications:update', {
        detail: data
      }));
    } catch (e) {
      return;
    }
  }

  function pollNotifications() {
    if (!notificationsEnabled || userId <= 0 || isPolling) {
      return;
    }
    isPolling = true;
    fetch(pollEndpoint, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      },
      cache: 'no-store'
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('notifications_poll_failed');
      }
      return response.json();
    }).then(function (payload) {
      var total = Number(payload && payload.total ? payload.total : 0);
      var items = Array.isArray(payload && payload.items ? payload.items : null) ? payload.items : [];
      updateNotificationBadges(total);
      maybeNotifyForNewItems(items);
      emitLiveUpdate({
        total: total,
        items: items
      });
      if (!firstPollComplete) {
        firstPollComplete = true;
      }
    }).catch(function () {
      return null;
    }).finally(function () {
      isPolling = false;
    });
  }

  function init() {
    setupInstallPrompt();
    setupAlertsOptIn();
    maybeRenderInstallPrompt();
    window.setTimeout(maybeRenderInstallPrompt, 3000);
    syncPushSubscription();

    if (notificationsEnabled && userId > 0) {
      pollNotifications();
      window.setInterval(pollNotifications, Math.max(10000, pollIntervalMs));
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          pollNotifications();
          syncPushSubscription();
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
