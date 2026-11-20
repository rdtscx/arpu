# arpu · 北京移动资费 / ARPU 查询

静态前端（GitHub Pages）+ 自建后端（云服务器上的 Python 代理，经 Cloudflare 隧道提供 HTTPS）。

## 架构

```
浏览器  https://rdtscx.github.io/arpu/
   │  POST /  {"phone":"13xxxxxxxxx"}        （页面先读同源 current.json 拿地址）
   ▼
Cloudflare 隧道  https://<随机>.trycloudflare.com     ← cloudflared 在服务器上常驻
   │
   ▼
VPS 103.236.93.69（NAT 只映射 49014→22，公网 80/443 不可用，所以走隧道）
   │  proxy.py 监听 127.0.0.1:8787：白名单 CORS + 每 IP 60 次/分钟限流 + 10 分钟缓存
   ▼
ysmapp.bj.10086.cn   （ACAO 固定为自己的域名、OPTIONS 403，浏览器不能直连）
```

代理存在的唯一原因：上游 `Access-Control-Allow-Origin` 是 `https://ysmapp.bj.10086.cn`，
且 `OPTIONS` 返回 403。

## 后端部署（在 VPS 上，一次性）

| 组件 | 路径 | 说明 |
|---|---|---|
| 代理服务 | `/opt/arpu/proxy.py` + `arpu-proxy.service` | Python 标准库实现，只监听 127.0.0.1:8787 |
| 隧道 | `arpu-tunnel.service`（cloudflared） | `cloudflared tunnel --url http://127.0.0.1:8787`，日志在 `/opt/arpu/tunnel.log` |
| 地址同步 | `/opt/arpu/publish.sh` + `arpu-publish.timer` | 每分钟比对隧道地址，变了就更新仓库 `current.json` 并 push（写入用仓库 deploy key） |

前端不写死地址：每次查询前读同源的 `current.json`（`{"proxy":"https://…"}`），
隧道重启换了地址会自动跟上；地址刷新后仍失败才会报查询失败。

## 查询流程

1. 输满 11 位或点「查询话费」→ 读 `current.json` 取代理地址
2. `POST {phone}` 到代理；旧代理不支持 POST 时退回 `GET ?phone=`
3. 拿到数据后渲染账单；10 分钟内的同号码命中本地缓存
4. 结果区只显示解析后的账单，原始响应（含失败原因）只在左下角「接口原始数据」浮窗

上游偶尔返回 `errorCode:"error"`、`errorMsg:"查询用户信息失败"` 但 `entity` 里数据完整，
前端按 `entity` 解析，不看 `errorCode`。

## 排错

| 现象 | 处理 |
|---|---|
| 查询失败，浮窗里是「请求超时 / Failed to fetch」 | `curl -s https://<隧道地址>/?ping=1` 看隧道和代理是否在线；`systemctl status arpu-proxy arpu-tunnel` |
| 隧道地址变了但页面还在用旧地址 | `systemctl status arpu-publish.timer`；手动 `systemctl start arpu-publish.service` |
| 浏览器报 CORS | 代理只放行 `https://rdtscx.github.io`；换了站点域名要改 `proxy.py` 的 `ALLOWED_ORIGINS` |
| 显示「无记录」 | 上游返回的 `entity` 里没有 `arpu_1..6`，表示该号码确实没有可查档位 |
| 请求过于频繁 | 代理每 IP 每分钟 60 次 |

## 备注

- 手机号只在代理内存里做缓存键（SHA-256），不写日志；前端缓存键是号码哈希。
- 2026-11-20 已移除此前的所有中转方案（PHP 代理 `_proxy.php`、Cloudflare Worker、
  `test.cors.workers.dev`、Jina）以及自动检测（状态灯 / 每分钟 ping / 整点轮询），
  只保留这台服务器。
