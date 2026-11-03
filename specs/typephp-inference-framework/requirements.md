# 需求文档 — TypePHP 多后端推理框架（Kapitsa 引擎移植）· 评审稿 v0.2

> 文档性质：**评审稿（决策已闭合）**。本稿为产品设计需求文档（PRD），仅用于评审，**不含任何代码实现**。
> 编写：司南（资深产品经理 Agent）　·　日期：2026-11-01　·　状态：评审通过，决策闭合（v0.2）
> 参考来源：UNIKERN `design.md` / `requirements.md`、Kapitsa 0.2.25 `README.md` / `AGENTS.md`、AdaptiveCpp-develop `README.md`、llama.cpp/ggml 后端架构（DeepWiki）、TypePHP 源码 `C:\git\php\typephp`（PHP→原生 AOT 编译器，用户自有项目）。

---

## 文档信息

| 项 | 内容 |
|---|---|
| 版本 | v0.2（评审稿，决策闭合） |
| 作者 | 司南（产品） |
| 日期 | 2026-11-01 |
| 状态 | **评审通过 — 8 项决策已闭合，可进入 Phase 0 探针** |
| 变更记录 | v0.1 初稿（5 份参考 + 方案对比 + 开放决策）→ v0.2 闭合决策：修正"TypePHP→C++ 为原生链接非 FFI"、新增推理客户端编排层为一等架构支柱、引入 Kakashi（复制忍者）定位与 `typephp-kakasi` 命名、重构分阶段路线 |
| 评审记录 | 用户逐条确认第 15 节 8 项决策（见第十五节） |

**路由说明（专家流程）**：本需求命中「AI 推理引擎 / 技术基础设施」信号，无资金、无强合规（隐私/个保）硬底线信号；按通用 PRD 流程处理。Kapitsa 的"输出逐位一致"门槛视为本产品的"AI 质量验收"等价物，已在非功能需求中单列。v0.2 已闭合全部开放假设，下一步为 Phase 0 最小探针（用户已确认"可以做"）。

---

## 一、背景与目标

### 1.1 背景（为什么现在做）

- **本地 LLM 推理已成标准场景**，但现有引擎各有边界：
  - **Kapitsa**：Apple Silicon 上 decode 最快（KFlash 树状投机解码），但**仅限 Apple Silicon**（Metal + Neural Engine），无 Linux/Windows，且 Neural Engine 路径本质不可跨平台。
  - **llama.cpp/ggml**：事实标准、后端最广（CUDA/Metal/Vulkan/SYCL/HIP/OpenCL/RPC），但其"多后端"是**构建期 CMake 开关 + 插件 .so**，并非"一次编译、单二进制跨厂商运行"；核心 kernel 调优仍偏 CPU/SIMD 心智。
  - **UNIKERN**：证明了"以统一数学表示（KML）把内核定制归约为确定性优化问题、向多厂商后端生成代码"的可行性，但其范围是**算子内核求解**，不直接是推理引擎。
  - **AdaptiveCpp（原 hipSYCL）**：证明了"**单一源码、单一二进制即可 offload 到 CPU/NVIDIA/AMD/Intel/Apple 全部设备**"，支持 SYCL、C++ 标准并行、PCUDA（CUDA/HIP 方言编译为跨厂商单二进制）。
- **TypePHP（用户自有，`C:\git\php\typephp`）**：把 PHP **编译为 C++ 再链接**的 AOT 编译器，产物为原生二进制（+ WASM），运行时不依赖 PHP 解释器。**关键事实（已核实源码 `docs/zh-cn/MIXED_CPP_PHP.md`、`project-layout.md`）：TypePHP 调用 C++ 是链接期原生调用，不是运行时 FFI。** 具体两条零开销路径：① `*.stub.php`（空实现）+ `*.cc`（`php_` 前缀）桥接；② `project.yml` 的 `objects`/`link-libs`/`link-paths`/`include-paths` 直接把预编译 C++ 目标文件/库链进二进制。**因此整引擎（PHP 编排 + SYCL 内核）编译/链进同一原生二进制，框架逻辑与 GPU 内核之间无运行时 FFI 封送层**——这正是本框架相对"脚本语言 + FFI"方案的结构性优势。

### 1.2 目标

构建一个**以 TypePHP 编写、经 AOT 编译为单一原生二进制**的推理框架，满足：

1. **一次编译，多后端 GPU 运行**：一份 TypePHP 源码 + 一份 AdaptiveCpp SYCL/PCUDA 内核 → 一份原生二进制，在运行时按设备选择 offload 到 NVIDIA / AMD / Intel / Apple / CPU（不针对每个后端重编译、不依赖 FFI）。
2. **实现 Kapitsa 引擎的可移植版本**：把 Kapitsa 的核心能力（KFlash 树状投机解码、分词器/聊天模板、KV cache、MoE 专家流式、多设备 split、SSD 前缀缓存、OpenAI/Anthropic 兼容 API）重做成**跨后端**的引擎层，而非仅限 Apple。
3. **性能与正确性可验收**：单流 decode 速度对齐 Kapitsa（Apple 平台）与 llama.cpp（其他平台）；输出与 plain decoding **逐位一致**（bitwise parity gate）。
4. **【新增·一等目标】推理客户端工程化**：低端/受限设备（小显存、集成显卡、CPU 为主）的运行效率，由**推理客户端编排层（软件工程）**解决，而非仅靠 GPU 算子。编排层负责设备选择、内存预算、KV 量化、offload 决策、CPU+GPU 异构调度、搬运/计算重叠——它必须与可移植内核**同等重要**地设计（详见 1.4 与 FR-18）。

### 1.3 成功指标（口径）

| 指标 | 目标值 | 口径 / 统计方式 | 来源基准 |
|---|---|---|---|
| 单流 decode 速度 | ≥ 各平台对标引擎（Kapitsa / llama.cpp）的 90% | 4K prompt + 512-token 回答，T=0.6，空闲 GPU，A/B 交错 | Kapitsa 各 Mac 表 |
| prefill 速度 | 不低于对标引擎 | 同上 | 同上 |
| 输出一致性 | 逐行 bitwise identical | `logit_dump` 新旧构建 + `logit_compare`，阈值 = 2× 该模型噪声底 | Kapitsa AGENTS.md |
| 单二进制后端覆盖数 | ≥ 4（CPU + NVIDIA + AMD + Intel，Apple 可在同一 SYCL 路径覆盖） | 每个后端跑通同一二进制的最小模型 | AdaptiveCpp 能力 |
| 模型加载时间（16K 续聊冷启动） | 接近 Kapitsa 的 0.16s 量级（**估算**，需实测） | `--disk-cache` 命中后重启 | Kapitsa README |
| 工作集内存 | 按"Mac 尺寸表"思路给出每机型预算 | config.json + 权重 + drafter + 8K 上下文 | Kapitsa 模型表 |
| **受限设备效率（新增）** | 在用户本机（Intel/AMD/Apple）**最大化本地资源利用率**，相对"裸跑单后端"有可测量提升 | 同模型同硬件，开启编排层（量化/KV 压缩/CPU 卸载/重叠）前后对比 tok/s 与显存占用 | Phase 2 复测 |

