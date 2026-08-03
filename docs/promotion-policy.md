# Kssmi 仅 production 发布契约

当前发布模式是 production-only，直接部署 `https://kssmi.com`。不需要 staging
DNS、TLS、OpenLiteSpeed vhost、Unix 用户、数据目录，也不需要创建 GitHub
`staging`/`production` Environments。

这项取舍省去 staging 运维，但不会删除生产安全门禁。每个 workflow run 只构建
一次；构建 job 将 `dist`、private 模块和发布脚本封装成 tar，生成 SHA-256，
production 下载并校验同一个 artifact。release id 为
`<commit SHA>-<GitHub run id>-<run attempt>`，因此同一 commit 的 push 和手动重跑
不会复用服务器 release/state 目录。

production 使用仓库现有的 repository Secrets：

- `REMOTE_HOST`
- `REMOTE_USER`
- `SSH_PRIVATE_KEY`
- `SMOKE_ACCESS_CLIENT_ID`
- `SMOKE_ACCESS_CLIENT_SECRET`
- `SMOKE_ADMIN_PASSWORD`
- 可选：`CLOUDFLARE_API_TOKEN`、`CLOUDFLARE_ZONE_ID`
- 构建期公钥：`PUBLIC_TURNSTILE_SITE_KEY`

目标值在 workflow 和服务器发布管理器中双重固定为 `kssmi.com`、
`/home/kssmi.com`、`kssmi4374:kssmi4374`。部署组件拒绝其他环境、其他根目录和
故障注入模式。

## 保留的生产门禁

1. 完整离线策略、PHP 测试、前端验证和 Astro build；
2. 构建时联网核对 Cloudflare 官方 IP 范围，并由独立审计 workflow 绑定当前
   commit；
3. release artifact 完整性校验、原子 symlink 切换和唯一 release id；
4. 真实 OpenLiteSpeed/LSAPI UID/GID 与读写能力证明；
5. Cloudflare Access service token 加管理员登录的真实后台 smoke；
6. 激活、权限、在线 smoke 或 finalize 任一步失败时自动回滚；
7. build + production 证据汇总与最终签收。

因为不需要 staging，代码不会声称存在 staging 晋级或 staging 回滚演练。需要更
高隔离级别时可另行恢复独立预发布环境，但不能通过复制生产凭据或生产数据来
伪造隔离。
