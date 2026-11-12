import time
import logging
import threading
import urllib.parse
import urllib.request
import ssl
from database import Database

# 设置日志输出格式
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)


def notify_rc_release_success(serial_number):
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


def notify_rc_release_success_async(serial_number):
    """
    异步通知 RC，避免接口请求卡住检测脚本
    """
    threading.Thread(
        target=notify_rc_release_success,
        args=(serial_number,),
        daemon=True
    ).start()


def check_and_reset_vehicles():
    db = Database()
    try:
        # 查询异常车辆：无驾驶员 + 占有状态
        vehicles_sql = """
            SELECT id, serial_number, battery_level
            FROM vehicles
            WHERE driver_id IS NULL
              AND status = '占有'
        """

        # 如果你后面还想限制必须是驾驶状态，可以改成：
        # AND vehicle_status = '驾驶'

        vehicles = db.query(vehicles_sql)

        if vehicles:
            logging.info(f"检测到异常车辆，共 {len(vehicles)} 条")

            for vehicle in vehicles:
                vehicle_id = vehicle['id']
                serial_number = vehicle['serial_number']
                battery_level = vehicle['battery_level']

                # 判断电量状态，设置目标 status
                new_status = '在线' if battery_level != '-' else '离线'

                reset_sql = """
                    UPDATE vehicles
                    SET driver_id = NULL,
                        driver = NULL,
                        status = %s
                    WHERE id = %s
                """

                db.query(reset_sql, (new_status, vehicle_id), commit=True)

                logging.info(
                    f"已重置车辆，vehicle_id: {vehicle_id}，serial_number: {serial_number}，新状态: {new_status}"
                )

                # 新增：修改状态完成后，通知 RC 同步状态
                notify_rc_release_success_async(serial_number)

        else:
            logging.info("没有检测到异常车辆。")

    except Exception as e:
        logging.error(f"发生错误：{e}")
    finally:
        db.close()


if __name__ == '__main__':
    while True:
        check_and_reset_vehicles()
        time.sleep(60)