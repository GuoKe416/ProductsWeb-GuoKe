
# 🥔商品文件库管理系统
[![GitHub Stars](https://img.shields.io/github/stars/GuoKe416/ProductsWeb-GuoKe?style=social)](https://github.com/GuoKe416/ProductsWeb-GuoKe/stargazers)  
[![star](https://gitee.com/hyk0416/ProductsWeb-GuoKe/badge/star.svg?theme=dark)](https://gitee.com/hyk0416/ProductsWeb-GuoKe/stargazers)
[![GitHub Contributors](https://img.shields.io/github/contributors/GuoKe416/ProductsWeb-GuoKe?style=social)](https://github.com/GuoKe416/ProductsWeb-GuoKe/graphs/contributors)  
[![GitHub License](https://img.shields.io/github/license/GuoKe416/ProductsWeb-GuoKe)](https://github.com/GuoKe416/ProductsWeb-GuoKe/blob/main/LICENSE)

商品文件库管理系统是一个专为团队组织设计的商品资料管理平台，用于存储、管理和查询商品的文件、图片和相关信息。通过简单直观的界面，帮助团队组织高效组织和获取商品资料。

## 🚀免责声明
 - 这个项目免费开源，不存在收费。
 - 本系统仅供学习和技术研究使用，不得用于任何非法行为。
 - 本系统的作者不对本系统的安全性、完整性、可靠性、有效性、正确性或适用性做任何明示或暗示的保证，也不对本系统的使用或滥用造成的任何直接或间接的损失、责任、索赔、要求或诉讼承担任何责任。
 - 本系统的作者保留随时修改、更新、删除或终止本系统的权利，无需事先通知或承担任何义务。
 - 本系统的使用者应遵守相关法律法规，尊重版权和隐私，不得侵犯其他第三方的合法权益，不得从事任何违法或不道德的行为。
 - 本系统的使用者在下载、安装、运行或使用本系统时，即表示已阅读并同意本免责声明。如有异议，请立即停止使用本系统，并删除所有相关文件。

## ⭐功能特点

- **商品管理**：支持添加、编辑和删除商品信息，包括编码、图片、详情和链接
- **文件管理**：便捷地管理和组织与商品相关的各种文件
- **用户权限系统**：多级用户权限管理，包括管理员和普通用户角色
- **安全性保障**：
  - 密码加密存储
  - IP封禁功能，防止暴力破解
  - 会话管理，支持强制下线
  - 异常登录提醒
- **图像特征存储**：支持商品图像特征提取和存储
- **系统日志**：详细记录系统操作，便于追踪和审计
- **个性化配置**：支持网站标题、页脚、页面水印等多种自定义配置

## 📷页面截图
 - 安装页面
   ![image](https://github.com/user-attachments/assets/30f33d1e-d2a4-4aa7-bfd6-ffef45c167a3)
 - 登录页面
   ![image](https://github.com/user-attachments/assets/384b3dcd-a1ed-493e-89ff-f4866fab06a4)
 - 前端页面
   ![image](https://github.com/user-attachments/assets/3d2169a4-1dd7-44ed-b0f1-88deb7bd5a8f)
 - 后台页面
   ![image](https://github.com/user-attachments/assets/49c4a292-d2a1-43c8-91a2-709ff759fb9b)

## 🛠️安装要求

- PHP 7.0+
- MySQL 5.6+

## 🔧安装步骤

1. 复制所有文件到您的Web服务器目录
2. 创建一个mysql数据库
3. 访问 `http://您的域名` 开始安装
4. 按照安装向导填写数据库信息
5. 安装完成后，系统将自动创建所需的数据库表和初始配置

## 📄默认账号

安装完成后，系统会自动创建一个默认管理员账号：
- 用户名：admin
- 密码：123456

**重要提示**：首次登录后，请立即修改默认密码以确保安全。

## ✨使用说明

### 👮管理员功能

- 用户管理：添加、编辑、删除用户，设置用户权限，查看在线用户，强制用户下线
- 商品管理：添加、编辑、删除商品信息和文件
- 系统配置：自定义网站标题、页脚、水印等设置
- 日志查看：查看系统操作日志

### 👨‍💼普通用户功能

- 浏览商品：查看所有商品的列表
- 搜索功能：通过编码或信息快速定位商品
- 下载文件：下载与商品关联的文件（需要管理员配置权限）

## ❓常见问题

1. **登录失败次数过多被封禁**
   - IP封禁时间为24小时
   - 联系管理员手动解除封禁

2. **忘记密码**
   - 普通用户：联系管理员重置密码
   - 管理员：使用数据库管理工具重置密码或联系系统开发者

3. **文件上传大小限制**
   - 默认上传限制取决于PHP配置
   - 可在php.ini中修改upload_max_filesize和post_max_size值

## 🛡️安全建议

1. 定期更改密码
2. 只给必要的用户分配下载权限
3. 定期查看系统日志
4. 在生产环境中使用HTTPS协议
5. 及时更新系统

## 💻许可证

本项目采用MIT许可证。详情请查看[LICENSE](LICENSE)文件。

## 💬联系方式

如有任何问题或建议，请联系：

- 邮箱：1928816453@qq.com
- 微信：hyk416-

---
## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=GuoKe416/ProductsWeb-GuoKe&type=date&legend=top-left)](https://www.star-history.com/#GuoKe416/ProductsWeb-GuoKe&type=date&legend=top-left)

---

感谢使用商品文件库管理系统！ 