> 注：速度目标为**相对对标**而非绝对值，因绝对数值强依赖具体机型/后端/模型；绝对值在 Phase 0 探针后由基准流程固化。

### 1.4 产品定位与代号（Kakashi / 复制忍者）

- **项目代号**：`typephp-kakasi`，产品名 **TypePHP 多后端推理框架**。
- **隐喻来源**：卡卡西（Kakashi）是《火影忍者》中的上忍"**复制忍者**（Copy Ninja）"，能以写轮眼快速复制对手忍术。
- **产品主张（双关）**：
  1. **快速把推理能力"复制"到不同 GPU 后端**：一次编译即可在 NVIDIA / AMD / Intel / Apple / CPU 上运行，像复制忍术一样把引擎"0-day 推广"到新硬件，不必为每个厂商重写。
  2. **借鉴不同推理引擎的加速方案**：像复制忍术一样，快速吸收 Kapitsa/KFlash、llama.cpp/ggml、Splash 等引擎的加速思路，沉淀为本框架的可移植能力。

---

## 二、名词与术语

- **TypePHP**：用户自有项目，将 PHP 源码 AOT 编译为 C++ 再链接为原生二进制（+ WASM）。本框架的**实现语言与编译管线**。**与 C++ 的互操作为链接期原生调用（stub+cc 桥接、或直接链接 .o/.a），不走 FFI**。
- **AOT**：Ahead-Of-Time 编译，本语境指 PHP → C++ → 原生机器码，产物脱离 PHP 运行时。
- **AdaptiveCpp / SYCL / PCUDA**：异构编程编译器。SYCL 为跨厂商编程模型；PCUDA 为 CUDA/HIP 方言的跨厂商编译，可产出**单二进制 offload 到多厂商设备**。本框架的**可移植计算基座**。
- **ggml backend 接口**：llama.cpp 的统一后端抽象（buffer type 接口 + backend 接口 + 调度器），本框架后端抽象的**设计范本**。
- **推理客户端 / 编排层（Orchestrator）**：本框架**新增的一等架构层**（见 FR-18）。负责设备探测与后端选择、内存预算、KV 量化决策、CPU+GPU 异构 offload 决策、批处理/并发、I/O 与计算重叠、专家/权重流式调度。**它不是"胶水代码"，而是低端设备效率的核心工程所在**。
- **KML / UNIKERN**：UNIKERN 的统一内核数学表示与求解平台（KML 原语树 → 可行域 F → 确定性代价 C → 唯一解 → 三后端代码生成 → Ledger 校准）。本框架**V2 可选"内核调优/求解层"**（除非 V1 性能有巨大提升，否则不提前引入）。
- **GGUF**：llama.cpp 的模型单文件格式（含权重、tokenizer、metadata）。本框架**默认模型格式**；不排除后期支持其他格式（决策 5）。
- **KFlash**：Kapitsa 的树状投机解码（tree speculative decoding）：一个 verify pass 检查 drafter 的候选树，保留大模型认可的**最长分支**，输出与 plain decoding 完全一致。
- **Drafter / Verifier**：投机解码中的"草稿模型"与"验证大模型"。本框架支持 DFlash / DFlash2 / DSpark / LiLiCorr 等已发布 drafter。
- **KV cache**：键值缓存；含量化支持，控制长上下文显存。
- **MoE / 专家流式**：混合专家模型；活跃专家按预算从 SSD/显存流式加载（Kapitsa `expert_store`）。
- **SSD 前缀缓存 / disk_format**：对话状态落盘、跨更新保留；格式变更需 bump 版本号。
- **split / 多设备放置**：单层模型跨多设备/多机放置（`plan` + 远端 `reserve`）。
- **GPU 锁**：加载模型/跑基准时独占 GPU 的 flock 机制（Kapitsa `GpuLock`），避免测量互相干扰。

---

## 三、需求范围

### 3.1 包含（MVP + 演进）

- 框架以 **TypePHP** 编写，经 **TypePHP AOT** 产出**单一原生二进制**（可选 WASM 产物）。**所有与 C++/SYCL 的调用均为原生链接，禁用运行时 FFI**（已在 FR-01 落地为硬约束）。
- **推理客户端编排层（一等层，FR-18）**：设备探测/后端选择、内存预算、KV 量化决策、CPU+GPU 异构 offload 决策、批处理/并发、I/O 与计算重叠、专家/权重流式调度。MVP 即提供基础编排，Phase 2 在本机最大化验证。
- **统一后端抽象层**（ggml 风格）：设备发现、内存分配、张量算子、计算图调度（按设备切分）。
- **可移植计算基座**：集成 AdaptiveCpp，以 **SYCL/PCUDA** 实现核心算子（GEMM/dequant/attention/rope/softmax），单一二进制运行时按设备 offload。内核以 `objects`/`link-libs` 直接链入二进制。
- **多后端矩阵**：MVP = CPU + NVIDIA + AMD + Intel + Apple（经 SYCL/PCUDA 统一路径覆盖，Apple 亦可走 Metal，但 MVP 默认 SYCL 路径以满足"一次编译"）。
- **Kapitsa 引擎层**：KFlash 树状投机解码、分词器/聊天模板、KV cache（含量化）、MoE 专家流式、多设备 split、SSD 前缀缓存、OpenAI/Anthropic 兼容 API + chat page。
- **模型生态**：GGUF 加载 + 目录式模型文件夹；`import` GGUF；catalog / `pull` / `list` / `rm` / `doctor`。
- **基准与一致性**：`bench`（prefill/decode tok/s）、输出一致性 gate。

### 3.2 不包含（本期不做 / 明确边界）

- **Neural Engine 专用路径**：Apple 独占、不可跨平台，MVP **不做**；仅作为 Apple 后端未来调优项（标注"后续版本"）。
- **GPUDirect Storage（NVIDIA 专属）、PTX/ISA 级直出**：V1+ 演进，MVP 走显式拷贝/mmap 流式（与 UNIKERN 场景 B 一致）。
- **完整 UNIKERN KML 求解器集成**：MVP 不依赖；V2 可选层，**除非 V1 性能有巨大提升否则不提前引入**（决策 6）。
- **训练 / 微调 / 量化训练**：不做；仅消费现成量化权重（GGUF）。
- **视觉/多模态完整能力**：MVP 以文本为主；图像输入作为 V1 演进（受可移植 kernel 覆盖约束）。
- **远程/集群默认开启**：split 需显式启用，非默认。

