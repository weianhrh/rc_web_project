# -*- coding: utf-8 -*-
import redis
import pymysql

# ─────────── Redis 连接配置 ───────────
r = redis.Redis(host='localhost', port=6379, db=4, decode_responses=True)

# ─────────── MySQL 连接配置 ───────────
DB_CFG = dict(
    host="localhost",
    user="5grc",
    password="f6eca7806e73cd25",
    database="5grc",
    port=3306,
    charset="utf8mb4",
    cursorclass=pymysql.cursors.DictCursor,
)

# ─────────── 主逻辑 ───────────
def main():
    # 1. 获取 Redis 所有 key
    all_keys = r.keys('*')
    print(f"Redis中共有 {len(all_keys)} 个key")

    # 2. 过滤掉以 venue_image_lock 和 venue_name_lock 开头的 key
    filtered_keys = [
        k for k in all_keys
        if not (k.startswith("venue_image_lock:") or k.startswith("venue_name_lock:"))
    ]

    # 3. 从 MySQL 读取所有 serial_number
    conn = pymysql.connect(**DB_CFG)
    cursor = conn.cursor()
    cursor.execute("SELECT serial_number FROM vehicles")
    rows = cursor.fetchall()
    mysql_serials = {row["serial_number"] for row in rows}

    print(f"MySQL中共有 {len(mysql_serials)} 个serial_number")

    # 4. 检查 Redis 的 key 是否在 MySQL 中存在
    not_found = []
    for key in filtered_keys:
        if key not in mysql_serials:
            not_found.append(key)

    # 5. 输出结果
    if not_found:
        print("以下 Redis key 不存在于 vehicles.serial_number 中：")
        for k in not_found:
            print(" -", k)
    else:
        print("✅ 所有 Redis key 均存在于 vehicles.serial_number 中。")

    cursor.close()
    conn.close()

if __name__ == "__main__":
    main()
