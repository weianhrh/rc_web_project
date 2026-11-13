(() => {
  'use strict';
  document.querySelectorAll('details[data-stats-platform]').forEach(panel => {
    const platform = panel.dataset.statsPlatform;
    if (!['rc', 'xh'].includes(platform)) return;
    const key = 'reviewCombinedStatsCollapsed.' + platform;
    try {
      if (localStorage.getItem(key) === '1') panel.open = false;
    } catch (_) { /* Native disclosure still works when storage is unavailable. */ }
    panel.addEventListener('toggle', () => {
      try { localStorage.setItem(key, panel.open ? '0' : '1'); } catch (_) {}
    });
  });
})();