---

## 四、方案对比与选型（核心）

> 本节的焦点是"**一次编译、多后端 GPU 运行**"如何落地，以及"**推理客户端编排层为何是一等工程**"。

### 4.1 候选方案（机制选型）

| 方案 | 思路 | 实现成本 | 复杂度 | 体验（可移植性） | 风险 | 上线时间 |
|---|---|---|---|---|---|---|
| **A：AdaptiveCpp SYCL/PCUDA 单基座（原生链接）** | TypePHP→C++→原生二进制；计算内核用 SYCL/PCUDA 写，**单一二进制**运行时按设备 offload；PHP 与 C++ 经 tpc 原生链接（stub+cc / objects），无 FFI | 中 | 中 | **优（真正一次编译多后端）** | 中（tpc+acpp 联合构建；SYCL 性能 vs 手写） | 快 |
| **B：ggml 风格插件后端** | 仿 ggml：定义统一 backend 接口；每个后端编译为静态库或动态 .so，运行时注册加载 | 高（每个后端一份原生 kernel） | 高 | 中（需为每后端构建，或发 .so 包） | 中（维护多份 kernel） | 慢 |
| **C：TypePHP 按后端交叉编译** | 为每个目标后端分别编译出不同二进制 | 低 | 低 | 差（违反"一次编译"） | 低 | 最快 |

### 4.2 推荐方案

**推荐 = 方案 A 为可移植基线，吸收方案 B 的"后端接口 + 调度器"作为内部抽象，厂商调优内核作为 V1 插件演进；且框架与内核全部经 TypePHP 原生链接，禁用运行时 FFI。**

理由：
1. **方案 A 直接命中"一次编译、多后端"硬要求**——AdaptiveCpp 已验证单二进制跨厂商 offload，无需为每个 GPU 重编译。
2. **原生链接（非 FFI）是结构性优势**：TypePHP 把 PHP 编译成 C++ 再链接（`MIXED_CPP_PHP.md` / `project-layout.md`），SYCL 内核以 `objects`/`link-libs` 直接链入，框架逻辑与 GPU 内核间无运行时封送层。对比"Python/PHP + FFI 调 C++"方案，本路线省去 FFI 调用开销、类型封送与启动初始化成本（原 v0.1 RK-01 的"FFI 开销"风险因此移除）。
3. **方案 B 的 backend 接口/调度器是工业验证的"引擎与硬件解耦"范式**（llama.cpp 事实做法），本框架**内部采用该抽象**让引擎逻辑与后端无关；但**默认实现路径走 A 的 SYCL/PCUDA**，避免 B 的"每后端维护一份手写 kernel"高成本。
4. **方案 C 直接违反需求**，排除。
5. **演进**：当某后端需压榨极致性能时，允许以**原生 kernel 插件**（静态编译或动态 .so）覆盖可移植 SYCL 内核——变为 B 的子集，但**默认分发仍是单二进制**。

### 4.3 架构分层（含推理客户端编排层）

```
┌─────────────────────────────────────────────────────────────┐
│ App / CLI / HTTP Server 层        （TypePHP → 原生，无 PHP 运行时） │
├─────────────────────────────────────────────────────────────┤
│ ★ 推理客户端编排层 Orchestrator  （TypePHP → 原生）【一等层 FR-18】 │
│   设备探测/后端选择 · 内存预算 · KV 量化决策 · CPU+GPU 异构 offload  │
│   批处理/并发 · I/O↔计算重叠 · 专家/权重流式调度 · 低端设备效率核心  │
├─────────────────────────────────────────────────────────────┤
│ 引擎层：Kapitsa 可移植移植        （TypePHP → 原生）              │
│   KFlash 树状投机 · 会话/执行 · 分词器/模板 · KV cache            │
├─────────────────────────────────────────────────────────────┤
│ 后端抽象层（ggml 风格）           （TypePHP → 原生）              │
│   buffer_type · backend 接口 · 计算图调度器(按设备切分)           │
├─────────────────────────────────────────────────────────────┤
│ 可移植内核层（C++ / SYCL via AdaptiveCpp）【原生链接，非 FFI】    │
│   GEMM/dequant · attention · RoPE · softmax · norm             │
├─────────────────────────────────────────────────────────────┤
│ 厂商调优内核插件（V1，动态 .so 覆盖热点，可选）                   │
└─────────────────────────────────────────────────────────────┘
              ▼  tpc（PHP→C++/cl·g++） + acpp（SYCL→设备码）联合构建
              ▼  单一原生二进制：libphp + libphpx + SYCL 运行时 + 内核
```

**为什么编排层是一等层（决策 4 的工程化表达）**：低端/受限设备的运行效率，不能靠 GPU 算子本身解决。一张小显存卡跑不动大模型，靠的是"卸载多少到 CPU、KV 压到几 bit、哪些专家从 SSD 流式、搬运与计算如何重叠"——这些都是**软件工程决策**，由编排层承担。因此编排层与可移植内核在架构上并列，均属 Must。

### 4.4 演进路径

- **Phase 0（探针，已确认"可以做"）**：验证 `tpc + acpp` 能联合构建出单二进制，并在 ≥2 个后端（如 CPU + Intel GPU，或 CPU + Apple）跑通一个 SYCL matmul，测原生调用开销。不通过则重议路线。
- **Phase 1（可移植引擎 / MVP）**：A 单基座（SYCL/PCUDA 可移植内核，原生链接）+ ggml 风格内部后端抽象 + 推理客户端编排层（基础）+ Kapitsa 引擎核心 + GGUF + server。**"第一次重做，验证本推理引擎可用"**——正确性优先、能在目标硬件跑起来。
- **Phase 2（本机最大化利用复测 / V1）**：在用户**自有 Intel / AMD / Apple** 三机型上，复测并最大化本地资源利用率；按需引入厂商调优内核插件弥补 SYCL 与手写的差距。**"只是第一个版本能在本机上最大化利用资源的复测"**。
- **Phase 3（借鉴与扩展 / V2+）**：参考其他引擎（Splash 等）加速思路进一步提速；UNIKERN KML 求解/Ledger 集成**仅当 V1 性能差距巨大时**才引入。

---

## 五、用户角色与使用场景

