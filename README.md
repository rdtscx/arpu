# 运营商资费监控 · 数据展示站

本仓库是一个**自包含**的运营商资费监控项目：定时抓取移动 / 联通 / 电信 / 广电四家运营商官网的公开资费数据，
自动构建静态展示站点，并可选推送变化到钉钉 / 飞书 / 企业微信 / 邮箱。

抓取、构建、推送全部由本仓库的 GitHub Actions 完成（公开仓免费且不限量），
**不含任何代码密钥**——所有凭据走环境变量与 Secrets。

> **调度说明**：四站由主站 `mobile-build.yml` 统一定时（每 2 小时一轮），每轮开始时
> 同一个工作流的 `subsites` 作业一并派发联通 / 电信 / 广电，三家与移动保持同一刷新频率；
> `mobile-heartbeat.yml` / `ops-heartbeat.yml` / `build-watchdog.yml` 按数据陈旧度兜底，
> 绕开 GitHub schedule 丢触发 / 数小时延迟。

## 在线访问

- 中国移动：<https://rdtscx.github.io/tariff/>
- 中国联通：<https://rdtscx.github.io/tariff/unicom/>
- 中国电信：<https://rdtscx.github.io/tariff/telecom/>
- 中国广电：<https://rdtscx.github.io/tariff/gb/>

## 数据说明

- **移动**：全网资费（全国）+ 31 省，含套餐 / 加装包 / 营销活动 × 个人 / 政企 全分类
- **联通 / 电信 / 广电**：全国 + 31 省资费（广电另含独立区划的广州 / 深圳）
- 四家均由本仓库的 GitHub Actions 统一调度抓取并自动部署更新（主站每 2 小时一轮，
  联通 / 电信 / 广电跟随主站节拍由主站派发，心跳与看门狗按陈旧度兜底），无需外部依赖
- 数据均为运营商官网公示的公开信息，仅供参考，办理业务请以营业厅官方信息为准

## 站点功能

省份切换与搜索、分类筛选、资费变化历史追踪、**CSV 导出**、**收藏关注**（保存在本机浏览器）。


---

## 📮 接入你自己的通知通道

fork 本项目后，填入**你自己的**凭据，资费变化就能推送到**你的**
**钉钉 / 飞书 / 企业微信 / 邮箱**。四个通道任意组合，配几个推几个。

**完整教程见 → [NOTIFY_SETUP.md](NOTIFY_SETUP.md)**

| 通道 | 要填的 Secrets |
|---|---|
| 🔵 钉钉 | `DINGTALK_WEBHOOK` + `DINGTALK_SECRET` |
| 🟦 飞书 | `FEISHU_WEBHOOK` + `FEISHU_SECRET` |
| 🟩 企业微信 | `WECOM_WEBHOOK` |
| 📧 邮件 | `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `MAIL_TO` |

三步速览：

1. 在对应群里添加机器人，拿到 Webhook 地址（邮件则用邮箱授权码）
2. 仓库 **Settings → Secrets and variables → Actions** 添加上表变量
3. Actions 运行 **Tariff Notify**，约 14 秒后收到；或本地 `python3 scripts/notify.py --test`

推送示例：

```
- 🔵 移动：新增 340、下架 284、修改 1196
  - 湖南：无变化

**合计**：新增 340、下架 284、修改 1196
🔗 查看完整资费站
```

> 🔒 密钥只存在你自己的仓库里，加密存储且**只写不读**，作者与他人均无法查看。
> 抓取与推送脚本**不含任何硬编码凭据**，全部通过环境变量注入。

## 自动化说明

抓取与推送**全部在本仓库内完成**，跑于 GitHub Actions（公开仓免费、不限量）：

| 工作流 | 频率（北京） | 说明 |
|---|---|---|
| `mobile-build.yml` | 每 2 小时（0/2/4…/22 点） | 移动抓取 + 站点构建 + 自动部署，并派发三家子站 |
| `telecom-build.yml` | 跟随主站（每轮派发） | 电信抓取 + 站点构建 + 自动部署 |
| `gb-build.yml` | 跟随主站（每轮派发） | 广电抓取 + 站点构建 + 自动部署 |
| `unicom-build.yml` | 跟随主站（每轮派发） | 联通抓取 + 站点构建 + 自动部署 |
| `mobile-heartbeat.yml` | 常驻循环（320 分钟自续） | 移动心跳：按线上数据陈旧度触发下一轮，不依赖 GitHub schedule |
| `ops-heartbeat.yml` | 常驻循环（320 分钟自续） | 电信 / 联通 / 广电心跳：按线上数据陈旧度触发下一轮，不依赖 GitHub schedule |
| `announce-sync.yml` | 每 1 小时（:17 / :47） | 移动公告同步 |
| `dingtalk-summary.yml` | 每日 08:00 / 20:00 | 移动资费变化汇总推送 |
| `build-watchdog.yml` | 每 30 分钟 | 看门狗：取消卡死超 2 小时的构建运行，防止并发组排死导致数据停更 |

> 说明：2026-11-20 起三家子站不再各自依赖 schedule，统一由主站每轮抓取的 `subsites`
> 作业派发（只跳过已有运行在跑的站点，避免并发组 cancel-in-progress 打断联通约 75 分钟的
> 单轮抓取）；`ops-heartbeat.yml` 与 `build-watchdog.yml` 降为 3 小时陈旧度兜底。
> probe / verify / diag-dns 等诊断类一次性工作流已删除，避免触发失败产生通知邮件。

## UI 保护约定

自动化任务只允许提交**数据产物**（`data/`、`pipelines/mobile/snapshots/`、`tariff-fetch-source.zip`），
界面文件（`index.html`、`nav.html`、`push-center.html`、`manifest.json`、`version.json`、
`unicom/`、`telecom/`、`gb/` 等）
只由人工改动：

- 各工作流的提交步骤一律使用带路径限定的 `git add`，不用 `git add -A`；
- 提交前跑 `python3 scripts/guard_ui.py`，一旦发现界面文件被改动就还原并打日志；
- `history.json` 用 `scripts/merge_history.py` 做并集，只增不减；
- 人工改动界面时先 `git pull --rebase`，不要强制推送。
