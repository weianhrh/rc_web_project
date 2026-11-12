# -*- coding: utf-8 -*-
import asyncio
import json
import os
import uuid
from datetime import datetime

import aiohttp
import websockets
import redis

LOG_FILE = "websocket_messages.log"

HOST = "0.0.0.0"
PORT = 6565

SAVE_DOLL_URL_RC = "http://rcwulian.cn/app/user/Dollmachine/save_user_doll.php"
SAVE_DOLL_URL_XX = "http://rcxxzk.cn/app/user/Dollmachine/save_user_doll.php"

# 复用 HTTP 连接
http_session = None

# =========================
# Redis (db=3)
# 1) tk:{token} -> uid (TTL $ttl)
# 2) ws:uid:{uid}:game -> game_id (TTL 180s)
# =========================
REDIS_HOST = "127.0.0.1"
REDIS_PORT = 6379
REDIS_DB = 3

rdb = redis.Redis(
    host=REDIS_HOST,
    port=REDIS_PORT,
    db=REDIS_DB,
    decode_responses=True
)

# 可选：订阅成功后给 token 续期（秒）
# 如果你不想续期，设为 0
TOKEN_REFRESH_TTL = 0

# 路由表：game_id -> set(ws)
subs_by_game = {}

# ws -> info
# app:    {"role":"app","uid":"xxx","game_id":"xxx"}
# device: {"role":"device","sn":"xxx","game_id":"xxx"}
ws_info = {}


def now_ts() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def log_line(text: str):
    line = f"[{now_ts()}] {text}"
    print(line)
    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(line + "\n")


def json_for_log(value) -> str:
    try:
        return json.dumps(value, ensure_ascii=False, sort_keys=True)
    except Exception:
        return repr(value)


def normalize_token(token: str) -> str:
    return (token or "").strip()


def strip_bearer(token: str) -> str:
    t = (token or "").strip()
    if t.lower().startswith("bearer "):
        return t.split(" ", 1)[1].strip()
    return t


def normalize_game_id(game_id) -> str:
    return str(game_id or "").strip()


def redis_verify_token_get_uid(token: str):
    """
    用 Redis 校验 token，返回 uid（字符串）或 None
    兼容两种 key 形式：
      - tk:Bearer xxx
      - tk:xxx
    """
    token = normalize_token(token)
    if not token:
        log_line("[REDIS] MISS token empty")
        return None

    candidates = [
        f"tk:{token}",
        f"tk:{strip_bearer(token)}",
    ]

    try:
        for k in candidates:
            if k == "tk:" or k.endswith("tk:"):
                continue

            uid = rdb.get(k)
            if uid:
                log_line(f"[REDIS] HIT key={k} uid={uid}")
                if TOKEN_REFRESH_TTL and TOKEN_REFRESH_TTL > 0:
                    try:
                        rdb.expire(k, TOKEN_REFRESH_TTL)
                        log_line(f"[REDIS] EXPIRE key={k} ttl={TOKEN_REFRESH_TTL}")
                    except Exception as e:
                        log_line(f"[REDIS] EXPIRE failed key={k} err={e}")
                return str(uid)

        log_line(f"[REDIS] MISS token='{token}' candidates={candidates}")
        return None

    except Exception as e:
        log_line(f"[REDIS] verify token failed: {e}")
        return None


def redis_get_game_id_by_uid(uid: str):
    key = f"ws:uid:{uid}:game"
    try:
        val = rdb.get(key)
        log_line(f"[REDIS] GET {key} -> {val}")
        return val
    except Exception as e:
        log_line(f"[REDIS] get {key} failed: {e}")
        return None


async def safe_send(ws, payload: dict):
    try:
        await ws.send(json.dumps(payload, ensure_ascii=False))
        return True
    except Exception as e:
        log_line(f"[SEND] failed err={e} payload={payload}")
        return False


def get_save_url_by_order_id(order_id: str) -> str:
    """
    根据订单号前两位决定保存地址：
    - RC 开头 -> rcwulian.cn
    - XX 开头 -> rcxxzk.cn
    - 其他情况 -> 默认走 RC，避免旧逻辑失效
    """
    oid = str(order_id or "").strip().upper()

    if oid.startswith("XX"):
        return SAVE_DOLL_URL_XX

    # RC 或其他未知前缀，默认走原来的地址
    return SAVE_DOLL_URL_RC