| 角色 | 身份 | 核心目标 | 典型场景 |
|---|---|---|---|
| 终端开发者（Agent 用户） | 用本地模型驱动 opencode/Claude Code/Codex/pi 的开发者 | 本地低延迟、输出一致的推理 | `engine serve` 起服务，Agent 通过 OpenAI/Anthropic API 调用 |
| 模型运维者 | 管理本机模型与硬件适配 | 知道什么模型能跑、怎么下载 | `doctor` / `pull` / `list` / `rm` / `import` |
| 框架开发者 | 本项目维护者（我们） | 在 TypePHP 上迭代引擎、后端与编排层 | 改内核、加后端、跑基准 |
| 集群/多机用户 | 多设备协同 | 大模型跨机放置 | `--split` / cluster（V1+） |

**用户旅程（感知→进入→操作→完成→离开）**：安装二进制 → `doctor` 看硬件与适配模型 → `pull` 下载模型 → `serve` 起服务 → Agent/聊天页调用 → 多轮对话（SSD 缓存）→ 退出。

---

## 六、功能需求（FR）

> 每条含编号、描述、业务规则、GWT 验收（Given/When/Then）、优先级（MoSCoW）、溯源。v0.1 的 **[假设]** 已在 v0.2 闭合。

### FR-01　TypePHP 源码与 AOT 编译管线（原生链接，禁 FFI）　【Must】

1. 系统 SHALL 以 TypePHP（PHP）编写框架主体逻辑，经 TypePHP AOT 产出**单一原生二进制**，运行时不依赖 PHP 解释器。
2. WHEN 构建触发，系统 SHALL 执行联合构建：`tpc` 将 PHP 编译为 C++ 并由宿主编译器（cl/g++）产出对象，AdaptiveCpp `acpp` 将 SYCL 内核编译为设备码 + 主机桩，二者链接为单一 `engine` 二进制（可选 WASM 产物）。
3. **系统 SHALL 以原生链接方式调用 C++/SYCL 内核，禁用运行时 FFI。** 允许的两种零开销路径：① `*.stub.php`（空实现）+ `*.cc`（`php_` 前缀）桥接；② `project.yml` 的 `objects`/`link-libs`/`link-paths`/`include-paths` 直接链接预编译 C++/SYCL 目标文件与库。
4. 系统 SHALL 保证框架逻辑与 GPU 内核之间**无运行时封送层**：边界为被编译掉的普通 C++ 函数调用（已核实 TypePHP 源码 `docs/zh-cn/MIXED_CPP_PHP.md`、`project-layout.md`）。

**GWT**：
- Given 一份 TypePHP 框架源码 + 一份 SYCL 内核，When 执行联合构建，Then 产出不依赖 PHP 运行时的 `engine` 二进制且可启动。
- Given 二进制启动，When 调用一个经原生链接的 SYCL 算子，Then 返回结果与同算子 C++ 参考一致（数值在容差内），且调用路径不含 FFI 封送。

**溯源**：TypePHP 源码 `MIXED_CPP_PHP.md` / `project-layout.md`（stub+cc、objects/link-libs 原生链接）；Kapitsa `AGENTS.md`（Metal 源内嵌类比——此处改为 AOT 原生链接而非运行时编译）。

### FR-02　统一后端抽象层（ggml 风格）　【Must】

1. 系统 SHALL 定义统一后端接口：`buffer_type`（分配/对齐/最大尺寸/张量尺寸计算）与 `backend`（设备发现、内存管理、张量算子、执行计划）。
2. 系统 SHALL 提供**计算图调度器**：将推理图按"权重物理位置 + 输入产出位置"切分为设备子集，并在边界张量间拷贝（借鉴 ggml `sched_split_graph`）。
3. 引擎逻辑（Kapitsa 层）与编排层 SHALL **不引用任何厂商专属翻译件**，仅依赖本抽象层接口（类比 UNIKERN R5.1 生成独立性）。

**GWT**：
- Given 同一份引擎推理代码，When 在 CPU 与某 GPU 后端间切换，Then 无需修改引擎代码即可运行（仅换后端注册）。

**溯源**：llama.cpp/ggml backend 接口（DeepWiki）；UNIKERN R5.1（生成/抽象独立性）。

### FR-03　可移植计算基座（AdaptiveCpp SYCL/PCUDA，原生链接）　【Must】

1. 系统 SHALL 以 AdaptiveCpp 的 SYCL/PCUDA 实现核心算子：量化 GEMM（mmq/mmvq）、反量化、attention（含 Flash-Attention 类）、RoPE、softmax、归一化，并以 `objects`/`link-libs` **原生链入**单二进制（非 FFI）。
2. 系统 SHALL 产出**单一二进制**，在运行时探测并 offload 到系统中存在的设备（CPU/NVIDIA/AMD/Intel/Apple），无需为不同 GPU 重编译。
3. WHEN 某设备不可用，系统 SHALL 回退到 CPU 或其他可用后端并明确报告。

**GWT**：
- Given 同一二进制部署到含 NVIDIA 卡与仅 CPU 的两台机器，When 分别启动推理，Then 两者均能运行且自动选择对应后端。

**溯源**：AdaptiveCpp README（单二进制跨厂商 offload、PCUDA）；UNIKERN 场景 A/B 的多级存储思想。

### FR-04　多后端支持矩阵　【Must】

1. 系统 SHALL 在 MVP 支持以下后端：**CPU、NVIDIA（CUDA 经 SYCL/PCUDA）、AMD（ROCm/HIP）、Intel（SYCL）、Apple（Metal 或 SYCL）**（决策 3 确认：矩阵含全部五类）。
2. 系统 SHALL 在启动时探测各后端可用性，并在 `doctor` 与启动日志中输出后端清单与工具链指纹。
3. IF 某后端所需 SDK 缺失，系统 SHALL 标记为不可用并给出原因，而非崩溃。

**GWT**：
- Given 启动 `engine doctor`，When 设备含某 GPU，Then 输出该 GPU 厂商、型号、可用后端与可运行模型清单。

**溯源**：llama.cpp 后端矩阵；UNIKERN 三厂商（AMD/Apple/Intel，本框架扩展含 NVIDIA 与 CPU）。

### FR-05　厂商调优内核插件（演进，V1）　【Could】

1. 系统 SHALL 允许以原生 CUDA/Metal/HIP 手写内核作为**插件**覆盖默认 SYCL 可移植内核（Phase 2 在用户本机按需启用）。
2. 插件 SHALL 通过统一后端接口注册，引擎逻辑与编排层无感切换。
3. 插件可按"静态编译"或"动态 .so 加载"（借鉴 ggml `GGML_BACKEND_DL`）分发，以控制单二进制体积。

**GWT**：
- Given 安装某厂商调优插件，When 该厂商设备存在，Then 引擎自动优先采用插件内核，否则回退 SYCL。

**溯源**：llama.cpp 静态/动态后端加载（DeepWiki）。

