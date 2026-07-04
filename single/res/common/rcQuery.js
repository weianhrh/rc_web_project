/*
 * /res/common/rcQuery.js
 * RC物联后台 - 查询/筛选条件公共函数
 *
 * 目标：把 res/*.html 里重复的搜索条件读取、URL 参数拼接、重置、回车搜索、筛选绑定抽出来。
 * 特点：无依赖；可独立使用；如果页面已加载 window.RcCommon，会自动挂到 RcCommon.query。
 *
 * 推荐引入：
 *   <script src="./common/rcQuery.js"></script>
 *
 * 常用：
 *   const params = RcQuery.collect({
 *     uid: '#uid',
 *     order_number: '#orderNumber',
 *     status: '#statusFilter',
 *     hide_zero: { selector: '#hide_zero', type: 'checkbox', trueValue: 1 }
 *   });
 *   const url = RcQuery.appendParams('/api/xxx.php', params);
 */
(function (global, document) {
  'use strict';

  var RcQuery = {};
  var slice = Array.prototype.slice;
  var hasOwn = Object.prototype.hasOwnProperty;

  function isElement(value) {
    return value && value.nodeType === 1;
  }

  function isDocument(value) {
    return value && value.nodeType === 9;
  }

  function isFunction(value) {
    return typeof value === 'function';
  }

  function isPlainObject(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
  }

  function trim(value) {
    return String(value == null ? '' : value).replace(/^\s+|\s+$/g, '');
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

  function cssEscape(value) {
    value = String(value == null ? '' : value);
    if (global.CSS && CSS.escape) return CSS.escape(value);
    return value.replace(/([ #;?%&,.+*~\':"!^$[\]()=>|/@])/g, '\\$1');
  }

  function getRoot(root) {
    return root || document;
  }

  function qs(selector, root) {
    if (!selector) return null;
    if (isElement(selector) || isDocument(selector) || selector === global) return selector;
    return getRoot(root).querySelector(String(selector));
  }

  function qsa(selector, root) {
    if (!selector) return [];
    if (isElement(selector)) return [selector];
    return toArray(getRoot(root).querySelectorAll(String(selector)));
  }

  function matches(el, selector) {
    if (!el || !selector || !el.nodeType) return false;
    var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
    if (!fn) return false;
    return fn.call(el, selector);
  }

  function closest(el, selector, stopRoot) {
    while (el && el !== stopRoot && el.nodeType === 1) {
      if (matches(el, selector)) return el;
      el = el.parentNode;
    }
    return null;
  }

  function isEmptyValue(value) {
    if (value === undefined || value === null) return true;
    if (typeof value === 'string') return value === '';
    if (Array.isArray(value)) return value.length === 0;
    return false;
  }

  function normalizeBooleanAttr(value) {
    if (value === true || value === 'true' || value === '1' || value === 1) return true;
    if (value === false || value === 'false' || value === '0' || value === 0) return false;
    return undefined;
  }

  function getInputKind(el, field) {
    if (field && field.type) return String(field.type).toLowerCase();
    if (!el) return 'text';
    var tag = String(el.tagName || '').toLowerCase();
    var type = String(el.type || '').toLowerCase();
    if (tag === 'select' && el.multiple) return 'select-multiple';
    if (tag === 'select') return 'select';
    if (tag === 'textarea') return 'textarea';
    return type || 'text';
  }

  function getFieldElements(field, key, root) {
    field = normalizeField(field, key);
    if (field.elements) return qsa(field.elements, root);
    if (field.selector) return qsa(field.selector, root);
    if (field.el) return [qs(field.el, root)].filter(Boolean);
    if (field.name) return qsa('[name="' + cssEscape(field.name) + '"]', root);
    return [];
  }

  function normalizeField(field, key) {
    if (typeof field === 'string' || isElement(field)) {
      return { selector: field, param: key };
    }
    if (isFunction(field)) {
      return { value: field, param: key };
    }
    field = extend({}, field || {});
    if (!field.param) field.param = field.name || key;
    return field;
  }

  function parseValue(value, el, field) {
    field = field || {};

    if (typeof value === 'string' && field.trim !== false) {
      value = trim(value);
    }

    if (isEmptyValue(value) && field.defaultValue !== undefined) {
      value = isFunction(field.defaultValue) ? field.defaultValue(el, field) : field.defaultValue;
    }

    if (!isEmptyValue(value) && (field.valueType === 'number' || field.number === true)) {
      var n = Number(value);
      value = isNaN(n) ? value : n;
    }

    if (isFunction(field.parse)) {
      value = field.parse(value, el, field);
    }
    if (isFunction(field.transform)) {
      value = field.transform(value, el, field);
    }

    return value;
  }

  function readOneElement(el, field) {
    if (!el) return '';
    field = field || {};

    var kind = getInputKind(el, field);
    var value;

    if (kind === 'checkbox') {
      var trueValue = field.trueValue !== undefined ? field.trueValue : (el.getAttribute('data-query-true') != null ? el.getAttribute('data-query-true') : (el.value || '1'));
      var falseValue = field.falseValue !== undefined ? field.falseValue : (el.getAttribute('data-query-false') != null ? el.getAttribute('data-query-false') : '');
      var asBoolean = field.asBoolean === true || el.getAttribute('data-query-boolean') === 'true';
      if (asBoolean) {
        value = !!el.checked;
      } else {
        value = el.checked ? trueValue : falseValue;
      }
      return parseValue(value, el, field);
    }

    if (kind === 'radio') {
      if (!el.checked) return '';
      return parseValue(el.value, el, field);
    }

    if (kind === 'select-multiple') {
      value = [];
      for (var i = 0; i < el.options.length; i++) {
        if (el.options[i].selected) value.push(el.options[i].value);
      }
      return parseValue(value, el, field);
    }

    value = 'value' in el ? el.value : el.textContent;
    return parseValue(value, el, field);
  }

  function readField(field, key, root, options) {
    options = options || {};
    field = normalizeField(field, key);

    if (field.skip === true || (isFunction(field.skip) && field.skip(field) === true)) {
      return { key: field.param || key, value: undefined, skip: true };
    }

    if (field.value !== undefined) {
      var direct = isFunction(field.value) ? field.value(field, options) : field.value;
      return { key: field.param || key, value: parseValue(direct, null, field), field: field };
    }

    var elements = getFieldElements(field, key, root);
    if (!elements.length) {
      return { key: field.param || key, value: field.defaultValue !== undefined ? parseValue(field.defaultValue, null, field) : undefined, field: field };
    }

    var first = elements[0];
    var kind = getInputKind(first, field);
    var value;

    if (kind === 'radio') {
      value = '';
      for (var r = 0; r < elements.length; r++) {
        if (elements[r].checked) {
          value = elements[r].value;
          first = elements[r];
          break;
        }
      }
      value = parseValue(value, first, field);
    } else if (kind === 'checkbox' && elements.length > 1) {
      value = [];
      for (var c = 0; c < elements.length; c++) {
        if (elements[c].checked) value.push(elements[c].value || '1');
      }
      value = parseValue(value, first, field);
    } else {
      value = readOneElement(first, field);
    }

    return { key: field.param || key, value: value, field: field, element: first };
  }

  function assignParam(params, key, value) {
    if (!key) return params;
    if (params[key] === undefined) {
      params[key] = value;
    } else if (Array.isArray(params[key])) {
      if (Array.isArray(value)) {
        params[key] = params[key].concat(value);
      } else {
        params[key].push(value);
      }
    } else {
      params[key] = Array.isArray(value) ? [params[key]].concat(value) : [params[key], value];
    }
    return params;
  }

  function shouldInclude(value, field, options) {
    field = field || {};
    options = options || {};
    var includeEmpty = field.includeEmpty;
    if (includeEmpty === undefined) includeEmpty = options.includeEmpty;
    if (includeEmpty === undefined) includeEmpty = false;
    return includeEmpty || !isEmptyValue(value);
  }

  function collectFromSchema(fields, options) {
    options = options || {};
    var root = getRoot(options.root);
    var params = {};
    fields = fields || {};

    for (var key in fields) {
      if (!hasOwn.call(fields, key)) continue;
      var item = readField(fields[key], key, root, options);
      if (item.skip) continue;
      if (shouldInclude(item.value, item.field, options)) {
        assignParam(params, item.key, item.value);
      }
    }

    if (isFunction(options.afterCollect)) {
      params = options.afterCollect(params) || params;
    }

    return params;
  }

  function fieldFromDataElement(el) {
    var param = el.getAttribute('data-query-param') || el.getAttribute('data-query-field') || el.name || el.id;
    var field = {
      el: el,
      param: param
    };

    var type = el.getAttribute('data-query-type');
    if (type) field.type = type;

    var def = el.getAttribute('data-query-default');
    if (def != null) field.defaultValue = def;

    var includeEmpty = normalizeBooleanAttr(el.getAttribute('data-query-include-empty'));
    if (includeEmpty !== undefined) field.includeEmpty = includeEmpty;

    var trimAttr = normalizeBooleanAttr(el.getAttribute('data-query-trim'));
    if (trimAttr !== undefined) field.trim = trimAttr;

    var numAttr = normalizeBooleanAttr(el.getAttribute('data-query-number'));
    if (numAttr === true) field.valueType = 'number';

    var trueValue = el.getAttribute('data-query-true');
    if (trueValue != null) field.trueValue = trueValue;

    var falseValue = el.getAttribute('data-query-false');
    if (falseValue != null) field.falseValue = falseValue;

    return field;
  }

  function collectFromRoot(root, options) {
    options = options || {};
    root = getRoot(root || options.root);
    var selector = options.selector || '[data-query-field]';
    var elements = qsa(selector, root);
    var params = {};

    for (var i = 0; i < elements.length; i++) {
      var el = elements[i];
      if (el.disabled && options.includeDisabled !== true) continue;
      var field = fieldFromDataElement(el);
      var item = readField(field, field.param, root, options);
      if (shouldInclude(item.value, item.field, options)) {
        assignParam(params, item.key, item.value);
      }
    }

    if (isFunction(options.afterCollect)) {
      params = options.afterCollect(params) || params;
    }

    return params;
  }

  function encodePair(key, value) {
    return encodeURIComponent(key) + '=' + encodeURIComponent(value == null ? '' : String(value));
  }

  function cleanUrlTail(url) {
    return String(url || '').replace(/[?&]+$/, '');
  }

  function parseQueryString(url) {
    var text = String(url == null ? global.location.href : url);
    var query = text;
    var hashIndex = query.indexOf('#');
    if (hashIndex !== -1) query = query.slice(0, hashIndex);
    var qIndex = query.indexOf('?');
    query = qIndex === -1 ? query : query.slice(qIndex + 1);

    var params = {};
    if (!query) return params;

    var parts = query.split('&');
    for (var i = 0; i < parts.length; i++) {
      if (!parts[i]) continue;
      var kv = parts[i].split('=');
      var key = decodeURIComponent((kv.shift() || '').replace(/\+/g, ' '));
      var val = decodeURIComponent((kv.join('=') || '').replace(/\+/g, ' '));
      if (!key) continue;
      assignParam(params, key, val);
    }
    return params;
  }

  function setElementValue(el, value, field) {
    if (!el) return;
    field = field || {};
    var kind = getInputKind(el, field);

    if (kind === 'checkbox') {
      var trueValue = field.trueValue !== undefined ? field.trueValue : (el.getAttribute('data-query-true') != null ? el.getAttribute('data-query-true') : (el.value || '1'));
      if (typeof value === 'boolean') {
        el.checked = value;
      } else if (Array.isArray(value)) {
        el.checked = value.map(String).indexOf(String(el.value || trueValue)) !== -1;
      } else {
        el.checked = String(value) === String(trueValue) || String(value) === String(el.value || trueValue);
      }
      return;
    }

    if (kind === 'radio') {
      el.checked = String(el.value) === String(value);
      return;
    }

    if (kind === 'select-multiple') {
      var values = Array.isArray(value) ? value.map(String) : [String(value)];
      for (var i = 0; i < el.options.length; i++) {
        el.options[i].selected = values.indexOf(String(el.options[i].value)) !== -1;
      }
      return;
    }

    if ('value' in el) {
      el.value = value == null ? '' : value;
    } else {
      el.textContent = value == null ? '' : value;
    }
  }

  function setFieldValue(field, key, value, root) {
    field = normalizeField(field, key);
    var elements = getFieldElements(field, key, root);
    for (var i = 0; i < elements.length; i++) {
      setElementValue(elements[i], value, field);
    }
  }

  function clearField(field, key, root) {
    field = normalizeField(field, key);
    var value = field.defaultValue !== undefined ? (isFunction(field.defaultValue) ? field.defaultValue() : field.defaultValue) : '';
    setFieldValue(field, key, value, root);
  }

  function debounce(fn, wait) {
    var timer = null;
    return function () {
      var args = arguments;
      var ctx = this;
      clearTimeout(timer);
      timer = setTimeout(function () {
        fn.apply(ctx, args);
      }, wait || 0);
    };
  }

  function createContext(options) {
    options = options || {};
    var root = getRoot(options.root);
    var ctx = {};

    ctx.root = root;
    ctx.options = options;

    ctx.getParams = function (extra) {
      var params = RcQuery.collect(options.fields || root, extend({}, options, { root: root }));
      if (extra) extend(params, extra);
      return RcQuery.filterParams(params, options);
    };

    ctx.buildUrl = function (url, extra) {
      return RcQuery.appendParams(url, ctx.getParams(extra), options);
    };

    ctx.fill = function (values) {
      RcQuery.fill(options.fields || root, values || {}, extend({}, options, { root: root }));
      return ctx;
    };

    ctx.clear = function () {
      RcQuery.clear(options.fields || root, extend({}, options, { root: root }));
      return ctx;
    };

    ctx.search = function (source) {
      if (isFunction(options.resetPage) && source !== 'page' && source !== 'init') {
        options.resetPage(ctx);
      }

      var params = ctx.getParams();
      var beforeResult;
      if (isFunction(options.beforeSearch)) {
        beforeResult = options.beforeSearch(params, ctx, source);
        if (beforeResult === false) return false;
        if (beforeResult && isPlainObject(beforeResult)) params = beforeResult;
      }

      if (options.syncUrl) {
        RcQuery.syncUrl(params, options.syncUrl === true ? {} : options.syncUrl);
      }

      if (isFunction(options.onSearch)) {
        return options.onSearch(params, ctx, source);
      }
      return params;
    };

    ctx.reset = function () {
      ctx.clear();
      if (isFunction(options.onReset)) {
        var result = options.onReset(ctx.getParams(), ctx);
        if (result === false) return false;
      }
      if (options.searchAfterReset !== false) {
        return ctx.search('reset');
      }
      return ctx;
    };

    return ctx;
  }

  RcQuery.version = '1.0.0';

  RcQuery.qs = qs;
  RcQuery.qsa = qsa;
  RcQuery.trim = trim;
  RcQuery.extend = extend;

  /**
   * 收集查询条件。
   * 1) 传 schema：RcQuery.collect({ uid:'#uid', status:'#status' })
   * 2) 传容器：RcQuery.collect(document)，自动读取 data-query-field 元素
   */
  RcQuery.collect = function (fieldsOrRoot, options) {
    options = options || {};
    if (!fieldsOrRoot) fieldsOrRoot = options.root || document;

    if (isElement(fieldsOrRoot) || isDocument(fieldsOrRoot) || typeof fieldsOrRoot === 'string') {
      return collectFromRoot(qs(fieldsOrRoot, options.root) || fieldsOrRoot, options);
    }

    return collectFromSchema(fieldsOrRoot, options);
  };

  RcQuery.serialize = RcQuery.collect;

  RcQuery.filterParams = function (params, options) {
    options = options || {};
    var result = {};
    params = params || {};
    for (var key in params) {
      if (!hasOwn.call(params, key)) continue;
      var value = params[key];
      if (options.includeEmpty || !isEmptyValue(value)) {
        result[key] = value;
      }
    }
    return result;
  };

  RcQuery.toQueryString = function (params, options) {
    options = options || {};
    var parts = [];
    params = params || {};

    for (var key in params) {
      if (!hasOwn.call(params, key)) continue;
      var value = params[key];
      if (!options.includeEmpty && isEmptyValue(value)) continue;

      if (Array.isArray(value)) {
        for (var i = 0; i < value.length; i++) {
          if (!options.includeEmpty && isEmptyValue(value[i])) continue;
          parts.push(encodePair(options.brackets ? key + '[]' : key, value[i]));
        }
      } else {
        parts.push(encodePair(key, value));
      }
    }

    return parts.join('&');
  };

  RcQuery.appendParams = function (url, params, options) {
    options = options || {};
    url = cleanUrlTail(url);
    var hash = '';
    var hashIndex = url.indexOf('#');
    if (hashIndex !== -1) {
      hash = url.slice(hashIndex);
      url = url.slice(0, hashIndex);
    }

    var query = RcQuery.toQueryString(params, options);
    if (!query) return url + hash;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + query + hash;
  };

  RcQuery.buildUrl = RcQuery.appendParams;

  RcQuery.getParam = function (name, url) {
    var params = parseQueryString(url);
    return name ? params[name] : params;
  };

  RcQuery.getUrlParams = function (url) {
    return parseQueryString(url);
  };

  RcQuery.fill = function (fieldsOrRoot, values, options) {
    options = options || {};
    values = values || {};
    var root = getRoot(options.root);

    if (isElement(fieldsOrRoot) || isDocument(fieldsOrRoot) || typeof fieldsOrRoot === 'string') {
      var container = qs(fieldsOrRoot, root) || fieldsOrRoot;
      var elements = qsa(options.selector || '[data-query-field]', container);
      for (var i = 0; i < elements.length; i++) {
        var field = fieldFromDataElement(elements[i]);
        if (values[field.param] !== undefined) {
          setElementValue(elements[i], values[field.param], field);
        }
      }
      return;
    }

    for (var key in fieldsOrRoot) {
      if (!hasOwn.call(fieldsOrRoot, key)) continue;
      var fieldObj = normalizeField(fieldsOrRoot[key], key);
      var param = fieldObj.param || key;
      if (values[param] !== undefined || values[key] !== undefined) {
        setFieldValue(fieldObj, key, values[param] !== undefined ? values[param] : values[key], root);
      }
    }
  };

  RcQuery.clear = function (fieldsOrRoot, options) {
    options = options || {};
    var root = getRoot(options.root);

    if (isElement(fieldsOrRoot) || isDocument(fieldsOrRoot) || typeof fieldsOrRoot === 'string') {
      var container = qs(fieldsOrRoot, root) || fieldsOrRoot;
      var elements = qsa(options.selector || '[data-query-field]', container);
      for (var i = 0; i < elements.length; i++) {
        var field = fieldFromDataElement(elements[i]);
        var value = field.defaultValue !== undefined ? field.defaultValue : '';
        setElementValue(elements[i], value, field);
      }
      return;
    }

    for (var key in fieldsOrRoot) {
      if (!hasOwn.call(fieldsOrRoot, key)) continue;
      clearField(fieldsOrRoot[key], key, root);
    }
  };

  /**
   * 绑定查询按钮、重置按钮、回车搜索、筛选项变化自动查询。
   */
  RcQuery.bind = function (options) {
    options = options || {};
    var root = getRoot(options.root);
    var ctx = createContext(options);
    var searchHandler = function (source, event) {
      if (event && event.preventDefault) event.preventDefault();
      return ctx.search(source || 'search');
    };

    var delayedSearch = options.debounce ? debounce(function (source) {
      ctx.search(source || 'change');
    }, Number(options.debounce) || 300) : function (source) {
      ctx.search(source || 'change');
    };

    if (options.searchBtn) {
      var searchButtons = qsa(options.searchBtn, root);
      for (var i = 0; i < searchButtons.length; i++) {
        searchButtons[i].addEventListener('click', function (event) {
          searchHandler('search', event);
        });
      }
    }

    if (options.resetBtn) {
      var resetButtons = qsa(options.resetBtn, root);
      for (var r = 0; r < resetButtons.length; r++) {
        resetButtons[r].addEventListener('click', function (event) {
          if (event && event.preventDefault) event.preventDefault();
          ctx.reset();
        });
      }
    }

    if (options.enter !== false) {
      root.addEventListener('keydown', function (event) {
        event = event || global.event;
        var key = event.key || event.keyCode;
        if (key !== 'Enter' && key !== 13) return;

        var target = event.target || event.srcElement;
        if (!target) return;
        if (String(target.tagName || '').toLowerCase() === 'textarea' && options.enterInTextarea !== true) return;
        if (options.enterSelector && !matches(target, options.enterSelector) && !closest(target, options.enterSelector, root)) return;

        searchHandler('enter', event);
      });
    }

    if (options.autoChange) {
      var autoSelector = options.autoChangeSelector || '[data-query-auto], select[data-query-field], input[type="checkbox"][data-query-field], input[type="radio"][data-query-field]';
      root.addEventListener('change', function (event) {
        var target = event.target || event.srcElement;
        if (matches(target, autoSelector) || closest(target, autoSelector, root)) {
          delayedSearch('change');
        }
      });
    }

    if (options.fillFromUrl) {
      ctx.fill(RcQuery.getUrlParams());
    }

    if (options.autoInit) {
      setTimeout(function () { ctx.search('init'); }, 0);
    }

    return ctx;
  };

  RcQuery.syncUrl = function (params, options) {
    options = options || {};
    if (!global.history || !global.history.replaceState) return;
    var base = options.base || global.location.pathname;
    var url = RcQuery.appendParams(base, params, options);
    if (global.location.hash && url.indexOf('#') === -1) {
      url += global.location.hash;
    }
    global.history.replaceState(null, document.title, url);
  };

  RcQuery.createState = function (options) {
    options = options || {};
    var state = extend({
      page: 1,
      limit: 10,
      total: 0,
      totalPages: 1,
      params: {}
    }, options.defaults || {});

    var pageKey = options.pageKey || 'page';
    var limitKey = options.limitKey || 'limit';

    return {
      raw: state,
      set: function (patch) { extend(state, patch || {}); return this; },
      get: function (key) { return key ? state[key] : state; },
      setPage: function (page) { state.page = Math.max(1, parseInt(page, 10) || 1); return this; },
      resetPage: function () { state.page = 1; return this; },
      setLimit: function (limit) { state.limit = Math.max(1, parseInt(limit, 10) || state.limit || 10); return this; },
      setTotal: function (total) {
        state.total = Math.max(0, parseInt(total, 10) || 0);
        state.totalPages = Math.max(1, Math.ceil(state.total / Math.max(1, state.limit || 10)));
        if (state.page > state.totalPages) state.page = state.totalPages;
        return this;
      },
      setParams: function (params) { state.params = extend({}, params || {}); return this; },
      getParams: function (extra) {
        var params = extend({}, state.params || {});
        params[pageKey] = state.page;
        params[limitKey] = state.limit;
        if (extra) extend(params, extra);
        return RcQuery.filterParams(params, options);
      },
      buildUrl: function (url, extra) { return RcQuery.appendParams(url, this.getParams(extra), options); }
    };
  };

  RcQuery.today = function () {
    return RcQuery.formatDate(new Date());
  };

  RcQuery.addDays = function (date, days) {
    var d = date ? new Date(date) : new Date();
    d.setDate(d.getDate() + Number(days || 0));
    return d;
  };

  RcQuery.formatDate = function (date) {
    var d = date instanceof Date ? date : new Date(date);
    if (isNaN(d.getTime())) return '';
    var pad = function (n) { return String(n).length < 2 ? '0' + n : String(n); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  };

  RcQuery.getValue = function (selector, root) {
    var el = qs(selector, root);
    return readOneElement(el, {});
  };

  RcQuery.setValue = function (selector, value, root) {
    var el = qs(selector, root);
    setElementValue(el, value, {});
  };

  global.RcQuery = RcQuery;

  if (global.RcCommon) {
    global.RcCommon.query = RcQuery;
    global.RcCommon.collectQuery = RcQuery.collect;
    global.RcCommon.buildQueryUrl = RcQuery.appendParams;
  }
})(window, document);
