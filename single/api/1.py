# -*- coding: utf-8 -*-
import pymysql
import csv

DB_CFG = dict(
    host="localhost",
    user="5grc",
    password="f6eca7806e73cd25",
    database="5grc",
    port=3306,
    charset="utf8mb4",
    cursorclass=pymysql.cursors.DictCursor,
)

SQL = "SELECT * FROM balance_changes WHERE user_id=%s"

OUT_CSV = "113656_users消费异常.csv"  # Excel 友好：utf-8-sig

def main():
    conn = pymysql.connect(**DB_CFG)
    try:
        with conn.cursor() as cursor:
            cursor.execute(SQL, (113656,))
            rows = cursor.fetchall()

        if not rows:
            print("查询结果为空：没有符合条件的数据。")
            return

        fieldnames = list(rows[0].keys())
        with open(OUT_CSV, "w", newline="", encoding="utf-8-sig") as f:
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(rows)

        print(f"✅ 已导出 {len(rows)} 行到：{OUT_CSV}")
    finally:
        conn.close()

if __name__ == "__main__":
    main()
