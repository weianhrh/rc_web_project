# -*- coding: utf-8 -*-
import asyncio
import json
import time
import aiomysql
import aioredis
import websockets

HOST = '0.0.0.0'
PORT = 6363

# 本地 WS 服务端口
WS_PORT = 6565

# 注意：客户端连接不能用 0.0.0.0
WS_URL = f"ws://127.0.0.1:{WS_PORT}"

# =========================
# 自动接管参数
# =========================
TAKEOVER_IDLE_SECONDS = 20
TAKEOVER_WINDOW_SECONDS = 40
TAKEOVER_MIN_HITS = 2
REQUIRE_SAME_IP_FOR_TAKEOVER = False

# 缓冲区保护，防止异常连接撑爆内存
MAX_BUFFER_SIZE = 64 * 1024

# =========================
# 日志控制
# =========================
LOG_DEBUG_TRAFFIC = False      # 高频成功日志：HB OK / GPS OK / CMD SEND / R WS-OK
LOG_DEBUG_ACTIVITY = False     # ACTIVITY 日志
LOG_RATE_LIMIT_SECONDS = 10    # 限频日志默认间隔（秒）

last_log_time = {}

# 存储设备连接：serial_number -> writer
connections = {}

# 反向索引：writer -> serial_number
writer_to_serial = {}

# 设备历史/活动状态
serial_last_seen = {}   # serial_number -> monotonic time
serial_last_ip = {}     # serial_number -> ip string
known_serials = set()   # 已注册过/预加载的设备

# 自动接管候选区
pending_takeovers = {}

# 连接日志信息
writer_meta = {}
conn_seq = 0

# 全局 Redis 连接
REDIS_CONN = None


def log(msg: str):
    ts = time.strftime('%Y-%m-%d %H:%M:%S')
    print(f"[{ts}] {msg}")


def now_mono():
    return time.monotonic()


def log_debug(msg: str):
    if LOG_DEBUG_TRAFFIC:
        log(msg)


def log_activity(msg: str):
    if LOG_DEBUG_ACTIVITY:
        log(msg)


def log_limited(key: str, msg: str, interval: int = LOG_RATE_LIMIT_SECONDS):
    now_ts = now_mono()
    last_ts = last_log_time.get(key)
    if last_ts is None or (now_ts - last_ts) >= interval:
        last_log_time[key] = now_ts
        log(msg)


def normalize_serial(serial):
    if serial is None:
        return ''
    return str(serial).strip()


def get_peername(writer):
    try:
        return writer.get_extra_info('peername')
    except Exception:
        return None


def get_peer_ip(writer):
    peer = get_peername(writer)
    if isinstance(peer, tuple) and len(peer) >= 1:
        return str(peer[0])
    return "unknown"


def get_conn_id(writer):
    meta = writer_meta.get(writer)
    return meta.get("cid") if meta else "?"


def get_conn_label(writer):
    if writer is None:
        return "conn=? peer=?"
    meta = writer_meta.get(writer, {})
    cid = meta.get("cid", "?")
    peer = meta.get("peer", get_peername(writer))
    return f"conn={cid} peer={peer}"


def serial_idle_seconds(serial_number):
    last_seen = serial_last_seen.get(serial_number)
    if last_seen is None:
        return None
    return now_mono() - last_seen


def touch_serial_activity(serial_number, writer, via=''):
    serial_last_seen[serial_number] = now_mono()
    serial_last_ip[serial_number] = get_peer_ip(writer)
    if via:
        log_activity(
            f"[ACTIVITY] serial={serial_number} {get_conn_label(writer)} "
            f"via={via} last_ip={serial_last_ip[serial_number]}"
        )


def is_writer_bound_to_serial(serial_number, writer):
    return connections.get(serial_number) is writer and writer_to_serial.get(writer) == serial_number


def register_writer_meta(writer):
    global conn_seq
    conn_seq += 1
    peer = get_peername(writer)
    writer_meta[writer] = {
        "cid": conn_seq,
        "peer": peer,
        "created_at": now_mono(),
    }
    return conn_seq


def extract_messages(buffer: bytes):
    messages = []

    while True:
        start_index = buffer.find(b'+')
        if start_index == -1:
            return messages, b''

        if start_index > 0:
            buffer = buffer[start_index:]

        end_index = buffer.find(b'-', 1)
        if end_index == -1:
            return messages, buffer

        message = buffer[:end_index + 1]
        messages.append(message)
        buffer = buffer[end_index + 1:]


