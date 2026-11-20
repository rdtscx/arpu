# arpu · 北京移动资费 / ARPU 查询

静态前端（GitHub Pages）+ 自建 PHP 跨域代理（FTP 自动部署）。

## 架构

```
浏览器  https://rdtscx.github.io/arpu/
   │  POST /_proxy.php  {"phone":"13xxxxxxxxx"}        （主通道）
   ▼
PHP 主机  https://a781927.xqryfh.cn/_proxy.php     ← 来源白名单 + 限流 + 缓存 + 补 CORS 头
   │  GET ...customerValidateAndConsumption.action?submitPhoneNO=...
   ▼
ysmapp.bj.10086.cn   （ACAO 固定为自己域名、OPTIONS 预检 403，浏览器不能直连）

代理不可达时，页面会同时向几家中转并行发请求（主通道不单独回退，一律并行竞速）：
https://arpu-relay.3062438329.workers.dev/?url=<encoded 上游地址>
https://test.cors.workers.dev/?<encoded 上游地址>
https://r.jina.ai/<上游地址>
```

代理存在的唯一原因：上游 `Access-Control-Allow-Origin` 是 `https://ysmapp.bj.10086.cn`，
且 `OPTIONS` 返回 403。PHP 侧统一补 CORS 头并做 10 分钟落盘缓存。

## 查询通道

页面**不做任何自动检测**，只在输满 11 位或点「查询话费」时发一次查询。

**所有通道同时并行**（多家中转是为了绕开不同网络下的污染/拦截），谁先拿到合法数据用谁，
其余请求立即 abort；全部失败时只有左下角「接口原始数据」浮窗保留各通道的真实原因。

| 通道 | 请求形式 | 超时 |
|---|---|---|
| 自定义代理（`localStorage.arpu_proxy`） | `POST {"phone":…}` | 8s |
| 自建 PHP 代理 `a781927.xqryfh.cn/_proxy.php` | `POST {"phone":…}`，旧代理不支持 POST 时退回 GET | 8s |
| CF 中转 `arpu-relay…workers.dev` | `GET ?url=<上游地址>` | 12s |
| CF 备用 `test.cors.workers.dev` | `GET ?<上游地址>` | 12s |
| Jina 中转 `r.jina.ai` | `GET <上游地址>` | 15s |

结果区只显示解析后的账单；原始响应（含失败原因）只出现在左下角浮窗，来源标签会写清楚
是哪个通道返回的（自建代理 / 自定义代理 / CF 中转 / CF 备用 / Jina 中转 / 本地缓存）。

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
- **限流**：每 IP 每分钟 60 次请求（一次查询只占 1 次），超限返回 `429` + `Retry-After`；
  `ping` 不计数。
- **单飞合并**：冷缓存下同一号码被并发请求时，只放一路回源，其余等待并复用它的结果。
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
| 查询提示「请求超时 / 全部通道失败」 | 先 `curl -I https://a781927.xqryfh.cn/_proxy.php?ping=1` 确认代理在线；代理挂了会在 8s 后自动改走 CF 中转 |
| 页面提示「请求过于频繁」 | 触发每 IP 每分钟 30 次限流，等一分钟后重试 |
| 页面显示「无记录」、原始数据是 `暂无可办理档位` | 上游业务返回，表示该号码当前没有可办理档位；不是代理故障 |
| 浏览器控制台报 CORS | 确认页面来源在白名单内；改了域名需同步更新 `_proxy.php` 的 `ALLOWED_ORIGINS` |
| 换了服务器证书后部署失败 | 更新 `deploy-ftp.yml` 里的 `FTP_PIN` 指纹 |
| 修改前端后用户仍看到旧版 | 强制刷新，或访问 `index.html?v=<时间戳>` |
| 左下角来源显示「CF 中转」 | 自建代理不可达时的回退通道；国内网络访问 `*.workers.dev` 通常被 DNS 污染，看到这行说明主通道也挂了 |

## 备注

- 手机号在代理侧只参与缓存键（`md5(上游URL)`），不写业务日志；前端本地缓存键为号码哈希。
- `*.workers.dev` 在国内 DNS 被污染，所以除了 CF 中转还同时挂了 `test.cors.workers.dev`
  和 `r.jina.ai`，并行竞速；哪家先回合法 JSON 就用哪家，其余立即中止。
- 2026-11-20 已移除自动检测（右上角状态灯、每分钟 ping、整点轮询选最快通道、`arpu_channel_win` 缓存）：
  这些探测在网络不通时会持续报错，且与查询结果无关。