### FR-06　Kapitsa 引擎 — KFlash 树状投机解码　【Must】

1. 系统 SHALL 实现 KFlash：一个 verify pass 检查 drafter 的**候选树**（best-first），保留大模型认可的**最长分支**，输出与 plain decoding **逐位一致**。
2. 系统 SHALL 支持已发布 drafter：DFlash、DFlash2、DSpark、LiLiCorr。
3. WHEN 启用 KFlash，系统 SHALL 在部署前以输出一致性 gate 验证（见 FR-16），未通过不得设为默认。
4. KFlash 树大小 SHALL 按"verify 行成本在该设备实测"确定（Kapitsa 做法），MVP 用保守默认，Phase 2 做每设备测量（决策 4：先验证引擎可用，再逐主机复测调优）。

**GWT**：
- Given 同一模型与 prompt，When 分别用 plain decoding 与 KFlash 解码，Then 输出 token 序列逐位一致；decode 速度 ≥ plain 的 1.3×（目标，估算）。

**溯源**：Kapitsa README "How it works" / `docs/kflash.md`；AGENTS.md 输出一致性规则。

### FR-07　推理会话与执行引擎　【Must】

1. 系统 SHALL 支持单流 prefill/decode，含 KV cache（含量化）。
2. 系统 SHALL 支持请求取消、超时、终端顺序（start/cancel/join），保证会话生命周期正确。
3. 系统 SHALL 以"单流路径优先保精度"为原则；批处理/并发为 V1 演进，且**不得改变单流数值结果**。

**GWT**：
- Given 一个进行中的推理请求，When 收到取消，Then 请求终止、资源释放、后续请求可正常开始。

**溯源**：Kapitsa `execution_session_test`；AGENTS.md（单流路径不得改变）。

### FR-08　分词器与聊天模板　【Must】

1. 系统 SHALL 从 GGUF 加载 tokenizer（含 pre-tokenizer split，如 Flint）与 chat template。
2. 系统 SHALL 支持结构化输出（JSON schema）与工具调用（tool calls）。

**GWT**：
- Given 一个 GGUF 模型，When 加载并编码一段文本，Then tokenizer 输出与参考实现（llama.cpp/Kapitsa）一致。

**溯源**：Kapitsa `tokenizer_test` / `structured_check`；llama.cpp GGUF tokenizer。

### FR-09　服务器与 API　【Must】

1. 系统 SHALL 提供 HTTP 服务，兼容 `POST /v1/chat/completions`、`POST /v1/messages`、`POST /v1/responses`，支持流式、工具调用、JSON-schema 输出。
2. 系统 SHALL 提供本地 chat page（默认 `http://127.0.0.1:8000`）。
3. 服务 SHALL 默认仅绑定本机回环地址（安全默认，见 FR-10）。

**GWT**：
- Given 服务启动，When 向 `/v1/chat/completions` 发送流式请求，Then 返回 SSE 流式 token 且与 OpenAI 格式兼容。

**溯源**：Kapitsa README（chat page + 三 API + streaming）；AGENTS.md（API 是产品）。

### FR-10　模型格式与目录管理　【Must】

1. 系统 SHALL 加载 GGUF 单文件模型（**默认格式，决策 5 确认**），并支持目录式模型文件夹（Kapitsa 风格）。
2. 系统 SHALL 提供 `import`（导入外部 GGUF）、`pull` / `list` / `rm`、`doctor`（GPU/内存/适配模型）。
3. 系统 SHALL **不排除后期支持其他模型格式**（如 Safetensors 等），以可插拔 loader 演进（决策 5）。

**GWT**：
- Given 一个 GGUF 文件，When 执行 `engine import model.gguf`，Then 模型进入本地目录并可被 `serve` 加载。

**溯源**：Kapitsa CLI（`pull/list/rm/import/doctor`）；llama.cpp GGUF。

### FR-11　MoE 专家流式与分层放置　【Should，V1 / Phase 2】

1. WHEN 模型为 MoE 且活跃专家超出显存预算，系统 SHALL 按预算将专家分片并从 SSD/显存流式加载（借鉴 Kapitsa `expert_store`）。
2. 系统 SHALL 在场景 B（离散卡）按"分片粒度 + 预取距离"做显式流式，保持搬运与计算重叠（双缓冲）。

**GWT**：
- Given 一个 180B 级 MoE 在 48GB 设备，When 启动推理，Then 非活跃专家从 SSD 流式加载且 decode 不中断。

**溯源**：Kapitsa README（180B on 48GB、expert streaming）；UNIKERN 场景 B 流式。

### FR-12　多级存储与显式流式调度　【Should】

1. WHEN 目标设备为统一内存平台（Apple 类），系统 SHALL 采用零拷贝全驻留，并在放置记录标记"统一内存"（UNIKERN 场景 A）。
2. WHEN 目标设备为离散 GPU 且驻留需求超显存，系统 SHALL 走显式流式（pinned 拷贝 / mmap 流式），由代价模型择优（UNIKERN 场景 B）。

**GWT**：
- Given Apple Silicon，When 加载可驻留模型，Then 无显式拷贝、零迁移语义。

**溯源**：UNIKERN R8（场景 A/B）；Kapitsa Apple 路径。

### FR-13　多设备/多机 split　【Could，V1+】

1. 系统 SHALL 支持一层模型跨多设备/多机放置（`plan` + 远端 `reserve`），并显式启用（非默认）。

**GWT**：
- Given 两台设备，When 启用 `--split`，Then 模型层按 plan 分布且推理正确。

**溯源**：Kapitsa `cluster` / `split_place_test` / `remote.h`。

### FR-14　SSD 前缀缓存　【Should】

1. 系统 SHALL 将对话状态落盘、跨更新保留（`--disk-cache`）。
2. WHEN 状态字节含义变更（KV 行序/精度、recurrent 布局、drafter ring），系统 SHALL bump `kStateFormat` 版本号，避免新二进制误读旧状态。

**GWT**：
- Given 启用 disk-cache 的续聊，When 重启后恢复，Then 16K 续聊冷启动时间显著低于无缓存（目标量级 0.16s，估算）。

**溯源**：Kapitsa `engine_disk.cpp`、AGENTS.md（disk_format 版本）。

### FR-15　内核调优/求解层（UNIKERN 集成，V2，**条件性**）　【Could】

1. 系统 SHALL（可选，V2）集成 UNIKERN 的 KML 原语树 + 确定性代价模型 + Ledger，对可移植内核做 per-device 最优参数（tile/layout/swizzle）自动选择。
2. **该层默认不引入（决策 6）**：MVP 不依赖；仅当 **V1 性能相对对标引擎差距巨大、且求解层被证明能带来"巨大提升"** 时，才提前引入到 V1。**否则保持 V2 可选层。**
3. 该层 SHALL 为可关闭的演进能力。

