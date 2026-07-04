/*
 * /res/common/rc-request.js
 * RC物联后台 - 请求公共函数
 *
 * 目标：把 res/*.html 里重复的 fetch / $.ajax / XMLHttpRequest 请求、GET 参数拼接、POST 表单、上传、登录过期处理抽出来。
 * 特点：无依赖；默认携带 cookie；优先 fetch，浏览器不支持 fetch 时自动降级 XMLHttpRequest；如果页面已加载 window.RcCommon，会自动挂到 RcCommon.request。
 *
 * 推荐引入：
 *   <script src="./common/rc-request.js"></script>
 *
 * 常用：
 *   RcRequest.get('/api/xxx.php', { page: 1 }).then(function (res) {});
 *   RcRequest.post('/api/xxx.php', { uid: 10001, amount: 6 }).then(function (res) {});
 *   RcRequest.upload('/api/upload.php', formData).then(function (res) {});
 */
(function (global, document) {
  'use strict';

  var hasOwn = Object.prototype.hasOwnProperty;
  var slice = Array.prototype.slice;

  var defaults = {
    baseUrl: '',
    timeout: 30000,
    credentials: 'include',
    withCredentials: true,
    parse: 'auto',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    throwHttpError: true,
    rejectOnAuthExpired: false,
    redirectOnAuthExpired: false,
    authRedirectUrl: '',
    authCodes: [1001],
    showError: false,
    beforeRequest: null,
    afterResponse: null,
    onError: null,
    onAuthExpired: null,
    requestInterceptors: [],
    responseInterceptors: [],
    errorInterceptors: []
  };

  function noop() {}

  function isFunction(value) {
    return typeof value === 'function';
  }

  function isString(value) {
    return typeof value === 'string' || value instanceof String;
  }

  function isPlainObject(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
  }

  function isFormData(value) {
    return typeof FormData !== 'undefined' && value instanceof FormData;
  }

  function isURLSearchParams(value) {
    return typeof URLSearchParams !== 'undefined' && value instanceof URLSearchParams;
  }

  function isBlob(value) {
    return typeof Blob !== 'undefined' && value instanceof Blob;
  }

  function isFile(value) {
    return typeof File !== 'undefined' && value instanceof File;
  }

  function isFileList(value) {
    return typeof FileList !== 'undefined' && value instanceof FileList;
  }

  function isFormElement(value) {
    return value && value.nodeType === 1 && String(value.tagName || '').toLowerCase() === 'form';
  }

  function trim(value) {
    return String(value == null ? '' : value).replace(/^\s+|\s+$/g, '');
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

  function clone(obj) {
    return extend({}, obj || {});
  }

  function each(obj, fn) {
    if (!obj) return;
    if (Array.isArray(obj) || isFileList(obj)) {
      for (var i = 0; i < obj.length; i++) fn(obj[i], i);
      return;
    }
    for (var key in obj) {
      if (hasOwn.call(obj, key)) fn(obj[key], key);
    }
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

  function mergeHeaders() {
    var result = {};
    for (var i = 0; i < arguments.length; i++) {
      var source = arguments[i] || {};
      for (var key in source) {
        if (hasOwn.call(source, key) && source[key] !== undefined && source[key] !== null) {
          result[key] = source[key];
        }
      }
    }
    return result;
  }

  function findHeaderName(headers, name) {
    name = String(name || '').toLowerCase();
    for (var key in headers) {
      if (hasOwn.call(headers, key) && String(key).toLowerCase() === name) return key;
    }
    return null;
  }

  function getHeader(headers, name) {
    var key = findHeaderName(headers || {}, name);
    return key ? headers[key] : undefined;
  }

  function setHeader(headers, name, value) {
    if (value === false) {
      removeHeader(headers, name);
      return headers;
    }
    var key = findHeaderName(headers, name) || name;
    headers[key] = value;
    return headers;
  }

  function removeHeader(headers, name) {
    var key = findHeaderName(headers || {}, name);
    if (key) delete headers[key];
    return headers;
  }

  function isAbsoluteUrl(url) {
    return /^([a-z][a-z\d+\-.]*:)?\/\//i.test(String(url || ''));
  }

  function joinBaseUrl(baseUrl, url) {
    url = String(url == null ? '' : url);
    baseUrl = String(baseUrl || '');
    if (!baseUrl || isAbsoluteUrl(url) || url.charAt(0) === '/' || url.indexOf('./') === 0 || url.indexOf('../') === 0) return url;
    return baseUrl.replace(/\/+$/, '') + '/' + url.replace(/^\/+/, '');
  }

  function shouldSkipParam(value, options) {
    options = options || {};
    if (value === undefined || value === null) return true;
    if (value === '' && options.keepEmpty !== true) return true;
    return false;
  }

  function appendPair(parts, key, value, options) {
    options = options || {};
    if (shouldSkipParam(value, options)) return;

    if (Array.isArray(value) || isFileList(value)) {
      each(value, function (item) {
        appendPair(parts, key, item, options);
      });
      return;
    }

    if (value instanceof Date) value = value.toISOString();
    if (isPlainObject(value)) value = JSON.stringify(value);

    parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
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
    if (!query) return String(url || '');
    url = String(url || '');
    var hash = '';
    var hashIndex = url.indexOf('#');
    if (hashIndex >= 0) {
      hash = url.slice(hashIndex);
      url = url.slice(0, hashIndex);
    }
    return url + (url.indexOf('?') >= 0 ? '&' : '?') + query + hash;
  }

  function formToObject(form) {
    var obj = {};
    if (!isFormElement(form)) return obj;
    var fields = form.elements || [];
    for (var i = 0; i < fields.length; i++) {
      var el = fields[i];
      if (!el || !el.name || el.disabled) continue;
      var type = String(el.type || '').toLowerCase();
      if ((type === 'checkbox' || type === 'radio') && !el.checked) continue;
      if (type === 'file') {
        obj[el.name] = el.files;
      } else if (el.tagName && String(el.tagName).toLowerCase() === 'select' && el.multiple) {
        var arr = [];
        for (var j = 0; j < el.options.length; j++) {
          if (el.options[j].selected) arr.push(el.options[j].value);
        }
        obj[el.name] = arr;
      } else {
        obj[el.name] = el.value;
      }
    }
    return obj;
  }

  function hasFileValue(data) {
    var found = false;
    if (!data) return false;
    if (isBlob(data) || isFile(data) || isFileList(data) || isFormData(data)) return true;
    if (isPlainObject(data)) {
      each(data, function (value) {
        if (found) return;
        if (isBlob(value) || isFile(value) || isFileList(value)) {
          found = true;
        } else if (Array.isArray(value)) {
          for (var i = 0; i < value.length; i++) {
            if (isBlob(value[i]) || isFile(value[i])) {
              found = true;
              break;
            }
          }
        }
      });
    }
    return found;
  }

  function toFormData(data, options) {
    options = options || {};
    if (isFormData(data)) return data;
    if (isFormElement(data)) return new FormData(data);

    var fd = new FormData();
    each(data || {}, function (value, key) {
      if (value === undefined || value === null) return;
      if (Array.isArray(value) || isFileList(value)) {
        each(value, function (item) {
          if (item !== undefined && item !== null) fd.append(key, item);
        });
      } else if (isPlainObject(value) && options.stringifyObject !== false) {
        fd.append(key, JSON.stringify(value));
      } else {
        fd.append(key, value);
      }
    });
    return fd;
  }

  function makeError(message, code, extra) {
    var err = new Error(message || '请求失败');
    err.name = code || 'RcRequestError';
    err.code = code || 'REQUEST_ERROR';
    if (extra) extend(err, extra);
    return err;
  }

  function tryParseJson(text) {
    if (text === '' || text === null || text === undefined) return null;
    return JSON.parse(text);
  }

  function parseText(text, contentType, parseMode) {
    contentType = String(contentType || '').toLowerCase();
    parseMode = parseMode || 'auto';

    if (parseMode === 'text') return text;
    if (parseMode === 'json') return tryParseJson(text);
    if (parseMode === 'auto') {
      var t = trim(text);
      if (contentType.indexOf('application/json') >= 0 || contentType.indexOf('+json') >= 0) {
        return tryParseJson(text);
      }
      if (t.charAt(0) === '{' || t.charAt(0) === '[') {
        try {
          return JSON.parse(t);
        } catch (e) {
          return text;
        }
      }
    }
    return text;
  }

  function normalizeMethod(method) {
    return String(method || 'GET').toUpperCase();
  }

  function isGetLike(method) {
    method = normalizeMethod(method);
    return method === 'GET' || method === 'HEAD';
  }

  function buildBody(req) {
    var method = normalizeMethod(req.method);
    var data = req.data;
    var headers = req.headers;
    var bodyType = req.bodyType;
    var contentType = getHeader(headers, 'Content-Type');

    if (data === undefined || data === null) return undefined;
    if (isGetLike(method)) return undefined;

    if (req.processData === false || bodyType === 'raw') {
      if (req.contentType === false) removeHeader(headers, 'Content-Type');
      return data;
    }

    if (isFormData(data) || isFormElement(data) || bodyType === 'multipart' || hasFileValue(data)) {
      removeHeader(headers, 'Content-Type');
      return toFormData(data, req);
    }

    if (isURLSearchParams(data)) {
      if (!contentType) setHeader(headers, 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      return data.toString();
    }

    if (bodyType === 'json' || String(contentType || '').toLowerCase().indexOf('application/json') >= 0) {
      setHeader(headers, 'Content-Type', 'application/json; charset=UTF-8');
      return isString(data) ? data : JSON.stringify(data);
    }

    if (isString(data)) {
      if (!contentType && req.contentType !== false) setHeader(headers, 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      return data;
    }

    if (isPlainObject(data)) {
      if (!contentType && req.contentType !== false) setHeader(headers, 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      return toQueryString(data, req);
    }

    return data;
  }

  function normalizeRequest(url, options) {
    options = options || {};
    var cfg = getConfig(options);
    var method = normalizeMethod(options.method || options.type || cfg.method || 'GET');
    var headers = mergeHeaders(cfg.headers, options.headers);

    if (options.contentType === false) removeHeader(headers, 'Content-Type');
    if (options.contentType && options.contentType !== false) setHeader(headers, 'Content-Type', options.contentType);

    var req = extend({}, cfg, options, {
      url: joinBaseUrl(options.baseUrl !== undefined ? options.baseUrl : cfg.baseUrl, url),
      method: method,
      headers: headers,
      data: options.data !== undefined ? options.data : options.params
    });

    if (isGetLike(method) && req.data !== undefined && req.data !== null) {
      req.url = appendParams(req.url, req.data, req);
      req.data = undefined;
    }

    req.body = options.body !== undefined ? options.body : buildBody(req);
    return req;
  }

  function getResponseHeadersFromFetch(headers) {
    var obj = {};
    if (!headers || !headers.forEach) return obj;
    headers.forEach(function (value, key) {
      obj[key] = value;
    });
    return obj;
  }

  function parseFetchResponse(response, req) {
    var contentType = response.headers && response.headers.get ? response.headers.get('Content-Type') : '';

    if (req.responseType === 'blob' || req.parse === 'blob') {
      return response.blob().then(function (blob) {
        return {
          ok: response.ok,
          status: response.status,
          statusText: response.statusText,
          url: response.url,
          headers: getResponseHeadersFromFetch(response.headers),
          data: blob,
          text: '',
          raw: response,
          request: req
        };
      });
    }

    return response.text().then(function (text) {
      var data;
      try {
        data = parseText(text, contentType, req.parse);
      } catch (e) {
        throw makeError('响应 JSON 解析失败', 'PARSE_ERROR', {
          cause: e,
          responseText: text,
          status: response.status,
          request: req
        });
      }
      return {
        ok: response.ok,
        status: response.status,
        statusText: response.statusText,
        url: response.url,
        headers: getResponseHeadersFromFetch(response.headers),
        data: data,
        text: text,
        raw: response,
        request: req
      };
    });
  }

  function sendFetch(req) {
    var controller = null;
    var timer = null;
    var init = {
      method: req.method,
      headers: req.headers,
      body: req.body,
      credentials: req.credentials,
      cache: req.cache,
      mode: req.mode
    };

    if (req.timeout > 0 && typeof AbortController !== 'undefined') {
      controller = new AbortController();
      init.signal = controller.signal;
      timer = setTimeout(function () {
        controller.abort();
      }, req.timeout);
    }

    return fetch(req.url, init).then(function (response) {
      if (timer) clearTimeout(timer);
      return parseFetchResponse(response, req);
    }, function (err) {
      if (timer) clearTimeout(timer);
      if (err && err.name === 'AbortError') {
        throw makeError('请求超时', 'TIMEOUT', { cause: err, request: req });
      }
      throw makeError(err && err.message ? err.message : '网络请求失败', 'NETWORK_ERROR', { cause: err, request: req });
    });
  }

  function sendXHR(req) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open(req.method, req.url, true);
      xhr.withCredentials = req.withCredentials !== false;
      if (req.timeout > 0) xhr.timeout = req.timeout;

      for (var key in req.headers) {
        if (hasOwn.call(req.headers, key) && req.headers[key] !== undefined && req.headers[key] !== null) {
          xhr.setRequestHeader(key, req.headers[key]);
        }
      }

      xhr.onload = function () {
        var contentType = xhr.getResponseHeader('Content-Type') || '';
        var text = xhr.responseText;
        var data;
        try {
          data = parseText(text, contentType, req.parse);
        } catch (e) {
          reject(makeError('响应 JSON 解析失败', 'PARSE_ERROR', {
            cause: e,
            responseText: text,
            status: xhr.status,
            request: req
          }));
          return;
        }
        resolve({
          ok: xhr.status >= 200 && xhr.status < 300,
          status: xhr.status,
          statusText: xhr.statusText,
          url: req.url,
          headers: {},
          data: data,
          text: text,
          raw: xhr,
          request: req
        });
      };

      xhr.onerror = function () {
        reject(makeError('网络请求失败', 'NETWORK_ERROR', { request: req, raw: xhr }));
      };

      xhr.ontimeout = function () {
        reject(makeError('请求超时', 'TIMEOUT', { request: req, raw: xhr }));
      };

      xhr.send(req.body === undefined ? null : req.body);
    });
  }

  function isAuthExpired(data, cfg) {
    if (!data || typeof data !== 'object') return false;
    var code = data.code;
    var authCodes = cfg.authCodes || [];
    for (var i = 0; i < authCodes.length; i++) {
      if (String(code) === String(authCodes[i])) return true;
    }
    return false;
  }

  function redirectToLogin(url) {
    if (!url) return;
    try {
      if (global.top && global.top !== global) {
        global.top.location.href = url;
      } else {
        global.location.href = url;
      }
    } catch (e) {
      global.location.href = url;
    }
  }

  function getMessageFromResponse(data, fallback) {
    if (data && typeof data === 'object') {
      return data.message || data.msg || data.error || fallback;
    }
    return fallback;
  }

  function showErrorMessage(err) {
    var msg = err && (err.message || err.msg) ? (err.message || err.msg) : '请求失败';
    if (global.layer && isFunction(global.layer.msg)) {
      global.layer.msg(msg);
    } else if (global.layui && global.layui.layer && isFunction(global.layui.layer.msg)) {
      global.layui.layer.msg(msg);
    } else if (global.console && console.error) {
      console.error(msg, err);
    }
  }

  function runInterceptors(list, value, req) {
    list = list || [];
    var result = value;
    for (var i = 0; i < list.length; i++) {
      if (isFunction(list[i])) {
        var next = list[i](result, req);
        if (next !== undefined) result = next;
      }
    }
    return result;
  }

  function handleResponse(res, req) {
    var cfg = req;

    if (cfg.throwHttpError !== false && !res.ok) {
      throw makeError('HTTP 请求失败：' + res.status, 'HTTP_ERROR', {
        status: res.status,
        statusText: res.statusText,
        response: res,
        request: req
      });
    }

    if (cfg.afterResponse && isFunction(cfg.afterResponse)) {
      var after = cfg.afterResponse(res, req);
      if (after !== undefined) res = after;
    }

    res = runInterceptors(cfg.responseInterceptors, res, req);

    if (isAuthExpired(res.data, cfg)) {
      if (isFunction(cfg.onAuthExpired)) {
        cfg.onAuthExpired(res.data, req, res);
      }
      if (cfg.redirectOnAuthExpired && cfg.authRedirectUrl) {
        redirectToLogin(cfg.authRedirectUrl);
      }
      if (cfg.rejectOnAuthExpired) {
        throw makeError(getMessageFromResponse(res.data, '用户未登录或会话已过期'), 'AUTH_EXPIRED', {
          data: res.data,
          response: res,
          request: req,
          isAuthExpired: true
        });
      }
    }

    return cfg.fullResponse ? res : res.data;
  }

  function handleError(err, req) {
    var cfg = req || getConfig();
    if (cfg.onError && isFunction(cfg.onError)) {
      cfg.onError(err, req);
    }
    err = runInterceptors(cfg.errorInterceptors, err, req);
    if (cfg.showError) showErrorMessage(err);
    throw err;
  }

  function getConfig(options) {
    options = options || {};
    var cfg = extend({}, defaults, RcRequest._config || {});
    cfg.headers = mergeHeaders(defaults.headers, (RcRequest._config || {}).headers, options.headers);
    cfg.requestInterceptors = [].concat(defaults.requestInterceptors || [], (RcRequest._config || {}).requestInterceptors || [], options.requestInterceptors || []);
    cfg.responseInterceptors = [].concat(defaults.responseInterceptors || [], (RcRequest._config || {}).responseInterceptors || [], options.responseInterceptors || []);
    cfg.errorInterceptors = [].concat(defaults.errorInterceptors || [], (RcRequest._config || {}).errorInterceptors || [], options.errorInterceptors || []);
    return extend(cfg, options, { headers: cfg.headers });
  }

  function request(url, options) {
    var req = normalizeRequest(url, options || {});

    if (req.beforeRequest && isFunction(req.beforeRequest)) {
      var before = req.beforeRequest(req);
      if (before === false) {
        return Promise.reject(makeError('请求已取消', 'CANCELLED', { request: req }));
      }
      if (before !== undefined) req = before;
    }

    req = runInterceptors(req.requestInterceptors, req, req);

    var sender = req.forceXHR || typeof fetch === 'undefined' ? sendXHR : sendFetch;
    return sender(req).then(function (res) {
      return handleResponse(res, req);
    }).catch(function (err) {
      return handleError(err, req);
    });
  }

  function get(url, params, options) {
    options = extend({}, options || {}, { method: 'GET', data: params });
    return request(url, options);
  }

  function remove(url, params, options) {
    options = extend({}, options || {}, { method: 'DELETE', data: params });
    return request(url, options);
  }

  function post(url, data, options) {
    options = extend({ bodyType: 'form' }, options || {}, { method: 'POST', data: data });
    return request(url, options);
  }

  function postForm(url, data, options) {
    options = extend({ bodyType: 'form' }, options || {}, { method: 'POST', data: data });
    return request(url, options);
  }

  function postJson(url, data, options) {
    options = extend({ bodyType: 'json' }, options || {}, { method: 'POST', data: data });
    return request(url, options);
  }

  function upload(url, data, options) {
    options = extend({ bodyType: 'multipart' }, options || {}, { method: 'POST', data: data });
    return request(url, options);
  }

  function submit(form, options) {
    options = options || {};
    var el = isString(form) ? document.querySelector(form) : form;
    if (!isFormElement(el)) {
      return Promise.reject(makeError('表单不存在', 'FORM_NOT_FOUND'));
    }
    var url = options.url || el.getAttribute('action') || global.location.href;
    var method = options.method || el.getAttribute('method') || 'POST';
    return request(url, extend({ method: method, data: el }, options));
  }

  function ajax(options) {
    options = options || {};
    var url = options.url;
    var method = options.method || options.type || 'GET';
    var data = options.data;
    var success = options.success || noop;
    var error = options.error || noop;
    var complete = options.complete || noop;
    var beforeSend = options.beforeSend;

    if (isFunction(beforeSend)) {
      var beforeResult = beforeSend(null, options);
      if (beforeResult === false) {
        var cancelled = makeError('请求已取消', 'CANCELLED');
        error(cancelled, 'cancel', cancelled.message);
        complete(cancelled, 'cancel');
        return Promise.reject(cancelled);
      }
    }

    var reqOptions = extend({}, options, {
      method: method,
      data: data,
      parse: options.dataType === 'json' ? 'json' : (options.parse || 'auto')
    });

    if (String(options.contentType || '').toLowerCase().indexOf('application/json') >= 0) reqOptions.bodyType = 'json';
    if (options.processData === false && options.contentType === false) reqOptions.bodyType = 'multipart';

    var p = request(url, reqOptions);
    return p.then(function (res) {
      success(res, 'success', null);
      complete(null, 'success');
      return res;
    }, function (err) {
      error(err, 'error', err && err.message ? err.message : 'error');
      complete(err, 'error');
      throw err;
    });
  }

  function getJSON(url, params, options) {
    return get(url, params, extend({ parse: 'json' }, options || {}));
  }

  function ok(data) {
    if (!data || typeof data !== 'object') return false;
    if (data.success === true) return true;
    if (String(data.code) === '0' || String(data.code) === '200') return true;
    return false;
  }

  function message(data, fallback) {
    return getMessageFromResponse(data, fallback || '');
  }

  var RcRequest = {
    version: '1.0.0',
    _config: {},

    setup: function (options) {
      options = options || {};
      var next = extend({}, RcRequest._config, options);
      next.headers = mergeHeaders((RcRequest._config || {}).headers, options.headers);
      RcRequest._config = next;
      return RcRequest;
    },

    useRequest: function (fn) {
      if (isFunction(fn)) {
        RcRequest._config.requestInterceptors = (RcRequest._config.requestInterceptors || []).concat(fn);
      }
      return RcRequest;
    },

    useResponse: function (fn) {
      if (isFunction(fn)) {
        RcRequest._config.responseInterceptors = (RcRequest._config.responseInterceptors || []).concat(fn);
      }
      return RcRequest;
    },

    useError: function (fn) {
      if (isFunction(fn)) {
        RcRequest._config.errorInterceptors = (RcRequest._config.errorInterceptors || []).concat(fn);
      }
      return RcRequest;
    },

    request: request,
    get: get,
    getJSON: getJSON,
    post: post,
    postForm: postForm,
    postJson: postJson,
    upload: upload,
    submit: submit,
    ajax: ajax,
    delete: remove,
    remove: remove,

    toQueryString: toQueryString,
    appendParams: appendParams,
    formToObject: formToObject,
    toFormData: toFormData,
    ok: ok,
    message: message,
    isAuthExpired: function (data) {
      return isAuthExpired(data, getConfig());
    },
    showError: showErrorMessage,
    makeError: makeError
  };

  global.RcRequest = RcRequest;
  global.RcCommon = global.RcCommon || {};
  global.RcCommon.request = RcRequest;
})(window, document);