async def init_global_redis():
    global REDIS_CONN
    if REDIS_CONN is None:
        REDIS_CONN = aioredis.from_url(
            "redis://localhost",
            db=4,
            encoding="utf-8",
            decode_responses=True
        )
        log("[REDIS][INIT] global redis connection created")


async def close_global_redis():
    global REDIS_CONN
    if REDIS_CONN is not None:
        try:
            await REDIS_CONN.close()
        except Exception as e:
            log(f"[REDIS][CLOSE-ERR] error={e}")
        else:
            log("[REDIS][CLOSED] global redis connection closed")
        REDIS_CONN = None


async def load_known_serials():
    """
    启动时从数据库预加载全部合法设备 serial_number，
    解决服务重启后设备继续发 P/G 却被判 unknown_serial 的问题。
    """
    global known_serials

    try:
        db = await aiomysql.connect(
            host='localhost',
            user='5grc',
            password='f6eca7806e73cd25',
            db='5grc',
            charset='utf8mb4'
        )
        try:
            async with db.cursor() as cursor:
                await cursor.execute("""
                    SELECT serial_number
                    FROM vehicles
                    WHERE serial_number IS NOT NULL
                      AND serial_number != ''
                """)
                rows = await cursor.fetchall()

                loaded = 0
                for row in rows:
                    serial = normalize_serial(row[0])
                    if serial:
                        known_serials.add(serial)
                        loaded += 1

                log(f"[BOOT][KNOWN-SERIALS] loaded={loaded}")
        finally:
            db.close()

    except Exception as e:
        log(f"[BOOT][KNOWN-SERIALS-ERR] error={e}")


async def safe_close_writer(writer, reason=''):
    addr = get_peername(writer)

    if reason:
        log(f"[CONN][CLOSE-REQ] {get_conn_label(writer)} reason={reason}")

    try:
        if not writer.is_closing():
            writer.close()
        await writer.wait_closed()
    except Exception as e:
        log(f"[CONN][CLOSE-ERR] {get_conn_label(writer)} error={e}")
    else:
        log(f"[CONN][CLOSED] conn={get_conn_id(writer)} peer={addr}")


async def bind_writer_to_serial(serial_number, writer, reason=''):
    old_serial_for_writer = writer_to_serial.get(writer)
    if old_serial_for_writer and old_serial_for_writer != serial_number:
        if connections.get(old_serial_for_writer) is writer:
            del connections[old_serial_for_writer]
        writer_to_serial.pop(writer, None)
        log(
            f"[BIND][REUSE-WRITER] {get_conn_label(writer)} "
            f"old_serial={old_serial_for_writer} new_serial={serial_number}"
        )

    old_writer = connections.get(serial_number)

    connections[serial_number] = writer
    writer_to_serial[writer] = serial_number
    serial_last_ip[serial_number] = get_peer_ip(writer)

    pending_takeovers.pop(serial_number, None)

    log(f"[BIND][OK] serial={serial_number} {get_conn_label(writer)} reason={reason or 'bind'}")

    if old_writer and old_writer is not writer:
        log(f"[BIND][REPLACE] serial={serial_number} old={get_conn_label(old_writer)} new={get_conn_label(writer)}")
        await safe_close_writer(old_writer, f"设备 {serial_number} 已由新连接接管")


def clear_writer_binding_if_match(serial_number, writer, reason=''):
    if connections.get(serial_number) is writer:
        connections.pop(serial_number, None)
        log(f"[CLEANUP][UNBIND] serial={serial_number} {get_conn_label(writer)} reason={reason}")

    if writer_to_serial.get(writer) == serial_number:
        writer_to_serial.pop(writer, None)


def remove_pending_takeover_by_writer(writer):
    to_remove = []
    for serial_number, info in pending_takeovers.items():
        if info.get("writer") is writer:
            to_remove.append(serial_number)

    for serial_number in to_remove:
        pending_takeovers.pop(serial_number, None)
        log(f"[TAKEOVER][CLEANUP] serial={serial_number} {get_conn_label(writer)} pending_removed=True")


