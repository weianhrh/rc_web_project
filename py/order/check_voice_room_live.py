# -*- coding: utf-8 -*-
import hashlib
import json
import logging
import re
import secrets
import ssl
import time
import urllib.parse
import urllib.request
from database import Database

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s"
)

ZEGO_APP_ID = 141962251
ZEGO_SERVER_SECRET = "5bfaa3399946c98cc6792dd19f9a08ec"

CHECK_INTERVAL_SECONDS = 60
ZEGO_API_URL = "https://rtc-api.zego.im/"

# 语音房主播开播流：talk_时间戳
# 驾驶页/PTT：talk_房间ID_用户ID_时间戳，不算主播开播流
VOICE_ROOM_LIVE_STREAM_RE = re.compile(r"^talk_\d{10,}$")

SSL_CONTEXT = ssl._create_unverified_context()


class VoiceRoomLiveMonitor:
    def __init__(self):
        self.db = Database()

    def build_zego_signature(self, nonce, timestamp):
        raw = f"{ZEGO_APP_ID}{nonce}{ZEGO_SERVER_SECRET}{timestamp}"
        return hashlib.md5(raw.encode("utf-8")).hexdigest()

    def request_json_get(self, url, params, timeout=8):
        full_url = f"{url}?{urllib.parse.urlencode(params)}"

        req = urllib.request.Request(
            url=full_url,
            method="GET",
            headers={"Accept": "application/json"}
        )

        with urllib.request.urlopen(req, timeout=timeout, context=SSL_CONTEXT) as response:
            body = response.read().decode("utf-8", errors="replace")
            return json.loads(body)

    def get_live_venues(self):
        return self.db.query("""
            SELECT id, venue_name, venue_room_id, venue_status_num, is_live, live_marked_at
            FROM venues
            WHERE venue_status_num = 2
        """)

    def get_zego_stream_list(self, room_id):
        nonce = secrets.token_hex(8)
        timestamp = int(time.time())

        params = {
            "Action": "DescribeSimpleStreamList",
            "AppId": ZEGO_APP_ID,
            "SignatureNonce": nonce,
            "Timestamp": timestamp,
            "Signature": self.build_zego_signature(nonce, timestamp),
            "SignatureVersion": "2.0",
            "RoomId": str(room_id)
        }

        result = self.request_json_get(ZEGO_API_URL, params)

        if result.get("Code") != 0:
            raise RuntimeError(f"ZEGO 查询流列表失败 room_id={room_id}, result={result}")

        return (result.get("Data") or {}).get("StreamList") or []

    def has_voice_room_live_stream(self, stream_list, room_id):
        room_id = str(room_id).strip()

        for stream in stream_list:
            stream_id = str(stream.get("StreamId") or "").strip()
            user_id = str(stream.get("UserId") or "").strip()

            # 旧 iOS 格式：talk_时间戳，例如 talk_1779088016244
            if VOICE_ROOM_LIVE_STREAM_RE.match(stream_id):
                logging.info(f"检测到语音房主播流/旧iOS格式 stream_id={stream_id}, user_id={user_id}")
                return True

            # 旧 Android 格式：talk_房间ID，例如 talk_120001
            if stream_id == f"talk_{room_id}":
                logging.info(f"检测到语音房主播流/旧Android格式 stream_id={stream_id}, user_id={user_id}")
                return True

            # 新主播格式：talk_房间ID_用户ID，例如 talk_120001_10001
            # 注意：这里只认 3 段，避免把驾驶页/PTT 的 talk_房间ID_用户ID_时间戳 误判成主播流
            parts = stream_id.split("_")
            if len(parts) == 3 and parts[0] == "talk" and parts[1] == room_id and parts[2]:
                logging.info(f"检测到语音房主播流/房间用户格式 stream_id={stream_id}, user_id={user_id}")
                return True

        return False
        
    def close_live_in_db(self, venue_id, reason):
        try:
            # 先查操作前状态，用于日志记录
            old_rows = self.db.query(
                """
                SELECT venue_status_num, is_live
                FROM venues
                WHERE id = %s
                LIMIT 1
                """,
                (venue_id,)
            )
    
            old_venue_status_num = None
            old_is_live = None
    
            if old_rows:
                old_venue_status_num = old_rows[0].get("venue_status_num")
                old_is_live = old_rows[0].get("is_live")
    
            # 系统关播：大退兜底清理
            result = self.db.query(
                """
                UPDATE venues
                SET venue_status_num = 0,
                    is_live = 0,
                    last_live_cleared_at = NOW()
                WHERE id = %s
                  AND venue_status_num = 2
                """,
                (venue_id,),
                commit=True
            )
    
            # 写入开关播日志
            log_result = self.db.query(
                """
                INSERT INTO venue_live_status_logs
                (
                    venue_id,
                    action,
                    old_is_live,
                    new_is_live,
                    old_venue_status_num,
                    new_venue_status_num,
                    operator_uid,
                    remark,
                    ip,
                    user_agent
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    venue_id,
                    0,
                    old_is_live,
                    0,
                    old_venue_status_num,
                    0,
                    None,
                    "系统关播",
                    None,
                    "voice_room_live_monitor.py"
                ),
                commit=True
            )
    
            logging.warning(
                f"场地 {venue_id} 已直接数据库关播，原因：{reason}，"
                f"update_result={result}, log_result={log_result}"
            )
            return True

        except Exception as e:
            logging.error(f"场地 {venue_id} 数据库关播失败，原因：{reason}，error={e}")
            return False
        
    def check_once(self):
        venues = self.get_live_venues()

        if not venues:
            logging.info("当前没有 venue_status_num=2 的直播中场地。")
            return

        logging.info(f"开始检测直播中场地数量：{len(venues)}")

        for venue in venues:
            venue_id = venue.get("id")
            venue_name = venue.get("venue_name") or ""
            room_id = venue.get("venue_room_id")

            if not room_id:
                self.close_live_in_db(
                    venue_id,
                    f"venue_room_id 为空，venue_name={venue_name}"
                )
                continue

            try:
                stream_list = self.get_zego_stream_list(room_id)
                stream_ids = [str(item.get("StreamId") or "") for item in stream_list]

                logging.info(
                    f"场地 {venue_id}「{venue_name}」room_id={room_id} "
                    f"当前流数量={len(stream_list)}, streams={stream_ids}"
                )

                # if self.has_voice_room_live_stream(stream_list):
                if self.has_voice_room_live_stream(stream_list, room_id):
                    logging.info(f"场地 {venue_id}「{venue_name}」仍有主播语音流，跳过。")
                    continue

                self.close_live_in_db(
                    venue_id,
                    f"没有检测到语音房主播流，room_id={room_id}, streams={stream_ids}"
                )

            except Exception as e:
                self.close_live_in_db(
                    venue_id,
                    f"查询即构流列表失败，room_id={room_id}, error={e}"
                )

            time.sleep(0.3)

    def run(self):
        logging.info("启动语音房直播状态兜底清理任务...")

        while True:
            try:
                self.check_once()
            except Exception as e:
                logging.error(f"本轮检测异常：{e}")

            time.sleep(CHECK_INTERVAL_SECONDS)

    def close(self):
        self.db.close()


if __name__ == "__main__":
    monitor = VoiceRoomLiveMonitor()

    try:
        monitor.run()
    finally:
        monitor.close()
