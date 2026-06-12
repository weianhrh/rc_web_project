/**
 * useradmin demo
 */


layui.define(['table', 'form'], function(exports){
  var $ = layui.$
  ,admin = layui.admin
  ,view = layui.view
  ,table = layui.table
  ,form = layui.form;



  // 场地管理
  table.render({
    elem: '#LAY-venue-manage',
    url: '/api/venue/getVenueList.php', // 替换为实际场地列表接口路径
    cols: [[
      {type: 'checkbox', fixed: 'left'},
      {field: 'id', width: 100, title: 'ID', sort: true},
      {field: 'venue_name', title: '场地名称', minWidth: 100},
      {field: 'image_url', title: '场地图片', width: 100, templet: function(d) {
        return '<img src="' + d.image_url + '" alt="' + d.venue_name + '" />';
      }},
      {field: 'venue_description', title: '描述', minWidth: 200},
      {field: 'venue_tags', title: '标签', width: 90},
      {field: 'venue_type', title: '类型', width: 100, sort: true},
      {field: 'event_id', title: '活动ID', width: 100},
      {field: 'start_time', title: '开放时间', width: 110},
      {field: 'queue_length', title: '排队人数', width: 90},
      {field: 'venue_status', title: '状态', width: 90},
      {title: '操作', width: 150, align: 'center', fixed: 'right', toolbar: '#table-venue-list'}
    ]],
    page: true,
    limit: 10,
    height: 'full-220',
    text: '对不起，加载出现异常！'
  });

  
  exports('useradmin', {})
});