def can_attempt_takeover(serial_number, writer, msg_type):
    if serial_number not in known_serials and serial_number not in serial_last_ip:
        log_limited(
            key="takeover_unknown_serial",
            msg=(
                f"[TAKEOVER][REJECT] serial={serial_number} {get_conn_label(writer)} "
                f"msg_type={msg_type} reason=unknown_serial"
            ),
            interval=10
        )
        return False, "unknown_serial"

    old_writer = connections.get(serial_number)
    old_idle = serial_idle_seconds(serial_number)
    old_ip = serial_last_ip.get(serial_number)
    new_ip = get_peer_ip(writer)

    old_stale = (
        old_writer is None
        or old_writer.is_closing()
        or old_idle is None
        or old_idle >= TAKEOVER_IDLE_SECONDS
    )

    if not old_stale:
        log_limited(
            key=f"takeover_reject_active_{serial_number}",
            msg=(
                f"[TAKEOVER][REJECT] serial={serial_number} {get_conn_label(writer)} "
                f"msg_type={msg_type} reason=old_connection_active "
                f"old={get_conn_label(old_writer)} idle={old_idle:.2f}s"
            ),
            interval=10
        )
        return False, "old_connection_active"

    if REQUIRE_SAME_IP_FOR_TAKEOVER and old_ip and new_ip != old_ip:
        log_limited(
            key=f"takeover_ip_mismatch_{serial_number}",
            msg=(
                f"[TAKEOVER][REJECT] serial={serial_number} {get_conn_label(writer)} "
                f"msg_type={msg_type} reason=ip_mismatch old_ip={old_ip} new_ip={new_ip}"
            ),
            interval=10
        )
        return False, "ip_mismatch"

    return True, "ok"


async def try_takeover_by_pg(serial_number, writer, msg_type):
    allowed, _ = can_attempt_takeover(serial_number, writer, msg_type)
    if not allowed:
        return False

    now_ts = now_mono()
    candidate = pending_takeovers.get(serial_number)

    if candidate and candidate["writer"] is writer and (now_ts - candidate["first_seen"] <= TAKEOVER_WINDOW_SECONDS):
        candidate["count"] += 1
        candidate["last_seen"] = now_ts
        candidate["last_msg_type"] = msg_type
    else:
        if candidate and candidate["writer"] is not writer:
            log(
                f"[TAKEOVER][CANDIDATE-REPLACE] serial={serial_number} "
                f"old_candidate={get_conn_label(candidate['writer'])} "
                f"new_candidate={get_conn_label(writer)}"
            )

        pending_takeovers[serial_number] = {
            "writer": writer,
            "count": 1,
            "first_seen": now_ts,
            "last_seen": now_ts,
            "ip": get_peer_ip(writer),
            "last_msg_type": msg_type
        }
        candidate = pending_takeovers[serial_number]

    old_writer = connections.get(serial_number)
    old_idle = serial_idle_seconds(serial_number)

    log(
        f"[TAKEOVER][CANDIDATE] serial={serial_number} {get_conn_label(writer)} "
        f"msg_type={msg_type} count={candidate['count']}/{TAKEOVER_MIN_HITS} "
        f"window={TAKEOVER_WINDOW_SECONDS}s old={get_conn_label(old_writer)} "
        f"idle={old_idle if old_idle is not None else 'None'} "
        f"ip={candidate['ip']}"
    )

    if candidate["count"] >= TAKEOVER_MIN_HITS:
        log(
            f"[TAKEOVER][SUCCESS] serial={serial_number} {get_conn_label(writer)} "
            f"msg_type={msg_type} count={candidate['count']} "
            f"idle={old_idle if old_idle is not None else 'None'}"
        )
        await bind_writer_to_serial(
            serial_number,
            writer,
            reason=f"auto_takeover_via_{msg_type}_idle={old_idle if old_idle is not None else 'None'}s"
        )
        touch_serial_activity(serial_number, writer, via=f"takeover-{msg_type}")
        return True

    return False


async def handle_client(reader, writer):
    cid = register_writer_meta(writer)
    addr = get_peername(writer)
    data_buffer = b""
    log(f"[CONN][OPEN] conn={cid} peer={addr}")

    try:
        while True:
            data = await reader.read(1024)
            if not data:
                break

            data_buffer += data

            if len(data_buffer) > MAX_BUFFER_SIZE:
                log(f"[CONN][BUFFER-OVERFLOW] {get_conn_label(writer)} size={len(data_buffer)} > {MAX_BUFFER_SIZE}")
                break

            messages, data_buffer = extract_messages(data_buffer)

            for raw_message in messages:
                try:
                    message = raw_message.decode('utf-8')
                except UnicodeDecodeError as e:
                    log(f"[PROTO][DECODE-ERR] {get_conn_label(writer)} error={e} raw={raw_message!r}")
                    continue

                await process_message(message, writer)

    except Exception as e:
        log(f"[CONN][EXCEPTION] {get_conn_label(writer)} error={e}")
    finally:
        await cleanup_connection(writer)