async def post_save_user_doll(order_id: str, sn: str, game_id: str, trace_id: str):
    global http_session

    if not http_session:
        log_line(f"[SAVE][{trace_id}] http_session not ready")
        return False, "no session"

    payload = {
        "orderId": str(order_id or "").strip(),
        "serial_number": str(sn or "").strip(),
        "game_id": str(game_id or "").strip(),
    }

    if not payload["orderId"] or not payload["serial_number"] or not payload["game_id"]:
        log_line(f"[SAVE][{trace_id}] skip: missing fields payload={payload}")
        return False, "missing fields"

    save_url = get_save_url_by_order_id(payload["orderId"])

    try:
        async with http_session.post(
            save_url,
            data=payload,
            timeout=aiohttp.ClientTimeout(total=6)
        ) as resp:
            text = await resp.text()
            log_line(
                f"[SAVE][{trace_id}] POST ok url={save_url} http={resp.status} "
                f"req={payload} resp={text[:300]}"
            )
            return (200 <= resp.status < 300), text
    except Exception as e:
        log_line(f"[SAVE][{trace_id}] POST failed url={save_url} err={e} req={payload}")
        return False, str(e)


def remove_ws_from_all_games(ws):
    """
    把这个 ws 从所有 game_id 的订阅里彻底移除。
    这样即使之前因为重复 sub 残留了旧 game_id，也能一次性清掉。
    """
    removed_games = []

    for gid, s in list(subs_by_game.items()):
        if ws in s:
            s.discard(ws)
            removed_games.append(gid)

            if not s:
                subs_by_game.pop(gid, None)

    return removed_games


async def cleanup_ws(ws):
    """
    连接断开时统一清理：
    1. 删除 ws_info
    2. 从所有 game_id 订阅中移除该 ws
    """
    info = ws_info.pop(ws, None)
    removed_games = remove_ws_from_all_games(ws)

    log_line(
        f"[CLEAN] role={(info or {}).get('role')} "
        f"uid={(info or {}).get('uid')} "
        f"sn={(info or {}).get('sn')} "
        f"last_game_id={(info or {}).get('game_id')} "
        f"removed_games={removed_games} "
        f"now_sub_games={list(subs_by_game.keys())}"
    )


async def handle_sub(ws, obj, trace_id: str):
    """
    APP：
    {"type":"sub","token":"Bearer xxx"}
    或
    {"type":"sub","token":"xxx"}
    """
    token = obj.get("token", "")
    log_line(f"[SUB][{trace_id}] recv obj={json_for_log(obj)}")
    log_line(f"[SUB][{trace_id}] recv keys={list(obj.keys())}")
    log_line(f"[SUB][{trace_id}] recv token repr={repr(token)}")

    uid = redis_verify_token_get_uid(token)
    log_line(f"[SUB][{trace_id}] token -> uid={uid}")

    if not uid:
        await safe_send(ws, {"type": "deny", "msg": "token invalid", "trace_id": trace_id})
        await ws.close()
        log_line(f"[SUB][{trace_id}] deny: token invalid")
        return

    game_id_raw = redis_get_game_id_by_uid(uid)
    game_id = normalize_game_id(game_id_raw)
    log_line(f"[SUB][{trace_id}] uid={uid} -> game_id_raw={game_id_raw} norm_game_id='{game_id}'")

    if not game_id:
        await safe_send(
            ws,
            {
                "type": "deny",
                "msg": "no active game_id (not started or expired)",
                "trace_id": trace_id
            }
        )
        await ws.close()
        log_line(f"[SUB][{trace_id}] deny: no active game_id")
        return

    # 关键修复：
    # 同一个 ws 每次订阅新订单前，先从所有旧订单里解绑
    removed_games = remove_ws_from_all_games(ws)
    if removed_games:
        log_line(f"[SUB][{trace_id}] ws removed from old games={removed_games}")

    subs_by_game.setdefault(game_id, set()).add(ws)
    ws_info[ws] = {"role": "app", "uid": uid, "game_id": game_id}

    await safe_send(ws, {"type": "ok", "uid": uid, "game_id": game_id, "trace_id": trace_id})

    log_line(
        f"[SUB][{trace_id}] OK uid={uid} bind game_id='{game_id}' "
        f"online_in_game={len(subs_by_game[game_id])}"
    )
    log_line(f"[SUB][{trace_id}] ALL sub_games={list(subs_by_game.keys())}")


async def handle_unsub(ws, obj, trace_id: str):
    """
    可选支持：
    APP 主动退订
    {"type":"unsub"}
    或
    {"type":"unsub","game_id":"xxx"}
    """
    req_game_id = normalize_game_id(obj.get("game_id", ""))
    removed_games = []

    if req_game_id:
        s = subs_by_game.get(req_game_id)
        if s and ws in s:
            s.discard(ws)
            removed_games.append(req_game_id)
            if not s:
                subs_by_game.pop(req_game_id, None)
    else:
        removed_games = remove_ws_from_all_games(ws)

    info = ws_info.get(ws) or {}
    if info.get("role") == "app":
        info["game_id"] = ""
        ws_info[ws] = info

    await safe_send(ws, {"type": "ok", "action": "unsub", "removed_games": removed_games, "trace_id": trace_id})
    log_line(f"[UNSUB][{trace_id}] removed_games={removed_games} now_sub_games={list(subs_by_game.keys())}")


