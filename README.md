# arpu · 北京移动资费 / ARPU 查询

静态前端（GitHub Pages）+ 自建 PHP 跨域代理（FTP 自动部署）。

## 架构

```
浏览器  https://rdtscx.github.io/arpu/
   │  POST /_proxy.php  {"phone":"13xxxxxxxxx"}
   ▼
PHP 主机  https://a781927.xqryfh.cn/_proxy.php     ← 来源白名单 + 限流 + 缓存 + 补 CORS 头
   │  GET ...customerValidateAndConsumption.action?submitPhoneNO=...
   ▼
ysmapp.bj.10086.cn   （ACAO 固定为自己域名、OPTIONS 预检 403，浏览器不能直连）
```

代理存在的唯一原因：上游 `Access-Control-Allow-Origin` 是 `https://ysmapp.bj.10086.cn`，
且 `OPTIONS` 返回 403。PHP 侧统一补 CORS 头并做 10 分钟落盘缓存。

## 前端通道

右上角只有一个**状态灯**：灰=尚未查询、绿=通道正常、红=通道异常。点击灯会展开浮层，
显示「优先通道 / 当前状态 / 可用代理数 / 服务端缓存」，并提供两个操作：

- **切换优先通道**：在「自建优先」与「跨域优先」之间切换（记忆在 `localStorage.arpu_mode`）。
- **清除本地缓存**：清掉本机的查询结果缓存（键是号码哈希，不含明文号码）。

查询流程：

| 阶段 | 请求形式 | 超时 | 说明 |
|---|---|---|---|
| 主通道 | `POST _proxy.php {"phone":…}` | 10s | 号码放请求体，不进 URL / 访问日志 / Referer |
| 重试 | 同上（换第二个代理；没配就同一代理重试） | 12s | 主通道失败后才发起 |

主通道超时（10s）刻意大于代理自身的上游超时（8s），避免客户端先放弃、服务端还在请求、
随后又重试造成同一号码并发打三次上游。旧代理不支持 POST 时会自动退回 GET，保证不断服。

自定义代理存 `localStorage.arpu_proxy`；`PROXIES` 列表支持配置多个地址做故障切换，
换域名或加备用代理只需改 `PROXY_DEFAULT` / `PROXIES`。

## 代理端点

| 方法 | 请求 | 说明 |
|---|---|---|
| `POST` | `{"phone":"1xxxxxxxxxx"}` | 主查询，号码放请求体（推荐） |
| `GET` | `/?phone=1xxxxxxxxxx` | 兼容旧页面（号码会出现在 URL 与日志里） |
| `GET` | `/?url=<encoded>` | 透传，仅允许上游查询接口「同主机 + 同路径 + 白名单参数」 |
| `HEAD` | `/?ping=1` | 连通性探测（不计数、不查上游） |
| `OPTIONS` | 任意 | 预检，204 |

安全约束：

- **来源白名单**：只有 `https://rdtscx.github.io` 与 `https://a781927.xqryfh.cn` 会拿到
  `Access-Control-Allow-Origin`；其他浏览器来源直接 403（无 Origin 的命令行请求放行）。
- **限流**：每 IP 每分钟 30 次查询，超限返回 `429` + `Retry-After`；`ping` 不计数。
- **透传收敛**：`url=` 不再接受任意目标，必须是上游查询接口本身且参数在白名单内，
  不做通用 SSRF 代理。

响应头：`X-Cache: HIT | STALE | MISS`、`X-Cache-Age`、`X-RateLimit-Remaining`（均已通过
`Access-Control-Expose-Headers` 暴露给前端）。

缓存策略：10 分钟内直接命中；6 小时内过期仍先返回旧值并后台刷新（stale-while-revalidate），
避免上游变慢时页面一直转圈。

## 部署

- 前端：push 到 `main` 后由 GitHub Pages 自动发布（源：`main` / `/`）。
- 代理：`Deploy to FTP` 工作流在 `index.html` / `_proxy.php`（或本工作流自身）变更时执行：
  1. `php -l _proxy.php` 语法校验（不通过则中止，不会覆盖线上文件）
  2. 显式 FTPS 上传（`--ssl-reqd` + `--pinnedpubkey` 钉死服务器公钥指纹，指纹不符即失败）
  3. 线上冒烟测试：`ping`、`OPTIONS` 预检 204、白名单来源回显 ACAO、非白名单 403、
     非法号码 400、透传非法路径 403 —— 全程不使用任何真实手机号

需要的仓库密钥：`FTP_SERVER`、`FTP_USERNAME`、`FTP_PASSWORD`。

## 排错

| 现象 | 原因 / 处理 |
|---|---|
| 状态灯变红 | 点开浮层看「当前状态」；再 `curl https://a781927.xqryfh.cn/_proxy.php?ping=1` 确认代理在线 |
| 页面提示「请求过于频繁」 | 触发每 IP 每分钟 30 次限流，等一分钟后重试 |
| 页面显示「无记录」、原始数据是 `暂无可办理档位` | 上游业务返回，表示该号码当前没有可办理档位；不是代理故障 |
| 浏览器控制台报 CORS | 确认页面来源在白名单内；改了域名需同步更新 `_proxy.php` 的 `ALLOWED_ORIGINS` |
| 换了服务器证书后部署失败 | 更新 `deploy-ftp.yml` 里的 `FTP_PIN` 指纹 |
| 修改前端后用户仍看到旧版 | 强制刷新，或访问 `index.html?v=<时间戳>` |

## 备注

- 手机号在代理侧只参与缓存键（`md5(上游URL)`），不写业务日志；前端本地缓存键为号码哈希。
- 曾尝试 Cloudflare Worker 作为替代后端：`*.workers.dev` 在国内 DNS 被污染、`dash.cloudflare.com`
  返回 403，无法部署与访问，故保留 PHP 方案。
