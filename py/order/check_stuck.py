import logging
import threading
import time
import urllib.parse
import urllib.request
import ssl
from datetime import datetime
from database import Database

# 配置日志
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s"
)


class VehicleMonitor:
    def __init__(self):
        """初始化数据库连接"""
        self.db = Database()  # 主检测线程使用
        self.pending_updates = {}  # 用于存储等待更新的车辆 {vehicle_id: timer}
# 不调用
    def notify_rc_release_success(self, serial_number): 
        """
        车辆释放成功后，通知 RC 接口更新车辆状态
        POST:
            platform = dn
            serial_number = xxx
        不关心返回结果
        """
        if not serial_number:
            logging.warning("serial_number 为空，跳过请求 redis_update_vehicles_status.php")
            return
    
        url = "https://rcwulian.cn/app/user/drive/redis_update_vehicles_status.php"
    
        post_data = urllib.parse.urlencode({
            "platform": "dn",
            "serial_number": serial_number
        }).encode("utf-8")
    
        req = urllib.request.Request(
            url=url,
            data=post_data,
            method="POST",
            headers={
                "Content-Type": "application/x-www-form-urlencoded"
            }
        )
    
        try:
            # 跳过 SSL 证书校验，类似 curl -k
            context = ssl._create_unverified_context()
    
            with urllib.request.urlopen(req, timeout=3, context=context) as response:
                response.read(64)
    
            logging.info(f"已请求 RC 状态同步接口，serial_number={serial_number}, platform=dn")
    
        except Exception as e:
            # 不影响主逻辑，只记录日志
            logging.warning(f"请求 RC 状态同步接口失败，serial_number={serial_number}，错误：{e}")

    def update_vehicle_status(self, vehicle_id, serial_number):
        """20秒后执行的更新操作"""
        # 定时器线程里单独创建数据库连接，避免多线程共用 self.db 出问题
        db = Database()

        try:
            logging.info(f"正在更新车辆 {vehicle_id} 状态为‘在线’，driver_id 清空...")

            update_result = db.query(
                """
                UPDATE vehicles
                SET status = '在线',
                    vehicle_status = '驾驶',
                    driver_id = NULL,
                    driver = NULL
                WHERE id = %s
                """,
                (vehicle_id,),
                commit=True
            )

            if update_result is not None:
                logging.info(f"车辆 {vehicle_id} 状态已更新为‘在线’，driver_id 已清空。")

                # 新增：修改状态完成后，通知 RC 同步状态
                self.notify_rc_release_success(serial_number)

            else:
                logging.error(f"车辆 {vehicle_id} 状态更新失败。")

        except Exception as e:
            logging.error(f"车辆 {vehicle_id} 状态更新异常：{e}")

        finally:
            db.close()

            # 更新完成后，从 pending_updates 中移除
            self.pending_updates.pop(vehicle_id, None)

    def check_abnormal_occupancy(self):
        """检查异常占有的车辆"""

        # 从 vehicles 表获取 driver_id 不为空的车辆
        vehicles = self.db.query("""
            SELECT id, serial_number, driver_id, uid, status
            FROM vehicles
            WHERE driver_id IS NOT NULL
        """)

        if not vehicles:
            logging.info("当前无车辆被分配司机，无需检查异常占有。")
            return

        for vehicle in vehicles:
            vehicle_id = vehicle['id']
            serial_number = vehicle['serial_number']
            driver_id = vehicle['driver_id']
            owner_id = vehicle['uid']  # 车主的ID
            status = vehicle['status']

            # 如果 driver_id 等于车主 uid，跳过检查
            if str(driver_id) == str(owner_id):
                continue

            # 查询 Reservations 表，检查该 driver_id 是否有‘等待驾驶’或‘正在驾驶’的订单
            active_orders = self.db.query(
                """
                SELECT id
                FROM Reservations
                WHERE user_id = %s
                  AND order_status IN ('等待驾驶', '正在驾驶')
                """,
                (driver_id,)
            )

            if not active_orders and status == '占有':
                # 车辆被异常占有，启动 20 秒后更新状态的计时器
                if vehicle_id not in self.pending_updates:
                    logging.warning(
                        f"车辆 {vehicle_id}，serial_number: {serial_number}，driver_id: {driver_id} "
                        f"可能被异常占有，20秒后更新状态。"
                    )

                    timer = threading.Timer(
                        20,
                        self.update_vehicle_status,
                        args=(vehicle_id, serial_number)
                    )

                    timer.start()

                    # 记录计时器，防止重复执行
                    self.pending_updates[vehicle_id] = timer

                else:
                    logging.info(f"车辆 {vehicle_id} 已在等待更新状态中，跳过重复计时。")

            else:
                logging.info(f"车辆 {vehicle_id}，driver_id: {driver_id} 状态正常或已处理，无需更新。")

    def run_check(self):
        """周期性检查异常占有"""
        logging.info("启动异常占有检测服务...")

        while True:
            self.check_abnormal_occupancy()
            time.sleep(60)


if __name__ == "__main__":
    monitor = VehicleMonitor()
    thread = threading.Thread(target=monitor.run_check)
    thread.start()