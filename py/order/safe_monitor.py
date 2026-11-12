import time
import logging
from database import Database
import threading
import urllib.parse
import urllib.request
import ssl

# 日志配置
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("illegal_usage.log"),
        logging.StreamHandler()
    ]
)

# 记录每个设备连续 battery_level == - 的次数
battery_check_counts = {}


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
        logging.warning(f"请求 RC 状态同步接口失败，serial_number={serial_number}，错误：{e}")


def notify_rc_release_success_async(serial_number):
    """
    异步通知 RC，避免请求接口卡住主检测逻辑
    """
    threading.Thread(
        target=notify_rc_release_success,
        args=(serial_number,),
        daemon=True
    ).start()


def send_room_ban_message(room_id):
    """
    图传房间封禁通知
    POST:
        room_id = xxx
    不关心返回结果
    """
    if not room_id:
        logging.warning("send_room_ban_message 未执行：room_id为空")
        return

    url = "https://rcwulian.cn/app/ban/send_room_ban_message.php"

    post_data = urllib.parse.urlencode({
        "room_id": room_id
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
        # 跳过 SSL 证书校验，保持和 RC 状态同步接口一致
        context = ssl._create_unverified_context()

        with urllib.request.urlopen(req, timeout=5, context=context) as response:
            # 不关心返回结果，只读取少量内容避免连接未正常释放
            response.read(512)

        logging.info(f"已请求房间封禁通知接口，room_id={room_id}")

    except Exception as e:
        logging.warning(f"请求房间封禁通知接口失败，room_id={room_id}，错误：{e}")


def send_room_ban_message_async(room_id):
    """
    异步通知房间封禁，避免影响主违规处理逻辑
    """
    threading.Thread(
        target=send_room_ban_message,
        args=(room_id,),
        daemon=True
    ).start()


def try_send_room_ban_by_image_device(db, serial_number, image_device_serial, image_transmission_type):
    """
    非法驾驶远程停车后调用。

    如果 image_transmission_type == 1：
        通过 vehicles.image_device_serial 查询 device_information.id
        拿到 room_id
        请求 send_room_ban_message.php

    该逻辑异常不影响原来的非法驾驶处理流程。
    """
    try:
        if str(image_transmission_type) != '1':
            return

        if not image_device_serial:
            logging.warning(
                f"设备 {serial_number} image_transmission_type=1，"
                f"但 image_device_serial 为空，跳过房间封禁通知"
            )
            return

        result = db.query(
            """
            SELECT room_id 
            FROM device_information 
            WHERE id = %s 
            LIMIT 1
            """,
            (image_device_serial,)
        )

        if not result:
            logging.warning(
                f"设备 {serial_number} 未找到 device_information 记录，"
                f"image_device_serial={image_device_serial}"
            )
            return

        room_id = result[0].get('room_id')

        if not room_id:
            logging.warning(
                f"设备 {serial_number} 查询到 device_information，但 room_id 为空，"
                f"image_device_serial={image_device_serial}"
            )
            return

        logging.info(
            f"设备 {serial_number} image_transmission_type=1，准备通知房间封禁，"
            f"image_device_serial={image_device_serial}, room_id={room_id}"
        )

        send_room_ban_message_async(room_id)

    except Exception as e:
        logging.warning(
            f"设备 {serial_number} 处理图传房间封禁逻辑失败，"
            f"image_device_serial={image_device_serial}, "
            f"image_transmission_type={image_transmission_type}，错误：{e}"
        )


def handle_illegal_device(serial_number, illegal_uid, image_device_serial=None, image_transmission_type=None):
    db = Database()
    try:
        logging.warning(f"正在处理设备 {serial_number} 的非法驾驶，执行远程停车")

        db.query(
            "UPDATE vehicles SET start_status = 'false' WHERE serial_number = %s",
            (serial_number,),
            commit=True
        )

        # 新增逻辑：
        # 远程停车后，如果 image_transmission_type == 1，
        # 通过 image_device_serial 查询 device_information 表拿 room_id，
        # 然后通知房间封禁接口。
        try_send_room_ban_by_image_device(
            db=db,
            serial_number=serial_number,
            image_device_serial=image_device_serial,
            image_transmission_type=image_transmission_type
        )

        time.sleep(10)

        result = db.query(
            "SELECT driver_id FROM vehicles WHERE serial_number = %s",
            (serial_number,)
        )

        if result:
            current_driver_id = result[0]['driver_id']

            if str(current_driver_id) == str(illegal_uid):
                db.query("""
                    UPDATE vehicles 
                    SET status = '在线',
                        driver_id = NULL,
                        driver = NULL,
                        vehicle_status = '驾驶',
                        start_status = 'true'
                    WHERE serial_number = %s
                """, (serial_number,), commit=True)

                logging.warning(f"设备 {serial_number} 的非法用户 {illegal_uid} 已强制清除，状态重置为在线")

                # 释放成功后通知 RC 同步状态
                notify_rc_release_success_async(serial_number)

            else:
                logging.info(f"设备 {serial_number} 当前驾驶者已变更，跳过清除处理")
        else:
            logging.warning(f"设备 {serial_number} 查询不到车辆记录，跳过处理")

    except Exception as e:
        logging.error(f"处理非法设备 {serial_number} 失败：{e}")
    finally:
        db.close()


def check_illegal_usage():
    db = Database()
    try:
        devices = db.query("""
            SELECT 
                serial_number, 
                battery_level, 
                driver_id, 
                uid, 
                bind_site,
                image_device_serial,
                image_transmission_type
            FROM vehicles 
            WHERE status = '占有'
        """)

        logging.info(f"检测到 {len(devices)} 台占用设备")

        for device in devices:
            serial_number = device['serial_number']
            driver_id = device['driver_id']
            owner_uid = device['uid']
            bind_site = device['bind_site']
            battery_level = device['battery_level']
            image_device_serial = device.get('image_device_serial')
            image_transmission_type = device.get('image_transmission_type')

            if not driver_id:
                continue

            if str(driver_id) == str(owner_uid):
                # 合法车主使用，增加电量判断逻辑
                if battery_level == -1 or battery_level == '-':
                    battery_check_counts[serial_number] = battery_check_counts.get(serial_number, 0) + 1

                    if battery_check_counts[serial_number] >= 2:
                        logging.warning(f"🚨 设备 {serial_number} 已由车主驾驶，但连续 2 次检测电量为 -，执行状态重置")

                        try:
                            db.query("""
                                UPDATE vehicles 
                                SET status = '离线',
                                    vehicle_status = '驾驶',
                                    driver_id = NULL,
                                    driver = NULL 
                                WHERE serial_number = %s
                            """, (serial_number,), commit=True)

                            logging.info(f"设备 {serial_number} 状态已重置为 离线/驾驶")

                            # 释放成功后通知 RC 同步状态
                            notify_rc_release_success_async(serial_number)

                        except Exception as ex:
                            logging.error(f"更新设备状态失败 {serial_number}：{ex}")

                        # 清空累计次数，避免重复操作
                        battery_check_counts.pop(serial_number, None)

                else:
                    battery_check_counts.pop(serial_number, None)
                    logging.info(f"设备 {serial_number} 正由车主驾驶，电量为 {battery_level}，跳过违规检查")

                continue

            # 查询用户是否有合法订单
            reservation = db.query(
                """
                SELECT reservation_id 
                FROM Reservations 
                WHERE user_id = %s 
                  AND reservation_id = %s 
                  AND order_status IN ('等待驾驶', '正在驾驶')
                """,
                (driver_id, bind_site)
            )

            if reservation:
                logging.info(f"用户 {driver_id} 合法使用设备 {serial_number}")
            else:
                logging.warning(f"⚠️ 警告：设备 {serial_number} 当前由用户 {driver_id} 驾驶，无合法订单，触发违规处理")

                threading.Thread(
                    target=handle_illegal_device,
                    args=(
                        serial_number,
                        driver_id,
                        image_device_serial,
                        image_transmission_type
                    ),
                    daemon=True
                ).start()

    except Exception as e:
        logging.error(f"检测异常失败：{e}")
    finally:
        db.close()


if __name__ == "__main__":
    while True:
        check_illegal_usage()
        time.sleep(60)