async def handle_device_report(ws, obj, trace_id: str):
    """
    设备：
    {"type":"1D","sn":"...","game_id":"...","win_total":1,"delta":1}
    或 {"type":"report", "game_id":"...", "payload":{...}}
    或 {"type":"result", ...}
    """
    game_id = normalize_game_id(obj.get("game_id", ""))
    sn = str(obj.get("sn", "")).strip()

    log_line(f"[DEV][{trace_id}] recv sn='{sn}' game_id='{game_id}' obj={obj}")

    if not game_id:
        await safe_send(ws, {"type": "error", "msg": "missing game_id", "trace_id": trace_id})
        log_line(f"[DEV][{trace_id}] error: missing game_id")
        return

    ws_info[ws] = {"role": "device", "sn": sn, "game_id": game_id}

    # 设备上报 win_total=1 时：调用 save_user_doll.php（不依赖订阅者存在）
    try:
        win_total = int(obj.get("win_total", 0) or 0)
    except Exception:
        win_total = 0

    if win_total == 1:
        order_id = obj.get("orderId") or obj.get("order_id") or obj.get("orderID") or game_id
        asyncio.create_task(post_save_user_doll(order_id, sn, game_id, trace_id))

    all_games = list(subs_by_game.keys())
    targets = list(subs_by_game.get(game_id, set()))

    if not targets:
        log_line(f"[ROUTE][{trace_id}] DROP game_id='{game_id}' no subscribers. all_sub_games={all_games}")
        await safe_send(
            ws,
            {
                "type": "ack",
                "game_id": game_id,
                "subs": 0,
                "note": "no subscribers",
                "trace_id": trace_id
            }
        )
        return

    push_payload = {"type": "push", "game_id": game_id, "data": obj, "trace_id": trace_id}

    dead = []
    ok_count = 0

    for w in targets:
        ok = await safe_send(w, push_payload)
        if ok:
            ok_count += 1
        else:
            dead.append(w)

    for w in dead:
        try:
            await cleanup_ws(w)
        except Exception:
            pass

    log_line(
        f"[ROUTE][{trace_id}] OK game_id='{game_id}' "
        f"pushed={ok_count}/{len(targets)} dead={len(dead)} all_sub_games={list(subs_by_game.keys())}"
    )

    # 给设备 ACK：subs=实际推送数量
    await safe_send(ws, {"type": "ack", "game_id": game_id, "subs": ok_count, "trace_id": trace_id})


async def handle_client(ws, path):
    client_address = ws.remote_address
    log_line(f"[IN] client={client_address} path={path}")
    try:
        log_line(f"[IN] client={client_address} headers={dict(ws.request_headers)}")
    except Exception as e:
        log_line(f"[IN] client={client_address} headers read failed: {e}")

    try:
        async for message in ws:
            trace_id = uuid.uuid4().hex[:8]
            log_line(f"[MSG][{trace_id}] from={client_address} raw repr={repr(message)}")

            try:
                obj = json.loads(message)
            except Exception as e:
                log_line(f"[MSG][{trace_id}] json parse error: {e}")
                await safe_send(ws, {"type": "error", "msg": "message must be json", "trace_id": trace_id})
                continue

            mtype = obj.get("type")
            log_line(f"[MSG][{trace_id}] parsed={json_for_log(obj)}")
            log_line(f"[MSG][{trace_id}] keys={list(obj.keys())}")
            log_line(f"[MSG][{trace_id}] type={mtype}")

            if mtype == "sub":
                await handle_sub(ws, obj, trace_id)
                continue

            if mtype == "unsub":
                await handle_unsub(ws, obj, trace_id)
                continue

            if mtype in ("1D", "report", "result"):
                await handle_device_report(ws, obj, trace_id)
                continue

            await safe_send(ws, {"type": "echo", "msg": "unknown type", "got": obj, "trace_id": trace_id})

    except websockets.exceptions.ConnectionClosed as e:
        log_line(f"[OFF] client={client_address} code={getattr(e, 'code', None)} reason={getattr(e, 'reason', None)}")
    finally:
        await cleanup_ws(ws)


async def main():
    global http_session

    log_line(f"启动WebSocket服务器，监听 {HOST}:{PORT} ...")
    log_line(f"接收的消息将保存到: {os.path.abspath(LOG_FILE)}")
    log_line(f"Redis: host={REDIS_HOST} port={REDIS_PORT} db={REDIS_DB}")

    http_session = aiohttp.ClientSession()

    try:
        async with websockets.serve(
            handle_client,
            HOST,
            PORT,
            ping_interval=20,
            ping_timeout=20
        ):
            await asyncio.Future()
    finally:
        if http_session:
            await http_session.close()
            http_session = None


if __name__ == "__main__":
    asyncio.run(main())
