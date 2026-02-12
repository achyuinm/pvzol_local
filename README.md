# pvzol_local

一个用于老 Flash（AMF）网页游戏的本地复刻/重建项目，目标是把客户端（SWF）在本地环境中跑通，并逐步补齐所需的服务端接口。

## 当前状态
- 已拆分两条入口：页面/文本接口 `GET /pvz/index.php/...` 与 AMF 网关 `POST /pvz/amf/`
- 目前 AMF 网关为占位，后续按抓包逐步实现各 API

## 结构概览
- `client/`：客户端资源（SWF/可选静态资源）
- `server/public/`：本地 HTTP 入口与路由（`index.php` + `amf.php`）
- `server/app/`：服务端逻辑（AMF dispatch、handlers、schemas）
- `server/launcher/`：本地启动脚本

## 说明
本仓库只提交框架与代码，不包含真实会话、抓包文件以及原始游戏资源。
