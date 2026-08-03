# Kssmi production 自动回滚策略

当前不使用 staging，因此发布 workflow 不提供受控故障按钮，也不注入故障到
production。故意让线上请求失败来证明回滚，风险大于收益。

每次 production 发布仍强制启用自动回滚。候选版本激活后，只要权限验证、真实
OpenLiteSpeed/LSAPI 能力探针、Cloudflare/TLS 检查、真实后台 smoke 或 finalize
任一步失败，部署 action 都调用服务器端 `rollback`：恢复 previous webroot
symlink、共享 private 模块和 cutover 状态，并再次校验 Email JSON 与 VJT SQLite。

发布证据必须记录 previous release id 和最终 active release id。服务器会保留最近
的已验收 release；必要时可用对应 release id 执行人工恢复。人工恢复属于事故
处置，不在正常 workflow 中自动触发，也不使用 staging 演练证据作为发布前置
条件。

如果将来建立完全隔离的 staging，可重新增加非生产故障演练；在此之前，任何
production fault injection 都是禁止的。