**GWT**（V2 或条件性 V1）：
- Given 某算子与某设备，When 启用求解层，Then 返回该设备最优内核参数且可被 Ledger 复用。

**溯源**：UNIKERN `design.md` / `requirements.md`（求解器、Ledger、场景 A/B）。

### FR-16　可观测、基准与输出一致性 gate　【Must】

1. 系统 SHALL 提供 `bench`（prefill/decode tok/s）与 GPU 锁（借鉴 `GpuLock`，避免测量互相干扰）。
2. 系统 SHALL 实现输出一致性 gate：`logit_dump` 新旧构建 + `logit_compare`，阈值 = 2× 该模型噪声底（`logit_floors/<model>.json`）。
3. WHEN 一致性 gate 失败，系统 SHALL 拒绝将该变更设为默认，并报告差异。

**GWT**：
- Given 一次内核/解码变更，When 运行 gate，Then 输出判定（通过/失败）与超阈详情。

**溯源**：Kapitsa `AGENTS.md`（GPU lock、输出检查、logit_compare、噪声底）。

### FR-17　构建与可移植分发　【Must】

1. 系统 SHALL 通过联合构建集成 `acpp`（AdaptiveCpp）与 `tpc` 完成构建，构建命令集中、可复现（含 `tpc` 调用宿主编译器 + `acpp` 编译 SYCL 两段）。
2. 系统 SHALL 支持单二进制部署到多平台（借鉴 AdaptiveCpp deployment 文档），可选动态后端 .so 以减小核心包体。
3. 系统 SHALL 提供 `tpc` 与 `acpp` 的构建编排脚本，确保单二进制同时包含 libphp/libphpx + SYCL 运行时 + 内核（Phase 0 探针验证项）。

**GWT**：
- Given 同一份源码，When 在 CI 构建，Then 产出可在目标平台直接运行的单二进制。

**溯源**：AdaptiveCpp `using-acpp` / `deployment` 文档；Kapitsa `CMakeLists.txt`。

### FR-18　推理客户端编排层（一等架构层，低端设备效率核心）　【Must】

1. 系统 SHALL 实现推理客户端编排层（Orchestrator），与可移植内核**并列为一等层**，负责：设备探测与后端选择、全局内存预算、KV cache 量化决策、CPU+GPU 异构 offload 决策、批处理/并发策略、I/O 与计算重叠、专家/权重流式调度。
2. WHEN 目标为受限/低端设备（小显存、集成显卡、CPU 为主），系统 SHALL 由编排层（而非内核）决定卸载比例、KV 精度、split/流式策略与重叠粒度，以最大化本地资源利用率（决策 4：低端效率靠软件工程，不靠 GPU 算子）。
3. 编排层 SHALL 暴露可观测指标（offload 比例、KV 压缩比、重叠命中率、有效 tok/s），供 `bench` 量化"编排层开启前后"的本地效率提升（Phase 2 复测口径）。
4. 编排层的决策 SHALL 不破坏单流数值结果（与 FR-07 一致）。

**GWT**：
- Given 同一模型与硬件，When 分别关闭/开启编排层（量化 + CPU 卸载 + 重叠），Then 后者在受限设备上显存占用更低且有效 tok/s 更高，且输出与 plain decoding 逐位一致。

**溯源**：用户决策 4（"推理客户端是软件工程不是仅 GPU 算子 就能解决低端设备的运行效率的"）；Kapitsa 的 memory budget / MoE streaming / split 思想。

---

## 七、非功能需求

| 类别 | 要求 | 数值 / 口径 |
|---|---|---|
| 性能 | 单流 decode 不低于对标引擎 90% | 4K+512、T=0.6、空闲 GPU、A/B 交错 |
| 可移植性 | 一次编译 → 单二进制 → 多后端运行，无需按 GPU 重编译 | 除非启用厂商插件需对应 SDK |
| 原生性 | **框架与 C++/SYCL 内核为原生链接，禁用运行时 FFI** | 边界为编译掉的 C++ 调用（FR-01） |
| 兼容性 | 模型格式 GGUF（默认，不排除其他）；API 与 OpenAI/Anthropic 兼容 | 流式、工具调用、JSON-schema |
| 数值一致性 | 输出与 plain decoding bitwise identical（KFlash 等变更） | 阈值 = 2× 噪声底 |
| 资源 | 工作集内存按机型预算；**编排层负责受限设备效率最大化**（FR-18） | config + 权重 + drafter + 8K ctx |
| 安全 | 服务默认绑定 127.0.0.1；模型/GPU 锁；内核越界校验（各后端调试层） | 新/改内核先小尺寸 + 调试层跑 |
| 可维护性 | TypePHP 源码分层清晰；原生/链接边界明确；无未授权第三方依赖 | 类比 Kapitsa "Dependencies: None without strong reason" |
| 启动 | 冷启动（含 disk-cache 续聊）接近 Kapitsa 量级；无 FFI 初始化成本 | **估算**，待基准固化 |
| 二进制体积 | 单二进制链接 libphp+libphpx+SYCL 运行时+内核，可用动态 .so 后端分包控制 | FR-05/FR-17 |

---

## 八、业务流程与状态流转

**主流程**：启动 → 设备探测/后端选择（编排层）→ 模型加载（GGUF/目录）→ 内存预算/KV 量化决策 → prefill → decode（KFlash）→ 流式输出 → 会话保持/落盘 → 退出。

**关键状态机（推理会话）**：

```
IDLE → LOADING(模型) → READY → PREFILLING → DECODING → (STREAMING) → READY/IDLE
                            ↓ cancel/timeout
                         CANCELLED → IDLE
```

**后端可用性状态**：`UNKNOWN → PROBED(available/unavailable)`；不可用后端不进入调度。

**编排决策状态**（新增）：`BUDGETING(内存预算) → OFFLOAD_DECIDED(卸载决策) → OVERLAP_SCHEDULED(重叠调度) → ACTIVE`。

**split 放置状态**（V1+）：`PLANNED → RESERVED(各设备) → ACTIVE → RELEASED`。

---

## 九、数据与埋点

- **核心实体**：Model（GGUF metadata / 目录）、Backend（厂商/型号/工具链指纹）、Session（KV/状态）、BenchRun（prefill/decode/TTFT）、OrchestratorDecision（offload 比例/KV 压缩/重叠命中）、LedgerEntry（V2，可选）。
- **埋点事件**：`inference.start/prefill/decode/done`、`backend.selected`、`kv.quant.saving`、`expert.stream.hit`、`split.placed`、`orchestrator.offload`、`consistency.gate(pass/fail)`。
- **指标口径**：decode tok/s = 生成 token 数 ÷ 生成耗时（512-token 回答，T=0.6）；TTFT = 首 token 延迟；显存占用 = 峰值工作集；**有效 tok/s = 受限设备开启编排层后的净吞吐**（Phase 2 复测核心指标）。

