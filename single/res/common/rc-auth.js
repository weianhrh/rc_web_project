/*
 * /res/common/rc-auth.js
 * RC物联后台 - 登录态 / 当前用户 / 权限公共函数
 *
 * 目标：把 res/*.html 里重复的 code=1001、未登录跳转、get_current_user、role_id 权限判断抽出来。
 * 特点：无依赖；如果已引入 rc-request.js，会优先复用 RcRequest；如果页面已加载 window.RcCommon，会自动挂到 RcCommon.auth。
 *
 * 推荐引入：
 *   <script src="./common/rc-request.js"></script>
 *   <script src="./common/rc-auth.js"></script>
 *
 * 常用：
 *   RcAuth.getCurrentUser().then(function (user) {});
 *   RcAuth.requireLogin().then(function (user) {});
 *   if (RcAuth.isAdmin(user)) { ... }
 */
(function (global, document) {
  'use strict';

  var hasOwn = Object.prototype.hasOwnProperty;

  var defaults = {
    userApi: '/api/operat/get_current_user.php',
    loginUrl: '/res/login.html',
    appendRedirect: true,
    redirectParam: 'redirect',
    cache: true,
    cacheTtl: 15000,
    adminRoles: [1, 2],
    venueRoles: [3, 4],
    authCodes: [1001, 401, 403],
    currentUserFailCodes: [1, 1001, 401, 403],
    alertOnRedirect: false,
    unauthorizedMessage: '用户未登录或会话已过期',
    onAuthExpired: null,
    onUserLoaded: null
  };

  var config = extend({}, defaults);
  var cacheUser = null;
  var cacheTime = 0;
  var loadingPromise = null;

  function noop() {}

  function isFunction(value) {
    return typeof value === 'function';
  }

  function isPlainObject(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
  }

  function extend(target) {
    target = target || {};
    for (var i = 1; i < arguments.length; i++) {
      var source = arguments[i] || {};
      for (var key in source) {
        if (hasOwn.call(source, key)) target[key] = source[key];
      }
    }
    return target;
  }

  function toArray(value) {
    if (value == null) return [];
    return Array.isArray(value) ? value : [value];
  }

  function toNumber(value, fallback) {
    if (isPlainObject(value)) {
      value = value.role_id != null ? value.role_id : value.roleId;
    }
    var n = Number(value);
    return isNaN(n) ? (fallback == null ? 0 : fallback) : n;
  }

  function contains(list, value) {
    list = toArray(list);
    value = Number(value);
    for (var i = 0; i < list.length; i++) {
      if (Number(list[i]) === value) return true;
    }
    return false;
  }

  function getMessage(data, fallback) {
    if (data && typeof data === 'object') return data.message || data.msg || data.error || fallback || '';
    return fallback || '';
  }

  function isAuthCode(code, list) {
    list = toArray(list || config.authCodes);
    for (var i = 0; i < list.length; i++) {
      if (String(code) === String(list[i])) return true;
    }
    return false;
  }

  function isAuthExpired(data, options) {
    options = extend({}, config, options || {});
    if (!data || typeof data !== 'object') return false;
    return isAuthCode(data.code, options.authCodes);
  }

  function isCurrentUserFailed(data, options) {
    options = extend({}, config, options || {});
    if (!data || typeof data !== 'object') return true;
    if (String(data.code) === '0' || String(data.code) === '200' || data.success === true) return false;
    return isAuthCode(data.code, options.currentUserFailCodes) || data.code != null;
  }

  function appendRedirect(url, options) {
    options = extend({}, config, options || {});
    if (!options.appendRedirect) return url;
    var current = global.location ? global.location.href : '';
    if (!current) return url;
    var key = encodeURIComponent(options.redirectParam || 'redirect');
    var value = encodeURIComponent(current);
    return String(url || '') + (String(url || '').indexOf('?') >= 0 ? '&' : '?') + key + '=' + value;
  }

  function redirectToLogin(options) {
    options = extend({}, config, options || {});
    var message = options.message || options.unauthorizedMessage;

    if (isFunction(options.onAuthExpired)) {
      var ret = options.onAuthExpired(message, options);
      if (ret === false) return false;
    }

    if (options.alertOnRedirect && message) {
      try { global.alert(message); } catch (e) {}
    }

    var url = appendRedirect(options.loginUrl || config.loginUrl, options);
    if (global.top && global.top !== global && options.topRedirect !== false) {
      global.top.location.href = url;
    } else if (global.location) {
      global.location.href = url;
    }
    return true;
  }

  function requestJson(url, options) {
    options = options || {};
    if (global.RcRequest && isFunction(global.RcRequest.get)) {
      return global.RcRequest.get(url, options.params || null, extend({
        redirectOnAuthExpired: false,
        rejectOnAuthExpired: false
      }, options.requestOptions || {}));
    }

    var finalUrl = url;
    if (options.params) {
      if (global.RcUrl && isFunction(global.RcUrl.appendParams)) {
        finalUrl = global.RcUrl.appendParams(finalUrl, options.params);
      } else {
        var qs = [];
        for (var k in options.params) {
          if (hasOwn.call(options.params, k) && options.params[k] !== undefined && options.params[k] !== null && options.params[k] !== '') {
            qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(options.params[k]));
          }
        }
        if (qs.length) finalUrl += (finalUrl.indexOf('?') >= 0 ? '&' : '?') + qs.join('&');
      }
    }

    return fetch(finalUrl, extend({
      method: 'GET',
      credentials: 'include',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }, options.fetchOptions || {})).then(function (res) {
      return res.text().then(function (text) {
        if (!text) return {};
        try { return JSON.parse(text); } catch (e) { return { code: res.ok ? 0 : res.status, data: text, msg: text }; }
      });
    });
  }

  function normalizeUser(data) {
    if (!data || typeof data !== 'object') return null;
    if (data.data && typeof data.data === 'object') return data.data;
    if (data.user && typeof data.user === 'object') return data.user;
    if (data.role_id != null || data.venue_id != null || data.uid != null) return data;
    return null;
  }

  function setCurrentUser(user) {
    cacheUser = user || null;
    cacheTime = Date.now();
    global.RC_CURRENT_USER = cacheUser;
    return cacheUser;
  }

  function clearCurrentUser() {
    cacheUser = null;
    cacheTime = 0;
    loadingPromise = null;
    global.RC_CURRENT_USER = null;
  }

  function getCurrentUser(options) {
    options = extend({}, config, options || {});
    var now = Date.now();

    if (options.cache !== false && cacheUser && now - cacheTime < Number(options.cacheTtl || 0)) {
      return Promise.resolve(cacheUser);
    }

    if (loadingPromise && options.force !== true) return loadingPromise;

    loadingPromise = requestJson(options.userApi, options).then(function (res) {
      loadingPromise = null;
      if (isCurrentUserFailed(res, options)) {
        clearCurrentUser();
        var msg = getMessage(res, options.unauthorizedMessage);
        if (options.redirectOnFail) redirectToLogin(extend({}, options, { message: msg }));
        if (options.rejectOnFail !== false) {
          var err = new Error(msg);
          err.code = res && res.code;
          err.response = res;
          err.isAuthExpired = true;
          throw err;
        }
        return null;
      }

      var user = normalizeUser(res);
      setCurrentUser(user);
      if (isFunction(options.onUserLoaded)) options.onUserLoaded(user, res);
      return user;
    }).catch(function (err) {
      loadingPromise = null;
      clearCurrentUser();
      if (options.redirectOnFail) redirectToLogin(extend({}, options, { message: err && err.message }));
      if (options.rejectOnFail === false) return null;
      throw err;
    });

    return loadingPromise;
  }

  function requireLogin(options) {
    return getCurrentUser(extend({ redirectOnFail: true, rejectOnFail: true }, options || {}));
  }

  function loadCurrentUser(callback, failCallback, options) {
    return getCurrentUser(extend({ rejectOnFail: false }, options || {})).then(function (user) {
      if (user && isFunction(callback)) callback(user);
      if (!user && isFunction(failCallback)) failCallback(null);
      return user;
    }).catch(function (err) {
      if (isFunction(failCallback)) failCallback(err);
      return null;
    });
  }

  function handleResponse(data, options) {
    options = extend({}, config, options || {});
    if (isAuthExpired(data, options)) {
      if (options.redirectOnAuthExpired) redirectToLogin(extend({}, options, { message: getMessage(data, options.unauthorizedMessage) }));
      if (options.rejectOnAuthExpired) {
        var err = new Error(getMessage(data, options.unauthorizedMessage));
        err.code = data.code;
        err.response = data;
        err.isAuthExpired = true;
        throw err;
      }
    }
    return data;
  }

  function setupRequestAuth(options) {
    options = extend({}, config, options || {});
    if (!global.RcRequest || !isFunction(global.RcRequest.setup)) return false;
    global.RcRequest.setup({
      authCodes: options.authCodes,
      authRedirectUrl: options.loginUrl,
      // 这里不直接用 RcRequest 的内置跳转，统一交给 RcAuth.redirectToLogin，避免 iframe/redirect 参数不一致。
      redirectOnAuthExpired: false,
      rejectOnAuthExpired: options.rejectOnAuthExpired === true,
      onAuthExpired: function (data, req, res) {
        if (isFunction(options.onAuthExpired)) {
          var ret = options.onAuthExpired(data, req, res);
          if (ret === false) return;
        }
        if (options.redirectOnAuthExpired !== false) {
          redirectToLogin(extend({}, options, { message: getMessage(data, options.unauthorizedMessage) }));
        }
      }
    });
    return true;
  }

  function getRoleId(userOrRole) {
    return toNumber(userOrRole, 0);
  }

  function getVenueId(user) {
    if (!user || typeof user !== 'object') return 0;
    return toNumber(user.venue_id != null ? user.venue_id : user.venueId, 0);
  }

  function getUid(user) {
    if (!user || typeof user !== 'object') return 0;
    return toNumber(user.uid != null ? user.uid : (user.user_uid != null ? user.user_uid : user.userId), 0);
  }

  function hasRole(userOrRole, roles) {
    return contains(roles, getRoleId(userOrRole));
  }

  function isAdmin(userOrRole, roles) {
    return hasRole(userOrRole, roles || config.adminRoles);
  }

  function isVenueRole(userOrRole, roles) {
    return hasRole(userOrRole, roles || config.venueRoles);
  }

  function canViewAllVenues(userOrRole) {
    return isAdmin(userOrRole);
  }

  function qs(selector) {
    if (!selector) return null;
    if (selector.nodeType === 1 || selector === global || selector === document) return selector;
    return document.querySelector(String(selector));
  }

  function qsa(selectors) {
    var result = [];
    toArray(selectors).forEach(function (selector) {
      if (!selector) return;
      if (selector.nodeType === 1) {
        result.push(selector);
      } else {
        var nodes = document.querySelectorAll(String(selector));
        for (var i = 0; i < nodes.length; i++) result.push(nodes[i]);
      }
    });
    return result;
  }

  function setDisplay(selectors, display) {
    var nodes = qsa(selectors);
    for (var i = 0; i < nodes.length; i++) nodes[i].style.display = display;
    return nodes;
  }

  function show(selectors, display) {
    return setDisplay(selectors, display == null ? '' : display);
  }

  function hide(selectors) {
    return setDisplay(selectors, 'none');
  }

  function disable(selectors, disabled) {
    var nodes = qsa(selectors);
    for (var i = 0; i < nodes.length; i++) nodes[i].disabled = disabled !== false;
    return nodes;
  }

  function applyRoleVisibility(userOrRole, options) {
    options = extend({
      adminRoles: config.adminRoles,
      venueRoles: config.venueRoles,
      adminShow: [],
      adminHide: [],
      venueShow: [],
      venueHide: [],
      otherShow: [],
      otherHide: []
    }, options || {});

    var roleId = getRoleId(userOrRole);
    if (contains(options.adminRoles, roleId)) {
      show(options.adminShow);
      hide(options.adminHide);
    } else if (contains(options.venueRoles, roleId)) {
      show(options.venueShow);
      hide(options.venueHide);
    } else {
      show(options.otherShow);
      hide(options.otherHide);
    }
    return roleId;
  }

  function protectPage(options) {
    options = extend({
      redirectOnFail: true,
      rejectOnFail: false,
      onReady: noop,
      onFail: noop
    }, options || {});
    return getCurrentUser(options).then(function (user) {
      if (user) options.onReady(user);
      else options.onFail(null);
      return user;
    }).catch(function (err) {
      options.onFail(err);
      return null;
    });
  }

  var RcAuth = {
    version: '1.0.0',
    setup: function (options) {
      config = extend(config, options || {});
      return RcAuth;
    },
    config: function () {
      return extend({}, config);
    },
    getCurrentUser: getCurrentUser,
    requireLogin: requireLogin,
    loadCurrentUser: loadCurrentUser,
    currentUser: function () { return cacheUser; },
    setCurrentUser: setCurrentUser,
    clearCurrentUser: clearCurrentUser,
    handleResponse: handleResponse,
    handleApiResponse: handleResponse,
    isAuthExpired: isAuthExpired,
    redirectToLogin: redirectToLogin,
    setupRequestAuth: setupRequestAuth,
    install: setupRequestAuth,
    getRoleId: getRoleId,
    getVenueId: getVenueId,
    getUid: getUid,
    hasRole: hasRole,
    isAdmin: isAdmin,
    isVenueRole: isVenueRole,
    isVenueOwner: isVenueRole,
    canViewAllVenues: canViewAllVenues,
    show: show,
    hide: hide,
    disable: disable,
    applyRoleVisibility: applyRoleVisibility,
    protectPage: protectPage
  };

  global.RcAuth = RcAuth;
  global.RcCommon = global.RcCommon || {};
  global.RcCommon.auth = RcAuth;
})(window, document);
