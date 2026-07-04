/*
 * /res/common/rc-venue.js
 * RC物联后台 - 场地下拉 / get_venues / venue_id 公共函数
 *
 * 目标：把 res/*.html 里重复的 action=get_venues、场地下拉填充、管理员/场地方 venue_id 处理抽出来。
 * 特点：无依赖；如果已引入 rc-request.js，会优先复用 RcRequest；如果页面已加载 window.RcCommon，会自动挂到 RcCommon.venue。
 *
 * 推荐引入：
 *   <script src="./common/rc-request.js"></script>
 *   <script src="./common/rc-auth.js"></script>
 *   <script src="./common/rc-venue.js"></script>
 *
 * 常用：
 *   RcVenue.loadSelect('#venue_id', { includeAll: true });
 *   RcVenue.loadSelectForUser('#venue_id', currentUser, { container: '#venue_filter_box' });
 */
(function (global, document) {
  'use strict';

  var hasOwn = Object.prototype.hasOwnProperty;

  var defaults = {
    api: '/api/operat/EnergyGift.php',
    method: 'POST',
    action: 'get_venues',
    data: null,
    credentials: 'include',
    adminRoles: [1, 2],
    venueRoles: [3, 4],
    includeAll: false,
    allText: '全部场地',
    allValue: '',
    placeholder: '',
    selectedValue: null,
    disabled: false,
    clear: true,
    labelSeparator: ' - ',
    idField: 'id',
    nameField: 'venue_name',
    renderLayui: true,
    onLoaded: null,
    onError: null
  };

  var config = extend({}, defaults);
  var cacheList = null;
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

  function contains(list, value) {
    list = toArray(list);
    value = Number(value);
    for (var i = 0; i < list.length; i++) {
      if (Number(list[i]) === value) return true;
    }
    return false;
  }

  function getRoleId(userOrRole) {
    if (global.RcAuth && isFunction(global.RcAuth.getRoleId)) return global.RcAuth.getRoleId(userOrRole);
    if (userOrRole && typeof userOrRole === 'object') userOrRole = userOrRole.role_id != null ? userOrRole.role_id : userOrRole.roleId;
    var n = Number(userOrRole);
    return isNaN(n) ? 0 : n;
  }

  function getVenueIdFromUser(user) {
    if (global.RcAuth && isFunction(global.RcAuth.getVenueId)) return global.RcAuth.getVenueId(user);
    if (!user || typeof user !== 'object') return 0;
    var n = Number(user.venue_id != null ? user.venue_id : user.venueId);
    return isNaN(n) ? 0 : n;
  }

  function isAdmin(userOrRole, options) {
    options = options || config;
    return contains(options.adminRoles || config.adminRoles, getRoleId(userOrRole));
  }

  function qs(target) {
    if (!target) return null;
    if (target.nodeType === 1 || target === document || target === global) return target;
    return document.querySelector(String(target));
  }

  function qsa(targets) {
    var result = [];
    toArray(targets).forEach(function (target) {
      if (!target) return;
      if (target.nodeType === 1) {
        result.push(target);
      } else {
        var nodes = document.querySelectorAll(String(target));
        for (var i = 0; i < nodes.length; i++) result.push(nodes[i]);
      }
    });
    return result;
  }

  function show(targets, display) {
    var nodes = qsa(targets);
    for (var i = 0; i < nodes.length; i++) nodes[i].style.display = display == null ? '' : display;
    return nodes;
  }

  function hide(targets) {
    var nodes = qsa(targets);
    for (var i = 0; i < nodes.length; i++) nodes[i].style.display = 'none';
    return nodes;
  }

  function setLoading(select, text) {
    if (!select || select.tagName !== 'SELECT') return;
    select.innerHTML = '<option value="">' + escapeHtml(text || '加载中...') + '</option>';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function toQueryString(data) {
    if (!data) return '';
    if (typeof data === 'string') return data.replace(/^\?/, '');
    if (global.RcUrl && isFunction(global.RcUrl.toQueryString)) return global.RcUrl.toQueryString(data);
    if (global.RcRequest && isFunction(global.RcRequest.toQueryString)) return global.RcRequest.toQueryString(data);
    var parts = [];
    for (var key in data) {
      if (hasOwn.call(data, key) && data[key] !== undefined && data[key] !== null && data[key] !== '') {
        parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
      }
    }
    return parts.join('&');
  }

  function request(options) {
    options = extend({}, config, options || {});
    var api = options.api;
    var method = String(options.method || 'GET').toUpperCase();
    var data = options.data || {};

    if (options.action && !data.action && api.indexOf('?get_venues') < 0) {
      data = extend({}, data, { action: options.action });
    }

    if (api.indexOf('?get_venues') >= 0) method = 'GET';

    if (global.RcRequest) {
      if (method === 'POST' && isFunction(global.RcRequest.post)) {
        return global.RcRequest.post(api, data, options.requestOptions || {});
      }
      if (isFunction(global.RcRequest.get)) {
        return global.RcRequest.get(api, method === 'GET' ? options.params : null, options.requestOptions || {});
      }
    }

    if (method === 'POST') {
      return fetch(api, {
        method: 'POST',
        credentials: options.credentials || 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: toQueryString(data)
      }).then(parseJson);
    }

    var finalUrl = api;
    if (options.params) {
      if (global.RcUrl && isFunction(global.RcUrl.appendParams)) finalUrl = global.RcUrl.appendParams(finalUrl, options.params);
      else {
        var qs = toQueryString(options.params);
        if (qs) finalUrl += (finalUrl.indexOf('?') >= 0 ? '&' : '?') + qs;
      }
    }

    return fetch(finalUrl, {
      method: 'GET',
      credentials: options.credentials || 'include'
    }).then(parseJson);
  }

  function parseJson(res) {
    return res.text().then(function (text) {
      if (!text) return {};
      try { return JSON.parse(text); } catch (e) { return { code: res.ok ? 0 : res.status, data: text, msg: text }; }
    });
  }

  function normalizeVenue(item) {
    item = item || {};
    var id = item.id != null ? item.id : (item.venue_id != null ? item.venue_id : item.value);
    var name = item.venue_name != null ? item.venue_name : (item.name != null ? item.name : (item.label != null ? item.label : item.title));
    var next = extend({}, item);
    next.id = id;
    next.venue_id = id;
    next.venue_name = name == null ? '' : name;
    next.name = next.name == null ? next.venue_name : next.name;
    return next;
  }

  function normalizeResponse(res, options) {
    options = options || {};
    var raw = res;
    var list = [];

    if (Array.isArray(raw)) list = raw;
    else if (raw && Array.isArray(raw.data)) list = raw.data;
    else if (raw && raw.data && Array.isArray(raw.data.venues)) list = raw.data.venues;
    else if (raw && raw.data && Array.isArray(raw.data.list)) list = raw.data.list;
    else if (raw && Array.isArray(raw.venues)) list = raw.venues;
    else if (raw && Array.isArray(raw.list)) list = raw.list;

    list = list.map(normalizeVenue);
    if (isFunction(options.mapList)) list = options.mapList(list, raw) || [];
    return list;
  }

  function fetchVenues(options) {
    options = extend({}, config, options || {});
    var now = Date.now();
    if (options.cache !== false && cacheList && now - cacheTime < Number(options.cacheTtl || 30000)) {
      return Promise.resolve(cacheList.slice());
    }
    if (loadingPromise && options.force !== true) return loadingPromise;

    loadingPromise = request(options).then(function (res) {
      loadingPromise = null;
      if (global.RcAuth && isFunction(global.RcAuth.handleResponse)) {
        global.RcAuth.handleResponse(res, { redirectOnAuthExpired: options.redirectOnAuthExpired === true });
      }
      var list = normalizeResponse(res, options);
      cacheList = list.slice();
      cacheTime = Date.now();
      if (isFunction(options.onLoaded)) options.onLoaded(list, res);
      return list;
    }).catch(function (err) {
      loadingPromise = null;
      if (isFunction(options.onError)) options.onError(err);
      else if (global.console && console.warn) console.warn('场地列表加载失败:', err);
      if (options.rejectOnError === false) return [];
      throw err;
    });

    return loadingPromise;
  }

  function getVenueValue(venue, options) {
    options = options || config;
    if (!venue) return '';
    return venue[options.idField || 'id'] != null ? venue[options.idField || 'id'] : venue.id;
  }

  function getVenueName(venue, options) {
    options = options || config;
    if (!venue) return '';
    var nameField = options.nameField || 'venue_name';
    return venue[nameField] != null ? venue[nameField] : (venue.venue_name || venue.name || venue.label || '');
  }

  function formatLabel(venue, options) {
    options = options || config;
    if (isFunction(options.formatLabel)) return options.formatLabel(venue);
    if (isFunction(options.getLabel)) return options.getLabel(venue);
    var id = getVenueValue(venue, options);
    var name = getVenueName(venue, options);
    if (options.nameOnly) return String(name || id || '');
    return [id, name].filter(function (v) { return v !== undefined && v !== null && v !== ''; }).join(options.labelSeparator || ' - ');
  }

  function createOption(value, label, venue) {
    var option = document.createElement('option');
    option.value = String(value == null ? '' : value);
    option.textContent = String(label == null ? '' : label);
    if (venue) {
      option.dataset.venueId = String(venue.id == null ? value : venue.id);
      option.dataset.venueName = String(venue.venue_name || venue.name || '');
      option.__venue = venue;
    }
    return option;
  }

  function renderLayui(select, options) {
    options = options || {};
    if (options.renderLayui === false) return;
    try {
      if (global.layui && layui.form && isFunction(layui.form.render)) {
        layui.form.render('select');
      }
    } catch (e) {}
  }

  function populateSelect(target, venues, options) {
    options = extend({}, config, options || {});
    var select = qs(target);
    if (!select) return [];
    venues = (venues || []).map(normalizeVenue);

    if (select.tagName !== 'SELECT') {
      return venues;
    }

    if (options.clear !== false) select.innerHTML = '';

    if (options.placeholder) {
      select.appendChild(createOption('', options.placeholder));
    }

    if (options.includeAll) {
      select.appendChild(createOption(options.allValue, options.allText));
    }

    if (options.includeEmpty) {
      select.appendChild(createOption(options.emptyValue || '', options.emptyText || '请选择场地'));
    }

    for (var i = 0; i < venues.length; i++) {
      var venue = venues[i];
      var value = getVenueValue(venue, options);
      select.appendChild(createOption(value, formatLabel(venue, options), venue));
    }

    if (options.selectedValue !== null && options.selectedValue !== undefined) {
      select.value = String(options.selectedValue);
    }

    select.disabled = options.disabled === true;
    renderLayui(select, options);
    return venues;
  }

  function loadSelect(target, options) {
    options = extend({}, config, options || {});
    var select = qs(target);
    if (select && options.loadingText !== false) setLoading(select, options.loadingText || '场地加载中...');
    return fetchVenues(options).then(function (venues) {
      populateSelect(target, venues, options);
      return venues;
    }).catch(function (err) {
      if (select) setLoading(select, options.errorText || '场地加载失败');
      if (options.rejectOnError === false) return [];
      throw err;
    });
  }

  function ensureVenueOption(target, venueId, venueName, options) {
    var select = qs(target);
    if (!select || select.tagName !== 'SELECT') return null;
    venueId = String(venueId == null ? '' : venueId);
    var old = null;
    for (var i = 0; i < select.options.length; i++) {
      if (select.options[i].value === venueId) old = select.options[i];
    }
    if (old) {
      old.selected = true;
      return old;
    }
    var label = venueName ? venueId + (options && options.labelSeparator || ' - ') + venueName : venueId;
    var option = createOption(venueId, label, { id: venueId, venue_name: venueName || '' });
    option.selected = true;
    select.appendChild(option);
    renderLayui(select, options || {});
    return option;
  }

  function setValue(target, value) {
    var el = qs(target);
    if (!el) return null;
    el.value = value == null ? '' : String(value);
    renderLayui(el, {});
    return el;
  }

  function getValue(target, fallback) {
    var el = qs(target);
    if (!el) return fallback == null ? '' : fallback;
    return el.value || (fallback == null ? '' : fallback);
  }

  function getSelectedVenue(target) {
    var select = qs(target);
    if (!select || select.tagName !== 'SELECT') return null;
    var option = select.options[select.selectedIndex];
    if (!option) return null;
    return option.__venue || {
      id: option.value,
      venue_id: option.value,
      venue_name: option.dataset.venueName || option.textContent
    };
  }

  function loadSelectForUser(target, user, options) {
    options = extend({
      container: null,
      hideForVenueRole: true,
      lockForVenueRole: true,
      includeAll: true,
      allText: '全部场地',
      allValue: '',
      rejectOnError: false
    }, options || {});

    var roleId = getRoleId(user);
    var select = qs(target);
    var venueId = getVenueIdFromUser(user);

    if (isAdmin(roleId, options)) {
      if (options.container) show(options.container, options.containerDisplay || '');
      if (select) select.disabled = false;
      return loadSelect(target, options);
    }

    if (options.container && options.hideForVenueRole) hide(options.container);
    if (select) {
      ensureVenueOption(select, venueId, user && (user.venue_name || user.name), options);
      select.value = String(venueId || '');
      if (options.lockForVenueRole) select.disabled = true;
      renderLayui(select, options);
    }
    return Promise.resolve([{ id: venueId, venue_id: venueId, venue_name: user && (user.venue_name || user.name) || '' }]);
  }

  function bindChange(target, handler) {
    var el = qs(target);
    if (!el || !isFunction(handler)) return null;
    el.addEventListener('change', function () {
      handler(getValue(el), getSelectedVenue(el), el);
    });
    return el;
  }

  function findById(list, id) {
    list = list || cacheList || [];
    id = String(id == null ? '' : id);
    for (var i = 0; i < list.length; i++) {
      if (String(list[i].id) === id || String(list[i].venue_id) === id) return list[i];
    }
    return null;
  }

  function clearCache() {
    cacheList = null;
    cacheTime = 0;
    loadingPromise = null;
  }

  var RcVenue = {
    version: '1.0.0',
    setup: function (options) {
      config = extend(config, options || {});
      return RcVenue;
    },
    config: function () { return extend({}, config); },
    fetch: fetchVenues,
    fetchVenues: fetchVenues,
    normalizeResponse: normalizeResponse,
    normalizeVenue: normalizeVenue,
    populateSelect: populateSelect,
    loadSelect: loadSelect,
    loadVenues: loadSelect,
    loadSelectForUser: loadSelectForUser,
    loadForUser: loadSelectForUser,
    ensureOption: ensureVenueOption,
    ensureVenueOption: ensureVenueOption,
    setValue: setValue,
    getValue: getValue,
    selected: getSelectedVenue,
    getSelectedVenue: getSelectedVenue,
    bindChange: bindChange,
    findById: findById,
    clearCache: clearCache,
    isAdmin: isAdmin,
    show: show,
    hide: hide
  };

  global.RcVenue = RcVenue;
  global.RcCommon = global.RcCommon || {};
  global.RcCommon.venue = RcVenue;
})(window, document);