---

## 十、权限与安全

- 服务 SHALL 默认绑定 `127.0.0.1`，不对外暴露（split/集群需显式且限本机/受信网络）。
- 模型加载与 GPU 基准 SHALL 经由 GPU 锁（flock），避免并发测量互相干扰。
- 内核 SHALL 在小尺寸 + 后端调试层（`MTL_SHADER_VALIDATION`/`MTL_DEBUG_LAYER` 类比）下先验证，防止越界 fault 整机。
- 不对未授权第三方库引入硬依赖；`third_party` 若 vendored 则不改其内部（类比 Kapitsa）。

---

## 十一、异常处理清单

| 场景 | 检测 | 处理 |
|---|---|---|
| 后端不可用 / AdaptiveCpp 编译失败 | 启动探测 / 编译退出码 | 标记不可用、回退 CPU、报告原因 |
| **联合构建失败（tpc+acpp 不兼容）** | Phase 0 探针 / 构建退出码 | **RK-01 重点**：定位是 tpc 宿主编译段还是 acpp SYCL 段，隔离修复；探针不过不进 Phase 1 |
| 设备复位 / 超时 | runner 监测 | 记录、隔离该解、继续队列 |
| 输出一致性 gate 失败 | logit_compare 超阈 | 拒绝设为默认、保存差异摘要 |
| 模型超出显存 | 工作集估算 | 触发编排层决策（KV 压缩/CPU 卸载/split/专家流式）/ 明确拒绝并建议更小变体 |
| 量化精度 gate 失败 | 噪声底比对 | 拒绝 merge、报告漂移 |
| 状态格式不兼容（disk-cache） | `kStateFormat` 不匹配 | 拒绝读旧状态、提示重新生成 |
| 单二进制体积过大 | 构建产物检查 | 启用动态 .so 后端分包 |

---

## 十二、上线计划、依赖与上线后验证

### 12.1 依赖就绪度评估

| 依赖项 | 所属 | 就绪状态 | 分级 | 最晚就绪时限 | 影响 / 替代 |
|---|---|---|---|---|---|
| **TypePHP AOT（原生二进制 + 原生链接 C++/SYCL）** | 用户自有 | **可用（FFI 风险已移除）；仅余"tpc+acpp 联合构建"待 Phase 0 验证** | **阻塞（Blocker，但已收窄）** | Phase 0 结束前 | 若联合构建不可行，整体路线需重议（RK-01） |
| AdaptiveCpp（SYCL/PCUDA 单二进制） | 开源 | 可用 | 并行（Parallel） | 联调前 | 无替代——本框架可移植基座 |
| GGUF 解析 / tokenizer | 开源（llama.cpp 参考） | 可复用 | 并行 | MVP | 自研解析或复用参考实现（注意许可） |
| 厂商 SDK（CUDA/ROCm/Metal/oneAPI） | 厂商 | 环境依赖 | 可绕过（Workaround） | — | SYCL/PCUDA 路径可不装厂商 SDK 即可覆盖（部分性能让步）；Phase 2 按需装 |
| UNIKERN 求解/Ledger | 用户自有 | 独立项目 | **V2 条件性（决策 6）** | 仅当 V1 性能差距巨大时 | MVP 不依赖；V1 仅"巨大提升"时引入 |

### 12.2 版本规划（对齐用户 8 决策）

| 阶段 | 范围 | 关键交付 | 对应决策 |
|---|---|---|---|
| **Phase 0（探针）** | `tpc + acpp` 联合构建出单二进制，≥2 后端跑通 SYCL matmul，测原生调用开销 | 探针报告：单二进制可行 / 开销数据 / 风险清单 | 决策 1「可以做」 |
| **Phase 1（可移植引擎 / MVP）** | 单二进制 + SYCL 可移植内核（原生链接）+ ggml 风格后端抽象 + 推理客户端编排层（基础）+ Kapitsa 引擎核心（KFlash/plain、KV、tokenizer、server、GGUF、bench/gate）+ 五后端矩阵（决策 3） | 单二进制跑通 CPU + ≥1 GPU 后端；验证引擎可用 | 决策 2/3/4（第一次重做，验证可用） |
| **Phase 2（本机最大化利用复测 / V1）** | 厂商调优内核插件、MoE SSD 专家流式、多设备 split、图像输入；在用户 **Intel/AMD/Apple** 逐主机复测最大化本地利用率；其他格式 loader（决策 5） | 三机型实测效率提升；对照"裸跑"的可测量收益 | 决策 4/5/8（先算法后逐主机测试） |
| **Phase 3（借鉴与扩展 / V2+）** | 参考 Splash 等引擎加速思路；UNIKERN KML 求解/Ledger（仅当 V1 差距巨大，决策 6） | 自动内核调优 / 多机集群 | 决策 6（条件性） |

### 12.3 上线后验证

- Phase 0：单二进制 + 双后端 matmul 正确性 + 原生调用开销实测。
- Phase 1：每后端最小模型跑通；`bench` 对标 Kapitsa/llama.cpp；一致性 gate 全绿；`doctor` 输出可信。
- Phase 2：用户 Intel/AMD/Apple 三机型逐主机复测，编排层开启前后效率对比。

---

## 十三、风险与遗留问题

| 编号 | 风险描述 | 类别 | 概率 | 影响 | 等级 | 应对措施 | 责任人 | 状态 |
|---|---|---|---|---|---|---|---|---|
| **RK-01** | **tpc 与 acpp 联合构建出单二进制**（PHP→C++ 宿主编译 + SYCL 设备码链接）的工程可行性；以及单二进制体积（libphp+libphpx+SYCL 运行时+内核）、启动延迟 | 技术 | 3 | 5 | **高** | **Phase 0 探针直接验证**（决策 1 已确认做）；不过不进 Phase 1；必要时用动态 .so 后端分包控体积 | 框架开发者 | 跟踪中（已收窄：FFI 开销风险已移除） |
| RK-02 | AdaptiveCpp SYCL/PCUDA 性能 vs 手写 CUDA/Metal 差距可能显著 | 技术/体验 | 3 | 4 | 中 | Phase 2 引入厂商调优插件覆盖热点；以 bench 量化差距 |
| RK-03 | 跨后端数值差异导致一致性 gate 难全绿 | 数据 | 3 | 4 | 中 | 明确每后端容差口径；reorder gate 处理重结合（借鉴 Kapitsa） |
| RK-04 | 单二进制体积与多后端依赖链膨胀 | 技术 | 3 | 3 | 中 | 动态 .so 后端分包；核心包最小依赖（FR-05/FR-17） |
| RK-05 | 法律风险：复制 llama.cpp/Kapitsa 专有内容或遗漏许可头部 | 合规 | 2 | 4 | 中 | 仅借鉴通用架构/交互模式，不复制文案/视觉/专有代码；Kapitsa 改编内核保留头部；GGUF 解析注意 MIT 许可 |
| RK-06 | "一次编译多后端"在 Apple 上可能因 Metal 不在 SYCL 路径而需双构建 | 技术 | 2 | 3 | 低 | Apple 同时走 SYCL（AdaptiveCpp 支持）或单列 Metal 构建开关（MVP 默认 SYCL 满足"一次编译"） |

