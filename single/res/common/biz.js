(function (global) {
  const RcCommon = global.RcCommon;
  if (!RcCommon) {
    throw new Error('RcCommon is required before RcBiz');
  }

  const RcBiz = {};

  RcBiz.riskWeightMap = {
    high: 3,
    medium: 2,
    low: 1
  };

  RcBiz.riskTextMap = {
    high: '高风险',
    medium: '中风险',
    low: '低风险'
  };

  RcBiz.riskClassMap = {
    high: 'rc-risk-tag rc-risk-high',
    medium: 'rc-risk-tag rc-risk-medium',
    low: 'rc-risk-tag rc-risk-low'
  };

  RcBiz.sortViolations = function (list) {
    return (list || []).slice().sort(function (a, b) {
      return (RcBiz.riskWeightMap[b.risk_level] || 0) - (RcBiz.riskWeightMap[a.risk_level] || 0);
    });
  };

  RcBiz.getRiskText = function (level) {
    return RcBiz.riskTextMap[level] || '未知风险';
  };

  RcBiz.getRiskClass = function (level) {
    return RcBiz.riskClassMap[level] || RcBiz.riskClassMap.medium;
  };

  RcBiz.toDisplayUrl = function (url) {
    try {
      return String(url || '').replace(/\/pending_images_folder\//i, '/risk_images_folder/');
    } catch (err) {
      return url || '';
    }
  };

  RcBiz.swapPendingRiskUrl = function (url) {
    const value = String(url || '');
    if (/\/pending_images_folder\//i.test(value)) {
      return value.replace(/\/pending_images_folder\//i, '/risk_images_folder/');
    }
    if (/\/risk_images_folder\//i.test(value)) {
      return value.replace(/\/risk_images_folder\//i, '/pending_images_folder/');
    }
    return value;
  };

  RcBiz.imageFallback = function (img) {
    if (!img || img.dataset.tried === '1') return;
    img.dataset.tried = '1';
    const alt = RcBiz.swapPendingRiskUrl(img.src);
    if (alt && alt !== img.src) {
      img.src = alt;
    }
  };

  RcBiz.updateCounterCard = function (target, options) {
    const el = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (!el) return null;
    const opts = Object.assign({
      text: '',
      bg: '',
      color: '',
      display: 'inline-block'
    }, options || {});
    if (opts.bg) el.style.backgroundColor = opts.bg;
    if (opts.color) el.style.color = opts.color;
    el.style.padding = '8px 12px';
    el.style.borderRadius = '6px';
    el.style.display = opts.display;
    el.style.fontWeight = 'bold';
    el.textContent = opts.text;
    return el;
  };

  RcBiz.loadVenues = function (target, options) {
    const select = typeof target === 'string' ? RcCommon.qs(target) : target;
    if (!select) return Promise.resolve([]);

    const opts = Object.assign({
      apiUrl: 'https://open.rcwulian.cn/api/devMgr/devMgtV2.php?get_venues',
      method: 'GET',
      requestData: null,
      placeholder: '',
      includeEmpty: false,
      selectedValue: null,
      fetchOptions: {},
      mapResponse: function (data) {
        if (!data || data.code !== 0) {
          return { list: [], selectedValue: null };
        }
        if (data.data && Array.isArray(data.data.venues)) {
          return {
            list: data.data.venues,
            selectedValue: data.venue_id || null
          };
        }
        if (Array.isArray(data.data)) {
          return {
            list: data.data,
            selectedValue: data.venue_id || null
          };
        }
        return { list: [], selectedValue: data.venue_id || null };
      },
      getValue: function (item) {
        return item && item.id != null ? item.id : '';
      },
      getLabel: function (item) {
        if (!item) return '';
        const id = item.id != null ? item.id : '';
        const name = item.venue_name || item.name || item.label || '';
        return [id, name].filter(Boolean).join('-');
      }
    }, options || {});

    const request = String(opts.method).toUpperCase() === 'POST'
      ? RcCommon.postForm(opts.apiUrl, opts.requestData || {}, opts.fetchOptions)
      : RcCommon.fetchJson(opts.apiUrl, opts.fetchOptions);

    return request.then(function (result) {
      const data = result.data || {};
      const mapped = opts.mapResponse(data) || {};
      const list = Array.isArray(mapped.list) ? mapped.list : [];
      const selectedValue = opts.selectedValue != null ? opts.selectedValue : mapped.selectedValue;
      RcCommon.populateSelect(select, list, {
        placeholder: opts.placeholder,
        includeEmpty: opts.includeEmpty,
        selectedValue: selectedValue,
        getValue: opts.getValue,
        getLabel: opts.getLabel
      });
      return list;
    });
  };

  RcBiz.renderPager = function (options) {
    return RcCommon.createPager(options);
  };

  RcBiz.mountBanModal = function (options) {
    const opts = Object.assign({
      modal: '#banModal',
      form: '#banForm',
      title: '#modalTitle',
      closeSelector: '[data-role="close"], .modal-close',
      fields: {}
    }, options || {});

    const modal = typeof opts.modal === 'string' ? RcCommon.qs(opts.modal) : opts.modal;
    const form = typeof opts.form === 'string' ? RcCommon.qs(opts.form) : opts.form;
    const title = typeof opts.title === 'string' ? RcCommon.qs(opts.title) : opts.title;

    function setFieldValue(target, value) {
      const el = typeof target === 'string' ? RcCommon.qs(target) : target;
      if (!el) return;
      if ('value' in el) {
        el.value = value == null ? '' : value;
      } else {
        el.textContent = value == null ? '' : value;
      }
    }

    function fill(data) {
      Object.keys(data || {}).forEach(function (key) {
        const target = opts.fields[key] || '#' + key;
        setFieldValue(target, data[key]);
      });
    }

    function open(data) {
      if (data && data.title && title) {
        title.textContent = data.title;
      }
      fill(data);
      RcCommon.openModal(modal);
    }

    function close() {
      RcCommon.closeModal(modal);
    }

    if (modal) {
      modal.addEventListener('click', function (event) {
        if (event.target === modal || event.target.closest(opts.closeSelector)) {
          close();
        }
      });
    }

    return {
      modal: modal,
      form: form,
      title: title,
      open: open,
      close: close,
      fill: fill,
      values: function () {
        return form ? Object.fromEntries(new FormData(form).entries()) : {};
      }
    };
  };

  RcBiz.createListRenderer = function (options) {
    const opts = Object.assign({
      target: null,
      loadingText: '加载中...',
      emptyText: '暂无数据',
      errorText: '数据加载失败，请稍后重试',
      renderItem: function (item) {
        return '<pre>' + RcCommon.escapeHtml(JSON.stringify(item, null, 2)) + '</pre>';
      },
      wrap: function (html) {
        return html;
      }
    }, options || {});

    const target = typeof opts.target === 'string' ? RcCommon.qs(opts.target) : opts.target;

    return {
      target: target,
      loading: function (message) {
        return RcCommon.renderLoading(target, message || opts.loadingText);
      },
      empty: function (message) {
        return RcCommon.renderEmpty(target, message || opts.emptyText);
      },
      error: function (message) {
        return RcCommon.renderError(target, message || opts.errorText);
      },
      render: function (list) {
        if (!Array.isArray(list) || list.length === 0) {
          return this.empty();
        }
        const html = list.map(function (item, index) {
          return opts.renderItem(item, index);
        }).join('');
        return RcCommon.setHtml(target, opts.wrap(html, list));
      }
    };
  };

  RcBiz.initTriStateToggle = function (options) {
    const opts = Object.assign({
      storageKey: 'rcTriStateMode',
      values: ['all', 'stateA', 'stateB'],
      labels: {
        all: '全部显示',
        stateA: '仅显示A',
        stateB: '仅显示B'
      },
      button: null,
      onChange: RcCommon.noop
    }, options || {});

    const button = typeof opts.button === 'string' ? RcCommon.qs(opts.button) : opts.button;
    let current = localStorage.getItem(opts.storageKey) || opts.values[0];
    if (opts.values.indexOf(current) === -1) current = opts.values[0];

    function render() {
      if (button) button.textContent = opts.labels[current] || current;
      opts.onChange(current);
    }

    function next() {
      const index = opts.values.indexOf(current);
      current = opts.values[(index + 1) % opts.values.length];
      localStorage.setItem(opts.storageKey, current);
      render();
    }

    if (button) button.addEventListener('click', next);
    render();

    return {
      getValue: function () { return current; },
      setValue: function (value) {
        if (opts.values.indexOf(value) === -1) return;
        current = value;
        localStorage.setItem(opts.storageKey, current);
        render();
      },
      next: next
    };
  };

  RcBiz.initPendingImageAlert = function (options) {
    const opts = Object.assign({
      apiUrl: 'https://open.rcwulian.cn/api/venue/getPendingImages.php',
      pendingPageUrl: 'https://open.rcwulian.cn/res/peddingMain.html',
      confirmMessage: '有图片待审核，是否立即前往？',
      interval: 30000
    }, options || {});

    let alertShown = false;
    const poller = RcCommon.createPoller(function () {
      return RcCommon.fetchJson(opts.apiUrl).then(function (result) {
        const data = result.data || {};
        if (data.code !== 0 || data.count <= 0 || alertShown) return;
        const confirmed = window.confirm(opts.confirmMessage);
        alertShown = true;
        if (confirmed) window.location.href = opts.pendingPageUrl;
      });
    }, opts.interval);

    poller.start();
    return poller;
  };

  RcBiz.initReportAlert = function (options) {
    const opts = Object.assign({
      dashboardUrl: 'https://open.rcwulian.cn/api/index/adminbackstage.php',
      reportApiUrl: 'https://open.rcwulian.cn/api/operat/ReporthandV2.php',
      reviewPageUrl: 'https://open.rcwulian.cn/res/reporthand.html',
      alertId: 'rcReportAlert',
      interval: 10000,
      cooldownMs: 10 * 60 * 1000,
      autoCloseMs: 30000
    }, options || {});

    let lastReportCount = 0;
    let lastAlertTimestamp = 0;
    let alertOpen = false;
    let autoCloseTimer = null;

    const alertBox = RcCommon.createAlertBox({
      id: opts.alertId,
      actionText: '立即处理',
      onAction: function () {
        window.location.href = opts.reviewPageUrl;
      }
    });

    function close() {
      alertBox.close();
      alertOpen = false;
      if (autoCloseTimer) {
        clearTimeout(autoCloseTimer);
        autoCloseTimer = null;
      }
    }

    global.closeCustomAlert = close;
    global.goToReportReview = function () {
      window.location.href = opts.reviewPageUrl;
    };

    const poller = RcCommon.createPoller(function () {
      const now = Date.now();
      if (alertOpen && now - lastAlertTimestamp < opts.cooldownMs) {
        return;
      }

      return RcCommon.fetchJson(opts.dashboardUrl).then(function (result) {
        const dashboard = result.data || {};
        const reportCount = (dashboard.data && dashboard.data.reportCount) || 0;
        if (reportCount <= lastReportCount || alertOpen) {
          if (reportCount > lastReportCount) lastReportCount = reportCount;
          return;
        }

        return RcCommon.fetchJson(opts.reportApiUrl).then(function (detailResult) {
          const detail = detailResult.data || {};
          if (detail.code !== 0 || !Array.isArray(detail.data) || detail.data.length === 0) {
            lastReportCount = reportCount;
            return;
          }

          alertBox.setMessage('当前有 ' + reportCount + ' 条举报待处理，是否立即前往？');
          alertBox.open();
          alertOpen = true;
          lastReportCount = reportCount;
          lastAlertTimestamp = now;

          if (autoCloseTimer) clearTimeout(autoCloseTimer);
          autoCloseTimer = setTimeout(close, opts.autoCloseMs);
        });
      });
    }, opts.interval);

    poller.start();
    return {
      poller: poller,
      close: close
    };
  };

RcBiz.initShippingAlert = function (options) {
  const opts = Object.assign({
    apiUrl: '/api/dolls/getPendingShipmentCount.php',
    reviewPageUrl: 'pending-shipping.html',
    alertId: 'rcShippingAlert',
    interval: 30000,
    cooldownMs: 60 * 1000,
    autoCloseMs: 30000,
    storageKey: 'pending_shipping_alert_last_time'
  }, options || {});

  let shippingAlertShown = false;
  let autoCloseTimer = null;

  const alertBox = RcCommon.createAlertBox({
    id: opts.alertId,
    actionText: '立即处理',
    onAction: function () {
      window.location.href = opts.reviewPageUrl;
    }
  });

  function canShowShippingAlert() {
    const lastTime = Number(localStorage.getItem(opts.storageKey) || 0);
    return Date.now() - lastTime >= opts.cooldownMs;
  }

  function markShippingAlertShown() {
    localStorage.setItem(opts.storageKey, String(Date.now()));
  }

  function closeShippingAlert() {
    alertBox.close();
    shippingAlertShown = false;

    if (autoCloseTimer) {
      clearTimeout(autoCloseTimer);
      autoCloseTimer = null;
    }
  }

  const closeBtn = alertBox.el.querySelector('[data-role="close"]');
  if (closeBtn) {
    closeBtn.onclick = closeShippingAlert;
  }

  function showShippingAlert(count) {
    alertBox.setMessage('当前有 ' + count + ' 条待发货记录，是否立即前往？');
    alertBox.open();

    shippingAlertShown = true;
    markShippingAlertShown();

    if (autoCloseTimer) clearTimeout(autoCloseTimer);
    autoCloseTimer = setTimeout(closeShippingAlert, opts.autoCloseMs);
  }

  const poller = RcCommon.createPoller(function () {
    return RcCommon.fetchJson(opts.apiUrl, { credentials: 'include' })
      .then(function (result) {
        const data = result.data || {};
        const count = Number(data.result || 0);

        // 只要有待发货，并且当前没在显示，且满足 localStorage 的冷却时间，就弹
        if (data.code === 0 && count > 0 && !shippingAlertShown && canShowShippingAlert()) {
          showShippingAlert(count);
        }
      });
  }, opts.interval);

  poller.start();

  return {
    poller: poller,
    close: closeShippingAlert
  };
};

RcBiz.renderNavGrid = function (container, items, options) {
  const root = typeof container === 'string' ? RcCommon.qs(container) : container;
  const opts = Object.assign({
    cardClass: 'nav-card',
    iconClass: 'nav-icon',
    textClass: 'nav-text'
  }, options || {});

  if (!root) return;

  root.innerHTML = '';

  (items || []).forEach(function (item) {
    const card = document.createElement('div');
    card.className = opts.cardClass;
    card.id = String(item.id || '').trim();

    const url = item.href || (item.page ? item.page + '.html' : '');
    // console.log(url);
card.innerHTML =
  '<i class="' + RcCommon.escapeHtml(item.icon || '') + ' ' + opts.iconClass + '"></i>' +
  '<div class="' + opts.textClass + '">' + RcCommon.escapeHtml(item.title || item.label || '') + '</div>' +
  (item.badgeId
    ? '<span class="badge" id="' + RcCommon.escapeAttr(item.badgeId) + '" style="display:none;">0</span>'
    : '');

    card.addEventListener('click', function () {
      if (url) window.location.href = url;
    });

    root.appendChild(card);
  });
};

  RcBiz.exposeLegacyGlobals = function () {
    const mappings = {
      loadVenues: RcBiz.loadVenues,
      renderNavGrid: RcBiz.renderNavGrid
    };
    Object.keys(mappings).forEach(function (key) {
      if (typeof global[key] === 'undefined') {
        global[key] = mappings[key];
      }
    });
  };

  RcBiz.exposeLegacyGlobals();

  global.RcBiz = RcBiz;
})(window);
