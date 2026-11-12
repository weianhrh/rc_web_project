import time
import json
import redis
import logging
from database import Database

# ===================== 📌 配置日志 =====================
logger = logging.getLogger(__name__)
logger.setLevel(logging.INFO)

# 日志文件输出
file_handler = logging.FileHandler("order_check.log")
file_handler.setFormatter(logging.Formatter("%(asctime)s - %(levelname)s - %(message)s"))

# 终端输出
console_handler = logging.StreamHandler()
console_handler.setFormatter(logging.Formatter("%(asctime)s - %(levelname)s - %(message)s"))

# 添加日志处理
logger.addHandler(file_handler)
logger.addHandler(console_handler)

# ===================== 📌 连接 Redis =====================
try:
    redis_client = redis.Redis(host='127.0.0.1', port=6379, db=8, decode_responses=True)
 
    
    logger.info("✅ Redis 连接成功")
except Exception as e:
    logger.error(f"❌ Redis 连接失败: {e}")
    exit()

# ===================== 📌 连接数据库 =====================
db = Database()

def check_orders():
    """🔄 订单超时检测循环 """
    while True:
        try:
            order_keys = redis_client.keys("A*")
            if not order_keys:
                logger.info("⏳ 未找到订单，等待下一次检查...")
                time.sleep(60)
                continue

            for order_key in order_keys:
                order_data = redis_client.get(order_key)
                if not order_data:
                    continue

                order_info = json.loads(order_data)
                start_time = order_info.get("start_time")
                billing_rules = order_info.get("billing_rules")  # 可驾驶时间（分钟）

                start_timestamp = time.mktime(time.strptime(start_time, "%Y-%m-%d %H:%M:%S"))
                billing_duration = int(billing_rules) * 60
                expire_time = start_timestamp + billing_duration

                current_time = time.time()
                remaining_time = expire_time - current_time

                #logger.info(f"🕒 订单 {order_key} 剩余时间: {remaining_time:.2f} 秒")

                if remaining_time < -30:
                    logger.info(f"⚠️ 订单 {order_key} 已超时，检查订单状态...")
                    auto_settle_order(order_key, order_info)

            time.sleep(10)
        except Exception as e:
            logger.error(f"❌ 检测订单时发生错误: {e}")
def auto_settle_order(order_key, order_info):
    """✅ 自动结算：最小日志但能直接定性问题"""
    try:
        # 0) 订单号“照妖镜”：repr/len/hex/hash
        ok_raw = order_key
        ok_clean = order_key.strip() if isinstance(order_key, str) else order_key

        logger.warning(
            "[SETTLE][KEY] raw=%r len=%s hex=%s | clean=%r len=%s hex=%s",
            ok_raw,
            len(ok_raw) if isinstance(ok_raw, str) else "NA",
            ok_raw.encode("utf-8", "backslashreplace").hex() if isinstance(ok_raw, str) else "NA",
            ok_clean,
            len(ok_clean) if isinstance(ok_clean, str) else "NA",
            ok_clean.encode("utf-8", "backslashreplace").hex() if isinstance(ok_clean, str) else "NA",
        )

        # 1) DB 会话信息（排除“连的到底是谁/哪台/哪个库/隔离级别/是否自动提交”）
        who_sql = """
            SELECT
              DATABASE() AS db,
              @@hostname AS host,
              @@port AS port,
              USER() AS user,
              @@version AS ver,
              @@autocommit AS autocommit,
              @@transaction_isolation AS tx_iso
        """
        who = db.query(" ".join(who_sql.split()))
        logger.warning("[SETTLE][DB_WHO] %s", who)

        # 2) 关键对比查询：EQ / BINARY / LIKE （一次就能判断“值不相等/隐藏字符/大小写/尾空格”）
        # 注意：这里用 clean 去查，不改变业务，只是用于定位；真正业务仍用 raw（你也可改成用 clean）
        q_eq = db.query(
            "SELECT order_id,status,start_time,LENGTH(order_id) len,HEX(order_id) hex "
            "FROM orders WHERE order_id=%s LIMIT 1",
            (ok_clean,)
        )
        q_bin = db.query(
            "SELECT order_id,status,start_time,LENGTH(order_id) len,HEX(order_id) hex "
            "FROM orders WHERE BINARY order_id=%s LIMIT 1",
            (ok_clean,)
        )
        q_like = db.query(
            "SELECT order_id,status,start_time,LENGTH(order_id) len,HEX(order_id) hex "
            "FROM orders WHERE order_id LIKE %s LIMIT 3",
            (f"%{ok_clean}%",)
        )

        logger.warning("[SETTLE][CHK] EQ=%s | BIN=%s | LIKE=%s", q_eq, q_bin, q_like)

        # 3) 原业务查询（你原来那条）
        check_status_sql = "SELECT status FROM orders WHERE order_id=%s"
        result = db.query(check_status_sql, (order_key,))  # 仍用原 order_key，不改业务
        logger.warning("[SETTLE][BUS_SELECT] hit=%s result=%s", bool(result), result)

        if not result:
            # 只保留必要信息：Redis 是否存在 + key 的 TTL（判断是否被别人清理/是否临时key）
            try:
                exists = redis_client.exists(order_key)
                ttl = redis_client.ttl(order_key)
                logger.warning("[SETTLE][MISS] db_miss redis_exists=%s ttl=%s", exists, ttl)
            except Exception as re:
                logger.error("[SETTLE][MISS] redis read error: %s", re)

            # 仍按你现有业务：查不到就清 Redis（我不改业务）
            cleanup_redis(order_key)
            return

        # 4) 后续业务不需要那么多日志：只记录“更新影响”
        row0 = result[0]
        current_status = row0.get("status") if isinstance(row0, dict) else (row0[0] if row0 else None)
        logger.warning("[SETTLE][STATUS] current_status=%s", current_status)

        if current_status == "已完成":
            logger.warning("[SETTLE][SKIP] already_done -> cleanup_redis")
            cleanup_redis(order_key)
            return

        update_order_sql = """
            UPDATE orders
            SET status='已完成', end_time=NOW()
            WHERE order_id=%s AND status!='已完成'
        """
        r1 = db.query(update_order_sql, (order_key,), commit=True)
        logger.warning("[SETTLE][UPDATE_ORDERS] return=%s", r1)

        update_reservation_sql = """
            UPDATE Reservations
            SET order_status='已完成', driving_end_time=NOW()
            WHERE order_number=%s AND order_status!='已完成'
        """
        r2 = db.query(update_reservation_sql, (order_key,), commit=True)
        logger.warning("[SETTLE][UPDATE_RES] return=%s", r2)

        cleanup_redis(order_key)
        logger.warning("[SETTLE][DONE] ok")

    except Exception as e:
        logger.exception("[SETTLE][EXC] order=%r err=%s", order_key, e)

def cleanup_redis(order_key: str):
    """删除实时订单和预约订单的 Redis 记录（幂等）"""
    try:
        # 实时订单（DB8）
        deleted_main = redis_client.delete(order_key)
        if deleted_main:
            logger.info(f"🗑️ 已从 Redis DB8 删除订单 {order_key}")
        else:
            logger.info(f"ℹ️ Redis DB8 中未找到订单 {order_key}，可能已被删除")
    except Exception as e:
        logger.error(f"❌ 清理 Redis 订单 {order_key} 时发生异常: {e}")

if __name__ == "__main__":
    logger.info("🚀 启动订单检测任务...")
    check_orders()