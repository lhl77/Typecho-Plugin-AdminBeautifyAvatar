<h1 align="center">AB Avatar</h1>

<p align="center">
  <strong>Typecho 的 AdminBeautify 专用头像管理插件 · Gravatar 代理 / 自定义上传 / PicUp 集成</strong>
</p>

<p align="center">
  <a href="https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar/releases"><img src="https://img.shields.io/github/v/release/lhl77/Typecho-Plugin-AdminBeautifyAvatar?style=flat-square&label=release&color=blue" alt="Latest Release"></a>
  <img src="https://img.shields.io/badge/Typecho-%3E%3D1.3.0-orange?style=flat-square" alt="Typecho >= 1.3.0">
  <img src="https://img.shields.io/badge/PHP-%3E%3D7.2-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP >= 7.2">
  <img src="https://img.shields.io/badge/AdminBeautify-Required-6750A4?style=flat-square" alt="AdminBeautify Required">
  <img src="https://img.shields.io/badge/PicUp-Optional-0EA5E9?style=flat-square" alt="PicUp Optional">
  <a href="https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar/issues"><img src="https://img.shields.io/github/issues/lhl77/Typecho-Plugin-AdminBeautifyAvatar?style=flat-square" alt="Issues"></a>
  <a href="https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar/stargazers"><img src="https://img.shields.io/github/stars/lhl77/Typecho-Plugin-AdminBeautifyAvatar?style=flat-square&logo=github" alt="GitHub Stars"></a>
  <a href="https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar/network/members"><img src="https://img.shields.io/github/forks/lhl77/Typecho-Plugin-AdminBeautifyAvatar?style=flat-square&logo=github" alt="GitHub Forks"></a>
</p>

<p align="center">
  快捷链接：
  <a href="https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar">GitHub</a> |
  <a href="https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar/issues">问题反馈</a> |
  <a href="https://github.com/lhl77/Typecho-Plugin-AdminBeautify">AdminBeautify</a> |
  <a href="https://github.com/lhl77/Typecho-Plugin-PicUp">PicUp</a>
</p>

---

## 功能特色

| 功能 | 说明 |
| --- | --- |
| Gravatar 多源切换 | 支持官方 / loli / Cravatar / 自定义域名 / 本地代理 |
| 代理地址编码 | 本地代理使用 token 编码参数，避免暴露明文 Gravatar 格式 |
| 本地头像专属路由 | 本地存储头像通过签名 token 路由访问，不直接暴露文件路径 |
| 自定义头像上传 | 个人设置页与用户编辑页支持上传、裁剪、压缩、恢复 |
| PicUp 集成 | 可选 PicUp 存储后端，支持上传策略（Profile）选择 |
| 管理面板 | 插件设置页内置“自定义头像管理”，支持恢复为 Gravatar |
| 安全防护 | 支持代理防盗链、每 IP 限流、代理缓存天数配置 |

## 安装

### 方式一：AB-Store 一键安装（推荐）

安装 [AdminBeautify](https://github.com/lhl77/Typecho-Plugin-AdminBeautify) 插件后，进入后台 **AB-Store** 应用商店，搜索 **AB Avatar** 即可一键安装并获取后续更新。

### 方式二：下载压缩包

1. 前往 [GitHub 仓库](https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar) 下载源码或发布包
2. 解压后将目录重命名为 `AdminBeautifyAvatar`
3. 上传到 Typecho 的 `usr/plugins/` 目录
4. 在后台进入 **控制台 -> 插件**，启用 `AdminBeautifyAvatar`

### 方式三：Git 克隆

```bash
cd /your-site/usr/plugins/
git clone https://github.com/lhl77/Typecho-Plugin-AdminBeautifyAvatar.git AdminBeautifyAvatar
```

目录示例：

```text
your-site/
└── usr/
    └── plugins/
        └── AdminBeautifyAvatar/
```

## 依赖说明

- 必需：`AdminBeautify`
- 可选：`PicUp`（作为头像存储后端）

当 `AdminBeautify` 未启用时，插件设置页仅显示依赖提示，不显示其它配置项。

## 主要配置项

- 前台 Gravatar 源：官方 / loli / Cravatar / 自定义 / 本地代理
- 本地代理上游源、缓存天数、限流、防盗链
- 允许用户上传自定义头像
- 自定义头像大小限制（MB）
- 头像存储后端：本地 / PicUp
- PicUp 上传策略（Profile）
- 前台自动替换 Gravatar 地址

## 路由说明

| 路由 | 作用 |
| --- | --- |
| `/ab-avatar/gravatar/{token}` | 头像代理路由（token 编码参数） |
| `/ab-avatar/local/{token}` | 本地头像专属访问路由（签名 token） |
| `/ab-avatar/upload` | 当前登录用户上传自定义头像 |
| `/ab-avatar/restore` | 当前登录用户恢复为 Gravatar |
| `/ab-avatar/manage` | 管理员恢复指定用户头像 / 代上传 |

## 使用建议

1. 先启用 `AdminBeautify`，再启用本插件
2. 默认推荐“前台 Gravatar 源 = 本地代理”
3. 若站点开启 CDN / 缓存，建议保持 `proxy_hotlink_protection` 与 `proxy_rate_limit_per_min` 为开启状态
4. 若使用 PicUp，请先确认 PicUp 插件已正确配置并可正常上传

## 常见问题

### 1) 访问代理头像返回 `invalid token`

说明当前链接不是插件生成的编码代理地址，或 token 已损坏。请确保头像 URL 来自插件自动生成逻辑。

### 2) 用户头像上传失败

请检查：

- PHP `gd` 扩展是否可用
- 上传文件格式是否为 JPG/PNG/GIF/WEBP
- 上传文件大小是否超过插件设置阈值

### 3) 设置页更新后样式或脚本不生效

插件静态资源带版本参数（基于文件修改时间），如仍未更新，建议清理站点缓存和 OPcache。

## Stars

<a href="https://www.star-history.com/?repos=lhl77%2FTypecho-Plugin-AdminBeautifyAvatar&type=date&legend=top-left">
 <picture>
   <source media="(prefers-color-scheme: dark)" srcset="https://api.star-history.com/image?repos=lhl77/Typecho-Plugin-AdminBeautifyAvatar&type=date&theme=dark&legend=top-left" />
   <source media="(prefers-color-scheme: light)" srcset="https://api.star-history.com/image?repos=lhl77/Typecho-Plugin-AdminBeautifyAvatar&type=date&legend=top-left" />
   <img alt="Star History Chart" src="https://api.star-history.com/image?repos=lhl77/Typecho-Plugin-AdminBeautifyAvatar&type=date&legend=top-left" />
 </picture>
</a>

---

<p align="center">
  Made with ❤️ by <a href="https://github.com/lhl77">LHL</a>
</p>
