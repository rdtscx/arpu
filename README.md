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
| 地址同步 | `/opt/arpu/publish.sh` + `arpu-publish.timer` | 开机 15s 后开始，每 20s 一轮；地址变了且 `?ping=1` 通才更新仓库 `current.json` 并 push（deploy key `arpu-publisher`） |
| 看门狗 | `/opt/arpu/watchdog.sh` + `arpu-watchdog.timer` | 开机 60s 后开始，每 2 分钟查一次：代理 ping 不通 → 重启代理；隧道地址访问不通 → 重启隧道 |

四个单元均 `systemctl enable`，**开机自动运行**；`arpu-tunnel` 设了 `StartLimitIntervalSec=0`，
不会因为反复重试被 systemd 熔断。

前端不写死地址：每次查询先读同源 `current.json`，再回退到 localStorage 里上次成功的地址，
都不行就重新拉一次配置再试，三次都失败才报错。

### 重启后会发生什么（2026-11-20 实测）

```
18:27:23  reboot
18:28:25  cloudflared 重新连接拿到新地址，publish 已把它写进 current.json
18:29:xx  GitHub Pages 重新部署完成
          → 页面无需任何人工操作，直接可查（旧地址的白名单也仍可用）
```

quick tunnel 的域名重启后会变，所以不要把它当成固定域名写死在使用方；
要做完全固定的域名，需要域名接入 Cloudflare 后改用 named tunnel（或在能占用 80/443 的主机上直接签证书）。

## 查询流程

1. 页面加载后空闲时预取 `current.json` 并 ping 一次隧道，预热 TLS/HTTP2 连接；
   输满 11 位时直接复用。配置超过 700ms 未返回则先用上次成功的地址开跑，不把配置往返算进等待
2. `POST {phone}` 到代理；旧代理不支持 POST 时退回 `GET ?phone=`
3. 拿到数据后渲染账单；10 分钟内的同号码命中本地缓存
4. 结果区只显示解析后的账单，原始响应（含失败原因）只在左下角「接口原始数据」浮窗

## 查询耗时构成（2026-11-21 实测）

| 环节 | 耗时 | 现状 |
|---|---|---|
| 读取 `current.json`（GitHub Pages） | 0.5~2.5s | 已在加载时预取 |
| Cloudflare quick tunnel 建连 | 1~2s | 已预热复用 |
| 代理命中 10 分钟缓存 | +0~1s | 正常 |
| 代理未命中、回源 10086 | 6~13s | **主要瓶颈**；需在服务端并行化/延长缓存才能改善 |

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
