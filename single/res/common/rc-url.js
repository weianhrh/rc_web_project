/*
 * /res/common/rc-url.js
 * RC物联后台 - URL 参数公共函数
 *
 * 目标：把 res/*.html 里重复的 URLSearchParams / getUrlParameter / location.search 处理抽出来。
 * 特点：无依赖；可独立使用；如果页面已加载 window.RcCommon，会自动挂到 RcCommon.url。
 *
 * 推荐引入：
 *   <script src="./common/rc-url.js"></script>
 *
 * 常用：
 *   var id = RcUrl.get('id');
 *   var page = RcUrl.getInt('page', 1);
 *   var params = RcUrl.all();
 *   var url = RcUrl.appendParams('/api/xxx.php', { page: 1, venue_id: 2 });
 */
(function (global, document) {
  'use strict';

  var hasOwn = Object.prototype.hasOwnProperty;
  var slice = Array.prototype.slice;

  function isString(value) {
    return typeof value === 'string' || value instanceof String;
  }

  function isPlainObject(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
  }

  function isURLSearchParams(value) {
    return typeof URLSearchParams !== 'undefined' && value instanceof URLSearchParams;
  }

  function trim(value) {
    return String(value == null ? '' : value).replace(/^\s+|\s+$/g, '');
  }

  function each(obj, fn) {
    if (!obj) return;
    if (Array.isArray(obj)) {
      for (var i = 0; i < obj.length; i++) fn(obj[i], i);
      return;
    }
    if (isURLSearchParams(obj)) {
      obj.forEach(function (value, key) { fn(value, key); });
      return;
    }
    for (var key in obj) {
      if (hasOwn.call(obj, key)) fn(obj[key], key);
    }
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

  function toArray(list) {
    if (!list) return [];
    try {
      return slice.call(list);
    } catch (e) {
      var arr = [];
      for (var i = 0; i < list.length; i++) arr.push(list[i]);
      return arr;
    }
  }

  function encode(value) {
    return encodeURIComponent(String(value == null ? '' : value));
  }

  function decode(value) {
    try {
      return decodeURIComponent(String(value == null ? '' : value).replace(/\+/g, ' '));
    } catch (e) {
      return String(value == null ? '' : value);
    }
  }

  function getCurrentUrl() {
    return global.location ? global.location.href : '';
  }

  function splitHash(url) {
    url = String(url == null ? getCurrentUrl() : url);
    var hash = '';
    var index = url.indexOf('#');
    if (index >= 0) {
      hash = url.slice(index);
      url = url.slice(0, index);
    }
    return { url: url, hash: hash };
  }

  function splitQuery(url) {
    var part = splitHash(url);
    var query = '';
    var base = part.url;
    var index = base.indexOf('?');
    if (index >= 0) {
      query = base.slice(index + 1);
      base = base.slice(0, index);
    }
    return { base: base, query: query, hash: part.hash };
  }

  function getSearch(url) {
    if (url == null && global.location) return String(global.location.search || '').replace(/^\?/, '');
    return splitQuery(url).query;
  }

  function normalizeQuery(input) {
    if (input == null) return '';
    input = String(input);
    if (input.indexOf('?') >= 0 || input.indexOf('#') >= 0) return getSearch(input);
    return input.replace(/^\?/, '');
  }

  function parseQuery(input, options) {
    options = options || {};
    var query = normalizeQuery(input);
    var result = {};
    if (!query) return result;

    var pairs = query.split('&');
    for (var i = 0; i < pairs.length; i++) {
      var pair = pairs[i];
      if (!pair) continue;
      var eq = pair.indexOf('=');
      var key = eq >= 0 ? pair.slice(0, eq) : pair;
      var value = eq >= 0 ? pair.slice(eq + 1) : '';
      key = decode(key);
      value = decode(value);
      if (!key && options.keepEmptyKey !== true) continue;

      if (hasOwn.call(result, key)) {
        if (!Array.isArray(result[key])) result[key] = [result[key]];
        result[key].push(value);
      } else {
        result[key] = value;
      }
    }
    return result;
  }

  function shouldSkipValue(value, options) {
    options = options || {};
    if (value === undefined || value === null) return true;
    if (value === '' && options.keepEmpty !== true) return true;
    if (Array.isArray(value) && value.length === 0) return true;
    return false;
  }

  function appendPair(parts, key, value, options) {
    options = options || {};
    if (shouldSkipValue(value, options)) return;

    if (Array.isArray(value)) {
      for (var i = 0; i < value.length; i++) appendPair(parts, key, value[i], options);
      return;
    }

    if (value instanceof Date) value = value.toISOString();
    if (isPlainObject(value)) value = JSON.stringify(value);

    parts.push(encode(key) + '=' + encode(value));
  }

  function toQueryString(params, options) {
    if (!params) return '';
    if (isString(params)) return trim(params).replace(/^\?/, '');
    if (isURLSearchParams(params)) return params.toString();

    var parts = [];
    each(params, function (value, key) {
      appendPair(parts, key, value, options);
    });
    return parts.join('&');
  }

  function appendParams(url, params, options) {
    var query = toQueryString(params, options);
    if (!query) return String(url == null ? '' : url);
    var part = splitHash(url);
    return part.url + (part.url.indexOf('?') >= 0 ? '&' : '?') + query + part.hash;
  }

  function setParams(url, params, options) {
    options = options || {};
    var part = splitQuery(url == null ? getCurrentUrl() : url);
    var current = parseQuery(part.query, { keepEmptyKey: true });
    each(params, function (value, key) {
      if (shouldSkipValue(value, options) && options.removeEmpty !== false) {
        delete current[key];
      } else {
        current[key] = value;
      }
    });
    var query = toQueryString(current, options);
    return part.base + (query ? '?' + query : '') + part.hash;
  }

  function removeParams(url, names) {
    var part = splitQuery(url == null ? getCurrentUrl() : url);
    var current = parseQuery(part.query, { keepEmptyKey: true });
    if (!Array.isArray(names)) names = [names];
    for (var i = 0; i < names.length; i++) {
      delete current[names[i]];
    }
    var query = toQueryString(current);
    return part.base + (query ? '?' + query : '') + part.hash;
  }

  function get(name, defaultValue, url) {
    var params = parseQuery(url == null ? getCurrentUrl() : url);
    if (hasOwn.call(params, name)) {
      var value = params[name];
      return Array.isArray(value) ? value[value.length - 1] : value;
    }
    return defaultValue == null ? '' : defaultValue;
  }

  function getAll(name, url) {
    var params = parseQuery(url == null ? getCurrentUrl() : url);
    if (!hasOwn.call(params, name)) return [];
    return Array.isArray(params[name]) ? params[name] : [params[name]];
  }

  function has(name, url) {
    var params = parseQuery(url == null ? getCurrentUrl() : url);
    return hasOwn.call(params, name);
  }

  function getNumber(name, defaultValue, url) {
    var value = get(name, defaultValue, url);
    var num = Number(value);
    return isNaN(num) ? (defaultValue == null ? 0 : defaultValue) : num;
  }

  function getInt(name, defaultValue, url) {
    var num = parseInt(get(name, defaultValue, url), 10);
    return isNaN(num) ? (defaultValue == null ? 0 : defaultValue) : num;
  }

  function getBool(name, defaultValue, url) {
    var value = get(name, undefined, url);
    if (value === undefined || value === '') return defaultValue == null ? false : defaultValue;
    if (value === true || value === 'true' || value === '1' || value === 1 || value === 'yes' || value === 'on') return true;
    if (value === false || value === 'false' || value === '0' || value === 0 || value === 'no' || value === 'off') return false;
    return Boolean(value);
  }

  function all(url) {
    return parseQuery(url == null ? getCurrentUrl() : url);
  }

  function pick(names, url) {
    var params = all(url);
    var result = {};
    if (!Array.isArray(names)) names = [names];
    for (var i = 0; i < names.length; i++) {
      var key = names[i];
      if (hasOwn.call(params, key)) result[key] = params[key];
    }
    return result;
  }

  function omit(names, url) {
    var params = all(url);
    if (!Array.isArray(names)) names = [names];
    for (var i = 0; i < names.length; i++) delete params[names[i]];
    return params;
  }

  function getHash(url) {
    url = String(url == null ? getCurrentUrl() : url);
    var index = url.indexOf('#');
    return index >= 0 ? url.slice(index + 1) : '';
  }

  function hashParams(url) {
    var hash = getHash(url);
    var index = hash.indexOf('?');
    if (index >= 0) hash = hash.slice(index + 1);
    return parseQuery(hash);
  }

  function updateLocation(params, options) {
    options = extend({ replace: true, removeEmpty: true }, options || {});
    if (!global.history || !global.location) return '';
    var nextUrl = setParams(global.location.href, params, options);
    if (options.replace) {
      global.history.replaceState(options.state || null, document.title, nextUrl);
    } else {
      global.history.pushState(options.state || null, document.title, nextUrl);
    }
    return nextUrl;
  }

  function copyToForm(map, root, url) {
    root = root || document;
    var result = {};
    each(map || {}, function (selector, key) {
      var el = root.querySelector(selector);
      var value = get(key, '', url);
      if (el) el.value = value;
      result[key] = value;
    });
    return result;
  }

  function queryFromElements(fields, root) {
    root = root || document;
    var params = {};
    each(fields || {}, function (selector, key) {
      var el = typeof selector === 'string' ? root.querySelector(selector) : selector;
      if (!el) return;
      if (el.type === 'checkbox') {
        if (el.checked) params[key] = el.value || 1;
      } else {
        params[key] = el.value;
      }
    });
    return params;
  }

  var RcUrl = {
    version: '1.0.0',
    parse: parseQuery,
    all: all,
    toObject: all,
    get: get,
    getParam: get,
    getUrlParameter: get,
    getAll: getAll,
    has: has,
    getNumber: getNumber,
    getInt: getInt,
    getBool: getBool,
    pick: pick,
    omit: omit,
    getSearch: getSearch,
    getHash: getHash,
    hashParams: hashParams,
    toQueryString: toQueryString,
    appendParams: appendParams,
    setParams: setParams,
    removeParams: removeParams,
    updateLocation: updateLocation,
    replace: function (params, options) {
      return updateLocation(params, extend({ replace: true }, options || {}));
    },
    push: function (params, options) {
      return updateLocation(params, extend({ replace: false }, options || {}));
    },
    copyToForm: copyToForm,
    queryFromElements: queryFromElements,
    splitQuery: splitQuery,
    splitHash: splitHash,
    toArray: toArray
  };

  global.RcUrl = RcUrl;
  global.RcCommon = global.RcCommon || {};
  global.RcCommon.url = RcUrl;

  // 兼容老页面：删掉页面里自定义 getUrlParameter 后，也能继续使用。
  if (typeof global.getUrlParameter === 'undefined') {
    global.getUrlParameter = function (name, defaultValue) {
      return RcUrl.get(name, defaultValue);
    };
  }
})(window, document);
