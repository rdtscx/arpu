# arpu · 北京移动资费 / ARPU 查询

静态前端（GitHub Pages）+ 自建 PHP 跨域代理（FTP 自动部署）。

## 架构

```
浏览器  https://rdtscx.github.io/arpu/
   │  GET /_proxy.php?phone=13xxxxxxxxx
   ▼
PHP 主机  https://a781927.xqryfh.cn/_proxy.php     ← 缓存 + 补 CORS 头
   │  GET ...customerValidateAndConsumption.action?submitPhoneNO=...
   ▼
ysmapp.bj.10086.cn   （ACAO 固定为自己域名、OPTIONS 预检 403，浏览器不能直连）
```

代理存在的唯一原因：上游 `Access-Control-Allow-Origin` 是 `https://ysmapp.bj.10086.cn`，
且 `OPTIONS` 返回 403。PHP 侧统一补 `Access-Control-Allow-Origin: *` 并做 10 分钟落盘缓存。

## 前端通道

页面里点右上角「自建代理 / 跨域代理」可切换通道，`index.html` 默认三条：

| 通道 | 请求形式 | 说明 |
|---|---|---|
| 自建代理 | `_proxy.php?phone=` | 主通道，命中服务器缓存 |
| 透传通道 | `_proxy.php?url=<encoded>` | 直接透传上游 URL（仅白名单主机），可绕过按号码缓存 |
| 重试通道 | `_proxy.php?phone=&_r=<时间戳>` | 带随机参数重试，跳过 CDN/中间层缓存 |

主通道失败会自动并行尝试后两条，任一条返回合法 JSON 即采用。自定义代理存
`localStorage.arpu_proxy`，填了则优先走自定义地址。

## 代理端点

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/?phone=1xxxxxxxxxx` | 主查询，内部拼上游参数 |
| `GET` | `/?url=<encoded>` | 透传，仅允许 `ysmapp.bj.10086.cn`（防开放代理） |
| `HEAD` | `/?ping=1` | 前端连通性探测 |
| `OPTIONS` | 任意 | 预检，204 |

响应头：`X-Cache: HIT | STALE | MISS`、`X-Cache-Age`。
缓存策略：10 分钟内直接命中；6 小时内过期仍先返回旧值并后台刷新（stale-while-revalidate），
避免上游变慢时页面一直转圈。

## 部署

- 前端：push 到 `main` 后由 GitHub Pages 自动发布（源：`main` / `/`）。
- 代理：`Deploy to FTP` 工作流在 push 后执行
  1. `php -l _proxy.php` 语法校验（不通过则中止，不会覆盖线上文件）
  2. 显式 FTPS 上传（`--ssl-reqd` + `--pinnedpubkey` 钉死服务器公钥指纹，指纹不符即失败）
  3. 线上冒烟测试：`?ping=1`、真实号码、CORS 头

需要的仓库密钥：`FTP_SERVER`、`FTP_USERNAME`、`FTP_PASSWORD`。

## 排错

| 现象 | 原因 / 处理 |
|---|---|
| 页面显示「无记录」、原始数据是 `暂无可办理档位` | 上游业务返回，表示该号码当前没有可办理档位；不是代理故障 |
| 页面提示「自建代理不可用」 | 先 `curl https://a781927.xqryfh.cn/_proxy.php?ping=1`，再看 FTP 工作流是否成功 |
| 换了服务器证书后部署失败 | 更新 `deploy-ftp.yml` 里的 `FTP_PIN` 指纹 |
| 修改前端后用户仍看到旧版 | 强制刷新，或访问 `index.html?v=<时间戳>` |

## 备注

- 手机号只在代理内存/临时目录中参与缓存键（`md5(上游URL)`），不落业务日志。
- 曾尝试 Cloudflare Worker 作为替代后端：`*.workers.dev` 在国内 DNS 被污染、`dash.cloudflare.com`
  返回 403，无法部署与访问，故保留 PHP 方案。
