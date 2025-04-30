// 图片搜索功能实现
class ImageSearch {
    constructor() {
        this.products = [];
        this.filteredProducts = [];
        this.baiduApiToken = '';
        this.tokenExpireTime = 0;
        this.init();
        this.getBaiduApiToken();
    }

    init() {
        this.createUploadElement();
        this.bindEvents();
    }

    createUploadElement() {
        const searchBox = document.querySelector('.search-box');
        if (!searchBox) return;

        const uploadContainer = document.createElement('div');
        uploadContainer.className = 'image-upload-container';
        uploadContainer.innerHTML = `
            <span>或</span>
            <label for="image-upload" class="upload-label">
                <span>点此上传图片 以图搜图</span>
            </label>
            <input type="file" id="image-upload" accept="image/*" style="display: none;">
            
            
            
        `;
        // <input type="text" class="paste-input" placeholder="点此粘贴图片直接搜索">
        searchBox.parentNode.insertBefore(uploadContainer, searchBox.nextSibling);

        // 添加样式
        const style = document.createElement('style');
        style.textContent = `
            .image-upload-container {
                display: inline-flex;
                align-items: center;
                margin-left: 10px;
                vertical-align: middle;
                gap: 10px;
            }
            
            .paste-input {
                padding: 8px 12px;
                border: 2px solid #e2e8f0;
                border-radius: 8px;
                font-size: 16px;
                transition: all 0.3s;
                outline: none;
                width: 450px;
                height:40px
            }
            
            .paste-input:focus {
                border-color: #4299e1;
                box-shadow: 0 0 0 3px rgba(66,153,225,0.2);
            }
            
            .paste-input:hover {
                border-color: #cbd5e0;
            }
            @keyframes light-sweep {
                0% { background-position: -100% 0; }
                50% { background-position: 0% 0; }
                100% { background-position: 100% 0; }
            }
            .upload-label {
                display: inline-flex;
                align-items: center;
                padding: 8px 12px;
                width: 180px;
                background: linear-gradient(90deg, #1a6fc9,rgb(31, 233, 216), #1a6fc9);
                background-size: 200% auto;
                color: white;
                border-radius: 4px;
                cursor: pointer;
                transition: all 0.3s;
                position: relative;
                overflow: hidden;
                box-shadow: 0 2px 10px rgba(66, 153, 225, 0.3);
                animation: light-sweep 1.5s linear infinite;
            }
            .upload-label:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
            }
            .upload-label i {
                margin-right: 6px;
                font-size: 18px;
            }
            .similarity-badge {
                position: absolute;
                top: 5px;
                right: 5px;
                background: rgba(0,0,0,0.7);
                color: white;
                padding: 2px 6px;
                border-radius: 10px;
                font-size: 12px;
                z-index: 1;
                display: block !important;
                opacity: 1 !important;
                visibility: visible !important;
            }
        `;
        document.head.appendChild(style);
    }

