{
  "code": 0
  ,"msg": ""
  ,"data": [{
    "title": "主页"
    ,"icon": "layui-icon-home"
    ,"jump": "/"
   
  },{
    "title": "新版本"
    ,"icon": "layui-icon-home"
    ,"jump": "/index"
   
  }, {
    "name": "component"
    ,"title": "设备"
    ,"icon": "layui-icon-component"
    ,"list": [{
      "name": "vehicleslite"
      ,"title": "设备列表"
      ,"jump": "vehicles/vehicleslite"
      
    }]
  }, {
    "name": "template"
    ,"title": "场地"
    ,"icon": "layui-icon-template"
    ,"list": [{
      "name": "venues"
      ,"title": "场地列表"
      ,"jump": "venues/venues"
    },{
      "name": "ReservationsList"
      ,"title": "预约订单"
      ,"jump": "venues/ReservationsList"
    
    },{
      "name": "DrivingOrders"
      ,"title": "驾驶订单"
      ,"jump": "venues/DrivingOrders"
    
    }
    
    ]
  }, {
    "name": "app"
    ,"title": "财务"
    ,"icon": "layui-icon-app"
    ,"list": [{
      "name": "content"
      ,"title": "支付记录"
      ,"list": [{
        "name": "list"
        ,"title": "今日记录"
      }]
    },{
      "name": "workorder"
      ,"title": "工单系统"
      ,"jump": "app/workorder/list"
    }]
  }, {
    "name": "user"
    ,"title": "用户"
    ,"icon": "layui-icon-user"
    ,"list": [{
      "name": "user"
      ,"title": "网站用户"
      ,"jump": "user/user/list"
    }, {
      "name": "administrators-list"
      ,"title": "后台管理员"
      ,"jump": "user/administrators/list"
    }, {
      "name": "administrators-rule"
      ,"title": "角色管理"
      ,"jump": "user/administrators/role"
    }]
  }, {
    "name": "set"
    ,"title": "设置"
    ,"icon": "layui-icon-set"
    ,"list": [{
      "name": "system"
      ,"title": "系统设置"
      ,"spread": true
      ,"list": [{
        "name": "website"
        ,"title": "网站设置"
      },{
        "name": "email"
        ,"title": "邮件服务"
      }]
    },{
      "name": "user"
      ,"title": "我的设置"
      ,"spread": true
      ,"list": [{
        "name": "info"
        ,"title": "基本资料"
      },{
        "name": "password"
        ,"title": "修改密码"
      }]
    }]
  }, {
    "name": "get"
    ,"title": "关于"
    ,"icon": "layui-icon-auz"
    ,"jump": "system/about"
  }]
}