async def process_message(message, writer):
    try:
        if len(message) < 3 or not message.startswith('+') or not message.endswith('-'):
            log(f"[PROTO][INVALID] {get_conn_label(writer)} raw={message}")
            return

        body = message[1:-1]
        parts = body.split('+', 2)

        if len(parts) < 2:
            log(f"[PROTO][INCOMPLETE] {get_conn_label(writer)} raw={message}")
            return

        msg_type = parts[0].strip()
        serial_number = normalize_serial(parts[1])
        msg_content = parts[2] if len(parts) >= 3 else ''

        if not msg_type or not serial_number:
            log(f"[PROTO][EMPTY-FIELD] {get_conn_label(writer)} raw={message}")
            return

        if msg_type == 'J':
            await register_device(serial_number, msg_content, writer)

        elif msg_type == 'F':
            await forward_message(serial_number, msg_content)

        elif msg_type == 'G':
            await handle_gps_data(serial_number, msg_content, writer)

        elif msg_type == 'P':
            await heartbeat(serial_number, writer)

        elif msg_type == 'R':
            await forward_r_to_ws(serial_number, msg_content)

        else:
            log(f"[PROTO][UNKNOWN-TYPE] {get_conn_label(writer)} msg_type={msg_type} raw={message}")

    except Exception as e:
        log(f"[PROTO][PROCESS-ERR] {get_conn_label(writer)} message={message} error={e}")


async def heartbeat(serial_number, writer):
    try:
        if is_writer_bound_to_serial(serial_number, writer):
            touch_serial_activity(serial_number, writer, via='heartbeat')
            writer.write(b"+ping-")
            await writer.drain()
            log_debug(f"[HB][OK] serial={serial_number} {get_conn_label(writer)}")
            return

        takeover_ok = await try_takeover_by_pg(serial_number, writer, 'P')

        if takeover_ok or (serial_number in pending_takeovers and pending_takeovers[serial_number]["writer"] is writer):
            writer.write(b"+ping-")
            await writer.drain()
            log_debug(f"[HB][PING-CANDIDATE] serial={serial_number} {get_conn_label(writer)} takeover_ok={takeover_ok}")
        else:
            log_limited(
                key=f"hb_reject_{serial_number}",
                msg=f"[HB][REJECT] serial={serial_number} {get_conn_label(writer)} reason=not_bound_and_takeover_failed",
                interval=10
            )

    except Exception as e:
        log(f"[HB][ERR] serial={serial_number} {get_conn_label(writer)} error={e}")


async def register_device(serial_number, msg_content, writer):
    try:
        known_serials.add(serial_number)

        await bind_writer_to_serial(serial_number, writer, reason="register_J")
        touch_serial_activity(serial_number, writer, via='register')

        db = await aiomysql.connect(
            host='localhost',
            user='5grc',
            password='f6eca7806e73cd25',
            db='5grc',
            charset='utf8mb4'
        )
        try:
            async with db.cursor() as cursor:
                await cursor.execute(
                    "UPDATE vehicle_control_settings SET version = %s WHERE serial_number = %s",
                    (msg_content, serial_number)
                )
                await db.commit()

                if cursor.rowcount == 0:
                    log(f"[REG][WARN] serial={serial_number} {get_conn_label(writer)} vehicle_control_settings_not_updated=True")
        finally:
            db.close()

        writer.write(b"+OK-")
        await writer.drain()
        log(f"[REG][OK] serial={serial_number} {get_conn_label(writer)} version={msg_content}")

    except Exception as e:
        log(f"[REG][ERR] serial={serial_number} {get_conn_label(writer)} error={e}")


async def forward_message(serial_number, msg_content):
    writer = connections.get(serial_number)

    if not writer:
        log(f"[CMD][MISS] serial={serial_number} reason=no_bound_connection cmd={msg_content}")
        return

    idle = serial_idle_seconds(serial_number)

    if writer.is_closing():
        log(f"[CMD][MISS] serial={serial_number} target={get_conn_label(writer)} reason=writer_closing cmd={msg_content}")
        clear_writer_binding_if_match(serial_number, writer, reason="writer_closing_before_send")
        return

    if idle is not None and idle >= TAKEOVER_IDLE_SECONDS:
        log(f"[CMD][STALE-WARN] serial={serial_number} target={get_conn_label(writer)} idle={idle:.2f}s cmd={msg_content}")

    try:
        writer.write((f"+{msg_content}-").encode('utf-8'))
        await writer.drain()
        log_debug(
            f"[CMD][SEND] serial={serial_number} target={get_conn_label(writer)} "
            f"idle={idle if idle is not None else 'None'} cmd={msg_content}"
        )
    except Exception as e:
        log(f"[CMD][FAIL] serial={serial_number} target={get_conn_label(writer)} cmd={msg_content} error={e}")
        clear_writer_binding_if_match(serial_number, writer, reason=f"command_send_fail:{e}")
        remove_pending_takeover_by_writer(writer)