**遗留问题**：Kapitsa 的 Neural Engine 路径是否在未来以 Apple 专属后端形式回归（非 MVP）；Phase 3 是否吸收 Splash 引擎后端；V1 图像输入的具体可移植 kernel 覆盖边界。

---

## 十四、附录

### 14.1 原型思路（CLI / Server 线框）

```
[CLI]  engine serve ──► [本地服务 127.0.0.1:8000]
         │            ├── /v1/chat/completions (SSE)
         │            ├── /v1/messages
         │            └── /v1/responses + chat page
         ├── doctor（后端清单 + 适配模型 + 编排层建议）
         ├── pull / list / rm / import
         └── bench（prefill/decode tok/s + 编排层效率对比）

[启动流程] 探测设备 → 编排层(内存预算/后端选择/offload决策) → 加载 GGUF → KV 分配 → prefill → KFlash decode → 流式
[构建流程] tpc(PHP→C++→宿主对象) ＋ acpp(SYCL→设备码) ──► 单一原生二进制（libphp+libphpx+SYCL运行时+内核，原生链接，无 FFI）
```

### 14.2 精选用户故事

- **US-1**：作为本地 Agent 开发者，我想要一个 `engine serve` 起的服务，以便我的 coding agent 通过 OpenAI API 调用本地模型，且输出与标准解码一致。
- **US-2**：作为框架开发者，我想要用 TypePHP 写框架并经 AOT 出单二进制（原生链接 SYCL，无 FFI），以便在不引入 PHP 运行时依赖的前提下跨 GPU 部署。
- **US-3**：作为小显存设备用户，我想要编排层帮我决定 KV 压到几 bit、卸载多少到 CPU、专家从 SSD 流式，以便在受限设备上跑得动且更快。

### 14.3 参考材料

1. UNIKERN `design.md` / `requirements.md` — 统一多厂商 GPU 内核抽象、KML、求解器、Ledger、场景 A/B。
2. Kapitsa 0.2.25 `README.md` / `AGENTS.md` — KFlash、引擎架构、GPU 锁、输出一致性、MoE 流式、split、SSD 缓存、API。
3. AdaptiveCpp-develop `README.md` — SYCL/PCUDA 单二进制跨厂商 offload、acpp 编译。
4. llama.cpp/ggml（DeepWiki）— backend 接口、静态/动态后端、GGUF、多后端矩阵。
5. TypePHP 源码 `C:\git\php\typephp` — `docs/zh-cn/MIXED_CPP_PHP.md`（stub+cc 原生桥接）、`docs/zh-cn/project-layout.md`（objects/link-libs 直接链接）、`project.yml`（构建字段）。

### 14.4 合规声明

本方案为产品设计参考，具体实施请结合公司合规与法务要求确认。Kapitsa 为 Apache 2.0，借鉴其架构/改编内核须保留原始文件头部与第三方声明；GGUF/llama.cpp 相关复用须遵守 MIT 许可；不复制任何产品的专有文案、视觉或内核实现细节。

---

## 十五、评审结论与已闭合决策

> v0.1 第十五节的 8 项开放决策，经用户逐条确认，现全部闭合。下方为确认结果与文档落点。

| # | v0.1 待确认决策 | 用户确认（2026-11-01） | 文档落点 |
|---|---|---|---|
| 1 | 前提 A：先做 TypePHP 调 AdaptiveCpp SYCL matmul → AOT 双后端跑通的最小探针？ | **可以做**（确认先行探针） | 升级为 **Phase 0**，纳入版本规划与 RK-01 应对；不过不进 Phase 1 |
| 2 | "一次编译多后端"机制选型 | **单二进制**（确认方案 A） | 方案 A 为基线；FR-01/FR-03/FR-17 落地"单二进制原生链接" |
| 3 | MVP 后端矩阵 | **确认**（CPU+NVIDIA+AMD+Intel+Apple） | FR-04 固化五后端矩阵 |
| 4 | Kapitsa 引擎范围 | **第一次重做验证引擎可用；第二本机最大化利用复测；后续参考他引擎；推理客户端是软件工程而非仅 GPU 算子，解决低端设备效率** | 新增 **FR-18 编排层（一等层）**；Phase 1=验证可用、Phase 2=本机复测；4.3 架构分层单列 Orchestrator |
| 5 | 模型格式 | **GGUF 默认；不排除后期其他格式** | FR-10 默认 GGUF + 可插拔 loader 演进 |
| 6 | UNIKERN 集成深度 | **不依赖，走 V2；除非 V1 性能有巨大提升** | FR-15 改为 V2 **条件性**；12.1 依赖表标注"仅当 V1 差距巨大" |
| 7 | 命名与仓库 | **typephp-kakasi — TypePHP 多后端推理框架；Kakashi=复制忍者，快速复制到不同 GPU、借鉴不同引擎加速方案** | 1.4 节产品定位与代号；全文代号统一 |
| 8 | 交付节奏 | **接受分阶段；先算法后逐主机测试；用户有 Intel/AMD/Apple 显卡** | Phase 1=算法/可移植（一次编译）、Phase 2=逐主机（Intel/AMD/Apple）复测；12.2 版本规划对齐 |

### 下一步（进入开发前的第一个动作）

- **Phase 0 最小探针已绿灯**：用 TypePHP 调一个 AdaptiveCpp SYCL matmul，经 `tpc + acpp` 联合构建出单二进制，在 ≥2 后端（建议 CPU + Intel GPU，或 CPU + Apple）跑通并测量原生调用开销。
- 探针交付物：可运行的 `engine` 单二进制（最小形态）+ 探针报告（可行性结论 / 开销数据 / 风险清单）。
- 待用户确认"开始 Phase 0 实现"后，再进入编码（本稿仍为评审稿，未含代码）。

---

*— 评审稿 v0.2 结束；8 项决策已闭合，路线收敛为 Phase 0→1→2→3。待用户授权后启动 Phase 0 编码。*
