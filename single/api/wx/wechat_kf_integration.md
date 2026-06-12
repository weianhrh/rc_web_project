# 微信对话开放平台 H5 客服系统接入完整文档

---

## 一、接入背景与目标

为实现微信对话开放平台与第三方客服系统的对接，完成以下目标：

- 实现用户与智能机器人/人工客服之间的消息交互；
- 客服可在网页客服面板中接入用户、发送消息、结束会话；
- 支持状态同步，避免客服状态丢失后机器人重复响应；
- 实现用户进入/退出事件的处理。

---

## 二、接入功能总览

### 接口分类：

1. **回调消息接收接口**（`callback.php`）
2. **发送客服消息接口**（`sendmsg.php`）
3. **客服状态设置接口**（`kf_state.php`）
4. **客服状态查询接口**（微信官方：`kefustate/get`）

### 回调消息字段说明：

字段 | 说明
---|---
userid | 用户 openid
from | 0: 用户；1: 机器人；2: 客服
content.msg | 消息文本
kfstate | 客服接入状态：0/1/2/3  kfstate:1 ==> 客服接入状态 kfstate:2==> 客服退出状态
channel | 渠道 ID，一般为 7（H5）
event | 事件类型，如 userEnter、userQuit

---

## 三、发送客服消息（sendmsg.php）

### 请求逻辑：

- 接收 `userid`, `msg`, `event`, `channel`, `kefuname`, `kefuavatar`
- 构造加密 XML，通过 `https://chatbot.weixin.qq.com/openapi/sendmsg/{TOKEN}` 发送
- 特殊事件 `waiterEnter` 发送后应立即调用 `kefustate/change` 设置状态为 `personserving`

### 自动设置状态示例：

```php
if ($event === 'waiterEnter') {
  changeWxKefuState($userid, 'personserving');
}
```

---

## 四、客服接入状态管理（kf_state.php）

用于本地记录 Redis 或数据库客服接入的缓存逻辑，提供手动或前端设置：

- `action=set` 表示客服接入
- `action=del` 表示客服退出
- Redis 存储键建议：`wx_kf_state:{userid}`

---

## 五、回调消息处理（callback.php）

### 解密消息后处理逻辑：

1. 接收 `encrypted` 字段，解密成 XML；
2. 提取 `userid`, `msg`, `from`, `event`, `kfstate` 等字段；
3. 若 `from=0` 且 `kfstate!=1`，说明客服状态丢失，可调用 `kefustate/change` 修复：

```php
if ($from === 0 && empty($event)) {
    $state = getWxKefuState($userid);
    if (!$state['err'] && $state['kefustate'] !== 'on') {
        changeWxKefuState($userid, 'personserving');
    }
}
```

4. 若事件为 `userQuit`，则调用 `changeWxKefuState($userid, 'complete')`

---

## 六、网页客服面板（wechatkfpanel.html）

实现功能：

- 左侧用户列表自动加载待接入用户（从 Redis 或数据库）；
- 点击用户加载聊天记录；
- 支持发送消息、结束会话；
- 每 5 秒自动轮询更新对话内容；

### JS 关键逻辑：

```js
fetch('/api/wx/sendmsg.php', {
  method: 'POST',
  body: `userid=${uid}&event=waiterEnter` // 同时设置状态
});

fetch('/api/wx/kf_state.php?action=set&userid=' + uid); // 本地状态记录
```

结束会话时：

```js
fetch('/api/wx/sendmsg.php', {
  method: 'POST',
  body: `userid=${uid}&event=waiterQuit`
});
fetch('/api/wx/kf_state.php?action=del&userid=' + uid);
```

---

## 七、客服状态定义

状态 ID | 状态名称 | 含义
---|---|---
0 | asking | 机器人自动回复状态
1 | personserving | 人工客服已接入
2 | complete | 对话已结束
3 | needperson | 待转人工（触发“人工客服”）

---

## 八、常见问题及解决方案

### ❓ 问题：客服接入后机器人仍回复？
- ✔ 原因：未调用 `kefustate/change` 设置为 `personserving`
- ✅ 解决：在 `waiterEnter` 时主动调用接口

### ❓ 问题：用户息屏/切后台后状态丢失？
- ✔ 原因：微信平台自动清除状态
- ✅ 解决：在 `callback.php` 中自动检测并调用恢复

### ❓ 问题：没有收到微信的回调？
- ✔ 原因：只有用户进入 H5 页面后互动才有回调
- ✅ 解决：确保 iframe 加载正常，用户有发消息行为

---

## 九、测试建议

1. 用户打开 H5 页面，输入文字，应收到 `from=0` 回调；
2. 客服点击用户，触发 `waiterEnter` 并发送消息，状态应为 `personserving`；
3. 用户锁屏或关闭页面后再发消息，系统应自动检测并恢复状态；
4. 客服点击“结束会话”，状态应变为 `complete`，机器人恢复。

---

## 十、推荐日志文件

- `dialog_callback.log`：所有消息记录日志
- `wx_kefustate_debug.log`：微信状态获取与同步记录
- `debug_raw_input.log`：原始回调 POST 数据包

---

## ✅ 建议总结

| 功能 | 是否必做 | 说明 |
|---|---|---|
| 发送 `personserving` 状态 | ✅ 必做 | 防止机器人多次插话 |
| 用户发消息时恢复状态 | ✅ 强烈建议 | 防止微信状态被清除 |
| `userQuit` 设置 `complete` | ✅ 推荐 | 清理 Redis、退出标识 |
| 页面定时轮询 | ⚠️ 可选 | 防止客服断连后未感知 |