async def forward_r_to_ws(serial_number, msg_content):
    try:
        async with websockets.connect(
            WS_URL,
            open_timeout=3,
            close_timeout=3,
            ping_interval=None
        ) as ws:
            await ws.send(msg_content)
            log_debug(f"[R][WS-OK] serial={serial_number} data={msg_content}")

    except Exception as e:
        log(f"[R][WS-ERR] serial={serial_number} error={e}")


async def handle_gps_data(device_id, gps_content, writer):
    global REDIS_CONN

    try:
        if not is_writer_bound_to_serial(device_id, writer):
            takeover_ok = await try_takeover_by_pg(device_id, writer, 'G')
            if not takeover_ok:
                log_limited(
                    key=f"gps_reject_{device_id}",
                    msg=f"[GPS][REJECT] serial={device_id} {get_conn_label(writer)} reason=not_bound_and_takeover_failed",
                    interval=10
                )
                return

        touch_serial_activity(device_id, writer, via='gps')

        data_parts = gps_content.split('#')
        if len(data_parts) < 5:
            raise ValueError(f"GPS数据字段不完整: {data_parts}")

        json_output = {
            "serial_number": device_id,
            "voltage": float(data_parts[1]),
            "longitude": float(data_parts[2]),
            "latitude": float(data_parts[3]),
            "speed": float(data_parts[4])
        }

        json_str = json.dumps(json_output, ensure_ascii=False)

        if REDIS_CONN is None:
            log(f"[GPS][REDIS-NOT-READY] serial={device_id} {get_conn_label(writer)}")
            return

        await REDIS_CONN.setex(device_id, 30, json_str)
        log_debug(f"[GPS][OK] serial={device_id} {get_conn_label(writer)} data={json_str}")

    except Exception as e:
        log(f"[GPS][ERR] serial={device_id} {get_conn_label(writer)} error={e}")


async def cleanup_connection(writer):
    addr = get_peername(writer)

    serial_number = writer_to_serial.pop(writer, None)
    if serial_number and connections.get(serial_number) is writer:
        connections.pop(serial_number, None)
        log(f"[CLEANUP][UNBIND] serial={serial_number} {get_conn_label(writer)} reason=connection_closed")

    remove_pending_takeover_by_writer(writer)

    try:
        if not writer.is_closing():
            writer.close()
        await writer.wait_closed()
    except Exception as e:
        log(f"[CONN][CLOSE-ERR] {get_conn_label(writer)} error={e}")

    log(f"[CONN][CLOSE] {get_conn_label(writer)} peer={addr}")

    writer_meta.pop(writer, None)


async def update_device_status(serial_number, status):
    try:
        db = await aiomysql.connect(
            host='localhost',
            user='5grc',
            password='f6eca7806e73cd25',
            db='5grc',
            charset='utf8mb4'
        )
        try:
            async with db.cursor() as cursor:
                await cursor.execute(
                    "UPDATE vehicles SET status = %s WHERE serial_number = %s",
                    (status, serial_number)
                )
                await db.commit()
        finally:
            db.close()

        log(f"[DB][STATUS-OK] serial={serial_number} status={status}")

    except Exception as e:
        log(f"[DB][STATUS-ERR] serial={serial_number} status={status} error={e}")


async def start_server():
    await init_global_redis()
    await load_known_serials()

    server = await asyncio.start_server(handle_client, HOST, PORT)
    log(f"[SERVER][START] TCP={HOST}:{PORT}")
    log(f"[SERVER][START] WS_FORWARD={WS_URL}")
    log(
        f"[SERVER][TAKEOVER] idle={TAKEOVER_IDLE_SECONDS}s "
        f"window={TAKEOVER_WINDOW_SECONDS}s hits={TAKEOVER_MIN_HITS} "
        f"same_ip={REQUIRE_SAME_IP_FOR_TAKEOVER}"
    )
    log(
        f"[SERVER][LOG] debug_traffic={LOG_DEBUG_TRAFFIC} "
        f"debug_activity={LOG_DEBUG_ACTIVITY} rate_limit={LOG_RATE_LIMIT_SECONDS}s"
    )

    try:
        async with server:
            await server.serve_forever()
    finally:
        await close_global_redis()


if __name__ == "__main__":
    asyncio.run(start_server())