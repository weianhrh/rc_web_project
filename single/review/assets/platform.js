(function () {
  'use strict';
  let csrfPromise;
  function platform(value) {
    if (value !== 'rc' && value !== 'xh') throw new Error('无效平台');
    return value;
  }
  function csrf() {
    if (!csrfPromise) csrfPromise = fetch('/review/platform-session.php', {credentials:'same-origin', cache:'no-store'})
      .then(async response => {
        const data = await response.json();
        if (!response.ok || !data.csrf) throw new Error(data.msg || '审核登录已失效，请重新登录');
        return data.csrf;
      }).catch(error => { csrfPromise = null; throw error; });
    return csrfPromise;
  }
  async function request(source, path, options) {
    platform(source);
    const target = new URL(path, location.origin);
    if (!target.pathname.startsWith('/api/')) throw new Error('无效审核接口');
    const init = Object.assign({}, options || {}, {credentials:'same-origin', cache:'no-store'});
    init.headers = new Headers(init.headers || {});
    if ((init.method || 'GET').toUpperCase() !== 'GET') init.headers.set('X-Review-CSRF', await csrf());
    const response = await fetch('/review/platform-api.php?platform=' + source + '&path=' + encodeURIComponent(target.pathname + target.search), init);
    if (response.status === 401) throw new Error('审核登录已失效，请刷新工作台或重新登录');
    // 老接口若输出 PHP HTML 错误，也应显示清楚的接口提示，而非 JSON 解析异常。
    await jsonResponse(response.clone());
    return response;
  }
  async function jsonResponse(response) {
    const raw = await response.text();
    try { return JSON.parse(raw); }
    catch (_) { throw new Error('审核接口返回了非 JSON 内容（HTTP ' + response.status + '），请查看 PHP 错误日志'); }
  }
  function media(source, value) {
    platform(source);
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^data:image\/(?:jpeg|png|gif|webp);base64,/i.test(raw)) return raw;
    try {
      const url = new URL(raw, source === 'rc' ? 'https://open.rcwulian.cn/' : 'https://open.sxzjjx.cn/');
      if (!['https:','http:'].includes(url.protocol) || url.username || url.password || url.port) return '';
      const owned = ['open.rcwulian.cn','rcwulian.cn','open.sxzjjx.cn','sxzjjx.cn'].includes(url.hostname);
      if (owned || /\.aliyuncs\.com$/i.test(url.hostname)) url.protocol = 'https:';
      // 展示直接使用网站原图，保留 Nginx 目录映射、CDN 和视频 Range 行为。
      return url.href;
    } catch (_) { return ''; }
  }
  function mediaProxy(source, value) {
    const url = media(source, value);
    if (!url || url.startsWith('data:')) return url;
    return '/review/platform-media.php?platform=' + platform(source) + '&url=' + encodeURIComponent(url);
  }
  async function fetchImage(source, url) {
    const target = mediaProxy(source, url);
    if (!target) throw new Error('图片地址无效');
    const response = await fetch(target, {credentials:'same-origin', cache:'no-store'});
    if (!response.ok || !/^image\//i.test(response.headers.get('Content-Type') || '')) {
      let reason = '图片读取失败（HTTP ' + response.status + '）';
      try { const data = await jsonResponse(response); reason = data.msg || data.message || reason; } catch (_) {}
      throw new Error(reason);
    }
    return response;
  }
  async function upload(source, file) {
    platform(source);
    const form = file instanceof FormData ? file : new FormData();
    if (!(file instanceof FormData)) form.append('file', file, file.name || 'review.jpg');
    const response = await fetch('/review/platform-upload.php?platform=' + source, {
      method:'POST', credentials:'same-origin', cache:'no-store', headers:{'X-Review-CSRF':await csrf()}, body:form
    });
    const data = await jsonResponse(response);
    if (!response.ok) throw new Error(data.msg || data.message || '图片上传失败');
    return data;
  }
  window.ReviewAPI = {
    request, media, mediaProxy, fetchImage, upload,
    label: source => platform(source) === 'rc' ? 'RC物联' : '星河远控',
    badge: source => '<span class="platform-badge platform-' + platform(source) + '">' + source.toUpperCase() + '</span>'
  };
}());
