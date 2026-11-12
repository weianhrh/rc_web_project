import time
import json
import logging
import requests
import redis
from datetime import datetime, timedelta

# 日志配置
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("auto_cancel.log"),
        logging.StreamHandler()
    ]
)

# Redis 配置
REDIS_HOST = '127.0.0.1'
REDIS_PORT = 6379
REDIS_DB = 9

# 取消预约 API 地址
CANCEL_URL = "https://open.rcwulian.cn/api/venue/CancelReservation.php"

def cancel_expired_reservations():
    r = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DB, decode_responses=True)
    keys = r.keys('*')
    now = datetime.now()

    for order_number in keys:
        try:
            value = r.get(order_number)
            data = json.loads(value)

            reservation_time_str = data.get("reservation_time")
            if not reservation_time_str:
                logging.warning(f"订单 {order_number} 缺少 reservation_time 字段，跳过")
                continue

            # 解析预约时间
            reservation_time = datetime.strptime(reservation_time_str, '%Y-%m-%d %H:%M:%S')

            # 判断是否超过半小时
            if now - reservation_time > timedelta(minutes=10):
                # 发起取消请求
                response = requests.get(CANCEL_URL, params={"order_number": order_number}, timeout=10)

                if response.status_code == 200 and '已取消' in response.text:
                    logging.info(f"✅ 订单 {order_number} 已超时并取消成功")
                    r.delete(order_number)
                else:
                    
                    logging.warning(f"❌ 取消订单 {order_number} 失败，返回：{response.text}")
            else:
                logging.info(f"订单 {order_number} 未超时，跳过")
        except Exception as e:
            logging.error(f"处理订单 {order_number} 出错：{e}")

if __name__ == "__main__":
    while True:
        cancel_expired_reservations()
        time.sleep(60)  # 每分钟执行一次
