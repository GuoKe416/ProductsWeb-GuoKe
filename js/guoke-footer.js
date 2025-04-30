// GuoKe商品库版权信息
(function() {
    
    // 干啥，怎么还偷看我代码？
    // 干啥，怎么还偷看我代码？
    // 干啥，怎么还偷看我代码？
    // 防止调试
    function preventDebug() {
        // 检测控制台是否打开的函数
        const detectDevTools = function() {
            const widthThreshold = window.outerWidth - window.innerWidth > 160;
            const heightThreshold = window.outerHeight - window.innerHeight > 160;
            
            // 检测窗口大小变化，判断是否打开了控制台
            if (widthThreshold || heightThreshold) {
                document.body.innerHTML = '<div style="text-align:center;padding:50px;"><h1 style="color:red;">检测到已启用开发者工具，页面已被锁定</h1><p>请关闭开发者工具后刷新页面继续使用</p><button class="layui-btn layui-btn-primary" onclick="window.location.reload()">刷新页面</button></div>';
                return true;
            }
            
            return false;
        };
        
        // 使用debugger陷阱
        function debuggerTrap() {
            let start = new Date().getTime();
            debugger;
            let end = new Date().getTime();
            
            // 如果debugger被阻止，时间差会很小
            // 如果在调试模式下，时间差会很大，因为执行会暂停
            if (end - start > 100) {
                document.body.innerHTML = '<div style="text-align:center;padding:50px;"><h1 style="color:red;">检测到已启用开发者工具，页面已被锁定</h1><p>请关闭开发者工具后刷新页面继续使用</p><button class="layui-btn layui-btn-primary" onclick="window.location.reload()">刷新页面</button></div>';
                return true;
            }
            
            return false;
        }
        
        // 检测控制台对象
        function detectConsoleOpen() {
            const div = document.createElement('div');
            Object.defineProperty(div, 'id', {
                get: function() {
                    document.body.innerHTML = '<div style="text-align:center;padding:50px;"><h1 style="color:red;">检测到已启用开发者工具，页面已被锁定</h1><p>请关闭开发者工具后刷新页面继续使用</p><button class="layui-btn layui-btn-primary" onclick="window.location.reload()">刷新页面</button></div>';
                    return 'preventDevTools';
                }
            });
            
            // 尝试将对象打印到控制台
            console.log(div);
            console.clear();
            // 在控制台输出版权信息
            console.log("%c商品库安装程序 Powered By GuoKe ", "background: linear-gradient(to right, #ff7e5f, #feb47b); color: white; padding: 5px;");
            console.log("%chttps://hyk416.cn", "background: linear-gradient(to right, #ff7e5f, #feb47b); color: white; padding: 5px;");
        }
        
        // 禁止右键菜单
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });
        
        // 禁止常见键盘快捷键
        document.addEventListener('keydown', function(e) {
            // Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+Shift+C, F12
            if (
                (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) ||
                (e.keyCode === 123)
            ) {
                e.preventDefault();
                return false;
            }
        });
        
        // 每秒检测一次
        setInterval(function() {
            detectDevTools();
            debuggerTrap();
        }, 1000);
        
        // 初始检测
        detectConsoleOpen();
    }
    
    // 激活防调试功能
    preventDebug();
})(); 