    bindEvents() {
        const uploadInput = document.getElementById('image-upload');
        if (!uploadInput) return;

        // 点击上传
        uploadInput.addEventListener('change', (e) => this.handleImageUpload(e));
        
        // 粘贴图片
        document.addEventListener('paste', (e) => {
            const items = e.clipboardData.items;
            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    const blob = items[i].getAsFile();
                    this.processImage(blob);
                    break;
                }
            }
            
            // 处理粘贴的图片URL
            const pasteInput = document.querySelector('.paste-input');
            if (pasteInput) {
                const pastedText = e.clipboardData.getData('text');
                if (pastedText && /^https?:\/\/.+\.(jpg|jpeg|png|gif|webp)$/i.test(pastedText)) {
                    pasteInput.value = pastedText;
                    const img = new Image();
                    img.crossOrigin = 'Anonymous';
                    img.onload = () => this.analyzeImage(img);
                    img.src = pastedText;
                }
            }
        });
    }

    handleImageUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        this.processImage(file);
    }

    processImage(file) {
        showLoadingToast('正在分析图片...');
        
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                this.analyzeImage(img);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    async analyzeImage(uploadedImg) {
        // 获取当前商品数据
        this.products = window.products || [];
        
        // 创建canvas获取上传图片的特征
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        canvas.width = 16;
        canvas.height = 16;
        ctx.drawImage(uploadedImg, 0, 0, 16, 16);
        
        // 获取上传图片的像素数据
        const uploadedData = ctx.getImageData(0, 0, 16, 16).data;
        
        try {
            // 检查是否启用百度API
            const configResponse = await fetch('api/site_config.php?key=enable_baidu_api');
            const configData = await configResponse.json();
            const enableBaiduApi = configData.success && configData.value === '1';
            
            // 仅在启用百度API时发起请求
            if (enableBaiduApi) {
                // 使用百度API进行图片搜索
                const base64Image = await this.getBase64Image(uploadedImg);
                const baiduResponse = await this.searchWithBaiduAPI(base64Image);
                // console.log('百度API返回结果:', baiduResponse);
                
                if (baiduResponse && baiduResponse.result) {
                    // 处理百度API返回的结果
                    this.filteredProducts = baiduResponse.result.map(item => {
                        const product = this.products.find(p => p.code === item.brief);
                        if (product) {
                            return {
                                ...product,
                                similarity: item.score * 100
                            };
                        }
                        return null;
                    }).filter(Boolean).slice(0, 6);
                    
                    // 渲染结果并标记为使用百度API
                    this.renderResults(true);
                    return;
                }
            }
            
            // 如果百度API关闭或失败，使用本地计算方法
            const response = await fetch('get_product_features.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    productCodes: this.products.map(p => p.code)
                })
            });
            
            const productFeatures = await response.json();
            
            // 计算每个商品的相似度
            const similarityPromises = this.products.map(product => {
                const feature = productFeatures.find(f => f.product_code === product.code);
                
                if (feature && feature.pixel_data) {
                    // 使用预存的像素数据计算相似度
                    return this.calculateSimilarityWithPrecomputedData(
                        uploadedData, 
                        JSON.parse(feature.pixel_data)
                    ).then(similarity => ({
                        ...product,
                        similarity
                    }));
                } else {
                    // 没有预存数据，实时计算
                    return this.calculateSimilarity(
                        uploadedImg, 
                        product.image, 
                        uploadedData
                    ).then(similarity => ({
                        ...product,
                        similarity
                    }));
                }
            });
            
            // 等待所有相似度计算完成
            const productsWithSimilarity = await Promise.all(similarityPromises);
            
            // 按相似度排序并取前5个
            this.filteredProducts = productsWithSimilarity
                .sort((a, b) => b.similarity - a.similarity)
                .slice(0, 10);
            
            // 渲染结果
            this.renderResults(enableBaiduApi);
            
        } catch (error) {
            console.error('Error:', error);
            // 回退到原始方法
            this.fallbackAnalyzeImage(uploadedImg, uploadedData);
        }
    }

    fallbackAnalyzeImage(uploadedImg, uploadedData) {
        // 计算每个商品的相似度
        const similarityPromises = this.products.map(product => 
            this.calculateSimilarity(uploadedImg, product.image, uploadedData)
                .then(similarity => ({
                    ...product,
                    similarity
                }))
        );
        
        // 等待所有相似度计算完成
        Promise.all(similarityPromises).then(productsWithSimilarity => {
            // 按相似度排序并取前5个
            this.filteredProducts = productsWithSimilarity
                .sort((a, b) => b.similarity - a.similarity)
                .slice(0, 10);
            
            // 渲染结果并标记为使用百度API
            this.renderResults(true);
        });
    }

    calculateSimilarity(uploadedImg, productImgUrl, uploadedData) {
        return new Promise((resolve) => {
            const productImg = new Image();
            productImg.crossOrigin = 'Anonymous';
            productImg.onload = () => {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = 16;
                canvas.height = 16;
                ctx.drawImage(productImg, 0, 0, 16, 16);
                
                // 获取商品图片的像素数据
                const productData = ctx.getImageData(0, 0, 16, 16).data;
                
                // 计算相似度 (简单的像素差异比较)
                let diff = 0;
                for (let i = 0; i < uploadedData.length; i += 4) {
                    diff += Math.abs(uploadedData[i] - productData[i]) + 
                            Math.abs(uploadedData[i+1] - productData[i+1]) + 
                            Math.abs(uploadedData[i+2] - productData[i+2]);
                }
                
                // 转换为相似度百分比 (0-100)
                const maxDiff = 16 * 16 * 3 * 255;
                const similarity = Math.max(0, 100 - (diff / maxDiff * 100));
                
                // 保存特征数据到数据库
                this.saveImageFeature(productImgUrl, productData);
                
                resolve(similarity);
            };
            productImg.src = productImgUrl;
        });
    }

    calculateSimilarityWithPrecomputedData(uploadedData, productData) {
        return new Promise((resolve) => {
            // 计算相似度 (简单的像素差异比较)
            let diff = 0;
            for (let i = 0; i < uploadedData.length; i += 4) {
                diff += Math.abs(uploadedData[i] - productData[i]) + 
                        Math.abs(uploadedData[i+1] - productData[i+1]) + 
                        Math.abs(uploadedData[i+2] - productData[i+2]);
            }
            
            // 转换为相似度百分比 (0-100)
            const maxDiff = 16 * 16 * 3 * 255;
            const similarity = Math.max(0, 100 - (diff / maxDiff * 100));
            resolve(similarity);
        });
    }
    
    async getBaiduApiToken() {
        try {
            // 首先检查是否启用百度API
            const configResponse = await fetch('api/site_config.php?key=enable_baidu_api');
            const configData = await configResponse.json();
            const enableBaiduApi = configData.success && configData.value === '1';
            
            // 如果百度API未启用，直接返回
            if (!enableBaiduApi) {
                return;
            }
            
            // 检查token是否过期
            if (this.baiduApiToken && Date.now() < this.tokenExpireTime) {
                return;
            }
            
            const response = await fetch('/api.php?action=get_baidu_token', {
                method: 'GET'
            });
            
            const data = await response.json();
            if (data.access_token) {
                this.baiduApiToken = data.access_token;
                this.tokenExpireTime = Date.now() + (25 * 24 * 60 * 60 * 1000); // 25天后过期
            }
        } catch (error) {
            console.error('Error getting Baidu API token:', error);
        }
    }
    
    async searchWithBaiduAPI(base64Image) {
        try {
            // 首先检查是否启用百度API
            const configResponse = await fetch('api/site_config.php?key=enable_baidu_api');
            const configData = await configResponse.json();
            const enableBaiduApi = configData.success && configData.value === '1';
            
            // 如果百度API未启用，直接返回
            if (!enableBaiduApi) {
                return null;
            }
            
            if (!this.baiduApiToken) {
                await this.getBaiduApiToken();
            }
            
            // 如果仍然没有token，可能是API未启用或请求失败
            if (!this.baiduApiToken) {
                return null;
            }
            
            const formData = new URLSearchParams();
            formData.append('image', base64Image);
            
            const response = await fetch(`https://aip.baidubce.com/rest/2.0/image-classify/v1/realtime_search/product/search?access_token=${this.baiduApiToken}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: formData
            });
            
            const data = await response.json();
            if (data && data.result) {
                // 解析brief字段中的name值，并过滤无效结果
                const results = data.result
                    .map(item => {
                        try {
                            const brief = JSON.parse(item.brief);
                            return {
                                ...item,
                                brief: brief.name
                            };
                        } catch (e) {
                            return null;
                        }
                    })
                    .filter(Boolean)
                    .slice(0, 10); // 只取前10个结果
                
                return {
                    ...data,
                    result: results
                };
            }
            return data;
        } catch (error) {
            console.error('Error searching with Baidu API:', error);
            return null;
        }
    }
    
    getBase64Image(img) {
        return new Promise((resolve) => {
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth || img.width;
            canvas.height = img.naturalHeight || img.height;
            
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            
            // 获取Base64编码的图片数据
            const base64String = canvas.toDataURL('image/jpeg').split(',')[1];
            resolve(base64String);
        });
    }

    async saveImageFeature(imageUrl, pixelData) {
        try {
            // 找到对应的商品
            const product = this.products.find(p => p.image === imageUrl);
            if (!product) return;
            
            // 发送到后端保存
            await fetch('save_image_feature.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    product_code: product.code,
                    image_url: imageUrl,
                    pixel_data: Array.from(pixelData) // 将Uint8ClampedArray转换为普通数组
                })
            });
        } catch (error) {
            console.error('Error saving image feature:', error);
        }
    }

    renderResults(isBaiduAPI = false) {
        const grid = document.querySelector('.products-grid');
        if (!grid) return;
        
        if (isBaiduAPI) {
            console.log('当前使用API进行图片搜索');
        } else {
            console.log('当前使用本地计算方法进行图片搜索');
        }
        
        grid.innerHTML = this.filteredProducts.map(product => {
            const similarity = typeof product.similarity === 'number' ? product.similarity.toFixed(1) : '0.0';
            return `
                <div class="product-item" onclick="showProductInfo('${product.code}')" style="box-shadow: 0 0 15px rgba(144, 238, 144, 0.5);">
                    <img src="${product.image}" alt="${product.code}">
                    <div class="code">${product.code}</div>
                    <div class="similarity-badge" style="display: block !important; opacity: 1 !important; visibility: visible !important;">相似度：${similarity}%</div>
                </div>
            `;
        }).join('');
        
        layui.use(['layer'], function(){
            layer.closeAll();
            layer.msg('图片搜索完成', {icon: 1});
        });
    }
}

// 页面加载完成后初始化图片搜索功能
document.addEventListener('DOMContentLoaded', () => {
    new ImageSearch();
});