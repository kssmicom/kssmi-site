# Kssmi production 发布证据包与最终签收

每次发布生成 build 和 production 两个中间证据 artifact，并汇总为
`kssmi-release-evidence-<commit>-<run-id>-<attempt>`。artifact 保存 90 天，包含
机器可读 JSON 和人工签收 Markdown，不包含 SSH key、Access token、后台密码或
GitHub token。

构建证据记录完整 commit SHA、唯一 release id、Node/PHP 版本、Cloudflare 快照
与统一 runtime asset manifest 的 SHA-256。production 证据记录前后 release id、
symlink target、真实 OpenLiteSpeed/LSAPI UID/GID、权限 policy-as-code、运行能力、
真实后台认证 smoke 和最终健康状态。

最终收集器通过 GitHub Actions API 核对：

- 当前 build 与 production 强制 job 都为 success；
- 同一 commit 的 `PHP 8.3 full backend suite` 为 success；
- 当前 commit 的 Cloudflare 官方地址审计为 success 且不超过 8 天；
- production 后台 smoke、权限和运行身份证据全部通过；
- previous accepted release 可识别，保证失败时自动回滚有明确目标；
- GitHub Issues 没有未解决的 P1；
- 收集过程没有缺失证据或 API 错误。

全部满足时 `signoff.status` 才是 `APPROVED`；否则为 `BLOCKED`。即使 BLOCKED，
JSON 和 Markdown 证据包也会先上传，随后最终门禁失败，便于诊断。production-only
模式不要求 staging 或故障注入演练证据。
