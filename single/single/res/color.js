document.addEventListener('click',  function(e) {
    // 完整社会主义核心价值观词库 
    const messages = ['富强', '民主', '文明', '和谐', 
                     '自由', '平等', '公正', '法治',
                     '爱国', '敬业', '诚信', '友善'];
    
    // 随机颜色生成（参考搜索结果[10][12]的两种实现方式）
    const randomColor = () => {
        // 方式1：HSL模式生成鲜艳颜色 
        const hue = Math.floor(Math.random()  * 360);
        return `hsl(${hue}, 100%, 50%)`;
        
        // 方式2：预定义颜色数组（可选）
        // const colors = ["#FF6B6B","#4ECDC4","#45B7D1","#96CEB4","#FFEEAD","#D4A5A5"];
        // return colors[Math.floor(Math.random() * colors.length)]; 
    };
 
    // 创建提示元素 
    const tip = document.createElement('div'); 
    tip.textContent  = messages[Math.floor(Math.random() * messages.length)]; 
    tip.style.position  = 'absolute';
    tip.style.left  = `${e.clientX  + 15}px`;  // 基于点击坐标定位 
    tip.style.top  = `${e.clientY  - 15}px`;
    tip.style.color  = randomColor();
    tip.style.fontSize  = '12px';
    tip.style.transition  = 'opacity 1s';
    
    document.body.appendChild(tip); 
    
    // 自动淡出移除 
    setTimeout(() => {
        tip.style.opacity  = '0';
        setTimeout(() => tip.remove(),  1000);
    }, 500);
});

setInterval(function () {
            var rain = document.createElement("div");
            rain.style.position = "fixed";
            rain.style.height = 20+"px";
            rain.style.width = "1px";
            rain.style.background = "blue"; //可以使用雨滴图片代替
            rain.style.filter = "blur(1px)"
            rain.style.top = "0px";
            rain.style.opacity = parseInt(Math.random()*10)/10;
            rain.style.left = Math.random() * 1920 + "px";
            document.body.appendChild(rain);
            var t = 1;
            var timer = setInterval(function () {
                var height = parseInt(rain.style.top);
                t++;
                rain.style.top = height + 2 * (Math.pow(t, 2)) + "px";      // 模拟物体下落的公式
                if (parseInt(rain.style.top) >= 500) {
                    clearInterval(timer);                            //删掉也可以，直接移除元素就不用停止循环调用
                    rain.remove();
                }
            },20)
        },10)
 