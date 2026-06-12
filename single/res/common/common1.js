/* /res/common/common.js
 * RC物联后台公共函数
 * 先统一 fetch 请求，后续其它公共函数也可以继续挂到 window.RcCommon
 */
(function (window, document) {
  'use strict';

  var RcCommon = window.RcCommon || {};
  var DEFAULT_TIMEOUT = 15000;

  function isObject(v) {
    return Object.prototype.toString.call(v) === '[object Object]';
  }

  function trimSlashHost(url) {
    url = String(url || '');

    // 如果写的是当前域名绝对地址，自动转成站内路径，避免环境切换麻烦
    try {
      var u = new URL(url, window.location.origin);
      if (u.origin === window.location.origin) {
        return u.pathname + u.search + u.hash;
      }
    } catch (e) {}

    return url;
  }

  function appendQuery(url, params) {
    url = trimSlashHost(url);

    if (!params) {
      return url;
    }

    var sp = new URLSearchParams();

    Object.keys(params).forEach(function (key) {
      var value = params[key];

      if (value === undefined || value === null || value === '') {
        return;
      }

      if (Array.isArray(value)) {
        value.forEach(function (item) {
          if (item !== undefined && item !== null) {
            sp.append(key, item);
          }
        });
      } else {
        sp.append(key, value);
      }
    });

    var query = sp.toString();
    if (!query) {
      return url;
    }

    return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
  }

  function appendNoCache(url) {
    return appendQuery(url, { _: Date.now() });
  }

  function toFormBody(data) {
    if (data instanceof URLSearchParams) {
      return data.toString();
    }

    var sp = new URLSearchParams();

    if (isObject(data)) {
      Object.keys(data).forEach(function (key) {
        var value = data[key];

        if (value === undefined || value === null) {
          return;
        }

        if (Array.isArray(value)) {
          value.forEach(function (item) {
            sp.append(key, item);
          });
        } else {
          sp.append(key, value);
        }
      });
    }

    return sp.toString();
  }

  function parseJsonText(text, url) {
    if (!text) {
      return null;
    }

    try {
      return JSON.parse(text);
    } catch (e) {
      e.message = '接口返回不是合法 JSON：' + url + '；' + e.message;
      e.responseText = text;
      throw e;
    }
  }

  function createTimeoutController(timeout) {
    if (!window.AbortController) {
      return {
        signal: undefined,
        clear: function () {}
      };
    }

    var controller = new AbortController();
    var timer = setTimeout(function () {
      controller.abort();
    }, timeout || DEFAULT_TIMEOUT);

    return {
      signal: controller.signal,
      clear: function () {
        clearTimeout(timer);
      }
    };
  }

  /**
   * 底层请求函数
   *
   * 返回格式：
   * {
   *   ok: true/false,
   *   status: 200,
   *   url: '',
   *   data: {},      // JSON解析后的数据
   *   text: ''       // 原始文本
   * }
   */
  function request(url, options) {
    options = options || {};

    var method = String(options.method || 'GET').toUpperCase();
    var headers = Object.assign({}, options.headers || {});
    var timeout = Number(options.timeout || DEFAULT_TIMEOUT);
    var noCache = options.noCache === true;
    var returnFull = options.returnFull !== false; // 默认返回完整对象，兼容 RcCommon.fetchJson
    var throwHttp = options.throwHttp !== false;
    var body = options.body;

    url = trimSlashHost(url);

    if (options.params) {
      url = appendQuery(url, options.params);
    }

    if (noCache) {
      url = appendNoCache(url);
    }

    if (options.authToken && !headers.Authorization) {
      headers.Authorization = 'Bearer ' + options.authToken;
    }

    if (method === 'GET' || method === 'HEAD') {
      if (options.data) {
        url = appendQuery(url, options.data);
      }
    } else {
      if (options.json !== undefined) {
        headers['Content-Type'] = headers['Content-Type'] || 'application/json';
        body = JSON.stringify(options.json);
      } else if (options.form !== undefined) {
        headers['Content-Type'] = headers['Content-Type'] || 'application/x-www-form-urlencoded; charset=UTF-8';
        body = toFormBody(options.form);
      } else if (options.formData !== undefined) {
        // FormData 不要手动设置 Content-Type，浏览器会自动带 boundary
        body = options.formData;
      } else if (options.data !== undefined) {
        headers['Content-Type'] = headers['Content-Type'] || 'application/x-www-form-urlencoded; charset=UTF-8';
        body = toFormBody(options.data);
      }
    }

    var timeoutController = createTimeoutController(timeout);

    var fetchOptions = {
      method: method,
      headers: headers,
      body: body,
      credentials: options.credentials || 'include',
      signal: timeoutController.signal
    };

    if (method === 'GET' || method === 'HEAD') {
      delete fetchOptions.body;
    }

    return fetch(url, fetchOptions)
      .then(function (response) {
        return response.text().then(function (text) {
          var result = {
            ok: response.ok,
            status: response.status,
            statusText: response.statusText,
            url: response.url || url,
            data: parseJsonText(text, url),
            text: text
          };

          if (!response.ok && throwHttp) {
            var err = new Error('请求失败：HTTP ' + response.status);
            err.result = result;
            throw err;
          }

          return returnFull ? result : result.data;
        });
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') {
          err.message = '请求超时，请稍后重试';
        }
        throw err;
      })
      .finally(function () {
        timeoutController.clear();
      });
  }

  // 兼容你现在 adminbackstage.html 里的写法：
  // const result = await RcCommon.fetchJson('/api/xxx.php')
  // const data = result.data
  RcCommon.fetchJson = function (url, options) {
    options = options || {};
    options.returnFull = true;
    return request(url, options);
  };

  // 新页面推荐使用这些：直接返回接口 JSON
  RcCommon.get = function (url, params, options) {
    options = Object.assign({}, options || {}, {
      method: 'GET',
      params: params || {},
      returnFull: false
    });
    return request(url, options);
  };

  RcCommon.postForm = function (url, data, options) {
    options = Object.assign({}, options || {}, {
      method: 'POST',
      form: data || {},
      returnFull: false
    });
    return request(url, options);
  };

  RcCommon.postJson = function (url, data, options) {
    options = Object.assign({}, options || {}, {
      method: 'POST',
      json: data || {},
      returnFull: false
    });
    return request(url, options);
  };

  RcCommon.postFormData = function (url, formData, options) {
    options = Object.assign({}, options || {}, {
      method: 'POST',
      formData: formData,
      returnFull: false
    });
    return request(url, options);
  };

  RcCommon.request = request;
  RcCommon.toFormBody = toFormBody;
  RcCommon.appendQuery = appendQuery;

  // 下面这些保留为公共函数入口，没有就补一个，有就不覆盖
  RcCommon.qs = RcCommon.qs || function (selector, root) {
    return (root || document).querySelector(selector);
  };

  RcCommon.qsa = RcCommon.qsa || function (selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  };

  RcCommon.setText = RcCommon.setText || function (selector, text) {
    var el = typeof selector === 'string' ? RcCommon.qs(selector) : selector;
    if (el) {
      el.textContent = text == null ? '' : String(text);
    }
  };

  RcCommon.formatMoney = RcCommon.formatMoney || function (value) {
    var num = Number(value || 0);
    if (!Number.isFinite(num)) {
      num = 0;
    }
    return num.toFixed(2);
  };

  RcCommon.toast = RcCommon.toast || function (msg) {
    if (window.layer && layer.msg) {
      layer.msg(msg);
    } else {
      alert(msg);
    }
  };

  window.RcCommon = RcCommon;

})(window, document);
