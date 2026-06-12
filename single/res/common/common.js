(function (global) {
  const RcCommon = {};

  RcCommon.qs = function (selector, root) {
    return (root || document).querySelector(selector);
  };

  RcCommon.qsa = function (selector, root) {
    return Array.from((root || document).querySelectorAll(selector));
  };

  RcCommon.noop = function () {};

  RcCommon.escapeHtml = function (value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  RcCommon.getQuery = function (key, url) {
    const params = new URL(url || window.location.href).searchParams;
    return key ? params.get(key) : params;
  };

  RcCommon.toQueryString = function (params) {
    const query = new URLSearchParams();
    Object.keys(params || {}).forEach(function (key) {
      const value = params[key];
      if (value === undefined || value === null || value === '') {
        return;
      }
      if (Array.isArray(value)) {
        value.forEach(function (item) {
          query.append(key, item);
        });
        return;
      }
      query.append(key, value);
    });
    return query.toString();
  };

  RcCommon.formatDateTime = function (input) {
    if (!input) return '';
    const date = input instanceof Date ? input : new Date(input);
    if (Number.isNaN(date.getTime())) return String(input);
    const pad = function (n) { return String(n).padStart(2, '0'); };
    return [
      date.getFullYear(),
      pad(date.getMonth() + 1),
      pad(date.getDate())
    ].join('-') + ' ' + [
      pad(date.getHours()),
      pad(date.getMinutes()),
      pad(date.getSeconds())
    ].join(':');
  };

  RcCommon.debounce = function (fn, wait) {
    let timer = null;
    return function () {
      const args = arguments;
      const ctx = this;
      clearTimeout(timer);
      timer = setTimeout(function () {
        fn.apply(ctx, args);
      }, wait);
    };
  };

  RcCommon.throttle = function (fn, wait) {
    let last = 0;
    let timer = null;
    return function () {
      const now = Date.now();
      const remain = wait - (now - last);
      const args = arguments;
      const ctx = this;
      if (remain <= 0) {
        last = now;
        clearTimeout(timer);
        timer = null;
        fn.apply(ctx, args);
      } else if (!timer) {
        timer = setTimeout(function () {
          last = Date.now();
          timer = null;
          fn.apply(ctx, args);
        }, remain);
      }
    };
  };

  RcCommon.createPoller = function (task, interval, options) {
    const opts = Object.assign({ immediate: true }, options || {});
    let timer = null;
    let stopped = false;

    function run() {
      if (stopped) return;
      Promise.resolve()
        .then(task)
        .catch(function (err) {
          console.error('Poller task failed:', err);
        });
    }

    return {
      start: function () {
        stopped = false;
        if (opts.immediate) run();
        timer = setInterval(run, interval);
      },
      stop: function () {
        stopped = true;
        clearInterval(timer);
        timer = null;
      },
      run: run
    };
  };

RcCommon.fetchJson = async function (url, options) {
  const response = await fetch(url, options || {});
  const contentType = response.headers.get('content-type') || '';
  let payload;

  if (contentType.indexOf('application/json') !== -1) {
    payload = await response.json();
  } else {
    const text = await response.text();
    try {
      payload = JSON.parse(text);
    } catch (e) {
      payload = text;
    }
  }

  const result = {
    ok: response.ok,
    status: response.status,
    headers: response.headers,
    data: payload
  };

  // 宽容模式：如果返回的是 JSON 对象，把 code/count/msg 等字段也复制到外层
  if (payload && typeof payload === 'object' && !Array.isArray(payload)) {
    Object.keys(payload).forEach(function (key) {
      if (typeof result[key] === 'undefined') {
        result[key] = payload[key];
      }
    });
  }

  return result;
};
  RcCommon.postJson = function (url, data, options) {
    return RcCommon.fetchJson(url, Object.assign({
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(data || {})
    }, options || {}));
  };

  RcCommon.postForm = function (url, data, options) {
    return RcCommon.fetchJson(url, Object.assign({
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: RcCommon.toQueryString(data || {})
    }, options || {}));
  };

  RcCommon.apiGet = function (url, params, options) {
    const query = RcCommon.toQueryString(params || {});
    const finalUrl = query ? url + (url.indexOf('?') === -1 ? '?' : '&') + query : url;
    return RcCommon.fetchJson(finalUrl, options || {});
  };

  RcCommon.apiPost = RcCommon.postForm;

  RcCommon.escapeAttr = function (value) {
    return RcCommon.escapeHtml(value).replace(/`/g, '&#96;');
  };

  RcCommon.formatMoney = function (value, digits) {
    const precision = typeof digits === 'number' ? digits : 2;
    const amount = Number(value || 0);
    if (!Number.isFinite(amount)) {
      return (0).toFixed(precision);
    }
    return amount.toFixed(precision);
  };

  RcCommon.fmtMoney = RcCommon.formatMoney;

  RcCommon.formatNumber = function (value, fallback) {
    const num = Number(value);
    if (!Number.isFinite(num)) {
      return fallback == null ? '0' : String(fallback);
    }
    return num.toLocaleString('zh-CN');
  };

  RcCommon.copyText = async function (text) {
    const value = String(text == null ? '' : text);
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(value);
      return true;
    }
    const input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', 'readonly');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    try {
      document.execCommand('copy');
      return true;
    } finally {
      input.remove();
    }
  };

  RcCommon.ensureToastStack = function () {
    let stack = RcCommon.qs('.rc-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'rc-toast-stack';
      document.body.appendChild(stack);
    }
    return stack;
  };

  RcCommon.toast = function (message, type, timeout) {
    const stack = RcCommon.ensureToastStack();
    const item = document.createElement('div');
    item.className = 'rc-toast' + (type ? ' is-' + type : '');
    item.textContent = message;
    stack.appendChild(item);
    setTimeout(function () {
      item.remove();
    }, timeout || 2500);
  };

  RcCommon.alert = function (message, type) {
    RcCommon.toast(message, type || 'warning', 2800);
  };

  RcCommon.setHtml = function (target, html) {
    const el = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (el) el.innerHTML = html;
    return el;
  };

  RcCommon.setText = function (target, text) {
    const el = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (el) el.textContent = text;
    return el;
  };

  RcCommon.renderState = function (target, className, message) {
    const html = '<div class="' + className + '">' + RcCommon.escapeHtml(message) + '</div>';
    return RcCommon.setHtml(target, html);
  };

  RcCommon.renderLoading = function (target, message) {
    return RcCommon.renderState(target, 'rc-loading', message || '加载中...');
  };

  RcCommon.renderEmpty = function (target, message) {
    return RcCommon.renderState(target, 'rc-empty', message || '暂无数据');
  };

  RcCommon.renderError = function (target, message) {
    return RcCommon.renderState(target, 'rc-error', message || '数据加载失败，请稍后重试');
  };

  RcCommon.showLoading = RcCommon.renderLoading;
  RcCommon.showEmpty = RcCommon.renderEmpty;
  RcCommon.showError = RcCommon.renderError;

  RcCommon.hide = function (target) {
    const el = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (!el) return null;
    el.classList.add('rc-hidden');
    return el;
  };

  RcCommon.show = function (target) {
    const el = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (!el) return null;
    el.classList.remove('rc-hidden');
    return el;
  };

  RcCommon.setButtonLoading = function (target, loading, options) {
    const button = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (!button) return null;
    const opts = Object.assign({
      loadingText: '处理中...'
    }, options || {});
    if (loading) {
      if (!button.dataset.originText) {
        button.dataset.originText = button.textContent;
      }
      button.disabled = true;
      button.textContent = opts.loadingText;
    } else {
      button.disabled = false;
      if (button.dataset.originText) {
        button.textContent = button.dataset.originText;
      }
    }
    return button;
  };

  RcCommon.populateSelect = function (target, items, options) {
    const select = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (!select) return [];
    const opts = Object.assign({
      placeholder: '',
      includeEmpty: false,
      emptyValue: '',
      selectedValue: null,
      getValue: function (item) {
        return item && item.id != null ? item.id : '';
      },
      getLabel: function (item) {
        if (!item) return '';
        const id = item.id != null ? item.id : '';
        const name = item.venue_name || item.name || item.label || '';
        return [id, name].filter(Boolean).join(' - ');
      }
    }, options || {});

    select.innerHTML = '';
    if (opts.placeholder || opts.includeEmpty) {
      const first = document.createElement('option');
      first.value = opts.emptyValue;
      first.textContent = opts.placeholder || '请选择';
      select.appendChild(first);
    }

    (items || []).forEach(function (item, index) {
      const option = document.createElement('option');
      option.value = String(opts.getValue(item, index));
      option.textContent = String(opts.getLabel(item, index));
      if (opts.selectedValue != null && String(opts.selectedValue) === option.value) {
        option.selected = true;
      }
      select.appendChild(option);
    });

    return Array.from(select.options);
  };

  RcCommon.createPager = function (options) {
    const opts = Object.assign({
      prev: '#prevBtn',
      next: '#nextBtn',
      info: '#pageInfo',
      page: 1,
      size: 10,
      total: 0,
      onChange: RcCommon.noop
    }, options || {});

    const prev = typeof opts.prev === 'string' ? RcCommon.qs(opts.prev) : opts.prev;
    const next = typeof opts.next === 'string' ? RcCommon.qs(opts.next) : opts.next;
    const info = typeof opts.info === 'string' ? RcCommon.qs(opts.info) : opts.info;
    const state = {
      page: Number(opts.page) || 1,
      size: Number(opts.size) || 10,
      total: Number(opts.total) || 0
    };

    function getTotalPages() {
      return Math.max(1, Math.ceil(state.total / state.size));
    }

    function render() {
      const totalPages = getTotalPages();
      if (info) {
        info.textContent = '第 ' + state.page + ' / ' + totalPages + ' 页';
      }
      if (prev) prev.disabled = state.page <= 1;
      if (next) next.disabled = state.page >= totalPages;
    }

    function update(nextState) {
      Object.assign(state, nextState || {});
      state.page = Math.max(1, Number(state.page) || 1);
      state.size = Math.max(1, Number(state.size) || 10);
      state.total = Math.max(0, Number(state.total) || 0);
      const totalPages = getTotalPages();
      if (state.page > totalPages) {
        state.page = totalPages;
      }
      render();
      return Object.assign({}, state, { totalPages: getTotalPages() });
    }

    if (prev) {
      prev.addEventListener('click', function () {
        if (state.page <= 1) return;
        state.page -= 1;
        render();
        opts.onChange(Object.assign({}, state, { totalPages: getTotalPages() }));
      });
    }

    if (next) {
      next.addEventListener('click', function () {
        if (state.page >= getTotalPages()) return;
        state.page += 1;
        render();
        opts.onChange(Object.assign({}, state, { totalPages: getTotalPages() }));
      });
    }

    render();
    return {
      state: state,
      render: render,
      update: update,
      next: function () {
        if (state.page < getTotalPages()) {
          state.page += 1;
          render();
          opts.onChange(Object.assign({}, state, { totalPages: getTotalPages() }));
        }
      },
      prev: function () {
        if (state.page > 1) {
          state.page -= 1;
          render();
          opts.onChange(Object.assign({}, state, { totalPages: getTotalPages() }));
        }
      }
    };
  };

  RcCommon.toggleClass = function (target, className, force) {
    const el = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (!el) return;
    el.classList.toggle(className, force);
    return el;
  };

  RcCommon.openModal = function (target) {
    const modal = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (!modal) return null;
    modal.classList.add('is-open');
    modal.classList.add('show');
    return modal;
  };

  RcCommon.closeModal = function (target) {
    const modal = target ? (typeof target === 'string' ? RcCommon.qs(target) : target) :
      RcCommon.qs('.modal.show, .modal.is-open, .rc-modal.is-open');
    if (!modal) return null;
    modal.classList.remove('is-open');
    modal.classList.remove('show');
    return modal;
  };

  RcCommon.bindModalDismiss = function (selector, panelSelector) {
    const modal = RcCommon.qs(selector);
    if (!modal) return;
    modal.addEventListener('click', function (event) {
      if (panelSelector && event.target.closest(panelSelector)) {
        return;
      }
      RcCommon.closeModal(modal);
    });
  };

  RcCommon.openImagePreview = function (imageUrl, options) {
    const opts = Object.assign({
      modalId: 'rcImagePreviewModal'
    }, options || {});

    let modal = document.getElementById(opts.modalId);
    if (!modal) {
      modal = document.createElement('div');
      modal.id = opts.modalId;
      modal.className = 'rc-modal rc-image-modal';
      modal.innerHTML = '<div class="rc-modal-panel"><div class="rc-modal-body"><img alt="预览图片"></div></div>';
      document.body.appendChild(modal);
      RcCommon.bindModalDismiss('#' + opts.modalId, '.rc-modal-panel');
    }

    const img = modal.querySelector('img');
    img.src = imageUrl;
    RcCommon.openModal(modal);
  };

  RcCommon.bindImagePreview = function (selector, options) {
    document.addEventListener('click', function (event) {
      const trigger = event.target.closest(selector);
      if (!trigger) return;
      const src = trigger.getAttribute('data-preview-src') || trigger.getAttribute('src');
      if (!src) return;
      RcCommon.openImagePreview(src, options);
    });
  };

  RcCommon.createAlertBox = function (options) {
    const opts = Object.assign({
      id: 'rcAlertBox',
      message: '',
      actionText: '确定'
    }, options || {});

    let box = document.getElementById(opts.id);
    if (!box) {
      box = document.createElement('div');
      box.id = opts.id;
      box.className = 'rc-alert-box';
      box.innerHTML = '' +
        '<div class="rc-alert-close" data-role="close">×</div>' +
        '<div class="rc-alert-message" data-role="message"></div>' +
        '<button class="rc-btn" data-role="action"></button>';
      document.body.appendChild(box);
    }

    const api = {
      el: box,
      setMessage: function (message) {
        const node = box.querySelector('[data-role="message"]');
        if (node) node.textContent = message;
      },
      setAction: function (text, handler) {
        const button = box.querySelector('[data-role="action"]');
        if (!button) return;
        button.textContent = text;
        button.onclick = typeof handler === 'function' ? handler : RcCommon.noop;
      },
      open: function () {
        box.classList.add('is-open');
      },
      close: function () {
        box.classList.remove('is-open');
      }
    };

    api.setMessage(opts.message);
    api.setAction(opts.actionText, opts.onAction);
    box.querySelector('[data-role="close"]').onclick = api.close;
    return api;
  };

  RcCommon.exposeLegacyGlobals = function () {
    const mappings = {
      escapeHtml: RcCommon.escapeHtml,
      escapeAttr: RcCommon.escapeAttr,
      openModal: RcCommon.openModal,
      closeModal: RcCommon.closeModal,
      showModal: RcCommon.openModal,
      showImageModal: RcCommon.openImagePreview,
      toast: RcCommon.toast,
      formatMoney: RcCommon.formatMoney,
      fmtMoney: RcCommon.fmtMoney
    };
    Object.keys(mappings).forEach(function (key) {
      if (typeof global[key] === 'undefined') {
        global[key] = mappings[key];
      }
    });
  };

  RcCommon.exposeLegacyGlobals();

  global.RcCommon = RcCommon;
})(window);
