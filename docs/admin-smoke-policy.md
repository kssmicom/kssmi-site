# Kssmi 真实后台 smoke 发布硬门禁

production 发布固定设置 `SMOKE_REQUIRE_ADMIN=true`。缺少
`SMOKE_ACCESS_CLIENT_ID`、`SMOKE_ACCESS_CLIENT_SECRET` 或
`SMOKE_ADMIN_PASSWORD` 中任意一个，smoke 必须在后台请求前失败；错误 Access
凭据、错误密码、弱 Cookie、CSRF/logout 回归、后台数据健康异常或凭据反射同样
使发布失败并触发自动回滚。

只有人工执行 local diagnostics 时，才可显式设置 `SMOKE_REQUIRE_ADMIN=false`
跳过认证段。未设置、空值和 `yes`/`1` 等模糊值都不能自动跳过。

## 凭据边界

- production 使用 repository-scoped GitHub Secrets；凭据只传给对应部署/smoke
  步骤，不进入构建环境。
- 管理员密码不附加到公共页面、安全路径或缓存检查。
- secret 不打印、不得写入 artifact、部署 tar、日志、文档或仓库文件。
- 对 service token 和管理员密码建立 rotation 日程；先更新 Secret，再手动运行
  workflow 验证，新凭据成功后撤销旧 token 并记录轮换日期。

没有 GitHub production Environment reviewer 时，push 到 `main` 会直接开始生产
发布；完整 CI、真实后台 smoke 和自动回滚仍是硬门禁。
