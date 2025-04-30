// 检查认证状态
function checkAuth() {
    fetch('check_session.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON:', text);
                    throw new Error('服务器返回无效的JSON格式');
                }
            });
        })
        .then(data => {
            if (!data.authenticated || data.session_expired) {
                if (data.message) {
                    // 如果已经存在弹窗，则不再创建新的
                    if (document.querySelector('.session-expired-dialog')) {
                        return;
                    }

                    // 创建遮罩层
                    const overlay = document.createElement('div');
                    overlay.className = 'session-expired-overlay';
                    overlay.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0, 0, 0, 0.7);
                        z-index: 999999;
                    `;
                    document.body.appendChild(overlay);

                    // 创建弹窗
                    const dialog = document.createElement('div');
                    dialog.className = 'session-expired-dialog';
                    dialog.style.cssText = `
                        position: fixed;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        background: white;
                        padding: 20px;
                        border-radius: 8px;
                        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                        z-index: 1000000;
                        text-align: center;
                        min-width: 300px;
                    `;

                    // 根据不同的退出原因设置不同的标题和样式
                    let title = '会话已过期';
                    let titleColor = '#718096';
                    let icon = '⚠️';

                    if (data.reason === 'forced_logout') {
                        title = '账号已被强制下线';
                        titleColor = '#e53e3e';
                        icon = '🚫';
                    } else if (data.reason === 'other_device') {
                        title = '账号在其他设备登录';
                        titleColor = '#dd6b20';
                        icon = '📱';
                    }

                    dialog.innerHTML = `
                        <div style="font-size: 40px; margin-bottom: 10px;">${icon}</div>
                        <h2 style="margin: 0 0 15px 0; color: ${titleColor}; font-size: 18px;">${title}</h2>
                        <p style="margin: 0 0 20px 0; color: #4a5568;">${data.message}</p>
                        <button id="reloginBtn" style="
                            background: #4299e1;
                            color: white;
                            border: none;
                            padding: 8px 20px;
                            border-radius: 4px;
                            cursor: pointer;
                            font-size: 14px;
                            transition: background 0.3s;
                        ">重新登录</button>
                    `;

                    document.body.appendChild(dialog);

                    // 添加按钮点击事件
                    const reloginBtn = document.getElementById('reloginBtn');
                    if (reloginBtn) {
                        reloginBtn.addEventListener('click', function() {
                            window.location.href = 'login.php';
                        });
                        
                        // 确保按钮永远可以点击
                        reloginBtn.style.cursor = 'pointer';
                        reloginBtn.style.pointerEvents = 'auto';
                    }

                    // 阻止右键菜单，但允许弹窗内部正常操作
                    document.addEventListener('contextmenu', function(e) {
                        if (!e.target.closest('.session-expired-dialog')) {
                            e.preventDefault();
                        }
                    }, true);

                    // 阻止键盘事件，但允许Tab键浏览弹窗
                    document.addEventListener('keydown', function(e) {
                        // 允许Tab键在弹窗内导航
                        if (e.key === 'Tab' && dialog.contains(document.activeElement)) {
                            return;
                        }
                        e.preventDefault();
                    }, true);

                    // 定期检查弹窗和遮罩是否存在，如果被删除则重新创建
                    const checkInterval = setInterval(() => {
                        if (!document.querySelector('.session-expired-dialog') || !document.querySelector('.session-expired-overlay')) {
                            document.body.appendChild(overlay.cloneNode(true));
                            document.body.appendChild(dialog.cloneNode(true));
                            
                            // 重新绑定按钮事件
                            const reloginBtn = document.getElementById('reloginBtn');
                            if (reloginBtn) {
                                reloginBtn.addEventListener('click', function() {
                                    window.location.href = 'login.php';
                                });
                                
                                // 确保按钮永远可以点击
                                reloginBtn.style.cursor = 'pointer';
                                reloginBtn.style.pointerEvents = 'auto';
                            }
                        }
                    }, 100);

                    // 防止开发者工具删除元素后继续操作
                    const observer = new MutationObserver((mutations) => {
                        mutations.forEach((mutation) => {
                            if (mutation.type === 'childList') {
                                if (!document.querySelector('.session-expired-dialog') || !document.querySelector('.session-expired-overlay')) {
                                    document.body.appendChild(overlay.cloneNode(true));
                                    document.body.appendChild(dialog.cloneNode(true));
                                    
                                    // 重新绑定按钮事件
                                    const reloginBtn = document.getElementById('reloginBtn');
                                    if (reloginBtn) {
                                        reloginBtn.addEventListener('click', function() {
                                            window.location.href = 'login.php';
                                        });
                                        
                                        // 确保按钮永远可以点击
                                        reloginBtn.style.cursor = 'pointer';
                                        reloginBtn.style.pointerEvents = 'auto';
                                    }
                                }
                            }
                        });
                    });

                    observer.observe(document.body, {
                        childList: true,
                        subtree: true
                    });

                    // 禁用页面交互
                    document.body.style.overflow = 'hidden';
                }
            }
        })
        .catch(error => {
            console.error('Check auth error:', error);
            
            // 获取错误通知消息并显示
            fetch('api/site_config.php?key=error_notice')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const errorMsg = data.value || '发生错误，请联系管理员';
                        // 创建错误提示
                        const errorDiv = document.createElement('div');
                        errorDiv.style.position = 'fixed';
                        errorDiv.style.bottom = '20px';
                        errorDiv.style.right = '20px';
                        errorDiv.style.backgroundColor = '#fff1f0';
                        errorDiv.style.color = '#a8071a';
                        errorDiv.style.padding = '10px';
                        errorDiv.style.borderRadius = '4px';
                        errorDiv.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
                        errorDiv.style.zIndex = '9999';
                        errorDiv.textContent = `${error.message} - ${errorMsg}`;
                        document.body.appendChild(errorDiv);
                        
                        // 5秒后移除错误提示
                        setTimeout(() => errorDiv.remove(), 5000);
                    }
                })
                .catch(() => {
                    // 如果配置获取失败，使用默认错误消息
                    const errorDiv = document.createElement('div');
                    errorDiv.style.position = 'fixed';
                    errorDiv.style.bottom = '20px';
                    errorDiv.style.right = '20px';
                    errorDiv.style.backgroundColor = '#fff1f0';
                    errorDiv.style.color = '#a8071a';
                    errorDiv.style.padding = '10px';
                    errorDiv.style.borderRadius = '4px';
                    errorDiv.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
                    errorDiv.style.zIndex = '9999';
                    errorDiv.textContent = `${error.message} - 如有问题请联系管理员`;
                    document.body.appendChild(errorDiv);
                    
                    // 5秒后移除错误提示
                    setTimeout(() => errorDiv.remove(), 5000);
                });
        });
}

// 每10秒检查一次会话状态（原来是30秒，改为更频繁的检查）
setInterval(checkAuth, 10000);

// 页面加载时立即检查一次
document.addEventListener('DOMContentLoaded', checkAuth);

// 验证密码
function verifyPassword() {
    const password = document.getElementById('password-input').value;
    if (!password) return;

    layui.use(['layer'], function(){
        var layer = layui.layer;
        layer.msg('正在验证密码...', {icon: 16, time: 0, shade: 0.3});
    });
    const formData = new FormData();
    formData.append('password', password);

    fetch('auth/verify_password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            layer.msg('密码正确，正在加载页面...', {icon: 1, time: 1000});
            setTimeout(() => {
                location.reload(); // 强制刷新页面
            }, 1000);
            document.querySelector('.content').style.display = 'none';
            checkAuth(); // 重新触发认证检查
        } else {
            layer.msg(data.message || '密码错误', {icon: 2});
        }
    })
    .catch(error => {
        console.error('Error:', error);
        layer.msg('发生错误，请稍后再试', {icon: 2});
    });
}

// 显示加载中的Toast通知
function showLoadingToast(message) {
    layui.use(['layer'], function(){
        var layer = layui.layer;
        return layer.msg(message || '加载中...', {
            icon: 16,
            shade: 0.01,
            time: 0
        });
    });
}

// 加载页面内容
function loadPageContent() {
    fetch('api/products.php')
        .then(response => response.json())
        .then(data => {
            products = data.products; // 从data对象中获取products数组
            filteredProducts = [...products];
            document.getElementById('total-products').textContent = data.total || 0;
            renderProducts();
            layui.use(['layer'], function(){
                var layer = layui.layer;
                layer.closeAll();
            });
            // 确保内容容器存在
            const contentElement = document.querySelector('.content') || document.createElement('div');
            if (!document.querySelector('.content')) {
                contentElement.className = 'content';
                document.body.appendChild(contentElement);
            }
            if (contentElement) {
                contentElement.style.display = 'block';
                document.getElementById('auth-modal').style.removeProperty('display');
                document.body.style.overflow = 'auto';
            } else {
                console.error('找不到内容容器');
                layer.msg('页面结构异常', {icon: 2});
            }
        })
        .catch(error => {
                layui.use(['layer'], function(){
    layer.msg('数据加载失败，请稍后重试', {icon: 2});
});
            });
        };


// 页面加载时检查认证状态
document.addEventListener('DOMContentLoaded', function() {
    layui.use(['layer'], function(){});
    const authModal = document.querySelector('.auth-modal');
    const loadingIndicator = document.querySelector('.loading-indicator');

    async function checkAuth() {
        try {
            if (loadingIndicator) loadingIndicator.style.display = 'block';
            const response = await fetch('auth/check_auth.php');
            
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            
            const text = await response.text();
            let data;
            
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON:', text);
                throw new Error('服务器返回无效的JSON格式');
            }

            if (!data.authenticated && authModal) {
                authModal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        } catch (error) {
            console.error('获取用户名失败:', error);
            // 在页面上显示一个提示
            const errorDiv = document.createElement('div');
            errorDiv.style.position = 'fixed';
            errorDiv.style.bottom = '20px';
            errorDiv.style.right = '20px';
            errorDiv.style.backgroundColor = '#fff1f0';
            errorDiv.style.color = '#a8071a';
            errorDiv.style.padding = '10px';
            errorDiv.style.borderRadius = '4px';
            errorDiv.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
            errorDiv.style.zIndex = '9999';
            errorDiv.textContent = '获取用户信息失败: ' + error.message;
            document.body.appendChild(errorDiv);
            
            // 5秒后移除错误提示
            setTimeout(() => errorDiv.remove(), 5000);
        } finally {
            if (loadingIndicator) loadingIndicator.style.display = 'none';
        }
    }
    addWatermark();
    initContextMenu();
});

// 初始化右键菜单
function initContextMenu() {
    // 添加样式
    const style = document.createElement('style');
    style.innerHTML = `
    #custom-context-menu {
        position: absolute;
        z-index: 9999;
        background: #fff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 6px 0;
        min-width: 160px;
        display: none;
    }
    .menu-item {
        padding: 8px 16px;
        font-size: 14px;
        color: #333;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .menu-item:hover {
        background: #f5f5f5;
        transform: translateX(2px);
    }
    .menu-item::before {
        font-size: 16px;
    }`;
    document.head.appendChild(style);
    // 创建菜单容器
    const menu = document.createElement('div');
    menu.id = 'custom-context-menu';
    menu.style.display = 'none';
    document.body.appendChild(menu);

    // 全局右键事件监听
    document.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const target = e.target;
        const productItem = target.closest('.product-item');
    
        // 清空旧菜单
        menu.innerHTML = '';
        menu.style.display = 'block';
        menu.style.left = `${e.pageX}px`;
        menu.style.top = `${e.pageY}px`;
    
        if (productItem) {
            const productCode = productItem.querySelector('.code').innerText;
            addMenuItem('📋 复制编码', () => copyText(productCode));
            
            const img = productItem.querySelector('img');
            if (img) {
                addMenuItem('📋 复制图片', () => copyImage(img));
                addMenuItem('📋 复制图片链接', () => copyText(img.src));
                addMenuItem('🌐 打开原图', () => window.open(img.src));
            }
            addMenuItem('🔄 刷新页面', () => location.reload());
        } else {
            // 添加通用菜单项
            addMenuItem('🔄 刷新页面', () => location.reload());
    
            // 根据目标类型添加功能
            if (target.tagName === 'IMG') {
                addMenuItem('📋 复制图片', () => copyImage(target));
                addMenuItem('📋 复制图片链接', () => copyText(target.src));
                addMenuItem('🌐 打开原图', () => window.open(target.src));
            }
            if (target.classList.contains('code')) {
                addMenuItem('📋 复制文本', () => copyText(target.innerText));
            }
        }
        e.stopPropagation();
    });

    // 点击其他地方隐藏菜单
    document.addEventListener('click', () => {
        menu.style.display = 'none';
    });

    function addMenuItem(text, handler) {
        const item = document.createElement('div');
        item.className = 'menu-item';
        item.textContent = text;
        item.addEventListener('click', handler);
        menu.appendChild(item);
    }

    function copyText(text) {
        navigator.clipboard.writeText(text).then(() => {
            layui.use(['layer'], function(){
                var layer = layui.layer;
                layer.msg('复制成功', {icon: 1});
            });
        });
    }

    async function copyImage(img) {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        ctx.drawImage(img, 0, 0);
        
        canvas.toBlob(async (blob) => {
            await navigator.clipboard.write([
                new ClipboardItem({ 'image/png': blob })
            ]);
            layui.use(['layer'], function(){
                var layer = layui.layer;
                layer.msg('图片已复制', {icon: 1});
            });
        });
    }
    
    // 在DOMContentLoaded事件开头添加
    layui.use(['layer'], function(){
        window.layer = layui.layer;
    });
}

let products = [];
let currentPage = 1;
let pageSize = 30;
let filteredProducts = [];

   // 添加水印功能
 async function addWatermark() {
    try {
        // 先检查是否启用水印
        const response = await fetch('api/manage.php?action=get_site_config');
        const result = await response.json();
        
        if (!result.success || result.data.enable_watermark !== '1') {
            // 如果水印未启用或获取配置失败，移除已存在的水印
            const existingWatermark = document.querySelector('.watermark');
            if (existingWatermark) {
                existingWatermark.remove();
            }
            return; // 如果未启用水印或获取配置失败，直接返回
        }

        // IP API 接口列表
        const ipApis = [
            'https://api.ip.sb/geoip',
            'https://ipapi.co/json/',
            'https://ip-api.com/json',
            'https://ip.useragentinfo.com/json',
            'https://api.ipify.org?format=json'
        ];
        
        let ipData = null;
        
        // 依次尝试不同的 IP API
        for (const api of ipApis) {
            try {
                const response = await fetch(api);
                const data = await response.json();
                
                // 根据不同 API 返回格式处理数据
                if (api.includes('useragentinfo.com')) {
                    ipData = {
                        ip: data.ip,
                        location: `${data.province}${data.city}`
                    };
                } else if (api.includes('ipify.org')) {
                    // ipify 只返回 IP，需要额外调用 ipapi.co 获取位置信息
                    const locationResponse = await fetch(`https://ipapi.co/${data.ip}/json/`);
                    const locationData = await locationResponse.json();
                    ipData = {
                        ip: data.ip,
                        location: `${locationData.region}${locationData.city}`
                    };
                } else if (api.includes('ip.sb')) {
                    ipData = {
                        ip: data.ip,
                        location: `${data.region}${data.city}`
                    };
                } else if (api.includes('ipapi.co')) {
                    ipData = {
                        ip: data.ip,
                        location: `${data.region}${data.city}`
                    };
                } else if (api.includes('ip-api.com')) {
                    ipData = {
                        ip: data.query,
                        location: `${data.regionName}${data.city}`
                    };
                }
                
                if (ipData) break; // 如果成功获取数据就跳出循环
            } catch (error) {
                console.log(`API ${api} 调用失败:`, error);
                continue; // 继续尝试下一个 API
            }
        }
        
        // 如果所有 API 都失败了，使用默认值
        if (!ipData) {
            ipData = {
                ip: '无法获取 IP',
                location: '未知位置'
            };
        }
        
        // 获取用户名
        let username = '未登录用户';
        try {
            const authResponse = await fetch('check_session.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            });
            const authData = await authResponse.json();
            if (authData.authenticated && authData.username) {
                username = authData.username;
            }
        } catch (error) {
            console.error('获取用户名失败:', error);
        }
        
        // 选择或创建水印容器
        let watermark = document.querySelector('.watermark');
        if (!watermark) {
            watermark = document.createElement('div');
            watermark.className = 'watermark';
            watermark.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                pointer-events: none;
                z-index: 2147483647;
            `;
            document.body.appendChild(watermark);
        } else {
            // 清除原有水印内容，以便重新生成
            watermark.innerHTML = '';
        }
        
        // 计算需要多少个水印才能覆盖整个页面
        const spacing = 300;
        const cols = Math.ceil(window.innerWidth / spacing);
        const rows = Math.ceil(window.innerHeight / spacing);
        
        // 创建水印
        for(let i = 0; i < rows; i++) {
            for(let j = 0; j < cols; j++) {
                const watermarkItem = document.createElement('div');
                watermarkItem.className = 'watermark-item';
                watermarkItem.style.cssText = `
                    position: absolute;
                    user-select: none;
                    color: rgba(0, 0, 0, 0.17);
                    transform: rotate(-30deg);
                    font-size: 16px;
                    white-space: pre-line;
                    line-height: 1.8;
                    pointer-events: none;
                `;
                
                // 获取当前时间
                const now = new Date().toLocaleString('zh-CN', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false
                });
                
                watermarkItem.textContent = `用户: ${username}\nIP: ${ipData.ip}\n${ipData.location}\n${now}`;
                watermarkItem.style.left = `${j * spacing}px`;
                watermarkItem.style.top = `${i * spacing}px`;
                
                watermark.appendChild(watermarkItem);
            }
        }

        // 定时更新时间
        setInterval(() => {
            document.querySelectorAll('.watermark-item').forEach(item => {
                const now = new Date().toLocaleString('zh-CN', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false
                });
                item.textContent = `用户: ${username}\nIP: ${ipData.ip}\n${ipData.location}\n${now}`;
            });
        }, 1000);

        // 监听窗口大小变化，重新生成水印
        window.addEventListener('resize', () => {
            // 在调整大小前，首先清空水印容器
            watermark.innerHTML = '';
            addWatermark();
        });

    } catch (error) {
        console.error('添加水印时出错:', error);
    }
}
        
// 加载商品数据
fetch('api/products.php')
    .then(response => response.json())
    .then(data => {
        products = data.products;
        window.products = data.products; // 添加这行代码，确保图片搜索功能可以访问商品数据
        filteredProducts = [...products];
        document.getElementById('total-products').textContent = data.total || 0;
        renderProducts();
        
        
    })
    .catch(error => console.error('Error:', error));

// 渲染商品列表
function renderProducts() {
    const grid = document.querySelector('.products-grid');
    const start = (currentPage - 1) * pageSize;
    const end = start + pageSize;
    const pageProducts = filteredProducts.slice(start, end);
    
    grid.innerHTML = pageProducts.map(product => `
        <div class="product-item" onclick="showProductInfo('${product.code}')">
            <img src="${product.image}" alt="${product.code}">
            <div class="code">${product.code}</div>
        </div>
    `).join('');
    
    renderPagination();

    return new Promise((resolve) => {
        const imgs = grid.querySelectorAll('img');
        let loadedCount = 0;

        imgs.forEach(img => {
            if (img.complete) {
                loadedCount++;
            } else {
                img.addEventListener('load', () => {
                    loadedCount++;
                    checkAllLoaded();
                });
                img.addEventListener('error', () => {
                    loadedCount++;
                    checkAllLoaded();
                });
            }
        });

        function checkAllLoaded() {
            if (loadedCount === imgs.length) {
                layui.use(['layer'], function(){
                    layer.msg('数据加载完成', {icon: 1});
                    resolve();
                });
            }
        }
        
        // 立即检查可能已经缓存的图片
        checkAllLoaded();
    });
}

// 渲染分页
function renderPagination() {
    const pagination = document.querySelector('.pagination');
    const pageCount = Math.ceil(filteredProducts.length / pageSize);
    
    let buttons = '';
    for(let i = 1; i <= pageCount; i++) {
        buttons += `<button onclick="goToPage(${i})" ${i === currentPage ? 'disabled' : ''}>${i}</button>`;
    }
    
    pagination.innerHTML = buttons;
}

// 显示商品信息
function showProductInfo(code) {
    const product = products.find(p => p.code === code);

    // 创建模态框
    const modal = document.createElement('div');
    modal.className = 'product-modal';
    // 阻止事件冒泡和默认行为
    modal.addEventListener('contextmenu', e => {
        e.preventDefault();
        e.stopPropagation();
    });
    // 为模态框内容添加同样的阻止行为
    const modalContent = modal.querySelector('.modal-content');
    if (modalContent) {
        modalContent.addEventListener('contextmenu', e => {
            e.preventDefault();
            e.stopPropagation();
        });
    }

    // 添加样式
    const style = document.createElement('style');
    style.innerHTML = `
    .status-indicator {
        position: absolute;
        bottom: 10px;
        right: 10px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
    }
    .has-report {
        background-color: #4CAF50;
    }
    .no-report {
        background-color: #9E9E9E;
    }
    .product-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.7);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
    }
    .modal-content {
        background: white;
        border-radius: 10px;
        width: 80%;
        max-width: 800px;
        height:400px;
        padding: 20px;
        position: relative;
    }
    .close-modal {
        position: absolute;
        top: 10px;
        right: 10px;
        font-size: 24px;
        cursor: pointer;
    }
    .modal-body {
        display: flex;
        gap: 30px;
        padding: 10px 0;
    }
    .product-left {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
    }
    .product-center {
        flex: 2;
        align-self: flex-start;
        border: 1px solid #000;
        border-radius: 5px;
        height:420px;
        padding: 10px;
    }
    .product-right {
        flex: 2;
        align-self: flex-start;
        border: 1px solid #000;
        border-radius: 5px;
        height:420px;
        padding: 10px;
        overflow-y: auto;
        overscroll-behavior: contain;
    }
    .specs-table {
        width: 100%;
    }
    .specs-table table {
        width: 100%;
        border-collapse: collapse;
    }
    .specs-table th, .specs-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }
    .no-data {
        text-align: center;
        color: #999;
        padding: 20px 0;
    }
    .specs-table th {
        background-color: #f2f2f2;
    }
    .product-image {
        max-width: 420px;
        height: 420px;
        border-radius: 5px;
        cursor: pointer;
        position: relative;
    }
    .product-image-container {
        position: relative;
        display: inline-block;
    }
    .zoom-icon {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 24px;
        color: white;
        padding: 10px;
        border-radius: 50%;
        display: none;
        z-index: 1;
    }
    .product-image-container:hover .zoom-icon {
        display: block;
    }
    .image-preview-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.9);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 10000;
    }
    .preview-image {
        max-width: 90%;
        max-height: 90vh;
        object-fit: contain;
    }
    .preview-controls {
        position: absolute;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 10px;
    }
    .preview-btn {
        background: rgba(255,255,255,0.2);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
    }
    .preview-btn:hover {
        background: rgba(255,255,255,0.3);
    }
    .close-preview {
        position: absolute;
        top: 20px;
        right: 20px;
        color: white;
        font-size: 24px;
        cursor: pointer;
    }
    .product-info {
        margin-bottom: 20px;
        font-size:15px;
        line-height: 1.6;
        margin:10px;
    }
    .download-btn {
        display: inline-block;
        padding: 10px 20px;
        background: #4CAF50;
        color: white;
        text-decoration: none;
        border-radius: 6px;
        position: absolute;
        right: 20px;
        bottom: 20px;
        font-size: 14px;
        transition: all 0.3s;
        border: none;
    }
    .download-btn:hover {
        background: #45a049;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .disabled {
        background: #e2e8f0;
        color: #a0aec0;
        cursor: not-allowed;
        pointer-events: none;
    }`;
    document.head.appendChild(style);

    // 检查是否有下载权限（从全局变量中获取）
    const hasPermission = typeof userHasDownloadPermission !== 'undefined' ? userHasDownloadPermission : false;

    // 根据权限和链接状态构建报告链接部分的HTML
    let reportLinkHtml = '';
    if (hasPermission) {
        if (product.link) {
            reportLinkHtml = `<div class="report-link">
                <a href="javascript:void(0)" onclick="downloadReport('${product.code}')" class="download-btn">下载文件</a>
            </div>`;
        } else {
            reportLinkHtml = `<div class="report-link">
                <button class="download-btn disabled">暂未记录文件</button>
            </div>`;
        }
    } else {
        // 无权限时不显示任何按钮
        reportLinkHtml = '';
    }

    modal.innerHTML = `
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h3 class="product-code-title">商品编码： ${product.code}</h3>
            <div class="modal-body">
                <div class="product-left">
                    <div class="product-image-container">
                        <img src="${product.image}" alt="${product.code}" class="product-image">
                        <div class="zoom-icon">🔍</div>
                    </div>





                </div>

                <div class="product-center">
                    <div class="product-info">${product.info}</div>


                </div>

                <div class="product-right">
                    <div class="specs-table">



                        <table>
                            <thead>
                                <tr>
                                    <th>规格</th>
                                    <th>备注1</th>
                                    <th>备注2</th>
                                </tr>
                            </thead>
                            <tbody id="specs-body">
                                <tr><td colspan="3" style="text-align:center;color:#999;">获取数据中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    ${reportLinkHtml}





                </div>
            </div>
        </div>
        <div class="image-preview-modal">
            <span class="close-preview">&times;</span>
            <img src="${product.image}" class="preview-image">
            <div class="preview-controls">
                <button class="preview-btn" onclick="zoomImage(1.2)">放大</button>
                <button class="preview-btn" onclick="zoomImage(0.8)">缩小</button>
                <button class="preview-btn" onclick="resetZoom()">重置</button>
            </div>
        </div>
    `;

    // 添加到body
    document.body.appendChild(modal);

    // 加载规格数据
    fetch(`api/query.php?action=get_specs&product_id=${product.code}`)
        .then(response => response.json())
        .then(data => {
            const specs = Array.isArray(data.data) ? data.data : [];
            const specsBody = modal.querySelector('#specs-body');
            if (specs.length > 0) {
                specsBody.innerHTML = specs.map(spec => 
                    `<tr>
                        <td>${spec.spec_name}</td>
                        <td>${spec.spec_value}</td>
                        <td>${spec.spec_remark || ''}</td>
                    </tr>`
                ).join('');
            } else {
                specsBody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#999;">暂无规格数据</td></tr>';
            }
        })
        .catch(error => console.error('加载规格数据失败:', error));

    // 关闭按钮事件
    modal.querySelector('.close-modal').onclick = function() {
        document.body.removeChild(modal);
    };

    // 点击模态框外部关闭
    modal.onclick = function(e) {
        if (e.target === modal) {
            document.body.removeChild(modal);
        }
    };

    // 图片预览功能
    const productImage = modal.querySelector('.product-image');
    const previewModal = modal.querySelector('.image-preview-modal');
    const previewImage = previewModal.querySelector('.preview-image');
    const closePreview = previewModal.querySelector('.close-preview');

    productImage.addEventListener('click', function() {

        previewModal.style.display = 'flex';
        previewImage.style.transform = 'scale(1)';
    });

    closePreview.addEventListener('click', function() {
        previewModal.style.display = 'none';
    });

    previewModal.addEventListener('click', function(e) {
        if (e.target === previewModal) {
            previewModal.style.display = 'none';
        }
    });

    // 图片缩放功能
    window.zoomImage = function(factor) {
        const currentScale = parseFloat(previewImage.style.transform.replace('scale(', '').replace(')', '')) || 1;
        const newScale = currentScale * factor;
        if (newScale >= 0.5 && newScale <= 3) {
            previewImage.style.transform = `scale(${newScale})`;


        }
    };



    window.resetZoom = function() {
        previewImage.style.transform = 'scale(1)';
    };
}

// 页面切换
function goToPage(page) {
    currentPage = page;
    renderProducts();
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

// 搜索功能
document.querySelector('.search-box').addEventListener('input', (e) => {
    const searchTerm = e.target.value.toLowerCase();
    filteredProducts = products.filter(product => 
        product.code.toLowerCase().includes(searchTerm)
    );
    currentPage = 1;
    if (filteredProducts.length === 0) {
        document.querySelector('.products-grid').innerHTML = '<div class="no-results">找不到商品？<br>尝试删除几个字符 <br><strong style=color:green>例如：MHYM12J 可尝试搜索 MHYM</strong><br><b>或 使用"以图搜商品"功能试试吧</b><strong style=color:red>点击上方搜索框右侧选择商品图片或直接粘贴商品图片即可</strong></div>';
    } else {
        renderProducts();
    }
});
// 切换每页显示数量
document.querySelector('.page-size').addEventListener('change', (e) => {
    pageSize = parseInt(e.target.value);
    currentPage = 1;
    renderProducts();
});

// 点击空白处关闭详情面板（移动端）
document.addEventListener('click', (e) => {
    const rightPanel = document.querySelector('.right-panel');
    if (window.innerWidth <= 768 && 
        !e.target.closest('.right-panel') && 
        !e.target.closest('.product-item')) {
        rightPanel.style.display = 'none';
    }
});

// 显示修改密码模态框
function showChangePasswordModal() {
    layui.use('layer', function(){
        var layer = layui.layer;
        // 重置表单
        document.getElementById('changePasswordForm').reset();
        // 显示模态框
        document.getElementById('changePasswordModal').style.display = 'block';
    });
}

// 隐藏修改密码模态框
function hideChangePasswordModal() {
    document.getElementById('changePasswordModal').style.display = 'none';
}

// 处理修改密码表单提交
document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const currentPassword = document.getElementById('current_password').value;
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    // 基本验证
    if (!currentPassword || !newPassword || !confirmPassword) {
        layer.msg('所有字段都不能为空', {icon: 2});
        return;
    }
    
    if (newPassword.length < 6) {
        layer.msg('新密码长度不能少于6个字符', {icon: 2});
        return;
    }
    
    if (newPassword !== confirmPassword) {
        layer.msg('两次输入的新密码不一致', {icon: 2});
        return;
    }
    
    // 提交表单
    const formData = new FormData();
    formData.append('current_password', currentPassword);
    formData.append('new_password', newPassword);
    formData.append('confirm_password', confirmPassword);
    
    fetch('change_password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            layer.msg(data.message, {icon: 1});
            // 修改成功后延迟跳转到登录页
            setTimeout(function() {
                window.location.href = 'logout.php';
            }, 1500);
        } else {
            layer.msg(data.message, {icon: 2});
        }
    })
    .catch(error => {
        console.error('Error:', error);
        layer.msg('修改密码失败，请稍后再试', {icon: 2});
    });
});

// 点击模态框外部关闭
window.onmousedown = function(event) {
    const modal = document.getElementById('changePasswordModal');
    if (event.target == modal) {
        hideChangePasswordModal();
    }
}

// 下拉菜单控制
function toggleDropdown() {
    const dropdown = document.querySelector('.dropdown-menu');
    const arrow = document.querySelector('.dropdown-arrow');
    dropdown.classList.toggle('show');
    arrow.style.transform = dropdown.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0)';
}

// 点击其他地方关闭下拉菜单
document.addEventListener('click', (e) => {
    const dropdown = document.querySelector('.dropdown-menu');
    const trigger = document.querySelector('.user-info-trigger');
    
    if (!trigger.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.remove('show');
        document.querySelector('.dropdown-arrow').style.transform = 'rotate(0)';
    }
});

// 移动端点击遮罩层关闭下拉菜单
if (window.innerWidth <= 768) {
    document.addEventListener('click', (e) => {
        const dropdown = document.querySelector('.dropdown-menu');
        if (dropdown.classList.contains('show') && !dropdown.contains(e.target)) {
            dropdown.classList.remove('show');
            document.querySelector('.dropdown-arrow').style.transform = 'rotate(0)';
        }
    });
}

// 下载报告
function downloadReport(code) {
    if (!userHasDownloadPermission) {
        layui.use(['layer'], function(){
            var layer = layui.layer;
            layer.msg('您没有下载权限，请联系管理员', {icon: 2});
        });
        return;
    }
    
    // 找到对应产品的信息
    const product = products.find(p => p.code === code);
    if (!product || !product.link) {
        layui.use(['layer'], function(){
            var layer = layui.layer;
            layer.msg('未找到该商品的文件', {icon: 2});
        });
        return;
    }
    
    // 使用产品的link字段作为跳转链接
    const reportUrl = product.link;
    
    // 记录下载日志
    fetch(`manage.php?action=log_download_report&code=${code}`)
        .then(response => response.json())
        .then(data => {
            console.log('日志记录结果:', data);
            // 无论日志记录成功与否，都继续下载
            window.open(reportUrl, '_blank');
        })
        .catch(error => {
            console.error('记录日志失败:', error);
            // 出错时仍然允许下载
            window.open(reportUrl, '_blank');
        });
}