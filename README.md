# Herta Flash Game Framework
作者：herta

这是一个用于 老 Flash 页游（AMF 协议）复刻 / 私服 / 本地运行 的最小可扩展框架。
设计目标不是“完全复刻原服务器”，而是：

在不重写客户端的前提下，用最少的服务端逻辑，让 SWF 正常运行、可进入主界面、可战斗、可跳过战斗。

## 设计理念（非常重要）

客户端（SWF）是主角

大部分资源、UI、动画、逻辑都在 SWF 内

不试图把所有东西拆出 SWF

服务端只做三件事

接收 AMF

生成结构正确的 AMF 返回

持久化最小状态（DB）

AMF ≠ 配置

AMF 是“运行时状态与结果”

配置/资源尽量静态化或在 SWF 内

## 线上路径基准（以真实会话为准）
页面/文本接口：`/pvz/index.php/...`

AMF 网关：`POST /pvz/amf/`（`Content-Type: application/x-amf`）

## 目录结构（当前实现）
```text
server/game_root/
  client/
    swf/
      main.swf
      libs/
        ui.swf
        battle.swf
        effects.swf
      patches/
        main.patched.swf     # 可选：打过补丁的 SWF

    assets/
      images/
        avatar/
        events/
        icons/
      sounds/
      fonts/

    locale/
      zh_CN.json

    manifest/
      client_manifest.json  # 可选：客户端资源清单

  server/
    public/
      router.php            # PHP built-in server 路由（仅本地开发时用）
      index.php             # /pvz/index.php/... 的动态接口（文本/XML）
      amf.php               # POST /pvz/amf/ 的 AMF 网关入口（二进制）
      assets/               # 可选：对外静态资源（头像/活动图等）
      config/               # 可选：对外静态配置
      php_xml/              # 可选：对外静态 xml
      manifest/             # 可选：对外清单
      skills.json           # 可选：调试/静态数据（当前在 docroot 可直接访问）
      spec_skills.json      # 可选：调试/静态数据（当前在 docroot 可直接访问）

    app/
      AMF.php               # (规划) AMF 解码 / 编码 / dispatch
      DB.php                # (规划) 数据库（PDO / SQLite / MySQL）
      handlers/
        message.php
        apiskill.php
        shop.php
        battle.php
      schemas/
        api.message.gets.schema.json
        api.apiskill.getAllSkills.schema.json

    launcher/               # 本地启动脚本/配置（web 模式）

  tools/
    extract/                # 解 AMF / 解 SWF
    patch/                  # 自动 patch SWF（域名/开关）
    build/

  runtime/
    logs/
    cache/
```

## 核心模块说明
### client/

Flash 客户端资源区。

swf/
原始或补丁后的 SWF 文件

assets/
从 SWF 外置出来的资源（可选）
例如 avatar、活动图、banner

manifest/
如果你想做热更新，这里放资源清单；不做也完全 OK

### server/

AMF 服务端。

#### server/public/router.php（仅开发用）
当你使用 `php -S` 内置服务器时，用它做路由分发和资源映射。

资源映射（web 模式，方便离线跑 SWF）：
- `/pvz/index.php/...` -> `server/public/index.php`
- `/pvz/amf/` -> `server/public/amf.php`
- `/pvz/*` 静态资源优先从工作区缓存目录读取（例如 `D:\Hreta_working\cache\youkia`、`D:\Hreta_working\cache\pvz`），找不到再回退到 `server/public/*`

#### server/public/index.php
处理 `GET /pvz/index.php/...` 这类启动链/页面/文本接口（通常是 text/xml/text/plain）。

不是 AMF 网关。

#### server/public/amf.php
处理 `POST /pvz/amf/`（`application/x-amf`）。

建议把 AMF 的 “解包 -> dispatch -> 封包” 都放在这条链路，避免被其它输出污染。

#### server/app/AMF.php（规划）

负责：

解码 AMF

拿到 targetURI（如 api.message.gets）

dispatch 到 handler

把 handler 返回的 PHP array/object 编码为 AMF

#### server/app/handlers/

每个模块一个文件，例如：

api.message.* → handlers/message.php

api.apiskill.* → handlers/apiskill.php

handlers 不做复杂逻辑，只做：

读 DB

组装返回结构

保证 schema 正确

#### server/app/schemas/

从真实 AMF body 样本归纳出的 结构模板。

用途：

校验返回字段是否缺失

防止 Flash 因字段/类型错误静默崩溃

## AMF 与战斗说明

本项目默认 战斗为“预演模式（B 模式）”

服务端一次性算完整场战斗

返回 battle log + final result

客户端只播放或直接跳过

不实现客户端实时回合计算

不要求完全还原原数值，只要求：

结构正确

状态闭环

## 数据库设计原则

DB 存 最小状态

玩家资源

背包数量

技能等级

战斗结果

不存整包 AMF

AMF 始终由 handler 动态生成

## 开发流程建议（重要）

先跑启动链

message

skill

user / warehouse

再跑最小战斗闭环

enter battle

skip battle

settle

最后再考虑复杂系统

活动

排行榜

PVP

## 你不需要做的事（刻意不做）

不做完整原服复刻

不做客户端重写

不把所有配置从 SWF 拆出来

不强求字段 100% 复刻（结构优先）

## 适用场景

Flash 页游私服

单机可玩版本

协议研究

老游戏存档/复活

## 作者说明

这个框架是为 herta 自用而写，
偏向 工程可控 / 心智负担低 / 能跑起来，
而不是“考古级 100% 还原”。
