/**
 * 今日充值记录
 */

layui.define(['table', 'form'], function(exports){
  var $ = layui.$
  ,admin = layui.admin
  ,view = layui.view
  ,table = layui.table
  ,form = layui.form;

  // 今日充值记录管理
  table.render({
    elem: '#LAY-app-content-list'
    ,url: '/api/pay/get_today_recharge_records.php' // 实际接口地址
    ,cols: [[
      {type: 'checkbox', fixed: 'left'}
      ,{field: 'id', width: 50, title: '订单ID', sort: true}
      ,{field: 'uid', width: 100, title: '用户ID'}
      ,{field: 'nickname', width: 100, title: '用户昵称'}
      ,{field: 'order_number', title: '订单编号', minWidth: 150}
      ,{field: 'product_name', title: '商品名称'}
      ,{field: 'shop_id', title: '商品ID'}
      ,{field: 'shop_type', title: '订单类型'}
      ,{field: 'value', title: '订单价值'}
      ,{field: 'extra_value', title: '附送价值'}
      ,{field: 'payer_total', title: '实际支付'}
      ,{field: 'status', title: '订单状态'}
      ,{field: 'created_at', title: '创建时间', sort: true}
      ,{field: 'paid_at', title: '付款时间'}
      ,{field: 'reservation_id', title: '预约ID'}
  
    ]]
    ,page: true
    ,limit: 10
    ,limits: [10, 15, 20, 25, 30]
    ,text: '对不起，加载出现异常！'
  });
  
  // 工具条事件
  table.on('tool(LAY-app-content-list)', function(obj){
    var data = obj.data;
    if(obj.event === 'del'){
      layer.confirm('确定删除此记录？', function(index){
        obj.del();
        layer.close(index);
      });
    } else if(obj.event === 'edit'){
      admin.popup({
        title: '编辑订单记录'
        ,area: ['550px', '550px']
        ,id: 'LAY-popup-content-edit'
        ,success: function(layero, index){
          view(this.id).render('app/content/listform', data).done(function(){
            form.render(null, 'layuiadmin-app-form-list');
            
            // 提交
            form.on('submit(layuiadmin-app-form-submit)', function(data){
              var field = data.field; // 获取提交的字段

              // 提交 Ajax 成功后，关闭当前弹层并重载表格
              //$.ajax({}); // 根据需要实现 AJAX 请求
              layui.table.reload('LAY-app-content-list'); // 重载表格
              layer.close(index); // 执行关闭 
            });
          });
        }
      });
    }
  });


  //分类管理
  table.render({
    elem: '#LAY-app-content-tags'
    ,url: './res/json/content/tags.js' //模拟接口
    ,cols: [[
      {type: 'numbers', fixed: 'left'}
      ,{field: 'id', width: 100, title: 'ID', sort: true}
      ,{field: 'tags', title: '分类名', minWidth: 100}
      ,{title: '操作', width: 150, align: 'center', fixed: 'right', toolbar: '#layuiadmin-app-cont-tagsbar'}
    ]]
    ,text: '对不起，加载出现异常！'
  });
  
  //工具条
  table.on('tool(LAY-app-content-tags)', function(obj){
    var data = obj.data;
    if(obj.event === 'del'){
      layer.confirm('确定删除此分类？', function(index){
        obj.del();
        layer.close(index);
      });
    } else if(obj.event === 'edit'){
      admin.popup({
        title: '编辑分类'
        ,area: ['450px', '200px']
        ,id: 'LAY-popup-content-tags'
        ,success: function(layero, index){
          view(this.id).render('app/content/tagsform', data).done(function(){
            form.render(null, 'layuiadmin-form-tags');
            
            //提交
            form.on('submit(layuiadmin-app-tags-submit)', function(data){
              var field = data.field; //获取提交的字段

              //提交 Ajax 成功后，关闭当前弹层并重载表格
              //$.ajax({});
              layui.table.reload('LAY-app-content-tags'); //重载表格
              layer.close(index); //执行关闭 
            });
          });
        }
      });
    }
  });

  //评论管理
  table.render({
    elem: '#LAY-app-content-comm'
    ,url: './res/json/content/comment.js' //模拟接口
    ,cols: [[
      {type: 'checkbox', fixed: 'left'}
      ,{field: 'id', width: 100, title: 'ID', sort: true}
      ,{field: 'reviewers', title: '评论者', minWidth: 100}
      ,{field: 'content', title: '评论内容', minWidth: 100}
      ,{field: 'commtime', title: '评论时间', minWidth: 100, sort: true}
      ,{title: '操作', width: 150, align: 'center', fixed: 'right', toolbar: '#table-content-com'}
    ]]
    ,page: true
    ,limit: 10
    ,limits: [10, 15, 20, 25, 30]
    ,text: '对不起，加载出现异常！'
  });
  
  //工具条
  table.on('tool(LAY-app-content-comm)', function(obj){
    var data = obj.data;
    if(obj.event === 'del'){
      layer.confirm('确定删除此条评论？', function(index){
        obj.del();
        layer.close(index);
      });
    } else if(obj.event === 'edit'){
      admin.popup({
        title: '编辑评论'
        ,area: ['450px', '300px']
        ,id: 'LAY-popup-content-comm'
        ,success: function(layero, index){
          view(this.id).render('app/content/contform', data).done(function(){
            form.render(null, 'layuiadmin-form-comment');
            
            //提交
            form.on('submit(layuiadmin-app-com-submit)', function(data){
              var field = data.field; //获取提交的字段

              //提交 Ajax 成功后，关闭当前弹层并重载表格
              //$.ajax({});
              layui.table.reload('LAY-app-content-comm'); //重载表格
              layer.close(index); //执行关闭 
            });
          });
        }
      });
    }
  });

  exports('contlist', {})
});