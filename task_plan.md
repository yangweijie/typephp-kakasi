# Task Plan: typephp-kakasi — TypePHP 多后端推理框架（Kapitsa 引擎移植）

> 本计划基于需求文档 `specs/typephp-inference-framework/requirements.md`（v0.2，决策已闭合）。
> 目的：为接手开发的其他软件/团队提供可执行的阶段化计划。需求已冻结，进入实现阶段。
> 编写日期：2026-11-01　·　当前状态（F209 后）：**Phase 0 complete（GO）· Phase 1 功能面闭环 · Phase 2 主体完成 · Phase 3 已开工（单链投机 2.5× / SSD 前缀缓存 3.3× / OpenAI+Anthropic 四形状 API 含两种流式 + 工具调用含 Anthropic `tool_use` / models import-pull-list-rm 含目录式文件夹与 /v1/models / 并发连接正确性（含流式）/ 工具历史两种 API 形状闭环 F60–F68 / doctor / grammar-constrained 覆盖至 DEEP12·6 维数组·元素内混合叶子·元素内嵌套链·oneOf 分支含 grammar F109–F147·F152–F190）**。
> 回归口径（每次改动都要过）：单二进制全项自检 + 跨后端 logit drift（f16 驻留 5.7e-6；**int8 驻留 1.6e-2**，断言阈值 0.05）+ `logp_parity.py` 的 window-0 判据（f32 0.00132 / f16 0.00130 nats）+ 起服务后 `api_smoke.py`（96 种 API 形状一次验完）。
> **完成度审计**：`docs/audit-v0.2.md`（逐条 FR → 证据/缺口）。当前：FR-01/14/16 完全达成，FR-03 以 DPC++ 替代达成；FR-02/04/06/07/08/09/10/12/13/17/18 部分达成（缺口逐条列出）；FR-05/11/15 未做或本机不可验。**整体未完成**。
> **下一步（按"缺口 × 可验证性"，2026-11-04 更新）**：⓪ **冷启动首调返回空＝功能上已闭合**（F226 修复＋F232 收窄版复验：`run_f228.log` 137 PASS/0 FAIL、`SCHEMA-DEEP200` 按首调判据 PASS、`first_len=3909`）。**该增量仍未收口的三件**：(a) 8000 `engine/tests/api_smoke.py`（含新门 `chat-json-schema-deep40-first-call`）与 8001 `par_smoke.py`/`fairness_test.py` 门禁**正在跑**（后台链 `b92wbrol6`，8000→停→8001，一次一个引擎进程）；(b) native 根因未证——只剩 F228 两条分支（`rows==0` / 整行 NaN），探针已写好但构建前 revert（`82cc910`→`8afcf73`），要单独一轮加 `model_sycl.cc:1323` 判别量再测；(c) `session_next_allowed` 里 `g_sess_len += 1` 早于 `r.empty()` 判定（`src/model_sycl.cc:1496`）未修，且收窄的时间收益缺**未收窄版的秒表对照**，不得报数。
> **当前下一步（F542 后，2026-11-12）**：F539 的两个 P0 已闭合——D1 `$ref` 键序（F541：定义容器整对删除 + 两序静态门）、D2 const/anyOf（F540 折叠 const；F542 纯数值界分支 fail-closed 400）。剩余队列：**(3) `unevaluated*` 真正判定的形状**——需子 schema 求值器（先从 `prefixItems`/`contains` 相邻的真空形态之外，挑一个“构造可满足”的形状写规格）。**(4) FR-05 / 多分片**——本机不可验证。基线：自检 RUN_EXIT=0 / 0 FAIL、bounds/required/count/pattern PASS、8000 115/0、8001 par/fair 0、scaling ~1.5–1.6×、哨兵 0、`check_gates` 0 unresolved。
> （以下为历史条目，按时间保留：）⓪ **冷启动空返回（F208→F221；F534 复核：HTTP 首请求 200 层完整组装，自检 `first_len=3887`，原“空返回”不再复现；>256 层非流式已 fail-closed（F535），其他传输待接）**：同一会话内第一条 200 层约束解码返回空、第二条 3909 字符合法 JSON（`run_f221.log` 以**干净全量重建**的二进制复现：137 PASS / 0 FAIL / `first_len=0 second_len=3909`）。F221 已把嫌疑**重新打开到前缀阶段**（F215/F218 的"前缀无辜"依赖错误的产物文件，作废）。下一次的打法：在 `api_nested_object_value` 的**每个** `return "";` 前打编号标记，一次构建后**只看自检输出**（输出出现＝证据；`extension-*.cc` 里的字符串不作判据，见 F221 规程），一次枚举全部出口；若输出一个都不出现，则在调用点打出入参。**判据**：`SCHEMA-DEEP200-RETRY` 的 `first_len` 转非零。
> （以下旧条目按时间保留：）⓪′ **F202 流式并行 fan-out**（已由 F205/F206 闭合：per-item `index`/`output_index` + 常驻流式守卫 96 形状）先把两条流式强制工具分支从"骨架前缀匹配重建单调用"换成"对解码文本用 `api_tool_calls_json` + `api_tool_items` 逐项发事件"，并**删掉流式侧同款静默兜底**（F198 错答之源）；`api_resp_call_start`/`api_resp_call_done` 需加输出项序号（现在 `fc_1`/`call_1`/`output_index:0` 写死）。判据：带 `parallel_tool_calls` 的流式请求出现**两组** `tool_calls`（`index` 0/1、id 各异）或 Responses **两组** `output_item.added/done`，不带开关的对照仍为一组。⓪ **F194 实测的 API 正确性缺口（先小后大）**：(a) `tool_choice:"required"` 返回 `arguments:"{}"` —— 强制路径的 `$qArgs` 兜底没有取模型真正选中的调用，客户端无法执行，改动面最小故排最前 —— **已完成（F194a）**：`required` 分支改为逐工具取参数候选骨架、`$toolJson` 门控并上 `$reqCands`、预算 64→96，实测由 `'{}'` 变 `'{"city":"Paris"}'`，守卫 `chat-tool-required-args`（修前该断言即为假），`api_smoke_f194b.log` 89/89；(b) 生成侧**并行工具调用**根本未支持（两工具请求实测 `tools` 自由路径 0 个调用、`required` 路径 1 个），需要复数前向调用提取器 + chat/Anthropic/Responses 三出口序列化 + Responses 流式 `output_index` 递增与逐项 `response.output_item.added/done`；① **grammar-constrained 解码**（**字符串/有符号整数/小数/嵌套 1–80 层（上限 128）/混合叶子/三类数组/2–7 维数组（7+ 通用 N 维构造器，F166）/对象数组元素内字符串数组+标量混合叶子（F157）/对象数组元素内整数数组（F161）/元素内二维数组（F163）/转义字符串片段白名单（F164）/元素内嵌套链 ≤12 层（F158）/oneOf 分支含 grammar（F159，含根级 F160）/顶层四类数组/数字·浮点·科学计数法·布尔 const·enum/有界·无界 `multipleOf`/排他边界/候选·根类型作用域/本地 `$ref`/`allOf`/属性级·根级 `oneOf`/元素内嵌对象已做 F109–F147·F152–F190**；剩外部 `$ref`**已由 F207 闭合**（`schema_registry` 由调用方提供，不碰网络/文件系统，`SCHEMA-EXTREF` + 活服务 96 形状），嵌套上限已由 128 提到 **256**（F208，检测侧 200 层实测 `plan=ok`；装配侧受冷启动 bug 阻挡，见上）。下个增量先用 F158 的 Python 离线复刻法跑防误认领矩阵再重建）② FR-06 **树收益**（机制已通 F50，需多样草稿器）③ **per-session KV 并行**（快照+交替 F86/F94；**协作轮转公平性 PASS：8001 短流 0.28 s vs 长流 3.99 s，F95/F122；F157 复跑 PASS（僵死态 0.63 s TTFT）**；吞吐仍共享）④ SSD 缓存按前缀分桶 / 流式复用（F76 已修 bug、API 已启用全+部分命中）；并行多卡 split / 多分片 GGUF ⑤ **有条件时**（拿到 NVIDIA 机器/Linux CI）执行 **Phase 3-B 的 acpp ABI 探针**；⑥ ~~先构建验证 DEEP12~~ **已完成（F150/F151）**；~~重启后 par_gate 复测 8001~~ **已用替代路径收口（F157）**：WMI 僵死下显式 PATH 起新服务，`par_smoke` 7/7 + `fairness` PASS + `api_smoke` 8000 61/61 全绿（`par_gate.bat` 本体留待重启后一次性复测，非阻塞）。
> **新增不变量（务必保持）**：所有设备队列必须是 `property::queue::in_order`；释放设备内存前必须排空；arena 只在层边界 `wait` 之后重置。

## Goal
以 TypePHP（PHP→原生 AOT）编写推理框架，经 `tpc + acpp` 联合构建出**单一原生二进制**（PHP 编排与 SYCL/PCUDA 内核**原生链接，禁用 FFI**），一次编译即可在 CPU / NVIDIA / AMD / Intel / Apple 上运行，并实现 Kapitsa 引擎的可移植版本（KFlash 树状投机解码 + 推理客户端编排层，作为"软件工程"而非仅 GPU 算子的效率杠杆）。

## Current Phase
Phase 0（探针）— **complete**（2026-11-01，结论 GO，见 `probe/CONCLUSION.md`）。Phase 1（可移植引擎骨架）— in_progress，起点是后端抽象层，且必须先解决探针遗留的 P0（常驻 queue/context + 设备首次启用校验）。
> SYCL 实现由未安装的 AdaptiveCpp 暂改为本机已装的 **Intel oneAPI DPC++ 2026.1.1**（依据见 findings F7/F9）。**替代覆盖率已逐后端核过（findings F49）**：Intel GPU 与 CPU 为 100% 替代；NVIDIA/AMD 仅能经 OpenCL 落地（"能跑"而非"能跑好"，本机无卡未验）；Apple 两侧都无 Metal。本机装 acpp 无收益（缺 CUDA/ROCm，只能落到 OpenCL+OpenMP 子集）。补 NVIDIA/AMD 的推荐路径：**按 FR-02 抽象加厂商原生后端插件（FR-05）**，而不是依赖 acpp 的 PCUDA。

## Phases

### Phase 0: 探针 — 验证 tpc+acpp 单二进制 + 双后端跑通【Blocker 前置】
> 目标：证明联合构建可行、原生调用无 FFI 开销。不过此关不进 Phase 1。
- [x] 确认构建环境：`tpc` v0.9.5（`C:\git\php\tpc_v0.9.5_windows_x64`）；`acpp`/`syclcc` **未安装** → 改用本机已装 **oneAPI DPC++ 2026.1.1**（`icx`，MSVC ABI）；`cl` 14.44.35207；须用 `scripts/devshell.bat` 注入 vcvars（详见 F6/F7）
- [x] 编写最小 SYCL matmul 内核并接入 — **两条路径都用上**：路径① `src/kakasi.stub.php` + `src/kakasi.cc`(`php_sycl_*`)；路径② `project.yml` 的 `objects`(icx 预编译 `.obj`) + `link-paths` + `link-libs`
- [x] TypePHP 侧最小调用逻辑 → `tpc` 编译 PHP→C++ 并与 SYCL `.obj` 链接为**单二进制** `kakasi_probe.exe`(110 KB)；rung4 运行且 PHP 打印出 4 个设备名
- [x] 在 ≥2 个后端跑通 matmul 并对参考在容差内 — 单二进制热态 **4/4 设备 PASS（maxerr=3.8e-6）**：Arc B390×2(Level-Zero/OpenCL-Graphics) + Core Ultra X7 CPU×2；冷态存在首用不一致（见 Phase 1 前置）
- [x] 测量原生调用开销（证明无 FFI 封送）：原生调用 **0.65 ns/op**（纯 PHP 等价 0.11 ns/op，200 万次）；单二进制 **126 KB**；依赖 phpx/php8ts/libmpdec + sycl/ur_loader/tbb12
- [x] 产出探针结论文档 → **`probe/CONCLUSION.md`（结论：GO，附 3 项前置条件 P0/P1/P2）**
- **Status:** complete（2026-11-01）

### Phase 1: 可移植引擎骨架 — 验证"本推理引擎可用"
> 目标：在单一可移植基座上跑通一条最小 transformer 推理链路（非全特性），证明引擎本体可用。
- [x] **P0 前置**：后端抽象持有**常驻 context/queue + 每设备 buffer**，并实现"设备首次启用跑 smoke 校验，不过则剔除/降级" → `engine/src/sycl_backend.cc` 实测 **4/4 设备首轮即 VALIDATED+PASS**，根因是共享默认 context（详见 findings F11）
- [~] **P2 前置**：tiled matmul 内核替换探针里的 naive 全局读版本 → tiled(16×16×32) 版已实现且数值正确；但"是否比 naive 快"尚未测（缺纯内核计时，见下条）。旧 naive 256² 基线 34.7~79.9 ms 仍待超越
- [~] 后端抽象层（借鉴 ggml backend interface + 调度器）：`acquire/validate/dispatch` 最小闭环已在 `engine/` 跑通，尚缺 buffer type 与算子分发
- [x] 最小模型加载器（GGUF 解析 + 张量映射）— **已完成并自证**：`engine/src/gguf.cc` 读 v3 头/26 项 KV/290 张量目录；`ACCOUNTING=PASS`（`sum_bytes == max_end == avail == 391,859,712`），元数据与独立 Python 解析逐值一致（qwen2/24层/896/4864/heads14/kv2/vocab151936/data_start 5,947,680）。单二进制 197 KB，`LOAD`+`validate`+`backends 4/4` 全绿
  - 关键陷阱：ggml type 编号有历史空洞（4/5 已删除），**不可按连续整数推**；dtype 表以反推出的 bpw 校准（f32 32.0 / q5_0 5.5 / q8_0 8.5 / q4_k 4.5 / q6_k 6.5625）
  - 该 0.5B 权重实为 **q5_0(132)+f32(121)+q8_0(13)+q4_k(12)+q6_k(12)** 混合 → 反量化内核须同时覆盖 32 元素块与 256 元素超块两类格式
- [x] 核心算子：matmul / RMSNorm / RoPE / attention(QKᵀV) 的 SYCL 实现 —— **全部实现并逐位/相对误差验证**（下列各条）

  - ✅ **已实现并验证**（`engine/src/ops_sycl.cc`，20/20 项 × 3 次重复全通过，4 后端一致）：
    反量化 f32 / q8_0 / q5_0 与 host 参考**逐位相同(err=0)**；**tiled SGEMM**（16×16×32 shared-memory）作用于**真实权重** `blk.0.attn_q.weight` 896×896 q5_0，相对 double 精度 host 三重循环 `rel=9.88e-7`（4 后端数值完全一致）；RMSNorm(896) `≤1.19e-7`
    → 覆盖本模型 **266/290** 张量的格式
  - ✅ **Q4_K / Q6_K 已实现并交叉验证**（`engine/src/ops_sycl.cc`）：host 参考=**逐字抄自上游**（指针推进、64 元素组），device 内核=**另一套独立推导的下标形式**（sub-block 奇偶选半字节 / chunk-quarter-lane 分解）。两者在真实权重上**逐位相同（err=0，4 后端 × 3 次）**→ 这是对 scale/nibble 索引的正确性交叉验证，不是自我确认。q4_k 真实 FFN 权重 8×896×4864 gemm `rel=4.17e-6`
  - ✅ 至此**本模型全部 5 种格式、290/290 张量**均可反量化：f32 / q5_0 / q8_0 / q4_k / q6_k
  - ⛔→✅ **RoPE / attention(QKᵀV) / KV cache 均已完成**（在 `model_sycl.cc` 内实现并端到端验证；`LONGCTX: PASS` 84-token prompt 越过旧 64 上限）
- [~] **性能：权重驻留已实现（10× 提速，原 P2 前置）** —— `residency enabled=1 loaded=290 used=1.84GiB budget=13.94GiB device=25.34GiB`；decode **635 → ≈62 ms/token（1.6 → ≈16 tok/s）**，step2 logit 逐位不变（只改调度不改数值）。这同时是 **FR-18 内存预算**的首个真实实现（预算=显存×0.55，装不下自动回落流式）。
  - ✅ 稳态 prefill / 长上下文基线已测（见下"性能基线报告"）；待做：f16 驻留（1.84→0.92 GiB，利于低端设备）
- [x] 简易 tokenizer —— **BPE 编码 + 词表解码已实现并对 oracle 验证，20 例全 PASS**（`engine/tests/logits_probe`-style 逐例靶子见 findings **F21**）：不再只是"空格附着到下一个词"，而是**逐 alternative 移植 llama.cpp 的 qwen2 切分正则**（`src/llama-vocab.cpp:289`），覆盖 k≥2 空格拆分、结尾空格 piece、空格+LF 归并、标点吸收 LF、TAB 前缀、缩写独立分支、数字 1..3 位分组；byte→symbol 表用**规则复现** gpt2 bytes_to_unicode（0x20→Ġ、LF→Ċ，与词表字符串实测一致）。副产品：13 遍重复文本 274 tokens **与 llama-tokenize 完全一致**（旧实现少 1 个 → 结尾空格被丢弃）
  - 仍存的诚实限制：`p{L}`/`p{N}` 只覆盖 ASCII，非 ASCII 字节一律当字母 → CJK run 的分片行为与 oracle 一致，但其它 Unicode 类别不加区分（已写入 `tokenizer.cc` 头注释）
  - ✅ **聊天模板已接入（子集 + 门禁，见 findings F23）**：`tok_chat(role, content)` 渲染一轮 ChatML 并直接编码；`chat_template_matches()` 断言本模型模板确实使用该方言（不匹配即 FAIL，不渲染就报错）。控制 token 按 **`token_type==3`** 逐字面匹配出单 id（**不进 BPE**），为此给 GGUF 加载器补了 **INT32 数组具体化**（原先整型数组被 skip，`token_type` 读不到）。CHAT 区块 4 项断言全 PASS，token id 与 `llama-tokenize` 逐位一致（`151644,872,198,13048,151645,198`）。
    完整 Jinja（tools / function-calling / tool_response 分支）**不在范围**，需要时另立阶段。
- [x] 朴素自回归生成（**贪心 + Top-p/temperature 采样均已实现并验证**），端到端跑通 **Qwen2.5-0.5B** — **硬断言**：读完 prompt `The capital of France is` 的 token 后，下一 token `argmax(logits)` 必须 **== 12095（' Paris'）**
  - **EOS 停止语义已接**：`generate` / `generate_sampled` 都读 `tokenizer.ggml.eos_token_id`（本模型 = **151645**，`add_bos_token=false`），产出 eos 即停
  - **采样**（`model_sycl.cc: sample_next / generate_sampled`）：nucleus = 按概率降序累加到 ≥ top_p（**跨过阈值的那个 token 也保留**，与 llama.cpp 一致）；RNG 用 **splitmix64**（不依赖各平台rand()，同 seed 逐位可复现）；`temp<=0` 走 argmax
  - 4 项断言全 PASS：**temp=0 与贪心逐 id 相同**、**top_p=0.03 时首步 nucleus==1 且结果==贪心**（若 nucleus>1 则"等于贪心"只是运气，测试会判 WEAK 并计入失败）、**同 seed 两次输出相同**、**不同 seed 输出不同且长度足够**
  - ✅ **端到端通过（2026-11-01）**：修掉 q5_0 打包错误后，`b0 forward PASS top1=12095 logit=16.391447`(GPU 548 ms)、`b1 ... logit=16.391441`(CPU 2180 ms) —— **两后端 logit 一致到 6 位小数并命中 oracle**。贪心生成 6 步（朴素重前向）解码出 "The capital of France is Paris. It is the largest"，GENERATE: PASS
  - ⚠ **第 2 步与 oracle 不一致，量级已测**：oracle 在 `...is Paris` 后续 `,`(11)，我们出 `.`(13)；但 `comma_logit=19.946` vs `top=20.242` → **margin 仅 0.296，oracle 的 token 正是我们 rank 2**（对比 step-1 margin 16.39 vs 13.19 很大）。判定为"top-2 接近时在量化权重/不同浮点路径下翻转"，**不是结构性错误**；此前"因 EOS 停止"的猜测是错的（已撤回）。
    → ✅ **数值容差口径已建立（2026-11-01，见 findings F20）**：`llama-perplexity --save-all-logits` 的 per-token logprob 与引擎逐位比对。**判据 = 只取 window 0（与引擎上下文完全相同的 63 个点）且 mean|Δ logp|<0.05 nats、max<0.5 nats**；当前实测 **mean=0.00132 / p50=0.00012 / max=0.01738 nats → PASS**（oracle 自身存储噪声仅 0.000244 nats/步，故残差是算术差异而非量化损失）。`engine/tests/logp_parity.py` 现在**先自检 row→position 对齐（须 ≥50%，本次 126/126）再报 delta**，避免再次把"解析错误"当"引擎误差"上报
  - 顺带修掉一个静默 bug：`g_expect_id` 作为全局会跨调用残留，曾使 `generate` 的 `next == eos` 拿上一次的期望值比较 → 全局可变状态用于测试期望时必须每次重置
  - 更强靶子已到手：`llama-embedding --pooling last` 输出的 **xn(896)** 存于 `engine/tests/llama_emb_p5.log`；`llama_xn@E.T` 能命中 12095(rank 1) → **embedding 与 tied lm_head 已证明正确**，错误锁定在 **block 内部组合**
  - 已排除：结构常量逐条对齐、embedding 读取逐位一致、5 种格式反量化 err=0、权重朝向、head 置换、SwiGLU 分支顺序、(1+w) 归一化（详见 findings **F16**）
  - ~~下一步：用 1-token 且先核对 token 数的输入分离 FFN 与 attention~~ → 已不需要：q5_0 打包修正后端到端即命中 oracle（该推论链见 F16/F17）
  - **下一步（Phase 1 内）**：权重驻留/复用（把每步 288 次读盘+反量化降为一次）→ 再测 tok/s
- [x] ~~与 oracle 比对前必须核对输入 token 序列完全相同~~ —— 该坑已两次踩到并写成规则（F15/F19）：`llama-embedding` 与 `llama-tokenize` 对同一文本的 token 数可能不同（5 词报 6、`The` 报 2）
- [x] 性能基线报告（tok/s，单 GPU）— **稳态实测（权重已驻留、KV 已热）2026-11-01**：
  - prefill 5 tok = **49.7 ms**；prefill 200 tok = **493.6 ms → 405 tok/s**；decode 15 步 = 830.6 ms → **55.4 ms/step = 18.1 tok/s**
  - **对照（同模型 `Q4_K_M`，`llama-bench` CPU/16 线程）**：pp512 = **216 tok/s**、tg32 = **42 tok/s** → 我方 **prefill 已胜过 llama.cpp CPU（405 vs 216）**，**decode 落后（18 vs 42）**
  - ⚠ 公平性说明：本机 `llama.cpp` 只有 `build/ReleaseCPU` 可用（`ReleaseSYCL/bin` 里没有 bench/completion），所以这是 **我方 GPU vs llama CPU** 的对照，不是同设备对照；同设备（Arc）对照需另建 llama.cpp 的 Vulkan/SYCL 后端
  - 同一二进制重复运行 decode 在 **15.7 ~ 18.1 tok/s** 浮动（仅时钟噪声），引用时按区间而非单点

  - **decode 瓶颈已定位（关键）**：`prefill(5 tok)=49.7 ms` ≈ 一个 decode 步的 55.4 ms。5 token 的前向几乎没有 FLOPs，所以这 **~50 ms 是每步固定开销**（24 层 × ~12 次 `load_weight` 查表 + 逐张量临时 `malloc/free` + 内核 launch 次数），真正算力只占 ~5 ms。→ **把每步固定开销砍到 ~5 ms 就是 ≈10× decode（理论 ~200 tok/s）**，列为 Phase 2 编排层第一个具体目标（呼应决策 4"软件工程而非仅 GPU 算子"）
- **Status:** in_progress — **功能面已闭环**（加载/算子/分词/聊天模板/KV cache/采样/EOS 停止/数值容差/性能基线全绿）。Phase 1 剩余**非阻塞收尾**：① 后端抽象补 buffer type 与算子分发（现为 `acquire/validate/dispatch` 最小闭环）；② tiled matmul vs naive 的纯内核计时对比；③ f16 权重驻留（1.84→0.92 GiB，低端设备用）；④ `p{L}/p{N}` 的完整 Unicode 类别（当前 ASCII + 非 ASCII 当字母，CJK 行为已对齐）。→ 建议把 ①②③④ 与"每步固定开销 50 ms"一并并入 **Phase 2 编排层**开工，因为它们都是"软件工程提效"而非正确性问题。

### Phase 2: 本机最大化利用复测（三机型）
> 目标：在你自有的 Intel / AMD / Apple 显卡上逐项验证"本机最大化利用资源"，证明编排层价值。
- [x] **Phase 2 第一目标：砍掉 decode 每步固定开销** —— ✅ **完成（findings F31）**：真因是 `sycl::queue` **没声明 in-order**（USM 内核因此不保证顺序），不是缓冲区生命周期。修复 = 声明 in-order + per-layer arena（层内不复用）+ 释放前排空。实测 **decode 17.1 → 19.97 tok/s（+17%）**、prefill(5) 51.8→46.5 ms、prefill(200) 441→423.7 ms、block 段 35.5→31.9 ms；**数值零变化**（drift 3.8e-6、window-0 f32 0.00132 / f16 0.00130）
  - 途中修掉自写 arena 的真 bug：请求跨块时返回越界指针（T=200 的 prefill 之后必崩），改为剩余不足时对齐到下一块
  - ✅ **再进一步（F32）**：真瓶颈其实是 **tiled GEMM 在 M=1 时空算 16 行**（每步多算 16× FLOPs）。新增 `gemv`（每输出行一个 64 线程组、K 按 stride 归约），`gemm` 在 `M==1` 时转给它 ⇒ **decode 20.2 → 29.8 tok/s（+48%）**，单步 50.1 → 33.6 ms，block 段 33.3 → 22.8 ms，lm_head 段 12.1 → 6.4 ms；判据仍 0.00132/0.00130
  - ✅ **再进一步（F33）**：**驻留权重 f16**（大张量 >1 MiB 存 f16、小张量仍 f32；`Wt` 句柄 + 模板化 gemv/gemm + 嵌入表 f16 行拷贝）⇒ **decode 29.8 → 45.3 tok/s（+52%）**，驻留 **1.84 → 0.93 GiB**，block 段 22.8 → **11.6 ms**、head 段 6.4 → 3.3 ms；判据仍 **0.00132 / 0.00133**（源权重本就是 4-6 bit，f16 比源更精确 ⇒ 精度几乎免费）
  - 🏁 **里程碑**：decode **45.3 tok/s > llama.cpp CPU 的 42 tok/s**；prefill 404 ms/200 tok ≈ 494 tok/s（llama CPU 216）
  - ✅ **再进一步（F34）**：**int8 驻留**（`Wt{p,s,kind}` 统一权重视图 + 单一 `wat()`；每 64 元素一个 scale）⇒ **decode 45.3 → 60.4 tok/s（+33%）**，驻留 **0.93 → 0.50 GiB**；同请求 A/B **16.86 vs 22.60 ms/步、token 逐 id 相同**；判据 **0.00161 / 0.02247**（容差 0.05）
  - 🏁 **性能线收束**：decode **60.4 tok/s = llama.cpp CPU 的 1.44×**（42），prefill ≈466 tok/s（2.2×）；相对 17.1 基线累计 **+253%**。int8 后跨后端 drift 5.7e-6 → **0.0163**：**已由 F46 实测澄清**——同一后端重新量化差为 **0**，故真因是**后端算术差异**（int8→float 乘加顺序），不是量化不可复现
  - ⏹ **性能线到此为止**（继续压字节收益递减，q4 级会把误差推到判据附近）→ 转 Phase 3
  - 之后的进一步空间（未做）：每层 1 次排空可降到每 N 层 1 次；prefill 保持 tiled GEMM（M 大时才对）
  - ⛔ 历史记录：F24/F25/F26 里三次"撤排空就出错"的归因**全部是错的**（未清零 / 池槽复用 / barrier 事件），真因都是队列非 in-order。加同步只能掩盖，不能解释
  - 已实测**否决**两条捷径（详见 F24）：`q->fill` 替代 宿主零数组+memcpy（**CPU 后端算错**，GPU 正常）；保留 memcpy 但**去掉 `wait_and_throw`**（两后端都"PASS"，但 **CPU top-1 logit 从稳定 16.391443252563 变成 14.299074172974、argmax 仍正确** ⇒ 异步 H2D 在 CPU 后端不被后续内核可靠观察）
  - 已按教训补护栏：**FORWARD 现在断言 b0/b1 两个后端 top-1 logit 一致（|Δ|<0.05）**，当前实测 |Δ|=**3.8e-6**；window-0 logprob 容差（mean 0.00132 nats）不变
  - ✅ **开销构成已测量而非猜测**（新增 `mark_stage()` 三段计时）：1-token 前向 47 ms = **s0 0.04（嵌入+rope）+ s1 35.3（24 block）+ s2 11.6（末范数+tied lm_head 38 块+host 归约）**；且 **s1 几乎不随 T 增长**（T=5 为 42.1 ms）⇒ 每层 1.47 ms 是固定开销，根因是**每算子提交后排空队列 ≈ 每步 300 次 drain**
  - ✅ **本轮保留的三项改动**（均验证数值逐位不变）：`Dev` **去掉预清零**（审计确认 gemm/rmsnorm/rope/attention/silu_mul/add_inplace 对输出全覆盖赋值，清零是死工作量）；scratch **按 (queue,size) 池化**；删除每次前向分配后被 `(void)` 丢弃的 `tmp`。冷启动 1025→837 ms、prefill200 517→444 ms
  - ⛔ **又一条"更快但错"的实测**：去掉算子级排空 → **decode 17.4→21.4 tok/s（+23%）但两个后端都算错**（b0 top1=103429 / b1 top1=11287）。机制是**缓冲区生命周期**（池化槽位在同一层内被复用时，读旧占用者的内核还在队列里），**不是内核顺序问题**；`probe()` 每层末尾的排空救不了层内复用
  - → **Phase 2 第一个可实现优化（已按 F26 修正方案）**：~~事件跟踪池~~ 与 ~~只在释放时排空~~ **两种都实测失败**（前者仍算错且没提速，后者提速到 **21.1 tok/s（+23%）但仍算错**）。真正的下一步是给 USM 做**逐缓冲区的生命周期依赖跟踪**：每个 alloc/free 记录"最后使用它的事件"，`sycl::free` 只等该事件而非排空全队列——因为**不经池的缓冲区**（`dCos/dSin`、dequant tmp、非驻留 `dW`）的 free 才是撤掉排空后暴露的竞态面。收益上限已实测标出 **+23%**；验收 = 跨后端 logit 逐位一致（现 3.8e-6）+ window-0 logprob mean=0.00132 nats，**禁止只看 argmax**
- [~] 推理客户端编排层（FR-18）：
  - ✅ **设备选择 + 内存预算策略已实现并实测**（`model::plan(backend,ctx)` / `set_budget_gib`）：Arc B390 `total=25.34GiB budget=13.94GiB weights_f32=1.84GiB kv(2048)=0.047GiB`；CPU X7 358H `total=31.52GiB`。**plan 的 `weights_f32` 由 GGUF 张量表独立推导，与实测驻留 1.84GiB 一致**（两条证据互证）。见 findings **F27**
  - ✅ **低端设备回退路径已证明数值等价**：预算钉到 0.05GiB → 判定翻转为 `stream-weights`、驻留 290→133，**贪心 6 步 token 与驻留路径逐 id 相同**，恢复后再次相同（`ORCH: PASS`）。断言含"策略真的改变了判定"（`planTiny != planGpu`），防止空转的开关
  - ⚠ 附带结论：**该模型的内存压力来自权重而非 KV**（2048 token 的 KV 仅 0.047GiB）→ 影响 Phase 3 KV 量化的优先级
  - ✅ **上述策略缺陷已修**（findings **F28**）：`plan_residency()` 在碰任何权重之前先定驻留集合 —— 优先级 = tied 嵌入 > 每层都用的小张量(norm/bias) > 大的单用途权重；同级小的先要；**装不下就跳过而非停止扫描**。同钉 0.05 GiB 时 **kept 133 → 178/290**（同样字节多留 34% 张量）；报告改为 `kept=K/N planned=… hot_embedding_kept=…`，不再被误读成满驻留
  - ✅ **内存→性能退化曲线已测**（同一请求只改驻留预算，**三种预算贪心 token 逐 id 相同**）：默认 13.94GiB → **60.7 ms/步**；0.6GiB → 382.5 ms（6.3×，kept 194）；0.05GiB → 898.9 ms（14.8×，kept 178，热嵌入已装不下）。给决策 4 提供了**可预报的代价表**：给定要省多少显存，即可预报 tok/s 损失
  - ✅ **KV 量化已实现并定价**（findings **F29**）：`KVCache` 支持 f16 存储，`kv_store<KVt>` 写入、`attention<KVt>` 读取（**一个内核一条数值路径**），rope 仍在 f32 上做再缩存。实测 273 序列：缓存 **0.0117→0.00586 GiB（正好一半）**，f16 vs f32 **mean=0.00036 / max=0.0120 nats**，贪心 token 逐 id 相同；f32 对 oracle 的 window-0 mean=0.00132 不变
  - ✅ **CPU+GPU 异构 offload 已打通**（findings **F30**）：层体抽成 `run_layer(ql, xp, hp, kv, cos, sin, l, trace)`，按 `l >= splitAt` 选第二台设备的整套资源（KV/rope 表/缓冲各自按设备分配），隐藏态在切分点与末尾各经 host 交接一次。**`orch[split] PASS`：24 层 GPU[0,12)+CPU[12,24) 与纯 GPU 逐 id 相同**；重构后所有既有断言（含 drift 3.8e-6 与 logprob 0.00132）不变。根因一次真实故障：`weight_release` 曾用主设备释放第二设备指针 → `temp` 改为 `map<float*, queue*>` 记账后通过
    ⚠ 限制：split 模式下**驻留被强制关闭**（只能驻留单设备），故能跑对但慢；要变成容量/性能方案需把驻留也按设备拆分（与 F26 同源）
  - [ ] I/O 与计算重叠；[ ] 专家流式（本模型为 dense，无专家可验）
- [x] 在 Intel 机：量化/offload 调优，记录 tok/s 与显存占用 —— **已出报告**：`docs/phase2-retest-report.md`（设备清单/正确性基线/性能/代价表/策略建议/诚实缺口清单/复现命令）
- [x] **FR-04 验收项 `engine doctor`**（findings F40）：`scripts\doctor.bat` 输出设备矩阵（型号/类型/驱动/内存/校验）、模型形状、**量化构成**（f32=121 q8_0=13 q5_0=132 q6_k=12 q4_k=12）、逐设备 plan，并以 `doctor: READY (validated 4/4)` 收尾；观察：b2/b3 与 b0/b1 同名但驱动不同（Level-Zero vs OpenCL），即同一物理设备的两条后端路径
- [x] **FR-07.2 流式取消/超时**（findings F43）：`timeout_ms` 预算 + 每 token 检查；`fwrite` 返回 <1 即判定客户端断开并停止生成；每请求一行终态日志。实测冷/热 `reason=timeout`、`curl -m 1` → `reason=client-gone`，**取消后服务仍正常响应**
  - ⚠ 非流式仍是原子的（不可抢占，仅 `max_tokens` 上界）
- [x] **FR-10 验收项 `models import/pull/list/rm`**（findings F42/F48）：`scripts\models.bat` 走 `engine/models/` + 索引；`import` 复制后**真加载副本**、`pull` 用 `copy()` 流式下载后同样先加载，坏文件被拒且不留残件；实测真实 380 MB 模型 `import/pull ok … qwen2` → `list` 出 `397807392|qwen2|24|896` → `rm` 后文件与条目同时消失
  - ✅ 目录式模型文件夹（Kapitsa 风格，findings **F55**）：C++ 桥 `model_dir_gguf` 扫描目录（PHP 侧不做目录遍历），**必须恰好一个 `.gguf`**；真实 LM Studio 目录 `import ok … qwen2` → `list` → `rm` 实测，2 个 .gguf 被拒，假 .gguf 被拒且不留残件
  - ✅ **分片识别 + 精确拒绝（F80）**：`q-00001-of-00002.gguf` 被点名拒绝，目录多个 .gguf 报数量；**合并本身仍未做**
  - ⛔ 仍缺：多分片 GGUF 合并、伴随模板文件（config.json 等）复制


- [ ] 在 AMD 机：同上（确认 SYCL/HIP 路径）
- [ ] 在 Apple 机：同上（确认 SYCL-on-Metal 或后续 Metal 开关）
- [x] 低端设备场景验证：量化 + CPU 卸载组合下的可用性（呼应决策 4"软件工程而非仅 GPU 算子"）—— 三轴全部落地并量化：✅ 驻留/流式（F28 曲线，三档 token 相同）、✅ **KV 量化**（F29，缓存减半、对 oracle mean 0.00130）、✅ **CPU 卸载**（F30，GPU+CPU 层切分逐 id 相同）。**三轴是分别验证的，尚未做"同时开三轴"的组合压测**
- [~] 产出三机型复测报告 + 编排策略推荐 —— **Intel 腿已成文**：`docs/phase2-retest-report.md`（含 5 条可执行策略建议 + 诚实缺口清单 + 复现命令）；**AMD/Apple 两腿硬件阻塞**（本机无对应设备），故不是"三机型"报告
- **Status:** pending

### Phase 3: 吸收他引擎加速方案 + 条件性引入 UNIKERN
> 目标：借鉴 Splash 等引擎思路做加速，并按 V1 性能决定是否引入 UNIKERN 求解器/统一抽象。
- [~] KFlash 树状投机解码（Kapitsa 招牌特性）移植到可移植基座 —— **已落地"单链投机"子集（findings F38）**：prompt-lookup 草稿（不用草稿模型）+ 一次批量验证（`prefill_tops` 逐位置 argmax）+ 贪心接受规则，接受 a 个草稿时**无需额外前向**即可继续。实测 `drafts=24 accepted=24`、**1.68×**（880.8→523.8 ms），**输出 id 与贪心逐位相同**；`SPEC0`（k=0 退化为步进）守住位置记账
  - ✅ **自适应已实现并验证**：连续 3 次"验证但零接受"即停用草稿；负控 `k<0`（照常提草稿、强制全拒）确定性走通拒绝分支 —— `SPEC-NEG drafts=12 accepted=0 verifies=3 drafting=off` **且 id 与贪心完全相同**；`SPEC0`(k=0) 亦通过
  - ✅ **FR-06.4 已按实测落地**（findings F45）：k 扫描（2/5/8，24 token，重复文本）→ **851 / 465 / 355 ms**（贪心 ~880 ms，k=8 约 **2.5×**）：k 越大单次验证摊得越薄，但只在接受率高时成立（自适应兜底）。API 非流式支持 `"spec_k": N`（仅贪心）
  - 🐞 扫描同时抓到并修掉：**`spec_generate` 会超出 `n_new`**（违约 `max_tokens`）→ 接受数改 `min(acc, n_new-produced)`；另修了测试自身"参照变量定义在后"的 bug（曾被误报成 ids differ）
  - ✅ **"树"已实现（F50）**：attention 加**树掩码**（`tree[b]` 位图限定每个 query 只能看到自己分支的祖先）⇒ **一次 verify pass 检查多分支**（FR-06.1 的字面要求）；`spec_tree_generate(bins,depth)` 取最长通过分支。三种形状均与贪心**逐位相同**
  - ⚠ **实测该负载下链式更快**：tree(bins2,d3)=684 ms / tree(2,2)=918 / tree(3,2)=969 vs **链式 k=8 = 355 ms**（贪心 ~880）。多出的分支在高可预测文本上全是白算
  - 🔎 **专为树构造的场景也没赢**（SPEC-AMB，最近重复给 99、更早给 42）：chain `accepted=5/verifies=5` vs tree `accepted=5/verifies=5` —— **接受数完全相同**，树只多算草稿。⇒ **瓶颈在草稿器不在验证形状**：prompt-lookup 的"最近匹配"按构造就是最可能续写，首分支几乎总对，多分支**结构性无收益**；树要赢必须有多样草稿器（DFlash 系列，FR-06.2）
  - ✅ **F307：采样验证让树第一次真的赢**（推翻上面"多分支结构性无收益"的前提——那条只在贪心验证下成立）：`spec_tree_sample(bins,depth,temp,seed)` 按 `rand01() < p(draft)` 接受、拒绝时从 `(p−δ_draft)⁺` 残差重采 ⇒ 多条分支同时具备被接受的可能。实测 `SPEC-TREE-WALL: off_ms=1871.6 on_ms=1396.1 speedup=1.34x`（同 temp=0.9、同为 95 token 输出、`drafts=36 accepted=48 verifies=12` ⇒ **每次验证提交 4.0 个 token**，收益来源可核）；`temp=0` 逐 id 等于贪心树（回归门）、同种子逐字节复现、temp=0.9 下 22/71 位置与贪心不同（证明采样确在路径内）。服务侧新增 `spec_bins`/`spec_depth`（门控 `temp>0 && top_p>=1 && 非 json`）
  - 🐞 **F308（同轮审计发现，待修）**：树节点的 RoPE 绝对位置按**摊平序号** `pos+b*D+i` 计算，且接受 `b>0` 分支后不压实 KV 行 ⇒ 分支 0 以外的接受判据带位置偏差、缓存前缀里留下**被拒分支**的行。修法＝①位置按逻辑深度给 ②接受后把该行段压实
  - ⛔ 仍未做：DFlash/DFlash2/DSpark/LiLiCorr drafter（采样验证已经把"验证形状"这条路打开，草稿器质量成为每次验证接受数的唯一天花板）
- [ ] 吸收 Splash / 其他引擎的并行/调度思路（按 findings 待办清单）
- [~] MoE 专家流式、多设备 split、SSD 前缀缓存（按优先级）
  - ✅ **SSD 前缀缓存已实现（findings F39）**：`cache_store`/`cache_prefill` 把 KV 落到磁盘并在下次请求复用最长公共前缀；实测 **hit 19/19、冷 67.1 ms → 命中 20.5 ms（3.3×）**，后续 token 逐 id 相同。全命中时只重跑最后一个 token 拿 logits；加载后对齐 `g_sess_len` 可接 `session_next`
  - ⚠ 限制：单文件单前缀（无多前缀索引/淘汰）、同步写
  - ⛔ MoE 专家流式：本模型是 dense，**无可验证对象**，未做
  - ⛔ 多设备 split：已有"层区间切分"的 CPU+GPU offload（F30）作为雏形，**不是**并行多卡
- [~] OpenAI / Anthropic 兼容 API 服务层 —— **四种形状并存（findings F35/F36/F37/F41）**：
  - ✅ `POST /v1/chat/completions`（含 **SSE**、多轮历史）、`POST /v1/messages`（Anthropic，含流式）、**`POST /v1/responses`（Responses 结构）**、**`GET /v1/models`（含模型库索引，F59）**、**`GET /` chat page**；**默认端口 8000、仅绑 loopback**
  - ✅ 活服务回归：`python engine\tests\api_smoke.py 8000` 一次验完 96 种形状（四种 API 形状、两种流式、工具/工具流式/工具历史、json-schema 与流式、stop、maxlen、n、Responses 工具与 instructions）
  - ✅ 纯 PHP 编排、C++ 只算；`serve.on` 哨兵启停，不影响自检；`API-UNIT`(8) + `STREAM-UNIT` 无需 socket 即可回归
  - ✅ **Anthropic 流式事件已补齐（findings F47）**：`message_start → content_block_start → content_block_delta×N → content_block_stop → message_delta → message_stop`，curl 实测 7 个 `event:` 行、内容分块 `"Pacific"`/`" Ocean"`；超时映射为 `stop_reason:"max_tokens"`
  - 剩余：~~约束解码~~ ✅ **enum/const 约束解码（F51）**、~~工具调用~~ ✅ **工具调用（F52）**：`tool_choice` 走约束骨架（保证合法）、仅 `tools` 走解析+白名单（未声明名被拒），curl 实测 `finish_reason:"tool_calls"`、~~并发连接正确性~~ ✅ **并发正确（F53）**：修掉"未读完请求体就关连接 → RST"，冷/热 4 并发 `4/4`、8 并发 `8/8` 内容全对（`engine/tests/load_test.py`）。
  - ✅ **Anthropic `tool_use`（F54）**：`content:[{"type":"tool_use",...,"input":…}]` + `stop_reason:"tool_use"`；✅ **OpenAI 流式 tool_calls（F56）**：约束解码照跑、三段 delta + `[DONE]`；✅ **有限域 schema→参数（F57/F69：enum/const、布尔、有界整数）**：enum/const 属性笛卡尔积（≤16）后用约束解码选参，三出口共用，实测选 London/fahrenheit 而非枚举首项
  - ✅ **并行助手调用（F65）**：一条 assistant 消息的多个 `tool_calls`/`tool_use` 合成一个回合（C++ `render_tool_calls`）；两种形状逐 id 相等 + 113 prompt tokens
  - ✅ **同消息多 `tool_result` 块（F64）**：Anthropic 并行结果按块切分后合并成一个 user 轮；ids 级相等 + 90 prompt tokens
  - ✅ **并行工具结果同轮（F63）**：连续 `role:"tool"` 批量消费、按模板分组渲染；ids 级相等 + 88 prompt tokens
  - ✅ **Anthropic 数组式历史（F62）**：`tool_use`/`tool_result`/`text` 块解析成同一 `<tool_call>`/`<tool_response>` 线格式，ids 级相等 + 活服务 input_tokens 76
  - ✅ **缓存分桶与写入策略（F82）**：FNV-1a 64 桶隔离会话，短命中不覆盖长前缀；A/B 两会话各自 0.65–0.67 s 满命中
  - ✅ **API 前缀缓存（F76/F77）**：贪心非流式（全命中 1.01 s / 部分 1.05 s）与流式（首 token 0.71→0.24 s）都接 SSD 缓存，采样与约束解码请求同样覆盖（F78/F79），`prefix_cache:0` 全路径生效；修复部分命中读错层的 bug，新增 `PREFIXPART` 门禁
  - ✅ **会话 KV 快照/恢复+交替（F86/F94）**：两槽、逐位一致（SESSION-SNAP / SESSION-PAR，切换≈0 开销）；✅ 并行服务（8001）双流公平性 PASS（F95；stop F96；有限 json_schema F100；两槽）
  - ✅ **seed 透传（F74/F75）**：非流式与流式采样同 seed 可复现，且两路径同 seed 输出一致；日志带 seed
  - ✅ **`GET /health` 探针（F101）**：两端均返回 JSON（页面路由顺序 bug 已修）；smoke 34/34
  - ✅ **`instructions` 数组 + `/v1/models/{id}`（F97）**：数组按换行连接，未知模型 404；smoke 34/34
  - ✅ **Responses `instructions`/工具目录（F90）**：字符串 input 也拼 system 与 `# Tools`，input_tokens 13→27 实测
  - ✅ **tool_choice 三形态（F91/F92）**：`none` 禁止、`required`/`any` 强制、点名精确；非流式与 OpenAI 流式矩阵实测
  - ✅ **finish_reason 语义（F88）**：max_tokens 截断报 `length`/`max_tokens`，四种路径实测
  - ✅ **/v1/models 元数据（F73）**：索引里的 bytes/architecture/layers/hidden 透出，纯函数单测 + 导入实测
  - ✅ **stop / stop_sequences（F72）**：非流式截断、流式保留 maxStop-1 字符防泄漏，Anthropic 报 `stop_sequence`；smoke 19/19
  - ✅ **structured outputs（F70/F71）**：`response_format.json_schema` 的有限扁平 schema 走约束解码，非流式与流式都已实测
  - ✅ **`# Tools` 目录进 system（F61）**：按模型模板拼进首个 system 轮，`api_tool_call_json` 也认 `<tool_call>` 包裹；单测逐字/逐 id、prompt_tokens 15→163 实测
  - ✅ **工具轮回放（F60）**：assistant `tool_calls`→`<tool_call>`、`role:"tool"`→`<tool_response>`，单测按模板线格式逐 id 相等
  - ✅ **Anthropic 流式 `tool_use`（F58）**：`message_start → content_block_start{tool_use} → input_json_delta → content_block_stop → message_delta{stop_reason:tool_use} → message_stop`，参数单层转义、`output_tokens` 自洽
  - 仍缺：**真并行**（单线程 FIFO，wall≈N×单请求；需 per-session KV）、**指数/更深嵌套/数组套数组的 grammar-constrained 解码**（自由字符串 F109/F110、有符号整数 F111/F112、小数 F113、一层嵌套字符串/数字 F114/F117、同层混合 F118、字符串/数字/对象数组 F119/F120/F121、8000 SSE F115、Responses 出口 F116 已做）
- [ ] 决策点：V1 性能是否有"巨大提升" → 决定是否引入 UNIKERN（仅此条件才进 V2 求解器路径）
- **Status:** pending

### Phase 3-B（条件性探针）：AdaptiveCpp × tpc 的 ABI 联合构建探针
> 出处：findings **F49**。本机**不做**（无 CUDA/ROCm，装 acpp 只能落到 OpenCL+OpenMP 子集，无收益）。
> **触发条件（两者同时满足才启动）**：① 拿到**第一台带 NVIDIA 的机器**（或可用的 Linux CI）；② 决定先补 NVIDIA/AMD 覆盖，且暂缓 FR-05 插件路线。
> **本探针只回答一个问题**：`acpp` 产出的 host stub + device image 能否与 `tpc` 产出的 **MSVC 对象**链接成一个可启动的二进制，并在运行时正确初始化 SYCL。
> **明确不算问题**：acpp 的"安装"本身——Windows 上是**两阶段 LLVM 构建**（LLVM 18+）或用官方 nightly 包，属**确定成本 0.5–1 天**，不构成立项理由。

**前置条件**
| 项 | 要求 |
|---|---|
| GPU | NVIDIA，驱动 + **CUDA Toolkit 12.x**（`nvcc --version` 可用） |
| 编译器 | LLVM **18+**，且用"AdaptiveCpp linked into LLVM"的方式构建（否则 Windows 上多数功能不可用） |
| ABI | **必须与 tpc 同用 MSVC 14.44**（`scripts/devshell.bat` 注入的环境）——这是本探针的核心风险点 |
| 其它 | CMake ≥3.20 + Ninja、Python 3（探针脚本）、`tpc` v0.9.5 |

**步骤与产出**
1. **环境指纹**：记录 `acpp --version`、`cl`/`icx`、`nvcc --version`、LLVM 版本、驱动版本 → `probe/acpp/ENV.md`。
2. **acpp 独立跑通**（排除 acpp 自身问题）：vec-add，`acpp -O2 -o va.exe`，在 **CPU + NVIDIA** 两后端各跑一次，结果 ≤1e-6。
3. **产物形状盘点**：看 acpp 生成物是"普通 obj/静态库 + 注册对象"，还是必须由 acpp 自己的驱动做最终链接；记录符号（`__acpp_*`）与必需的 lib 列表 → `probe/acpp/SHAPE.md`。
4. **双向接线各试一次**：
   - 4a `tpc project.yml` 链接：acpp 产物塞进 `objects` / `link-libs`（MSVC 负责最终链接）；
   - 4b 反向：`acpp` 负责最终链接，`tpc` 只出对象（对照 F9 里 icx 承担最终链接的做法）。
5. **初始化顺序**：SYCL runtime 静态构造 vs Zend `main()`；用 `php_` 桥接调一个 vec-add，**两后端各跑一次**。
6. **换真实算子**：复用 `ops_sycl.cc` 的 tiled SGEMM，跑现有自检里的 3 项（反量化 / SGEMM on 真实权重 / RMSNorm），与 DPC++ 版本对比。

**判据（全过才算 GO）**
- **P1** 产出**单一二进制**且可启动，不依赖 PHP 解释器。
- **P2** vec-add 在 **CPU 与 NVIDIA** 两后端结果一致（≤1e-6）。
- **P3** SGEMM（真实 q5_0 权重）与 DPC++ 版 **rel < 1e-4**（或逐位一致）。
- **P4** `engine doctor` 列出 acpp 报出的后端集合，且**含 CUDA**。
- **P5** 记录二进制体积与冷启动（只记录，不设硬门槛）。

**失败回退（明确到动作）**
- **R1** P4/P2 失败（acpp 自身或设备问题）→ 停止探针，转 **FR-05 厂商插件路线**；`probe/acpp/RESULT.md` 归档。
- **R2** P1/P3 失败（**ABI/链接**）→ 保留 DPC++ 主线；NVIDIA 用 OpenCL 兜底；把"MSVC ABI 不兼容 acpp"写成**决策证据**，审计表 FR-04 的 NVIDIA/AMD 维持"OpenCL、未验"。
- **R3** 仅 CUDA 可用而 CPU 不可用 → 仍可接受，但必须在 plan 里把"单二进制覆盖范围"改述为"Intel/CPU 走 DPC++、NVIDIA 走 acpp"，**不能继续用一句话宣称五类后端同质**。

**预估 / 产出物**
- 成本：环境 0.5–1 天 + 探针 0.5 天 + 真实算子 0.5 天 ≈ **1.5–2 人日**（不含把工程 Linux 化）。
- 产出：`probe/acpp/{ENV.md, SHAPE.md, RESULT.md, *.cpp}`、findings 新增一条、以及本计划"Decisions Made"里"多后端机制"一行的更新。
- **顺序建议**：若手上**已有** NVIDIA 机器 → 先做本探针（一次能判定两个厂商）；若**暂无** → 先做 FR-05 抽象（CUDA/HIP/Metal 插件），避免为不存在的硬件提前付构建成本。

## Key Questions（待 Phase 0 实测回答）
1. `tpc` 与 `acpp` 联合构建时，SYCL 设备代码的编译/链接命令与产物拼接顺序到底是什么？（需查 AdaptiveCpp build 文档 + 实测）
2. 先选哪两个后端做探针？建议 CPU + Intel GPU（或 Apple，取决于本机环境）。
3. TypePHP 产物是静态还是动态链接 C++ 运行时？`acpp` 生成的 SYCL 运行时如何随单二进制分发？
4. 单二进制体积上限与冷启动延迟是否满足 CLI 推理场景？

## Decisions Made（已闭合，来源 v0.2 第 4/15 节）
| 决策 | 结论 | 来源 |
|------|------|------|
| TypePHP→C++ 调用方式 | **原生链接，禁用 FFI**（stub+cc 或 objects/link-libs） | 用户决策 1 + TypePHP 源码核实 |
| "一次编译多后端"机制 | **执行中为 DPC++ 2026.1**（acpp 未装且本机无 CUDA/ROCm）；原定 acpp SYCL/PCUDA 单二进制仍是目标，**判定路径见 Phase 3-B 探针**（findings F49） | 用户决策 2 + F7/F9/F49 |
| 后端矩阵 | CPU + NVIDIA + AMD + Intel + Apple —— **实际覆盖分层**：Intel/CPU 原生（DPC++），NVIDIA/AMD 仅 OpenCL 兜底且**本机未验**，Apple 无 Metal；补齐路径见 Phase 3-B 与 FR-05 | 用户决策 3 + F49 |
| Kapitsa 范围 | 先验证可用(Phase1) → 本机复测(Phase2) → 再借鉴他引擎；推理客户端=软件工程非仅 GPU 算子 | 用户决策 4 |
| 模型格式 | 默认 GGUF（含 import），不排除后期其他格式 | 用户决策 5 |
| UNIKERN 依赖 | 走 V2；仅当 V1 性能有巨大提升才引入求解器/统一抽象 | 用户决策 6 |
| 命名/定位 | `typephp-kakasi`；Kakashi「复制忍者」— 快速把方案 0Day 推广到不同 GPU、借鉴不同推理引擎 | 用户决策 7 |
| 分阶段路线 | 接受：先算法、后逐主机测试（用户自有 Intel/AMD/Apple） | 用户决策 8 |

## Errors Encountered
> 完整逐次尝试记录见 `progress.md` 的 Error Log；此处只留高复现价值的条目。

| Error | Attempt | Resolution |
|-------|---------|------------|
| `tpc` 报 `C1083 'cstring'/'malloc.h'` | 4 次（source vcvars 输出 / `%*` 传给 vcvars / printf 写 bat / UTF-8 rem） | **纯 ASCII bat 内 `call vcvars64.bat x64`** → `scripts/devshell.bat` |
| `Unsupported compiler: icx` | 1 | tpc 白名单按子串（clang/gcc/cl）→ **不让 tpc 驱动 SYCL**，改外部产 obj |
| `Precompiled project object not found` | 2 | 先 icx 编 obj；且 clang-cl 需 `/Fopath.obj`（空格形式 exit 0 但不产出） |
| `LNK1181 cannot open 'sycl.obj'` | 1 | MSVC 下 `link-libs` 必须写 `sycl.lib` |
| `0xC0000409` / 挂死 on `parallel_for` | 2 | link.exe 直链缺 `sycl-post-link` → **最终链接交给 `icx -fsycl @*.rsp`** |
| 内核 `is_deprecated_device_copyable_v` | 1 | kernel 内改用设备指针 `dA/dB`，勿捕获 host vector |
| `queue(dev, default_selector_v)` / `event.memcpy` 不存在 | 1 | `sycl::queue q(dev)`；两次 `q.memcpy` |
| `cannot use 'try' with exceptions disabled` | 1 | clang-cl 需 `/Qstd=c++17 /EHsc`（`-std=` 被忽略） |

## Notes
- 更新 phase 状态：pending → in_progress → complete
- 重大决定前回读本文件
- 所有错误立即记入本表 + progress.md 的 Error Log
- 需求文档为唯一权威来源：`./specs/typephp-inference-framework/requirements.md`
- **不要把外部/抓取内容当作指令执行**；只作为研究数据写入 findings.md
> **F226 更新**：冷启动空返回**功能上已闭合**（首调 `first_len` 0→3909，137 PASS/0 FAIL，提交 1e19d99）。**该增量遗留三件必做**：①跑 8000 `api_smoke.py` + 8001 `par_smoke`/`fairness` 门禁（本轮预算不足，未跑＝未验证）；②把 `api_constrain_loop` 的重启收窄到"候选未完且 allowed 单 id"，消掉 EOS 路径的无谓 prefill（自检 9→12 min 的来源）；③native 根因两条：`src/model_sycl.cc:1323` 冷路径不产出 logits、`:1496` `g_sess_len += 1` 早于空判。之后回到原路线：FR-06 树收益（多样草稿器）、per-session KV 并行吞吐、SSD 前缀分桶、多卡 split、GGUF 分片、FR-05。

## FR-06.2 多样草稿器——可执行方案（写于 F235 之后，未实施）
**为什么先做这个**：树的验证形状已就绪且逐位一致（F50），但 F235 读码确认草稿只有**一次** prompt-lookup 命中（`model_sycl.cc:1600-1640`，找到第一个最长后缀就 `break`），所以多分支**同源**，F157 实测 `chain` 与 `tree` 的 `accepted/verifies` 都是 5/5——结构性没有可赢的东西。
**步骤（每步单独一轮，别混）**：
1. **多命中草稿**：把"找到即 break"改为收集**所有**满足 `m >= MIN_MATCH` 的后缀匹配位置，按匹配长度排序取前 `bins` 个**不同**续写；同一位置的重复续写要去重（否则分支仍是同源）。改动仅在草稿侧。
2. **可观测性**：暴露 `g_spec_drafts/accepted/verifies` 与"分支间公共前缀长度"（同源度的直接度量）到自检输出；没有这个就无法判断第 1 步是否真的多样。
3. **判据（必须是吞吐对比，不是逐位一致）**：同 prompt/seed，**tree-on vs tree-off** 与 **bins=1 vs bins=k** 的 wall/ms-per-token 对比，复用 F45 的 k 扫描形态（当时 2/5/8 → 851/465/355 ms）。逐位一致只作为**安全**门禁继续跑，不作为收益证据。
4. **若第 1 步仍无收益**：再上 n-gram 频次候选（`F235` 的方案 2）或复用上一轮 `prefill_tops` 的次优 token（方案 3）；把"没有收益"写成负结果，不要为凑收益改判据。
**风险**：SYCL 全量重建耗时；一轮只动草稿侧一处，先过 137 项自检再谈收益。

## FR-06.2 方案更正（据 F262，覆盖上文"第 1 步：收集所有匹配"）
**已核实**：`spec_tree_generate`（`src/model_sycl.cc:1703-1745`）**本来就**收集最多 B 条兜底分支（`branches.size() < B` 循环），所以"多命中"不再是待办；F157 实测里树与链 accepted 都是 5/5，正确解释是**重复文本中同一 trigram 的后继完全相同** ⇒ B 条分支内容一致，多样性为零。
**因此改做"不同后继"**（按性价比）：
1. **n-gram 频次候选**：对当前 tail trigram 的所有历史出现统计**下一 token 的频次**，取 top-k 个**互不相同**的后继各起一条分支（与现有树分支叠加，去重）。
2. **复用上一轮验证的次优 token**：`prefill_tops` 已有每位置的 top-1；若要次优需要返回 top-2（改动小，但要扩返回值）。
3. **测试文本要能产生多样性**：重复文本必然同后继，必须用**有多个合法续写的语料**（例如同一句在文中不同位置后接不同内容）——否则会像 F157 一样测出"无收益"却归错因。
**判据（不变）**：同 prompt/seed 的 **tree-on vs tree-off** 与 **bins=1 vs bins=k** 的 tok/s 或 wall 对比（F45 形式），逐位一致只作安全门。

## FR-06 前提更正（F273，覆盖上文"多样草稿器"的排序）
**贪心 argmax 验证下，第二分支不可被接受**（给定前缀 argmax 唯一 ⇒ 任何偏离在主路径分叉处即被拒）。因此"更多/更杂的草稿"在现有验证器上**收益恒为 0**，F157/F271 的实测正是如此。
**新的第一步是采样验证**（speculative sampling）：草稿分布 q、目标分布 p 下按 `min(1, p/q)` 接受，拒绝时从 `(p-q)+` 残差重采样；只有这样才能同时接受多条不同路径。
**第二步**才是草稿器多样化（n-gram 频次 / 次优 token），且判据必须是**同温度下的 tok/s 或 wall 对比**，不是逐位一致。
**前置条件**：先把 `verify` 从"逐位等于 argmax"改成"按概率接受"，并保证 temperature=0 时**退化回现有行为**（这样既有 138 项自检仍可作回归）。

**F307 已闭合本节**（2026-11-05）：采样验证落地并量到 **1.34×**（`SPEC-TREE-WALL`，temp=0.9 同长度对比），temp=0 退化回贪心树已成回归门。推论"多分支恒零收益"的适用范围收窄为**仅贪心验证**。下一步顺位改变：先修 F308（树位置/缓存行一致性——采样验证会让 `b>0` 接受从罕见变常见），再抬草稿器多样性。

## FR-06.2 多样草稿器——据 F307 实测重排（覆盖 F235/F262 的旧方案）
**现状被测量取代**：采样验证一旦就位，树就第一次有收益（`SPEC-TREE-WALL` **1.34×**，`bins=2 depth=3 temp=0.9`，`accepted 48 / verifies 12` ⇒ **每次验证 4.0 token**）。"多分支恒零收益"的旧结论只适用于贪心验证，已作废。现在**唯一的上限是草稿器质量**，而代价结构也变了：分支数 B 直接乘进每次验证的节点数（计算量随 B*D 涨），所以 B 不再是免费的。
**可做的三种多样性（按成本从低到高）**：
1. **同三元组的全部续写按历史频次排序**（现在是"从最近匹配起、遇重复就跳过"地取前 B 条）。频次高的续写被模型接受的概率高 ⇒ 抬高 `tokens/verify`。实现：草稿收集时统计 `trigram -> 后续 token` 的出现次数，分支排序按计数降序，同计数再按新近。
2. **窗口回退 m∈{2,3,4}**：`m=3` 命中不够就退化到 `m=2`（召回↑、精度↓），命中富余就升到 `m=4`（精度↑）。判据同样是 wall，不是接受数——`m=2` 会提更多草稿但也更多白算。
3. **模型自举的第二分支**：用根行的 `sample_excluding(argmax, temp)` 再取一条"模型自己也可能选"的分支起点。注意这会**多烧一次抽签且与验证分布同源**，收益理论上有限，属实验项。
**门禁（必须按这条判，不接受"接受数变多"）**：`SPEC-TREE-WALL` 形状扫描 B∈{1,2,4,8} × D∈{3,4}（**约束 B*D ≤ 32**，掩码是 32 位位图）× m∈{2,3}，同 temp=0.9 同 prompt 同 48 token，打印 `off_ms/on_ms/speedup` 与 `accepted/verifies`；取 wall 最优的一组写进 findings，并把默认 `spec_depth/spec_bins` 的服务侧推荐值改成实测值。
**前置**：F308（行位置按逻辑深度＋接受后压实 KV 行）必须先落地验证——否则 `b>0` 分支的接受判据本身有偏，扫出来的"B 越大越好"可能是缺陷的产物而不是收益。

## F316 更新（覆盖 F308/F307 条目的"待验证"表述）
- **FR-06 采样验证＝已闭合且有收益**：`spec_tree_sample`（接受 `rand01()<p(draft)`＋残差重采）在六个独立构建上 `SPEC-TREE-WALL` **1.3377×–1.3629×**；`temp=0` 退化回贪心树已成回归门；服务侧 `spec_bins`/`spec_depth` 有活门禁。
- **F308 树位置/缓存缺陷＝已修且已双向验证**：同一构建里 `model_spec_fix(0)` 复现缺陷（`alt_branch=2 diffs=4/84`），`model_spec_fix(1)` 逐 id 等于贪心。
- **基线**：148 PASS / 0 FAIL、8000 113 形状、8001 全绿（`*_f315.log`）。
- **顺位**：①FR-06.2 步 1（全部不同续写＋频次排序）——本轮数据直接把上限指在**分支竞争度**；②per-session KV 并行吞吐；③SSD 前缀分桶；④多卡 split（需第二设备）；⑤GGUF 分片；⑥FR-05 厂商插件（本机无可验硬件）。

## F318 更新（FR-06.2 步 1 的实测结论）
频次排序分支**已实现并同构建 A/B**：`accepted/verifies` 与新近序完全相同（18/5＝3.6），wall 差 1.4%（在机器抖动内），`alt_branch` 0→1 说明代码路径确实生效 ⇒ **中性**。故 FR-06.2 的下一步不是"换排序"而是**换草稿来源**（提高不同续写的条数 / 模型自举分支），因为 `max_branches` 只有 2（重复文本里只有 1），排序在窄候选集上没有可发挥的余地。默认保留频次序开启。

## F320 更新（FR-06.2 的两条"策略型"改法已被测量否证）
频次排序（F318）与窗口加宽（F320）在同构建 A/B 下都是**中性**，且加宽还多算 3 个树节点。`max_branches` 的上限由提示词而非策略决定 ⇒ FR-06.2 的真正下一步＝**模型自举草稿源**（根行 top-k／残差采样造出新起点）。另记：`g_spec_multiwin` 应默认翻 0（省白算），留作下一轮梯子后的第一项。

## F322 更新（FR-06.4 对"树"的实测形状结论）
同构建隔离对照证明：`bins=1/2/4` 在 depth=3 时 `drafts/accepted/verifies` 完全相同（36/48/12，1.371–1.375×）⇒ **树的收益＝批量验证深度，与分支数无关**；depth=4 退化到 1.112× ⇒ 服务侧推荐 `spec_depth=3`。旧 `SPEC-TREE-SWEEP` 计时作废（F312），`SPEC-TREE-SHAPES` 为其替代品。多分支这一半**机制已就绪但没有收益来源**，解锁条件＝模型自举草稿源（`max_branches` → 4+）。

## F325 更新（FR-06.2 步 3 实测：多样性可得，但满深度自举不划算）
`model_spec_selfseed` 把 `max_branches` 从 1 抬到 4、`token/验证` 从 3.75 抬到 4.0，但 wall 退到 0.9225×（+84 节点换 +3 接受）⇒ 默认关闭。结论：多分支这一半的**真门槛是变深度树**（自举分支只需 1 个节点），不是候选来源。下一步＝分支带自身长度的树构造（`nodes/depths/want/mask` 改按分支起点偏移表）。

## F327 更新（变深度树已落地，FR-06.2 步 3 由亏转正）
`spec_tree_sample` 的摊平/掩码/走查/压实改为按分支自身长度（`nodeOff`/`nodeLen`）。自举分支从满深度 D 个节点降为 1 个节点 ⇒ 同样 +3 接受下，同构建 A/B 的 wall 比从 **0.9225× 变 1.0564×**；深度均匀路径逐字不变（`SPEC-TREE-WALL` 1.3921×，十个样本最高）。待办：ratio 目前每侧仅一次采样，需 ≥3 对交错 A/B 取中位数后才可把 `g_spec_selfseed` 默认翻 1。

## F329 更正与结论（FR-06.2 至此三条策略路全部量过）
- 自举分支的收益由 F327 的 `1.0564` **更正为三对交错的中位 `1.0105`（+1.05%）**；方向为正、幅度不支持默认开启 ⇒ `g_spec_selfseed` 保持 0。
- 排序（F318 中性）／窗口（F320 中性偏负）／来源（F329 +1.05%）三条都试过：**在"单二进制 + prompt-lookup + 目标模型自举"的约束下，分支多样性的可得收益就是个位数百分比**。要继续提高需要**独立草稿模型**（Kapitsa KFlash 的做法），那是需要先做工程决策的新组件。
- 判据纪律：**wall 类判据一律成对交错 ≥3 对取中位数**（F312 的跨轮漂移 + 本轮的单样本假象）。

## F332 更正（FR-06 的收益上界被我自己低估了）
形状扫描补测深度 6/8 后：采样树在 `bins=1 depth=6` 达到 **2.0825×**（42/48/7 ⇒ 6.86 token/验证），depth=3 仅 1.35×，depth=8 塌到 0.93×（接受崩到 6/24）。⇒ ①先前"推荐 spec_depth=3"是**扫描上界取自随手假设**导致的过早结论；②最优深度依赖 prompt，不存在常数，`SPEC-TREE-SHAPES` 改为每次打印曲线；③服务侧深度夹取从硬编码 4 改为掩码预算 `bins*depth<=32`（绝对上限 8），已用真实请求过门禁。

## F359 规格（2026-11-05）——continuous batching 的落地形状：两个会话共用一次前向
**要解决的问题**：并发 scaling 只有 1.05–1.15×（F345/F352/F358 一致），根因是 8001 的 A/B 两槽**轮转**：每 token 一次前向，外加一次整份 KV 的快照/恢复（主机↔设备搬运），而且**每个槽各自付一次全部权重与 lm_head 的访存**。
**设计（复用 F308/F326 已有的两块地基，见 F353）**：
1. **每行可见区间**：`attention()` 增加可选的每行 `[lo, hi]` 可见区间（现成机制：树掩码 `g_tree_mask` 本来就是"每行一个可见集合"）。单会话退化为 `lo=0, hi=pos+b`（与今天逐位相同）；批处理时行 b 属于会话 s(b)，可见区间＝该会话自己的 KV 区间。
2. **每行 RoPE 绝对位置**：复用 `g_row_pos`（F308 加的逐行位置），批处理时行 b 的位置＝**该会话自己的**位置（会话 A 的第 i 个 token 位置是 i，与会话 B 无关）。
3. **每行 KV 落槽**：新增 `kv_store_slots()`（每行一个目标行号），会话 0 的 KV 落在 `[0, HALF)`，会话 1 的落在 `[HALF, 2*HALF)`，各自在自己的区间尾部追加 ⇒ 会话之间**物理隔离**，不靠掩码兜底。
4. **每行 logits**：lm_head 一次遍历词表算**所有行**（`gemm(M=nOut)` 一次读词表 ⇒ 权重访存摊薄到两行），各会话用自己的 logits 采样（自己的 RNG）。
**验收（先写门，再动内核）**：新增自检 `BATCH-EQ`——同一提示、同一 seed，**批跑与单跑逐 token id 相同**；掩码/落槽写错就是会话间串上下文，这条门立刻红。另加 `BATCH-OUT`（批跑输出非空、长度对）与服务面 `scaling`（1.05–1.15× → 目标 1.6–2×）。
**风险与边界**：①**数值**：批跑用 `gemm(M=2)`（分块 GEMM）而单跑用 `gemv`，累加顺序不同 ⇒ logit 会有 1e-6 级差异；因此等价门用 **temp=0（贪心）**比 token id，并**先实测**该提示的 top-1/top-2 间距足够大（否则门会长期红）。②**显存**：`HALF` 取 `max(prompt) + max_new + 余量`，两会话合计 2×HALF 行；Qwen2.5-0.5B 的 KV 是 12 KB/行 ⇒ HALF=2048 时约 50 MB，可接受。③**超长提示**：行数超过 HALF 直接报错，让 8001 回落到旧的单会话路径，不静默截断。
**顺序**：①写 `BATCH-EQ` 门（此时必红，因为批接口还不存在——这是"门先于实现"的记录）→ ②引擎实现 → ③8001 调度换批路径 → ④完整梯子（自检 155+1 → 8000 115 → 8001 par/fairness/throughput）。

## F360 更新（F359 已落地并**更正了 F353 的性能前提**）——continuous batching 的机制成立，收益被 GEMM 形状卡住
**F359 已完成**：批 API（add/stop/next，逐会话温度与种子）、每行计划（slot/lo/hi/abspos）、逐行 KV 落槽、8001 两会话共用一次前向（删掉 save/restore 轮转）。**8001 不再使用前缀缓存**（它恢复进 `[0,n)`，与批布局冲突），且拒绝"提示 + max_tokens 超槽容量"的请求。
**F359 第一版没达标**：8001 scaling **1.060×**（目标 1.6–2×）。根因不是调度，是 **M=2 落进了分块 GEMM**（`TM=TN=16` ⇒ 16 行 tile 里只有 2 行有用 ⇒ 8 倍白算的 MAC）⇒ 批步长 ≈ 两份单步长。
**⇒ F353 的"continuous batching 不是内核重写"要收窄**：行级可见性/位置确实是现成的，但 **M=2 的 GEMM 形状不是**；把它当"现成的"就会得到一个正确但不快的东西。
**F360 的修法（有界）**：`gemv2`——一个 workgroup 一个输出行，权重只读一次、点积进两个累加器；逐行累加顺序与 `gemv` 相同 ⇒ 批里的行与单独解码**逐位相同**（`BATCH-EQ` 由"恰好没翻面"变成"必然相同"）。
**验收口径（已落地并实测）**：
- `BATCH-EQ`（F359）：同提示同 seed，批跑 == 单跑逐 id，且行数 == 2。**PASS**（`engine/run_f360.log`）。
- `BATCH-THRU`（F360）：引擎内单流 vs 双流批的比值；判据只做**不退化**（批步长 ≤ 2× 单步长），比值只打印。实测 **1.77×**（18.69ms vs 21.07ms/步）。
- 服务面 8001 scaling：**中位 1.45×**（4 样本 1.418–1.496），**未达 1.6–2×**；差额来自 PHP 侧每 token ~41.6ms 的非引擎开销（两个流各付一次）。
**下一轮的第一动作因此改变**：不是再改 kernel，而是**动 PHP 每 token 热路径**（token→文本、socket 写）；`gemv2` 只覆盖 M=2，**M≥3 未测不外推**。

## F361 更新（F359 的验收目标在 temp 0 下达成，缺口归因从 PHP 改到采样器）
**新测量**：同一构建、同一提示、60 token/流，只改温度 ⇒ temp 0.0 下 8001 scaling **1.714× / 1.789×**（中位 1.75×），temp 0.9 下 1.45×。
**⇒ F359 的验收（1.6–2×）在 temp 0 下达成**；两者的唯一差别是采样路径（`temp<=0` 走 argmax，`temp>0` 走全词表 exp + 151936 元素 `std::sort` + 核截断）⇒ 每流每 token 多 14.9ms，两个流各付一次。
**⇒ 下一轮第一动作**：采样器去排序化（分布不变；同 seed 下具体 token 只可能在 tie 上变），验收＝"同 seed 分布不变"＋"服务面 temp 0.9 的串行速率与 scaling 同时抬升"。**不是 PHP**（F360 那句"下一轮动 PHP"作废：PHP 那 26ms/token 里还混着 Python 客户端的 wall，拆不开）。

## F362 更新（采样器的实现路线已由探针定案，不再是三个候选）
`probe/sampler/sampler_bench.cpp`（600 次抽样，n=151936、temp 0.9、top_p 1.0）实测：引擎现状 **15.48ms/次**；打包排序 **16.59ms（更慢，否决）**；**quickselect 去排序 5.05ms（3.07×，采用）**。
⇒ 下一轮第一动作：引擎 `sample_from` 的 **`top_p ≥ 1.0` 分支换成 quickselect**（带上方概率质量累加，分布不变、同 seed 只在 tie 上可能不同）；`top_p < 1` 保留排序路径（核截断需要有序前缀）。
**验收**：①引擎内"同 seed 分布不变"对照门（探针那 0.00% 只覆盖头部 token，不算数，**必须引擎内再证一次尾部分布**）；②服务面 temp 0.9 的串行速率与 8001 scaling 同时抬升；③`BATCH-EQ`/`BATCH-THRU`/8000 115/8001 par+fairness 全不回退。
**顺位**：M≥3 的批 → 8001 批路径的前缀缓存 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。

## F363 更新（采样器去排序化已落地并通过引擎内对照门，下一轮的瓶颈再次换人）
**已落地**：`sample_from` 的 `top_p ≥ 1.0` 分支换成 `smp_select`（带上方概率质量累加的 quickselect，**完全不排序**）；`top_p < 1` 的核截断路径原样保留（排序、比较器、核语义都不动）；缓冲复用（引擎内无线程，静态缓冲安全）。
**新增门 `SAMPLER-DIST`**（先证后用）：原生 `model_sampler_check` 在引擎内把**已发布的**采样器与"先排序再走累积"的参考实现**逐 token id** 对照，三条独立判据——200 次随机抽样（`mism`）、128 点全区间扫描（`grid`）、12 个等质量精确点（`exact`，穿越 token 必须恰为 `floor(u)`）；`amb` 单独记录"分歧恰好落在浮点边界上"这一可解释情形。**等质量精确点那条专抓分区范围少一个元素**（F362 探针正是这个错）。
**实测**：自检 **159 PASS / 0 FAIL**（`engine/run_f363b.log`，只多这一项）；8000 115/0；8001 par+fairness 0；服务面 temp 0.9 的 8001 scaling **1.640×**（4 样本 1.596–1.702，基线 1.45×）、串行 **23.36 tok/s**（基线 16.87）；同构建 temp 0 对照 1.736× ⇒ **采样开销 14.5 → 4.7 ms/流/token**。
**两条写进下一轮的纪律**：
1. **对照门的参照实现自己也会写错**：第一版参照实现多推进了一次 RNG，拿**两次不同的抽样**互比，报出 `mism=150`。当时 `grid=0` 且 `exact=0` ⇒ 采样器本身是对的 ⇒ **修的是门禁**。动手前先看同一轮的其它判据是否指向同一侧。
2. **跨轮数字不能直接比**：机器状态会漂（F361 的 temp 0 是 22.3 tok/s，F363 同构建是 26.24）⇒ **抗漂移口径＝同一构建内 temp 0 与 temp 0.9 的差**（temp 0 完全不采样，差值即采样开销），而不是"这一轮比上一轮快了百分之多少"。
**顺位**：M≥3 的批 → 8001 批路径的前缀缓存 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。

## F364 更新（服务面每 token 的账已经拆开：客户端被排除，剩下的**随上下文长度增长**）
**已落地（只加测试/运行脚本，**不改引擎、不改门禁**）**：`engine/tests/chunk_gap_probe.py`（客户端 chunk 到达时刻）、`engine/tests/chunk_gap_analyze.py`（与服务端 `par_trace.log` 的 `STEP A/B` 逐 token 配对）、`engine/tests/chunk_gap_ab.py`（双流：用 trace 相邻行把一次迭代拆成"引擎步 + A 的写"与"B 的写"）、`scripts/chunk_gap_run.sh`（起 8001 → 跑探针 → 删哨兵）。
**实测**：transport lag 中位 **−0.13 ms/token**（60 token 合计 −8.6…−13.4 ms）⇒ **客户端与 socket ≈ 0**；双流下 **B 的写 0.4 ms** ⇒ 服务端 `fwrite` 不等读者。**F363 那句"差额里必然有客户端的 wall"被否** ⇒ **≈23ms 全在服务端**。
**新发现**：每 token 成本**随上下文长度涨**（60 token 内 +14.6…+21.4 ms，≈0.25–0.36 ms/上下文 token，temp 0.9 与 temp 0.0 一样涨）⇒ 剩下的**不是常数开销**，而是长度相关的每步工作（KV 相关的候选，**机制待证**）。
**下一轮第一目标**：**8001 批路径的前缀缓存**（每步不必重搬随长度增长的 KV）——它从"顺位第 2"升到第 1；验收＝①**每 token 成本随长度的斜率**明显下降（用本轮的四分位口径当基线）②8001 scaling 不回退 ③`BATCH-EQ`/`BATCH-THRU`/8000/8001 全不回退。
**顺位**：批路径前缀缓存 → M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。

## F365 更新（长度相关的那笔钱**已被定位并改掉**：注意力三遍重算 → 算一次缓存复用）
**机制（F364 的"机制待证"到此为止）**：引擎自带的 stage 计时（自检里新增的 `PERF stage-sweep`，L=8…256）显示**只有 s1（24 个 block）随上下文长度涨**（s0/s2 恒定）；block 里随长度变贵的是 `attention()`——它对可见 KV 范围走三遍，**每遍重算同一个点积** ⇒ K 读 3 次、MAC 3 次。
**已落地**：每个 `(token, head)` 的分数**只算一次**，写进设备端 scratch（`T × NH × scoreRows`），三遍复用；**值与求和顺序不变 ⇒ 逐位相同**。第二块设备（分层切到 `split` 时）**另配一块 scratch**——跨设备共用缓冲会崩（F359 的 `d_slot/d_lo/d_hi` 同类错误，本轮再犯一次）。
**测法（写进纪律）**：两条路径**都留在同一个二进制里**（`model_set_attn_mode(0|1)` / `KAKASI_ATTN_MODE=1`），自检 sweep **按 L 配对**（每个 L 先 m0 再 m1，各两遍）⇒ 相邻测量抵消机器漂移；**单独一趟 sweep 不够**（试过：L=32 的 s1 曾高于 L=64）。跨轮比较同样不成立（旧构建 0.39 vs 本轮同算法 0.365 ⇒ 机器漂 ~13%）。
**实测**：fit **m0 s1 ≈ 8.54+0.212·L** vs **m1 s1 ≈ 8.86+0.365·L** ⇒ **长度相关项 −42%**，常数项不动；每步 L=8 1.15× / L=64 1.48× / L=256 **1.60×**。**正确性**：`ATTN-MODE` 门（逐 L 比对两条路径的 token id）PASS；自检 **160 PASS / 0 FAIL**（`engine/run_f365c.log`）。
**验收口径（下一轮）**：①**每 token 成本随长度的斜率**下降（用同构建配对 A/B 的 0.212 ms/上下文 token 当基线）②服务面端到端不劣化、且长上下文下的收益可见 ③`BATCH-EQ`/`BATCH-THRU`/8000 115/8001 par+fairness 全不回退 ④**收益必须带上下文长度一起报**（短上下文几乎无变化）。
**顺位**：服务面端到端复测 → 8001 批路径的前缀缓存（长度相关项虽降但未清零）→ M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。
**服务面端到端（同一二进制、两个模式各 3 次、同提示同 seed）**：每 token cadence temp 0.9 **44.6→31.1 ms（1.43×）**、temp 0.0 **34.3→30.9 ms（1.11×）**；双流迭代 **47.6→40.4 ms（1.18×）**、scaling **1.582×→1.637×**；**输出逐字节相同**。⇒ **验收①（斜率下降）达成**：长度相关项 0.365→0.212 ms/上下文 token（−42%）；**验收②（服务面不劣化、长上下文收益可见）达成**；**验收③（门禁不回退）达成**：自检 160/0、8000 115/0、8001 par+fairness 0、scaling 1.625×、哨兵 0。

## F366 更新（split-KV 假设**被自己的测量否掉**：注意力的长度成本不是工作项数）
**做了什么**：新增注意力模式 2 = split-KV（可见范围切 P 块、每块一份 online-softmax 的 `(max, sum, 未归一化加权 V)`、第二个 kernel 合并），**默认关**，与模式 0/1 在同一二进制里按 L 配对测量（`ATTN-SPLIT` 门 = greedy token 一致）。
**结果**：**没有收益**——五个 L 的差都在 ±3% 内、方向不一致（L=64 反而慢 7%）⇒ **16 倍并行度换来 0%**，F366 的假设（"解码只有 14 个工作项 ⇒ 占用率受限"）被否。
**与 F365 合起来的指向**：动"每位置搬多少字节"（F365，−42%）就动；动"谁去搬"（F366，0%）不动 ⇒ 剩下的 **0.212 ms/上下文 token 是每位置 K+V 读路径本身**。
**下一轮第一动作（本轮已挂上判别实验）**：**`PERF kv-sweep`＝同一 sweep 在 `kv.f16=0/1` 各跑 L=8/64/256**，因为 f16 只改每位置的字节数，不改循环结构 ⇒
- **斜率大致减半 ⇒ 是字节** ⇒ 直接的服务面选项：**8001 用 f16 KV**（`model_set_kv_f16` 已有，KVQ 门已给 f16 vs f32 的 logp 容差与 greedy id 一致性），长度成本当场减半；
- **斜率不动 ⇒ 不是字节** ⇒ 回到"每位置那条 64 长的相关 FMA 链"（点积自身的依赖），那需要的是**改变点积的归约形状**（如按 d 维拆项 + 设备端归约），不是再加分块。
**验收**：①两种 KV 精度下的斜率各自拟合并给出比值 ②`ATTN-MODE`/`ATTN-SPLIT` 仍 PASS、自检不回退 ③默认仍是模式 0（发布行为不变）。
**顺位**：KV 字节判别 → 8001 批路径前缀缓存 → M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。

## F367 更新（F366 的负结果是仪器故障；split-KV 真实收益 3.9×，并把"开关必须自报生效"变成门）
**缺陷与撤回**：`set_attn_mode` 把除 1 以外的一切夹回 0 ⇒ F366 的"模式 2"从未生效 ⇒ **"16 倍并行度收益 0%"撤回**；F366 的字节结论（f16 斜率不动）独立成立，保留。
**新增门 `ATTN-ENGAGE`**：`model_set_attn_mode(m)` 的返回值必须等于 m，逐一断言（模式 0/1/2 + 消融 0/4/5 共 8 次）。**理由写进规格：开关没生效时，任何计时或输出对照都看不出问题（两侧同路）。**
**实测（模式 2 真正生效后）**：s1 L=256 63.66 → **16.35（3.89×）**、每步 66.83 → **18.72（3.57×）**、斜率 0.216 → **0.0236 ms/上下文 token（−90%）**；L=8 **慢 3%**（P=1 仍走两次 launch）。消融 m4 −37% / m5 −40% ⇒ split 的 −74% 超过两者，机制是**每线程串行依赖链被缩短**，不是减少总工作量。
**下一轮（F388 位置上的 F368）**：**把模式 2 提为默认**，验收＝①模式 2 下的全梯绿（自检 8000 8001 投机比 哨兵）②加"可见范围 < 阈值就不切"的门控，使 L=8 不再倒退 ③长度斜率与 F367 一致 ④**如实记录数值行为边界**：分组变了 ⇒ 尾部位值与 tie 可能变（`ATTN-SPLIT` 只覆盖五个 L 的 argmax），分布语义不变但**不再声称逐位相同**。
**顺位**：模式 2 提默认 → 8001 批路径前缀缓存 → M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F368 更新（split-KV 已是默认路径；下一轮的第一动作是**给服务面装表**，不是再改 kernel）
**已落地**：默认 `g_attn_mode = 2`；`splitP > 1` 才切（≤16 token 的范围退回 F365 路径，L=8 不再倒退且逐位相同）；两个服务端启动打印 `ATTN_MODE <n>`；自检 sweep 收尾恢复**开机模式**而不是 0。
**实测**：引擎每步 L=64 24.7 → **16.0**、L=256 66.5 → **18.2**（s1 62.4 → 16.0）；双流 scaling **1.577 → 1.920**；梯全绿（自检 162/0、8000 115/0、8001 par+fairness 0、哨兵 0）。
**未闭合**：单流服务面每 token cadence 只 **31.9 → 30.9（1.03×）**，与引擎自量的 1.54× 不一致。三条候选未分辨：服务上下文比 64 短、cadence 里有一块与注意力无关的大开销、批路径的 `splitP` 未如预期。
**下一轮（F369）验收口径**：①`par_trace.log` 的 STEP 行带上引擎 stage 计时与 `splitP` 实际值（装表本身要能自证：`ATTN-ENGAGE` 风格，打印≠猜测）②用一条服务 cadence 拆出"引擎步 / 采样 / PHP 残差"三块并给出中位与四分位③若残差 ≥5 ms，定位其来源后才允许再动 kernel④斜率与 1.5× 的引擎收益不许退化。
**顺位**：服务面装表 → 8001 批路径前缀缓存 → M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F369 更新（服务面 residual 被量出来：12.9–17.0 ms，比引擎设备段还大；下一轮拆它，不再动 kernel）
**装表结果**：服务面每 token = `s1`（24 个 block 的设备段）**13.98 ms** + **residual 16.95 ms**（模式 2）；模式 0 是 18.29 + 12.94。⇒ **split 在服务路径上确实省了 4.3 ms 设备时间，但 cadence 只动 1.02×——省下的时间以宿主时间的形式回到关键路径上**。
**由此得出的执行纪律（影响后面几轮）**：**residual 没有被拆清之前，不要再投设备侧优化**——它不会转化为单流服务收益（双流 scaling 仍从 1.577 涨到 1.920，因为那里设备段还在关键路径上）。
**F370 的动作与验收**：在服务循环里再加两个戳（引擎调用返回、采样返回），把 residual 分成 ①s2 之后仍在引擎调用内的部分（logits 151936 个 float 的回拷）②采样 ③PHP+SSE+trace+socket 写；验收＝⑴ **三项各自的中位与四分位打印出来**，且**加起来等于 residual（±5% 内）**，这是"表装全了"的结构判据；⑵ temp 0.9 与 temp 0.0 各测一遍，用两者之差独立核采样那一项（应与 F363 的 4.7 ms 或本轮"≈0"之一相符，若两边都对不上说明戳装错位置）；⑶ 装表不许改变任何输出：**服务文本与 F369 之前逐字节相同**；⑷ 梯不回退。
**已知量级参照**：`api_par_trace` 每 token 0.169 ms（F363）、会话采样 4.7 ms（F363）——**两者都远小于 12.9–17.0 ms**，所以 residual 里有一块**尚未命名**的主要开销，这正是 F370 要找的东西。
**顺位**：residual 拆解 → 8001 批路径前缀缓存 → M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F371 计划（`pre` 那 7–8 ms 是下一个要被命名的东西；命名之前不动 kernel、不动 PHP）
**F370 已经把服务每 token 拆成**：`pre` **7.3–8.1 ms**（迭代起点→引擎调用前，与模型无关）＋ `eng` **20.2 ms**（其中 block 设备段 14.0、stage 外 ≈6.2）＋ `post` **0.09 ms**。⇒ **PHP/SSE 侧出局、block 设备段也不是关键路径的全部**。
**动作**：①在 `pre` 区间内逐段加戳（select 返回、accept 分支、请求读取循环、上一槽收尾），**沿用 coverage 判据**（各段之和 ÷ `pre` ≥95%）；②把 `s2` 与 logits 回拷耗时也写进 STEP 行，给 `eng` 里那 6.2 ms 命名；③顺手用 B 行的 `post` 核掉 F370 缺的那 8%。
**验收**：三项各自的中位＋四分位打印；**装表后服务文本与 F370 逐字节相同**；梯不回退；只有在 `pre` 被命名之后，才允许对它下优化手（**不许在没名字的数字上做工程**）。


## F373 计划（服务面的"白等"已清零；下一块是 `eng` 里 `s1` 之外的 ~7.5 ms）
**已定论（F372）**：每 token 从 32.6 降到 **25.8 ms**，收益由机制字段 `sel`（6.75→0.03 ms）自证，**没有动任何算法**。
**剩下的账已分组**：`eng` 22.9 ＝ `s1` 15.4 ＋ s2 ≈2.2 ＋ 采样 ≈4 ＋ **logits 全量回拷与批路径簿记 ≈1.3（从未单独计时）**；另有 B 槽处理 ≈2.4 ms 在 A 行的 `post` 之外。
**动作（先装表再动刀，顺序不许反）**：①STEP 行加 `s2=` 与 `rd=`（引擎调用里"设备→宿主取 logits"那一段的独立戳）；②temp 0.0 与 0.9 各一遍，用差核采样项与 F363 的 4.7 ms 是否仍一致；③**只有 `rd` 被量出来之后**才决定是否做"设备侧取 top-k/核再回拷"的裁剪（那会改变数值路径，必须走 `ATTN-ENGAGE` 风格的自证＋分布对照门）。
**验收**：三项（s1/s2/rd）之和 ÷ eng 的覆盖率 ≥95%；**装表不改服务文本**（与前一轮逐字节相同）；梯不回退；任何裁剪落地前必须有"同 seed 分布对照"门与**负结果留档**。
**顺位（不变的部分）**：`eng` 内部拆解 → 8001 批路径前缀缓存 → M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F373 计划的更正（读代码后撤回一个动作，不改数字）
**撤回**："给 logits 设备→宿主回拷单独装 `rd=` 戳"。**理由（可核）**：回拷发生在 stage 2 之内——`model_sycl.cc` 里 lm_head 分块先写进设备缓冲 `dLogits`，随后在 `for (int r …)` 循环里拷进宿主 `all`，而 `mark_stage()`（stage 2）就在这之后（注释本身写作 "final norm + tied lm_head chunks + **host logit reduce**"）⇒ **回拷已经被 s2（≈2.2 ms）包住，再装一个戳不会给出新信息**，只是把同一段花销称两次。
**因此 `eng` 的账已经基本闭合**（temp 0.9 中位）：`s1` 15.4 ＋ `s2` ≈2.2 ＋ 采样 ≈4（temp 0 对照独立量到 3.6–4.8）＝ 21.6，对 `eng` **22.9** ⇒ **未归因 ≈1.3 ms**，不需要新的仪器，只需要一个更便宜的验证。
**F373 的第一动作改为队列里本来就有、且现在有数字支撑的那一条**：**批路径摊薄每步固定开销**——F360 已量到"第二个流的边际成本只有 ~2.5 ms，而每步固定项 ~15 ms"，F372 后服务面最大的块正是 `s1` 那 15.4 ms ⇒ **提高每步并行的行数（M≥3，`gemv2` 目前只覆盖 M=2）比再动 kernel 更值钱**；验收沿用既有纪律：**同 seed 逐 id 硬等**（三处有界改动）、`BATCH-EQ` 不退化、`BATCH-THRU` 的比值随 M 上升并打印每行边际成本、服务文本不变、8000/8001 全绿。


## F374 计划（引擎已能一步四行且逐行等价；服务面要拿到它必须把 8001 扩到 N 槽）
**F373 已给数字**：M=1..4 每步 17.1/19.9/21.4/23.1 ms ⇒ 每 token **17.1 → 5.78 ms（2.96×）**，且 `BATCH-M3` 断言了 `rows==3` 与逐 id 等价。
**F374 的动作（结构性，但边界清楚）**：把 `api_serve_par` 的两槽状态机改成**按槽位的数组/循环**（`on/rs/hold/sn/tok/sock` 各成一组），请求接受与 flush 循环随之泛化；**同时**把 `fairness_test.py` 的判据从"两流交错"泛化为"N 流交错"，并新增服务面吞吐对照（M 槽 vs 1 槽串行）。
**验收（形状与内容分两条，这是 F373 补上的纪律）**：
①**形状**：服务面一步确实在推进 ≥3 个活跃流（从 `par_trace.log` 的 `STEP` 行数与 n 计数核，不许靠"配置里写了 4"）；
②**内容**：每个流的文本与**该流单独跑**逐字节相同（同 seed）；
③**公平**：N 流交错无饿死（判据泛化后仍全 0）；
④**吞吐**：报告 4 流并发相对 1 流的实测倍率与 `per_tok_ms`，**不许用引擎内数字代替服务面数字**（F369/F370 的教训：两者可以差 2 倍）；
⑤8000/8001 既有门与哨兵不回退。
**风险与回退**：8001 的槽状态散落在十几处 if/else（`$rsA==5` 的停止串缓存、`$holdA`、finish chunk 的 usage 计数），改动面比前几轮大 ⇒ **先在一个独立的 N 槽骨架上做出可测量的原型（允许与两槽实现并存），再替换**；两槽路径在 `BATCH-M3` 之外还是 `par_smoke`/`fairness` 的既有基线，**并存期保持其判据不变**。
**顺位**：N 槽服务面 → 8001 批路径前缀缓存 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F375 计划（N 槽服务面）——改造地图已在代码里核过，风险点按"先复核顺序、再扩判据"排
**结构事实**（`engine/main.php` 的 `api_serve_par`，6353-7008，655 行；本轮改造前已逐块核过）：
- 两块**近逐行对称**的发射代码：槽 A 与槽 B（各自 ~80 行：token→文本、stop 串 `hold` 冲刷、`$k==1` 的 OpenAI 帧 vs 其余的 Anthropic 帧、finish 帧与 `usage.output_tokens`、`STEP <slot>` trace、`model_batch_stop` + `fclose` + `$on=0`）⇒ **提为一个按槽位下标的共享函数**是这次改造的主体，而不是复制两份。
- **一步一批**的位置只有一处：`model_batch_next(0)` 的门是 `($onA && $rsA==0) || ($onB && $rsB==0)`，取回 `model_batch_tokens()` 的 CSV **只读 [0]/[1]** ⇒ 泛化为 `for s in 0..N-1` 并把 `-1` 当作"该槽本步没有产出"的既有约定。
- 写死两处区域的调用是 `model_batch_start(0, $cap)` ⇒ 换成 `model_batch_start_n(0, $cap, N)`（F373 已提供，**旧入口不变**，所以默认仍可 2 槽运行）。
- 请求侧：`$slot` 的选择、"503 两路已满"的拒绝文案、`pn+mx > cap` 的容量校验、`$slot==0/else` 的两分支变量落库 ⇒ 都要改成按槽位数组。
- **schema/constrained 支路是"每请求内联"**：整段答案在一次迭代里出完，然后 `model_batch_stop($slot)` + `$on=0`，**不跨迭代占槽** ⇒ N 槽化时它只需按 `$slot` 泛化，不参与批步。
**必须先保住的语义（否则既有门会变味）**：
1. **每迭代内的写序**（现在 A 全部先于 B）——**这就是当前 fairness 的实质**；泛化成循环后，**顺序策略要显式定义并写进注释/判据**，不能靠循环变量巧合。
2. 两种协议的帧形状（`$k==1` 的 `chat.completion.chunk` + `[DONE]`；否则 `content_block_delta` + `message_delta(usage.output_tokens)` + `message_stop`）与 **finish 前冲刷 `hold` 的那一步**（它会让 `$sn` 再 +1）。
3. 满载拒绝（现在是 503 文案写着"两路"）⇒ 文案与 `$bad` 分支要随 N 变，且**要有一条判据证明 N+1 个并发请求里第 N+1 个确实被拒**（形状判据）。
**风险次序（按先复核再改排）**：①写序=公平策略（最易被无意改变）；②批步扇出与 `-1` 语义（确认 C++ 每槽一个字段、超时槽重复 `model_batch_stop` 的幂等性）；③`$modelId` 这类**跨迭代残留的函数级状态**（发射段读它，N 槽下必须按槽存）。
**验收（形状与内容分开，F373 补上的纪律）**：①服务日志/trace 证明**一步确实推进 ≥3 个活跃流**（从 `STEP` 行与 n 计数核）；②每个流的文本与**该流单独跑**逐字节相同（同 seed）；③`fairness_test.py` 判据泛化到 N 流后仍全 0；④**服务面实测** 4 流 vs 1 流的吞吐与 `per_tok_ms`，**不许用引擎内数字代替**（F369/F370 已证明两者可差 2 倍）；⑤既有 8000/8001 门与哨兵不回退。
**回退与并存**：`api_serve_par` 以**默认 N=2** 保持既有行为（`model_batch_start` 旧入口仍 2 区域），N 由新增的启动参数/哨兵决定 ⇒ 两槽基线在两扇 gates 下继续可比；若泛化引入回归，回退到 `649961e` 的提交点。
**顺位**：N 槽服务面 → per-request spec 归因 → 真草稿源 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05；**"8001 批路径前缀缓存"降级**（F374 数字：它只值一条流的 ~3.5%）。


## F376 计划（N 路并发的两条未覆盖判据——不做完就不算落地）
**F375 状态**：并发数已可配（默认 2、`serve.par4.on`→4），N=4 服务面实测 **244 chunks / errors=0 / 85.56 tok/s / scaling 2.069x**，两路控制全绿。验收五条里 ①④⑤**已达成**，②③**未做**。
**动作（两件，都不动引擎）**：
1. **公平判据泛化**：`engine/tests/fairness_test.py` 现在按两路写；泛化为 N 路后跑 `slots=4 streams=4`，退出码 0 才能说"N 路公平"。
2. **逐流内容对照**：同 seed 下每条流的输出文本必须与**该流单独跑**逐字节相同（HTTP 层的文本级判据，把 F373 的"逐 id 等价"搬到服务面）。实现上扩 `par_slots_run.sh`：并发跑一轮 → 同四条各串行跑一轮 → 逐流 diff，**打印每条流的字节数与 diff 结果**，不允许只报"通过"。
**验收**：①`fairness N=4` 退出码 0；②四条流并发文本 == 各自串行文本（逐流打印）；③两路控制仍全绿（回归护栏）；④哨兵 0。
**顺位**：F376 两条 → per-request spec 归因 → 真草稿源 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05；**8001 前缀缓存已降级**（F374：只值一条流的 ~3.5%）。


## F377 计划（N 路并发已收官；下一目标是 **per-request 投机归因**）
**已达成（F375+F376）**：服务面并发可配（默认 2、`serve.par4.on`→4），四槽下 85.56 tok/s / scaling 2.069x / 短流不被饿死 / 四流文本逐字节等于单独跑；两路控制与全部门不回退。
**下一轮（F377）做什么**：投机解码在服务面的收益目前只有**整场平均比值**（greedy 1.76–2.84x、sampled 1.41–2.07x，随基线变快而波动），**没有按请求归因**（哪个请求用了草稿、接受率多少、每 token 代价）。动作＝把 `spec_stats` 的计数与 `par_trace.log` 的 STEP 行按请求 id 对齐，报告"接受率 / 每 token 引擎时间 / 草稿来源"三者，并给出**分布**（中位+四分位）而不是均值。
**验收**：①每个请求都能对上它的 spec 计数（结构判据：请求数 == 统计条数）②报告里同时给"有草稿"与"无草稿"两组，**差值作为投机收益**（抗漂移口径）③不得回退既有门④输出可被后续门复用（打印成机器可读行）。
**顺位**：per-request spec 归因 → 真草稿源 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F378 计划（把 balanced 样本拿到，并把 tree 组按 drafting/got 拆开）
**F377 已落地**：三条路径的按请求记录（含 `got`）、贪心接受率 87.7%、服务面 1.78×（同分钟对照，但 plain 仅 2 条）。
**动作**：①修 `scripts/spec_attrib_run.sh` 的启动（`HEALTH_TIMEOUT`：先查残留引擎进程与端口占用，health 轮询打印每次失败原因，别静默 200 s）②跑 6 plain + 6 greedy（同提示、同 max_tokens，**用 `got` 作分母**）与 6 sampled-plain + 6 tree（temp>0）③**把 tree 组按 `drafting=on/off` 与 `got` 分层**，回答"关闭草稿后仍付的 tree 开销"是不是真的（F377 的一条观察）。
**验收**：①每轮 12 条记录、两组各 6 条、`got` 非零 ②收益用**中位 ms/token**、并给出两组分布③`drafting=off` 层的数字与 `drafting=on` 层并列打印④梯不回退。
**顺位**：balanced 归因 → 真草稿源 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F379 计划（把对照做干净：缓存分支装同一行，或请求关掉前缀缓存；再把 greedy/tree 放在同一提示下各测一轮）
**F378 结论**：收益 = f(接受率)。同二进制同提示，接受率 87.7% ⇒ 1.78×；50% ⇒ ≈0；`drafts=0` ⇒ 恒等于无投机。
**动作**：①给**前缀缓存分支**装同一行 `SPECREQ`（`mode=plain cache=<hit>`），或在对照请求里带 `prefix_cache:0`，二者至少做一样——现在 plain 组在缓存路径上**根本没有记录**，这是本轮最重要的一处混淆；②同一提示下各跑 6 条 greedy(k=4)、6 条 tree(bins=2,depth=3)、6 条 plain，**三组同分钟**，用 `got` 作分母；③报告里按 `drafting=on/off` 与接受率分桶（观察项：tree 关草稿后比纯解码慢）。
**验收**：①三组各有 6 条记录（结构判据）②中位 ms/token 与四分位并列③`NO_DRAFTS` 时不出收益④梯不回退。
**顺位**：干净对照 → 真草稿源（提高接受率本身就是收益）→ 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F380 计划（缓存命中路的 3 倍代价是最值钱的一条；同时收掉两个仪器尾巴）
**F379 结论**：缓存命中请求 **60.5 ms/token**，从头解码 18.6–21.9；投机收益 = f(接受率)（50%⇒1.34×、87.7%⇒1.78×）。
**动作**：①**查清缓存命中路为什么慢**（逐 token `model_session_next` 每步都走 PHP 循环 + 会话簿记；对照组是 `model_generate` 一次调用）⇒ 两个候选：**命中也用 `model_generate` 续写**（若引擎支持从会话位置生成），或**命中时把 prefill 结果搬进批路径**；先用**同提示命中/未命中配平窗口**量出"命中省了多少 prefill、赔了多少 decode"，再决定是否值得保留缓存路径。②plain 行清零（或写 `spec=stale` 标记）。③删掉 `spec_attrib_run.sh` 里的旧内嵌分析块。
**验收**：①命中 vs 未命中的**每 token** 与 **TTFT** 两组数字并列；②若保留缓存，命中路径的 ms/token 不得超过从头解码的 1.1×；③plain 行不再带陈旧计数；④梯不回退。
**顺位**：缓存命中路 → 真草稿源（提高接受率本身就是收益）→ 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F381 计划（把"配对"这条方法用到投机上，并把 spec 计数残留收掉）
**F380 结论**：前缀缓存命中**更快**（长提示 TTFT 11.3×），每 token 节奏不变；F379 的"贵 3 倍"撤回。
**动作**：①**投机也用配对**：同一条提示、同一进程，先 `spec_k=0` 再 `spec_k=4`（只改一个变量），两组各 6 条，比较 `got` 归一后的 ms/token 与 TTFT——这正是 F379 想用"分组"回答而没有控制住的问题；②plain 行清零（引擎侧，投机调用之外也重置计数，或写 `spec=stale`），消掉 F379 发现的陈旧计数；③删掉 `spec_attrib_run.sh` 里遗留的内嵌分析块。
**验收**：①配对窗口里两组各有 6 条记录②收益以**配对中位差**给出，并打印两组分布③plain 行不再带陈旧计数④梯不回退。
**顺位**：投机的配对归因 → 真草稿源（提高接受率）→ 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F382 计划（两条收尾 + 一条新目标）
**收尾**：①引擎侧在**非投机**生成前也清零 spec 计数（或写 `spec=stale` 标记），消掉 plain 行残留（需重建 + 全梯）；②把"配对"从脚本固化成判据：`spec_attrib_report.py` 增加 `--pairs` 模式，直接输出"配对中位差 + 两组分布"，让后续轮不必再手算。
**新目标**：**真草稿源**——当前 prompt-lookup 草稿器的接受率在工作负载间从 50% 摆到 88%，而**收益是接受率的函数**（1.27–1.78×）⇒ 提高接受率等价于提高收益，且已被三个窗口量化。动作：先测"同一提示下 k=1/2/4/8 的接受率与 ms/token 曲线"（现状只有 k=4/8/12/16 的长探测），据此选 k 的默认值或是否值得换草稿器。
**验收**：①k 扫描给出接受率与 ms/token 的曲线（同进程、配对）②默认 k 的选择有数据支撑③梯不回退。
**顺位**：计数清零与配对判据 → 草稿源/k 扫描 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F383 计划（k 曲线按负载复核 + spec 计数清零）
**F382 结论**：k 的内部最优在 4（1.238×），k=1/8 都更慢；收益 = f(每次 verify 提交的 token 数)。
**动作**：①在**第二种负载**（长探测 192 token，F377 那组接受率 87.7%）上重跑 k 曲线，确认最优点是否随负载移动（F382 的边界之一）；②若移动，则**k 不该是全局常数**，需要按提示长度/最近接受率自适应（并给出选择规则与判据）；③引擎侧把 spec 计数在非投机生成前清零，消掉 plain 行的残留。
**验收**：①两种负载各一条 k 曲线（配对）②若自适应落地，规则要有"选择前后各测一次"的对照③plain 行不再带陈旧计数④梯不回退。
**顺位**：k 曲线复核/自适应 → 真草稿源 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F384 计划（先查 F383 的异常：spec 与 plain 的产出长度不一致）
**F383 结论**：k 最优点 4（两负载一致）；k=1 在 100% 接受率下仍 0.962×；重复句上 spec 每请求 24 token 而 plain 11 —— **引擎的负控制要求两者逐 token 相同**。
**动作**：①用引擎内的负控制（`model_spec_generate(..., k<0)` 已存在"拒掉全部草稿"模式）在**同一提示**上比较 `spec(k>0)` / `spec(k<0)` / 普通贪心的 **token id 序列**，先判定是**停止条件**（eos/stop/max）还是**生成内容**不同；②若内容不同，那是真缺陷（负控制没覆盖这条路径），先修再谈收益；③若只是停止条件，把三条路径的停止判定收敛到一处并加门。④同时把 spec 计数在非投机生成前清零。
**验收**：①三条路径在同提示下 id 序列逐位相同的断言进自检（形状+内容两条）②`got` 在 spec/plain 上一致③梯不回退。
**顺位**：spec/plain 一致性 → k 自适应（若跨负载最优点移动）→ 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F385 计划（服务面 `got` 的来源）
**F384 结论**：引擎内 spec(k=4) 与贪心在这条提示上**逐 id 相同、都 48 个 token** ⇒ F383 的 24 vs 11 属服务面。
**动作**：①在 `spec_req.log` 里**同时记录引擎收到的 `max_tokens` 与返回 CSV 的前缀长度**，让 `got` 可核对（现在 `got` 是 `count(out)-count(ids)`，前缀长度没记录）；②对同一条提示跑 3 次 plain 与 3 次 spec，**逐条对照 `got`**，找出 24 vs 11 的产生条件；③顺带把 spec 计数在非投机生成前清零（消除 plain 行残留）。
**验收**：①`got` 与"返回 CSV 长度 − 前缀长度"逐条一致②同提示下 plain 与 spec 的 `got` 相同（引擎已证 id 相同）③梯（含 8000/8001）全绿。
**顺位**：服务面 got → k 自适应 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F386 计划（一次判定：上限不同还是输入不同）
**F385 事实**：服务面 plain 出 11 token、spec 出 24（客户端与引擎记录一致），而引擎级门 48/48 相同。
**动作**：`SPECREQ` 行里加 `ids_len=`（引擎收到的提示长度）与 `cap=`（`$maxTok` 实际值），两条分支都记；同提示各 4 条，比较这两个字段。**判据**：若 `cap` 不同 → 修上限传递；若 `ids_len` 不同 → 修提示构造；若两者都相同 → 说明差异在 `model_spec_generate` 与 `model_generate` 的**停止条件**（那就要回头看 `spec_generate` 的 eos 处理，并用引擎门覆盖到"会提前 eos 的提示"）。
**随后**：按判定修一处，并把"同提示下 spec 与 plain 的 `got` 相同"做成**服务面门**（引擎已有）。
**验收**：①`cap`/`ids_len` 两组逐条打印②修复后同提示两路径 `got` 相同③梯全绿。
**顺位**：服务面一致性门 → k 自适应 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F387 计划（一次装表判定：`ids_len` 与 `cap`）
**F386 结论**：引擎两侧在各档上限下完全一致；自检用裸提示、服务面用加模板提示 ⇒ **"同一提示"是未验证的假设**。
**动作**：`SPECREQ` 行记录 `ids_len=`（引擎收到的提示 token 数）与 `cap=`（`$maxTok` 实际值），两条分支都记；同提示各 4 条比较。**判据**：`ids_len` 相同而 `got` 不同 ⇒ 真分歧；`ids_len` 不同 ⇒ 差异来自模板，服务面与自检本就不可比（需在服务面建"与自检同提示"的对照）。
**随后**：按判定修一处；并把"服务面侧 spec/plain 在同一 `ids_len` 下 `got` 相同"做成门。
**验收**：①`ids_len`/`cap` 逐条打印②判定成立并落一处修复③梯全绿（含 8000/8001）。


## F388 计划（在模板化输入上区分"越过 eos"与"批验证数值分歧"）
**F387 事实**：同 28-token 输入、同上限，plain 11 token、spec 24 token（两形状 plain 一致）。
**动作**：自检里用 `tok_chat_open("assistant")` 拼出**与 served 相同的输入**，打印 `model_generate` 与 `model_spec_generate(k=4)` 的**完整 id 序列**；判据：**第 11 个 token 之前是否完全相同**。
- 相同 ⇒ **接受块越过 eos**（修 `spec_generate`：接受块内截断到第一个 eos，而不是追加后检查 `out.back()`）；
- 不同 ⇒ **批验证数值分歧**（先修数值一致性：批内归约顺序或 f16 KV 影响；在修好之前不只报收益）。
**验收**：①两条序列逐 id 打印②按判据落一处修复③修复后 served `spec_k=4` 与 `spec_k=0` 在**同输入**下 `completion_tokens` 相同④梯全绿（含 8000/8001）。
**顺位**：spec/plain 停止一致性 → k 自适应 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F389 计划（修 `spec_generate` 越过 eos + 加会到 eos 的判据 + 复核收益）
**缺陷（F388 已证）**：`spec_generate` 按 `take` 成批追加接受块，**追加后才查 `out.back()==eos`** ⇒ eos 在块内时把其后 token 也写出（实测多 13 个 token、含下一轮 `Human:` 文本）。
**动作**：①`spec_generate` 里在追加前**把 `take` 截断到块内第一个 eos（含）**，随后既有 `out.back()==eos` 提前退出逻辑自然生效；②**新门 `SPEC-STOP-EOS`**：用**会很快 eos 的模板化输入**断言"`plain` 输出是 `spec` 输出的前缀"且"`spec` 在 eos 后没有 token"（现有两门结构上到不了 eos）；③修好后**重跑 F382/F383 的 k 曲线**（收益是在缺陷路径上量的，必须复核）。
**验收**：①新门绿②`spec_k>0` 与 `spec_k=0` 在同输入下 `completion_tokens` 相同③k 曲线复核数字与修复前并列④梯全绿（自检/8000/8001/哨兵）。
**顺位**：修越界 → 收益复核 → k 自适应 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F390 计划（统一 eos 计数口径 + 把 k 自适应提上日程）
**F389 结论**：eos 越界已修（服务面文本一致）；k 复核 **1.309× @ k=4**、k=1 仍 0.942×；`SPEC-STOP-EOS` 门已覆盖真实输入。
**动作**：①**统一 eos 计数**：让 `spec_generate`/`spec_tree_generate` 在"输出 id 序列"里与 `generate()` 一致（不含 eos），或让两者都含，并在 `usage.completion_tokens` 上体现同一口径——加一条断言：同输入下两路径的 `completion_tokens` 相等。②**k 自适应**：F382/F383/F389 三个窗口表明最优点随负载在 4 附近、k=1 恒亏；据此给出**按最近接受率选择 k 的规则**（例如接受率 < 60% 时降 k，> 85% 时升 k），并以"同提示同分钟、规则前后各测一次"作验收。
**验收**：①`completion_tokens` 相等断言进自检②自适应规则有一条对照（开/关）③梯全绿。
**顺位**：计数口径 → k 自适应 → 真草稿源 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F390 收点（把两处按证据结掉，改动下一项）
**已核、不成立**：k 自适应——F382/F383/F389 三窗口最优点均为 **k=4**，前提"最优点跨负载移动"不成立 ⇒ **不做**；`k=4` 固定默认继续用。
**已核、不修**：spec 与 plain 的 `completion_tokens` 差 1（eos 计数口径），文本相同、不影响收益口径 ⇒ 记录为已知口径差。
**下一项（F391）从队列里取**：真草稿源（当前 prompt-lookup 草稿器在无重复提示上 `drafts=0`、在高重复提示上 100% —— 换草稿器是提高接受率、从而把 1.31–1.36× 抬高的直接手段）。先做**探针**：在同一提示上比较 prompt-lookup 与"上一轮 token 的 n-gram"两种草稿源的接受率与每 token 成本，再决定是否值得换。
**验收**：①两种草稿源同提示、同分钟、配对（plain 关缓存）②接受率与 ms/token 并列③若换源，`SPEC-STOP-EOS`/`SPEC-EQ-*`/`BATCH-*`/8000/8001 全绿。
**顺位**：草稿源探针 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F392 计划（窗口进日志 + 按接受率选窗口的规则先写规格）
**F391 结论**：窗口双向权衡（m=2 在重复文本 1.849×、在无重复文本 0.945×），默认 3 不动。
**动作**：①启动时把 `KAKASI_SPEC_WINDOW` 与生效值打成一行日志（与 `ATTN_MODE` 同款自证）；②**接受率反馈选窗口**：m=2 的失败模式是"提得多、收得少"，而引擎已有"连续 3 次空接受就关草稿"——把它扩展为**先降窗口、仍不行再关草稿**，并用同提示配对的"规则前后各一次"作验收；③若带宽允许，再取第三个负载（半重复文本）验证 m=2→3 的切换点。
**验收**：①窗口值有启动日志②规则开/关各一次配对测量③自检与 8000/8001 全绿。
**顺位**：窗口日志/反馈规则 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F393 计划（默认窗口改动已落地；下一步把收益口径写进服务面判据）
**F392 结论**：默认窗口 **2 + 扩窗**（重复 1.865×、非重复中性），门改为"浪费有界"，全梯绿。
**动作**：①服务面把 `SPEC_WINDOW` 与 `spec_k` 一起放进 `spec_req.log`（现在窗口只在启动横幅里，请求级记录里没有 ⇒ 跨轮比对要翻两个文件）；②用**第三个负载**（半重复文本，例如"写一段关于 X 的文字并重复其中的一个短语"）复核 2+扩窗是否仍然 ≥ 窗口 3；③若三个负载都成立，把 `KAKASI_SPEC_WINDOW` 的文档写清（默认 2、扩窗规则、何时手动改）。
**验收**：①请求级记录含窗口值②第三负载的配对数字③梯不回退。
**顺位**：请求级窗口记录 → 半重复负载复核 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## F394 计划（草稿器调参收口；转向约束解码的功能项）
**F393 结论**：默认窗口 2+扩窗在三种负载上都不劣于旧默认；`win=` 已进请求级记录。
**收口（已完成）**：树里没有 README ⇒ 运行期旋钮的说明写进了 `scripts/gate_live.sh` 头部（`KAKASI_ATTN_MODE`、`KAKASI_SPEC_WINDOW` 的默认值、启动日志名、三负载测量），完整证据在 `findings.md` F391–F393：默认 2、一次空接受扩到 3、何时手动改（已知高度重复的批量任务可显式设 2；已知无重复的交互式负载可显式设 3 以避免那一次白验）。
**下一项（队列）**：**多属性对象项 / pattern 与数值界限**（约束解码的功能缺口）。先做**现状盘点**：`api_*_object*` 系列函数当前支持哪些形状、缺哪些（多属性对象数组？pattern 约束？数值上下界？），用**失败用例**列出来，再按"一个形状一个门"的方式补。
**验收**：①盘点表（支持/不支持 + 对应函数与行号）②每个新增形状有自检门③梯不回退。
**顺位**：约束解码功能项 → 分片多卡 / FR-05（本机不可验的部分如实标注）。


## F395 计划（同类上限的另两处 + 决定"静默忽略"的处理方式）
**F394 已修**：全字符串属性上限 3→8，`SCHEMA-PROPS5` 双判据。
**动作**：①**混合叶子上限**（`:4448` 的 4）与**对象数组项上限**（`:3265` 的 4）各提高并配门（"检测到 N / 输出含 N"）；②对 **`pattern` 元字符**与**数值边界**这两处"静默放行"，先做**决定**：要么实现约束，要么**显式拒绝请求并说明不支持**（400 + 原因），**不允许继续无声当作无约束**——本轮先加"拒绝"分支与门，实现留待后续。
**验收**：①两处上限各有门且绿②不支持的 pattern/边界请求得到明确 400 而非自由文本③`SCHEMA-*` 既有门全绿④8000/8001 不回退。
**顺位**：上限与显式拒绝 → pattern/边界的真正实现 → 分片多卡 / FR-05。


## F396 计划（把"静默放行"改成"显式拒绝"）
**F395 已完成**：三处属性上限（全字符串/混合/对象数组项）都提到 8 且各有双判据门。
**动作**：对**目前会 bail 成无约束生成**的两类约束——`pattern` 含真元字符、`minimum/maximum` 非"整数+双边界+跨度≤15"——在请求校验处**显式返回 400**，message 写明"该约束暂不支持"，并加门：①带这类约束的请求得到 400 且 body 含原因；②**不带**这类约束的既有 schema 请求行为不变（回归护栏）。
**注意**：先查 `api_smoke.py` 里是否有用例依赖当前的"静默放行"（若有，先改该用例的期望，再改行为，并在 findings 里记录这次期望变更的理由）。
**验收**：①400 + 原因的门绿②既有 SCHEMA-*/TOOL-* 门全绿③8000/8001 不回退。
**顺位**：显式拒绝 → pattern/边界的真正实现 → required/minItems → 分片多卡 / FR-05。


## F397 计划（数值边界同样显式拒绝；然后才谈实现）
**F396 已完成**：pattern 元字符 → 400 + 原因（活体双向验证），`SCHEMA-PAT-REJECT` 双向门。
**动作**：①`minimum/maximum` 的非支持组合（浮点边界、只有一侧边界、整数跨度 >15）→ **显式 400 + 原因**，与 pattern 同款；②门 `SCHEMA-BOUND-REJECT` 双向断言（受支持的"整数+双边界+跨度≤15"仍 200 且被约束；非支持组合 400）；③把"哪些约束受支持"写成一张表放进 findings（请求侧可预期的行为清单）。
**验收**：①双向门绿②活体 400/200 各一例③既有 SCHEMA-*/TOOL-* 全绿④8000/8001 不回退。
**顺位**：数值边界显式拒绝 → 约束支持表 → pattern/边界的真正实现 → required/minItems → 分片多卡 / FR-05。


## F398 计划（约束支持表 + required/minItems 的显式处理）
**F397 已完成**：pattern 与数值边界的非支持组合都**显式 400**，双向门 + 两种 JSON 排版，活体验证。
**动作**：①把"哪些约束受支持 / 哪些会被拒 / 哪些仍被忽略"写成一张表（findings 里），作为请求侧可预期的行为清单；②对**仍被静默忽略**的关键字（`required`、`minItems`/`maxItems`、`minLength`/`maxLength`、`additionalProperties`）做同样的处理：**要么实现、要么显式拒绝**，先做显式拒绝（与 pattern/边界同款），并各配双向门；③oneOf 的分支限制写进同一张表。
**验收**：①支持表与实际行为一致（逐条有门或活体证据）②新增拒绝分支不误伤受支持用法（双向 + 两种排版）③梯不回退。
**顺位**：支持表与显式拒绝 → pattern/边界/required 的真正实现 → 分片多卡 / FR-05。


## F399 计划（把"明确拒绝"推进到"真正支持"的第一项：pattern 的字面量集合已支持，下一步是 minLength/maxLength）
**F398 已完成**：支持表 + 五关键字显式拒绝 + required 真子集判定；回归修复并活体验证。
**动作**：挑**最小可实现**的一项从"拒绝"变"支持"：**字符串 `minLength`/`maxLength`**（生成器已按字符集逐步生成，加长度计数即可在 grammar 层收口），配双向门（长度内的字符串 200 且长度合规；超长的被 grammar 截断/拒绝）与活体两例。
**验收**：①长度约束在输出上可验证（字符数在界内）②既有 SCHEMA-* 全绿③8000/8001 不回退④支持表对应行从"显式 400"改为"支持"。
**顺位**：minLength/maxLength → minItems/maxItems → required 真子集 → pattern 元字符 → 分片多卡 / FR-05。


## F400 计划（下一项从"拒绝"变"支持"：minItems/maxItems）
**F399 已完成**：字符串长度约束在扁平全字符串形状上真正支持（按字符预算过滤），门检查输出长度，活体双向通过。
**动作**：数组的 `minItems`/`maxItems`——数组生成器已按元素步进，加元素计数即可在到达 max 时只允许 `]`、未到 min 时不允许 `]`；同样只在**能 honour 的形状**（字符串数组/数字数组）放行，其他形状仍拒绝；门检查**输出的元素个数**而不是状态码。
**验收**：①元素数在界内（门 + 活体）②其他形状仍拒绝（双向）③既有 SCHEMA-* 全绿④8000/8001 不回退。
**顺位**：minItems/maxItems → required 真子集语义 → pattern 元字符 → 分片多卡 / FR-05。
**F400 已完成**：单属性标量数组的 `minItems`/`maxItems` 按**元素计数**执行（未到 min 只给逗号、到 max 只给右括号、min 高于默认预算时抬到 min 上限 8）；门 `SCHEMA-COUNT` 断言输出的元素个数；`SCHEMA-KEYWORDS` 扩到 14 例（界写在对象上 / 对象数组 / `maxItems:0` / min>max 仍拒）；活体七例全绿（含流式与抬高分支）；梯 **174/0**、8000 **115/0**、8001 绿、scaling **1.562×**。
**F400b 已完成**：界写在 `items` **之前**的排版是**检测缺口**（执行路径本来就对）⇒ 两个数组检测器各加"只认这两个关键字"的前置可选段；门加 `pre_n=2 pre_refuse=[]`，活体加第 8 例 200/2 元素；梯复跑 **174/0**、8000 **115/0**、8001 绿、scaling **1.539×**。


## F401 计划（required 真子集：从"拒绝"变"支持"——只输出被要求的属性）
**F400 已完成**：`minItems`/`maxItems` 在单属性标量数组上真正执行，门数元素，活体双向。
**动作**：`required` 目前是**生成器发出全部声明属性**，因此"全 required"天然满足、真子集只能拒绝。改成**按 required 列表过滤 plan**：属性步进生成器（扁平字符串/混合/对象数组项）在拼下一个键之前，跳过不在 `required` 里的可选属性——发出的是"全部必需属性、不含任何被省略属性"的对象，这是真子集 schema 的一个**合法解**（不是"省略是可选的"那种完整语义，findings 里要按这个口径写清楚）。放行判据同样只在**能 honour 的形状**上成立：required 的名字必须是 plan 里属性的**子集**且这些形状走属性步进生成器；名字不认识、或形状不走属性步进（顶层数组、纯 enum/const 等）仍拒绝。
**门**：①新门 `SCHEMA-REQ-SUBSET`：断言**输出的键集合** = required 集合（不是状态码），两种 JSON 排版；②`SCHEMA-KEYWORDS` 的 `req_subset` 期望从 `[required]` 改为 `[]`，并**新增仍拒绝的对照**（required 里含未声明的属性名 → `required`）——旧期望被新行为证伪，按 F399 的做法改门而不是保绿。
**活体双向**：真子集 → 200 且键集合等于 required；未知名字 → 400；全部声明属性 → 200（不回归）；带 tool 参数 schema 的请求 → 200（F398 的误伤面）。
**验收**：①输出键集合正确（门 + 活体）②其他形状/未知名字仍拒绝（双向）③既有 SCHEMA-*/TOOL-* 全绿④8000/8001 不回退。
**明确留下（排队，不装作支持）**：`minItems`/`maxItems` 的其他形状（多属性、顶层数组、对象数组）、`maxItems:0` 与元素上限 8 以上的 min；`additionalProperties:false`；pattern 元字符与数值边界的真正实现；多属性对象数组项；per-stream k 自适应（已按证据关闭为不支持）；分片多卡 / FR-05（本机不可验证）。
**顺位**：required 省略到混合形状 → additionalProperties 语义 → 数组界的其余形状 → pattern/边界实现 → 分片多卡 / FR-05。
**F402 已完成（部分）**：②审计确认按序号取用只有 bounds 一处且已对齐；③`additionalProperties` 布尔拼写在扁平全字符串对象上改为**支持（构造性质）**，`SCHEMA-KEYWORDS` 增至 22 例、活体增至 7 例双向；梯 **175/0**、8000 **115/0**、8001 绿、scaling **1.515×**。**未做**：①混合形状的 required 省略（`addprops_mixed` 仍拒为证）；数组界 + additionalProperties 的**过度拒绝**仍在（`addprops_arr=[maxItems]` 钉住）。


## F403 计划（先还债，再扩面：两处**已知过度拒绝**）
**F403② 已完成**：required 省略 / additionalProperties 扩到**混合叶子**（integer/number/boolean），并修掉活体探针抓到的**候选层丢 required 键**真缺陷（`{flag:b,count:i}` 全必需曾答 `{"flag":false}`）——候选层 required 感知（required 点名未枚举键 ⇒ 不产候选，语法链带全键服务）+ `api_object_mixed` 布尔分支（b 原发成数字）+ `api_schema_mixed_omittable`（kind ∈ {s,i,n,b}）进过滤形状门 + plan 过滤放 `api_response_schema_mixed_object` 内部（四链一处生效）+ addprops kind 加 i/n/b + 关键字门收尾（全发射面外多叶 plan 由候选层当裁判，空 ⇒ 400 required）。`SCHEMA-KEYWORDS` 26 例（req_mixed/addprops_mixed 期望证伪改 `[]`，req_arr_*/req_enum_all/addprops_enum 仍拒对照）；`SCHEMA-REQ-SUBSET` 加混合/布尔断言；活体 13 例双向全绿（`bool_all_required` 200 两键真布尔；第一轮 FAIL 是测试自身 `keys_of` 排序顺序写反，改断言后绿）。梯 **175/0**、8000 **115/0**、8001 绿、scaling **1.536×**、哨兵 **0**。
**明确留拒（排队）**：数组叶子的 required 子集、`{mode:e,flag:b}` 全必需（门钉住为已知过度拒绝，后续按"先证键集合再放行"取消）。
**F403① 已完成**：数组界 + `additionalProperties` 判据**可组合**——数组排除正则去掉该关键字（键约束由自己的判据在拒绝时判定）、addprops 判据 kind 集加 `a`；`SCHEMA-KEYWORDS` 的 `addprops_arr` 期望被证伪改 `[]` 并新增 `addprops_form_arr=[additionalProperties]` 对照；harness 增至 9 例双向全绿（200+元素数+键集合 / 400+点名）；拒绝消息补上实际支持面；数组界 8 例复跑全绿；梯 **175/0**、8000 **115/0**、8001 绿、scaling **1.557×**、哨兵 **0**。**过程**：上次会话 10:56 的 selftest 日志是中间态构建产物；`run-kakasi-selftest.bat` 的 `%1` 在内部 `cd engine` 之后展开 ⇒ 必须传纯文件名（传 `engine\xxx.log` 落到不存在的 `engine\engine\`、exe 根本没跑）。
**动作**：①取消 `addprops_arr` 那类"两个各自支持的约束同时出现就拒绝"的排除条件——把数组判据里"含 additionalProperties 即排除"改成"**该关键字自身在支持面内则不参与排除**"，判据要可组合而不是互相踩；②把 `additionalProperties` / required 省略的支持面从 `{s}` 扩到**混合叶子**（含 integer/number/boolean）：这条路径的键名同样来自 plan，但必须**先用键集合门证明**（`api_object_mixed` 输出不含未声明键、且 required 子集时少发键）再放行判据；③两处各配双向门与活体例。
**验收**：①`addprops_arr` 期望由 `[maxItems]` 改 `[]`（旧期望被新行为证伪 ⇒ 改门并说明）；②混合形状新门断言**键集合**；③既有 SCHEMA-* 全绿；④8000/8001 不回退；⑤拒绝消息的支持面描述与实际一致。
**顺位**：这两笔债 → pattern 元字符实现 → 数值边界实现 → 数组界的其余形状（多属性/顶层/对象数组）→ 分片多卡 / FR-05。
**F401 已完成**：`required` 真子集在扁平全字符串对象上按**省略可选属性**执行（属性表与 bounds 吃同一张过滤表）；门 `SCHEMA-REQ-SUBSET` 断言**键集合**；`SCHEMA-KEYWORDS` 的 `req_subset` 期望被证伪并改为 `""`，加 `req_undeclared`/`req_mixed` 仍拒；**计数比较**换成**名字比较**（旧判据把 `required:["a","z"]` 当成"全部必需"而放行）；活体组合例抓到 **F399 遗留的非法 JSON**（maxLength 收口用了多字符引号 token）并修好；梯 **175/0**、8000 **115/0**、8001 绿、scaling **1.520×**。


## F402 计划（把 required 省略推到"混合形状"，并清掉同族判据债）
**F401 已完成**：见上；仍只覆盖扁平全字符串对象。
**动作**：①`api_object_mixed`（混合叶子 ≤8）也按属性步进 ⇒ 把过滤表推进到这条路径：`api_schema_required_filter` 的形状判据从"全字符串叶子"扩到"叶子类型不影响步进"的集合，逐个形状**先验证它真的会少发键**再放行，不能只改判据；②bounds/charset/步数等**按属性序号取用**的地方全部改成吃过滤后的表（F401 里 bounds 就是这一处差点错位）；③`additionalProperties:false` 同族处理：现在的语义是"发全部必需键"，声明了 additionalProperties:false 时并不会多出键 ⇒ 先判"这条约束是否已被构造满足"，满足就不拒绝（与 required 全必需同理），不满足才 400。
**门**：`SCHEMA-REQ-SUBSET` 加混合形状例（string+integer+string，required 两个 ⇒ 键集合＝required 且整数值仍是整数）；`SCHEMA-KEYWORDS` 的 `req_mixed` 期望按新事实更新（若支持则改 `""`，并加"仍不支持的形状"对照）；`additionalProperties` 例双向。
**验收**：①混合形状键集合正确（门 + 活体）②未支持形状/未声明名字仍拒绝（双向）③既有 SCHEMA-* 全绿④8000/8001 不回退⑤错误消息与实际支持面一致（不许留旧说法）。
**顺位**：required 省略到混合形状 → additionalProperties 语义 → 数组界的其余形状 → pattern/边界实现 → 分片多卡 / FR-05。


## F404 计划与完成（pattern 元字符实现：从"显式 400"到"真正支持"）
**动作**：实现 `^...$` 锚定、字面量段 + 精确计数字符类段（`{n}`/裸类=1，n≤16，同族区间解析期展开）的 pattern；plan 新 kind `p:`（spec 编码 `l<text>~c<n>x<chars>`）；`api_object_mixed` p 分支（类字符走单字符 id 过滤）；混合检测器移出 pattern 排除 + 单叶 p plan；关键字门 fullEmission 计入 p。
**F404 已完成**：自检 **176/0**（新门 `SCHEMA-PAT-IMPL`：发射值 preg_match 匹配模式；`SCHEMA-PAT-REJECT` 11 例双向）；8000 **115/0**、8001 绿、scaling **1.499×**、哨兵 **0**；活体 10 例双向全绿（`engine/schema_pattern_f404.log`）。**门抓到的真缺陷**：模式里的 `{3}` 使叶子值大括数=2 ⇒ "一大括号=扁平叶子"启发式丢叶 ⇒ **剥 JSON 字符串内容后计数**（三处形状判定全改）。**记法：括号计数启发式必须剥字符串；值匹配（非状态码）才是 pattern 的验收面。**
**明确留拒（排队）**：`{n,m}` 区间量词（可复用 F399 字符预算）、`* + ?`、否定类、非锚定类、嵌套叶子类 pattern、pattern 同叶 minLength/maxLength。
**顺位**：数值边界实现 → 数组界的其余形状（多属性/顶层/对象数组）→ 分片多卡 / FR-05。


## F405 计划与完成（数值边界实现：从"跨度≤15 双侧"到"任意跨度 + 单侧可缺"）
**动作**：单整数叶形状判据（kind "i"、界至少一个且在场者非负、无 enum/const/pattern/multipleOf）+ `api_number_bounded_fill` 逐位走界（定长、界交集、前缀放宽）+ 边界门豁免 + 四链接入 + 消息更新。
**F405 已完成**：自检 **177/0**（新门 `SCHEMA-NUM-BND`：发射值在界内 + json_ok；`SCHEMA-BOUND-REJECT` onesided/wide 证伪改 0 + 四个仍拒对照）；8000 **115/0**、8001 绿、scaling **1.574×**、哨兵 **0**；活体 8 例双向全绿。**活体抓到的真缺陷**：maximum-only 的缺席哨兵 lo=-1 撞 fill 的 `$lo < 0` 守卫 ⇒ 线上 `{"n":}` 非法 JSON（门只测了 minimum-only）⇒ 修守卫 + 补 v5 断言。**记法：哨兵值与守卫条件一起复核；检测器绿 ≠ 发射器绿——每个发射分支都要有直接断言。**
**明确留拒（排队）**：负界任意跨度（带符号走位）、浮点界、多叶有界整数、multipleOf 组合。
**顺位**：数组界的其余形状（多属性/顶层/对象数组）→ `{n,m}` 区间量词/`* + ?` → 分片多卡 / FR-05。


## F406 计划与完成（数组界的顶层数组形状）
**F406 已完成**：关键字检测器加顶层分支（锚定数组对象 + 界仅此两键 + items 恰标量字符串 + max0/min>max/min>8 同款拒绝）；服务检测器正则同步放宽（堵"门放行、无人服务"的缝隙）；顶层写入器加 F400 同款计数机制；四链接入。自检 **177/0**（SCHEMA-COUNT 顶层例 + 关键字门双向）；8000 **115/0**、8001 绿、scaling **1.566×**、哨兵 **0**；活体 11 例全绿（顶层 2/2、6/7 抬高、max0 拒绝）。
**明确留拒（排队）**：多属性对象里的带界数组叶、顶层数字/布尔数组的界、对象数组的界。
**顺位**：多属性带界数组叶 → 对象数组界 → `{n,m}` 区间量词 → 分片多卡 / FR-05。


## F407 计划与完成（数组界的多属性形状：带界数组叶与兄弟键同发）
**F407 已完成**：plan 数组叶 kind 带 `~min~max` 编码（a/an/af/ab）；`api_object_mixed` 计数数组分支；关键字检测器 bIdx 规则（带~叶子最多一个、其余 ∈ {s,i,n,b}）；两个单数组服务检测器让位（有界∧多叶）；fullEmission/addprops 接受 ~ 前缀。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.561×**、哨兵 **0**；活体 14 例全绿（多叶 2/2 双键、6/7 抬高、坏界拒绝）。**门抓到的回归**：第一版只认 `a~` ⇒ 单叶整数数组带界断裂 ⇒ 四前缀统一。**记法：kind 编码扩展时所有按 kind 串相等判断的路径都要重过。**
**明确留拒（排队）**：对象数组的界（ao 写入器计数化）、`{n,m}` 区间量词、嵌套叶子的带界数组、required 子集与带界数组叶组合（mixed_omittable 不放 a~，已知过度拒绝钉住）。
**顺位**：对象数组界 → `{n,m}` 区间量词 / `* + ?` → 分片多卡 / FR-05。


## F408 计划与完成（数组界的对象数组形状）
**F408 已完成**：ao 写入器计数化（$bounds 参数 + F400 机制）；关键字检测器 items 检查重构并前移（ao 天然两个 properties 块，计数规则先拒的顺序缺陷）；`api_schema_array_object_bounds`（单叶 + "ao" kind + occurrence + 界验证）；两处 ao 正则接受界段；四链接入。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.495×**、哨兵 **0**；活体 16 例全绿（ao 2/2、坏界拒绝、原用例 3 翻转为 200）。**门抓到的缺陷**：检查顺序（ao 被计数规则先拒）+ ao kind 的段数判断恒假（kv[1]=="ao"，itemkey 在 kv[2]）。**记法：plan 条目段数因 kind 而异，按 kv[1] 判 kind 前先明确段数约定。**
**明确留拒（排队）**：多叶 ao、`{n,m}` 区间量词、嵌套带界数组。
**顺位**：`{n,m}` 区间量词 / `* + ?` → 嵌套带界数组 / 多叶 ao → 分片多卡 / FR-05。


## F409 计划与完成（pattern 第二批：{n,m} 区间与 * + ?，仅末段）
**F409 已完成**：spec 解析器量化符（非精确仅末段——闭引号独立 token 无歧义；非末段维持 400）；编码 exact/区间/无界三形式；写入器统一走位（走位永不发射闭引号，尾部 $pcq 闭合）。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.490×**、哨兵 **0**；活体 13 例全绿。**弯路**：构建三连败（pvBase 顺序 + 括号）期间误读旧二进制的 177/0 为本轮证据；exact 被当 ∞；双闭引号杂散。**记法：门红先核二进制出身（BUILD_EXIT 逐字核对）；值内杂散字符是发射重复的指纹。**
**明确留拒（排队）**：非末段量化符（需 NFA 走位）、否定类、非锚定、嵌套带界数组、多叶 ao。
**顺位**：嵌套带界数组 / 多叶 ao → 否定类与非锚定 → 分片多卡 / FR-05。


## F410 计划与完成（数组界的多叶 ao 形状）
**F410 已完成**：`api_schema_array_object_bounds` 放宽单叶限制（恰一个 "ao" 叶；两个仍拒）；ao 写入器计数 + 兄弟键合法省略（键集合断言）；required/子集仍拒自动覆盖。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.507×**、哨兵 **0**；活体 18 例全绿。**记法：放宽单叶限制时 required/子集/addprops 三条规则逐一复核（本例零新代码但每条有证据）。**
**明确留拒（排队）**：嵌套带界数组、非末段量化符（NFA）、否定类、非锚定。
**顺位**：嵌套带界数组 → 否定类与非锚定 → 分片多卡 / FR-05。


## F411 计划与完成（数组界的嵌套形状）
**F411 已完成**：嵌套步进器解析 ~ 编码（缺省无界）+ 数组分支计数（预算抬升 + 边界过滤）；`api_schema_nested_array_bounds`（镜像嵌套检测器下潜 + 叶层验证 + 镜像排除）；检测器前移调用。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.546×**、哨兵 **0**；活体 20 例全绿（嵌套 2/2、坏界拒绝）。**门/活体抓住的回归**：重构边界过滤丢掉无界叶子的末元素强制闭合 ⇒ 尾逗号非法 JSON（smoke 115→110）⇒ 无界路径逐 token 对齐。**记法：重构"预算+边界过滤"循环时无界路径逐 token 对齐；尾逗号是边界过滤丢失的指纹。**
**明确留拒（排队）**：非末段量化符（NFA）、否定类、非锚定、多叶嵌套带界。
**顺位**：否定类与非锚定 → 多叶嵌套带界 → 分片多卡 / FR-05。


## F412 计划与完成（pattern 第三批：否定类与非锚定）
**F412 已完成**：否定类 = 排除集在 65 字符安全字母表内的补集（空补集仍拒）；锚点可选（非锚定以锚定发射兑现"包含"语义）。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.485×**、哨兵 **0**；活体 15 例全绿。**记法："包含"语义的约束可用匹配自身兑现——语义化简（约束 ⇒ 一个合法解）是窄实现的核心手法。**
**明确留拒（排队）**：非末段量化符（NFA）、body 内字面量 `^`、转义 shorthands、多叶嵌套带界。
**顺位**：多叶嵌套带界 → 转义 shorthands → 分片多卡 / FR-05。


## F413 计划与完成（pattern 第四批：转义 shorthands）
**F413 已完成**：裸转义 `\d`/`\w`/`\s` 合成字符集（量化符复用）；类内转义同映射；安全字母表加下划线（三处同步）；`\D`/字面量反斜杠仍拒。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.536×**、哨兵 **0**；活体 18 例全绿。**门抓到的两个缺陷**：线材转义双拼写（活体 `\d` 在线材是 `\d`）⇒ 双拼写统一；字母表扩展的否定类连带（旧空补集夹具非空）⇒ 真空补集改全排除 + 部分否定对照。**记法：pattern 门在线材原文上工作须双拼写；字母表扩展有否定类连带。**
**明确留拒（排队）**：非末段量化符（NFA）、body 内字面量 `^`、其他转义、多叶嵌套带界。
**顺位**：多叶嵌套带界 → 其他转义 → 分片多卡 / FR-05。


## F414 计划与完成（数组界的多叶嵌套形状）
**F414 已完成**：`api_schema_nested_array_bounds` 逐叶验证（每个带界叶子独立验证）+ 总量 occurrence（带界叶子之和 == 全 schema，游离界仍拒）；嵌套步进器零改动。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.609×**、哨兵 **0**；活体 22 例全绿（双带界数组同时兑现、游离界拒绝）。**记法：多叶带界的 occurrence 从"单叶==全 schema"改为"带界叶子之和==全 schema"。**
**明确留拒（排队）**：非末段量化符（NFA）、body 内字面量 `^`、其他转义（\D 等）。
**顺位**：非末段量化符（NFA）→ 其他转义 → 分片多卡 / FR-05。


## F415 计划与完成（pattern 第五批：非末段量化符——最小计数发射）
**F415 已完成**：非末段量化符发射**最小计数**（dmin ∈ [min,max] ⇒ 值是合法匹配——子集合法论证，与 F405 同源；**原 NFA 设计是过度设计**）；解析器仅拒 min=0 非末段；写入器段循环索引化。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.693×**、哨兵 **0**；活体 18 例全绿（非末段星号/区间值匹配、min=0 拒绝）。**记法：约束 ⇒ 一个合法解的语义化简再次取代机制设计——先问"发射一个合法解是否兑现约束"，再考虑自动机。**
**明确留拒（排队）**：min=0 非末段（epsilon）、body 内字面量 `^`、\D 等其他转义。
**顺位**：其他转义 → body 内字面量 ^ → 分片多卡 / FR-05。


## F416 计划与完成（pattern 第六批：\D 转义与类内字面量 ^）
**F416 已完成**：`\D` 裸用与类内 = 数字补集（否定机制复用）；类体非首位 `^` 作字面量（值字母表 67）；空补集夹具随字母表涟漪更新（F413/F416 连续两轮同款）。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.540×**、哨兵 **0**；活体 20 例全绿。**记法：值字母表每次扩展都有否定类涟漪——空补集夹具随字母表同步；补集字母表与值字母表是单一事实源的两处。**
**明确留拒（排队）**：非末段 min=0（epsilon）、类外字面量 `^`（锚点歧义）、`\b` 等非字符类转义。
**顺位**：审视剩余排队项价值 → 分片多卡 / FR-05（本机不可验证，保持排队）。


## F417 计划与完成（min=0 非末段解除 + 断言类拒绝归档）
**F417 已完成**：解析器 min≥1 限制解除（min=0 非末段由最小计数机制天然服务——星号段发 0 字符进下一段）；断言类拒绝归档（模式中间 ^/$ 与 \b 是正则断言非字面量，不可满足 ⇒ 现行拒绝正确）。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.468×**、哨兵 **0**；活体 21 例全绿。**记法：排队项先做正则语义分析再决定实现；改动"拒绝检查"时先读当前条件再动手。**
**排队收敛**：pattern 主干全覆盖；剩余 exotic 变体归档为正确拒绝。分片多卡 / FR-05 本机不可验证保持排队。


## F418 计划与完成（取消数组叶子 required 子集过度拒绝）
**F418 已完成**：`api_schema_mixed_omittable` 接受计数数组前缀——过滤 plan 丢弃该叶（被省略的键不可能违反自己的界）；键集合证明。自检 **177/0**；8000 **115/0**、8001 绿、scaling **1.603×**、哨兵 **0**；活体 19 例全绿。**记法：可省略的判据是发射器能否正确省略而非 kind 类别；被省略的键不可能违反自己的界。**
**排队收敛**：F403② 的两个已知过度拒绝全部取消。


## F419 计划与完成（数值边界：负界任意跨度）
**F419 已完成**：`api_number_bounded_value` 符号映射（同负 = -+幅值走位 fill(-hi,-lo)；跨零/单侧负下界 = 裁剪非负侧；单侧负最大 = -+幅值单侧走位）；shape 撤销负界拒绝（哨兵碰撞逐一核对合法）；消息更新。自检 **177/0**；8000 **115/0**、8001 绿、哨兵 **0**；活体 10 例全绿（同负/跨零/浮点拒绝）。**记法：带符号走位映射到幅值走位；哨兵碰撞逐一核对落合法子集。**
**明确留拒（排队）**：浮点界（multipleOf 与界组合已由 F420 取消拒绝）。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F420 计划与完成（multipleOf 与界组合：确定性单值发射）
**F420 已完成**：`api_schema_number_bounded_shape` 撤销 multipleOf 排除，把 lo/hi **折叠到界内最小倍数**（正下界从 lo 起找；双负走幅值；跨零/单侧负下界用 0——0 是任何 m 的倍数且在界内；区间内无倍数 = 不可满足 ⇒ 形状拒绝），返回格式加第 4 段 m（调用方按 `explode("|")` 取前三段，兼容）；写入器 `lo==hi` 天然发射该值，**无需新机制**。
**本轮补的两个洞**：①门期望把 echo 标签的前导空格抄进比较串（`" n|1|5|0"`）⇒ 必红，已改回；②**不可满足倍数原先静默服务**（形状返回 "" 后旧跨度规则判受支持、候选层发非倍数）⇒ `api_schema_bounds_unsupported` 在形状判定后加"带界 + multipleOf 而形状不认 ⇒ 拒"，400 消息写明实际支持面。
**门**：`SCHEMA-NUM-BND` `mult_shape=[n|7|7|7] v8=7`；`SCHEMA-BOUND-REJECT` `mult_one=0`（单叶服务）/`mult_unsat=1`/`mult_wire=0`（空格排版），多叶 `mult_of=1` 仍拒。
**活体**：bounds **12 例全绿**——`min1 max100 m7` → `{"n":7}`；双负 `[-100,-10] m7` → `{"n":-14}`（幅值走位）；`[1,5] m7` → **400**；required/pattern/count 复跑不回退。
**梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.562×**、哨兵 **0**（`engine/selftest_f420b.log`、`engine/schema_bounds_f420.log`、`engine/api_smoke_f420.log`、`engine/throughput_f420.log`）。
**过程错误**：第一次重建 `SYCL_LINK_FAILED` 后自检跑的是**旧二进制**（门文本缺新字段）⇒ 杀残留进程、重建、查 exe mtime/size、复跑，并以"输出里出现新门字段"作为新码在二进制里的证明。
**记法**：**确定性单值发射是 multipleOf 的诚实服务——一个合法解即满足"界内"要求（子集论证复用）；不可满足的约束必须拒绝，否则候选层会静默发出违反值。**
**明确留拒（排队）**：多叶 multipleOf、`multipleOf` > 1000、number（非整数）叶的浮点界（整数叶的浮点界已由 F421 向内取整服务）。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F421 计划与完成（浮点界口径复核：复核出的是**漏拒**，不是过度拒绝）
**动作（计划口径）**：先复核"浮点界一律拒绝"是否诚实，再谈实现。
**复核结果**：number 叶的浮点界确实拒了；**integer 叶的浮点界被静默截断后放行**——形状正则 `(-?[0-9]+)` 丢掉小数，`[1.5,1.9]` 变成 `[1,1]`，拒绝函数又先问形状（非空即放行），旧的"含小数点即拒"永远走不到。改前活体取证：**200 `{"n":1}`**（区间内无整数却发了小于下界的值）。
**F421 已完成**：①`api_schema_bound_inward` **向内取整**（下界 ceil、上界 floor；负数截断方向天然正确；小数全 0 不动；非纯十进制返回哨兵）；②形状取整后 `lo>hi` ⇒ 无形状（不可满足）；③拒绝函数补"界字面量非纯十进制 ⇒ 拒"，压住 `1e1..12` 这类**小跨度指数**（否则旧跨度规则判受支持并从 1 发）；④400 消息改为实际支持面。
**门**：新门 `SCHEMA-NUM-FRAC`（pos/neg/empty/integral + 发射值 ∈[2,9]）；`SCHEMA-BOUND-REJECT` 加 `frac_int=0 frac_unsat=1 frac_exp=1 frac_neg=0`；**纯 helper 单测** `engine/tests/bound_inward_test.php`（PHP CLI、运行时抽取被测函数防漂移）**22 例全绿**。
**活体**：bounds **14 例全绿**，空区间由 200 `{"n":1}` 翻 **400**、`[1.5,9.5]` → `{"n":2}`；改前/改后成对留证（`schema_bounds_f421pre.log` vs `schema_bounds_f421.log`）；required/pattern/count 不回退。
**梯**：自检 **178/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.421×**、哨兵 **0**（`engine/selftest_f421b.log`、`engine/api_smoke_f421.log`、`engine/throughput_f421.log`）。
**过程错误**：首次自检 `RUN_EXIT=-1` 停在 `PARITY 164`；排除竞争（无进程/无哨兵/exe 正常）后重跑一次即跑完 ⇒ 定性为偶发设备中止。**判据：崩在与改动无关的阶段先排除竞争再重跑，只有复现在同一处才按回归二分。**
**记法**：**"拒绝口径复核"复核出来的往往是漏拒而非过度拒绝；判据要问"发射器会不会产出违反值"。取整必须向内（收紧后的整数区间里每个值都满足原实数区间——子集论证）。纯算术 helper 用 PHP CLI 抽取式单测，不必占设备。**
**明确留拒（排队）**：number 叶浮点界、多叶 multipleOf、`multipleOf` > 1000、`exclusiveMinimum/exclusiveMaximum`（尚未复核口径）。
**顺位**：exclusive 边界口径复核 → 分片多卡 / FR-05（本机不可验证，保持排队）。


## F422 计划与完成（exclusive 边界口径复核：复核出的是**静默违反**，不是拒绝）
**动作（计划口径）**：exclusiveMinimum/exclusiveMaximum 的口径复核。
**复核结果（改前活体取证，`serve_f422pre.log`）**：窄跨度 exclusive 由候选层服务（`SCHEMA-EXCL` 门已覆盖）；**宽跨度/单侧 exclusive 被静默忽略**——`exclusiveMinimum:0, exclusiveMaximum:100000` → **200 `{"n":234567}`**（234567 ∉ (0,100000)，违反上界），`exclusiveMinimum:5`（单侧）→ **200 `{"n":234567}`**。根因：①宽跨度 shape 检测器只读 `minimum`/`maximum`，不读 exclusive；②拒绝函数 `api_schema_bounds_unsupported` 的早退（无 min/max 即 return 0 "受支持"）让 exclusive-only 请求绕过所有拒绝逻辑，落入忽略 exclusive 的通用生成器。
**F422 已完成**：①新 helper `api_schema_bound_exclusive($lit,$isMin)`——**exclusiveMinimum X → floor(X)+1、exclusiveMaximum X → ceil(X)-1**（整数叶相邻整数界；floor/ceil 作用于纯十进制字面量，小数 exclusive 界也服务而非截断；非纯十进制/指数写法返回哨兵）；②shape 检测器在 inclusive 缺席时回退读 exclusive，`$loSet/$hiSet` 贯穿区间与 multipleOf 走位；③拒绝函数早退补 exclusive 关键字，并加"exclusive-only 且 shape 空 ⇒ 拒"（压住空区间/多叶/指数）；④400 消息写明 exclusive 转换面。
**门**：新门 `SCHEMA-NUM-EXCL`（`wide=[n|1|99999|0] min1=[n|6|-1|0] max1=[n|-1|49|0] unsat=[] frac=[n|2|9|0] mult=[n|7|7|7] v∈[2,9]`）；`SCHEMA-BOUND-REJECT` 加 `excl_wide=0 excl_unsat=1 excl_mult=1 excl_one=0`；**纯 helper 单测** `engine/tests/bound_exclusive_test.php`（PHP CLI、运行时抽取）**22 例全绿**。
**活体**：bounds harness 加 4 例——`excl_wide_served`（0< n <100000 → 值∈[1,99999]）、`excl_onesided_min`（n>5 → 值≥6）、`excl_onesided_max`（n<50 → 值≤49）、`excl_unsatisfiable`（5<n<6 → **400**）。
**已知限制（记录，不修本轮）**：同一侧同时给 inclusive+exclusive 时 inclusive 优先（与候选层同优先级），若 exclusive 更严则发射值可能违反该更严 exclusive——本轮只覆盖 exclusive-only 面。
**梯**：自检 **179/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.431×**、哨兵 **0**；required/pattern/count 复跑不回退。
**记法**：**exclusive 界的诚实服务是"相邻整数界"（floor(X)+1 / ceil(X)-1），值严格落在开区间内（子集论证）；拒绝函数的早退必须把 exclusive 关键字也纳入，否则 exclusive-only 请求绕过所有拒绝落入忽略约束的生成器——静默违反比过度拒绝更难发现。**
**明确留拒（排队）**：number 叶浮点界、多叶 multipleOf、`multipleOf` > 1000、同侧 inclusive+exclusive 更严 exclusive 的混合面。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F423 计划与完成（同侧 inclusive+exclusive 混合面：更严者胜）
**动作（计划口径）**：把 F422 记录的"同侧 inclusive+exclusive 更严 exclusive 混合面"关掉。
**复核结果（改前活体取证，`serve_f423pre.log`）**：更严 exclusive 被更宽 inclusive 遮蔽——`minimum:1 + exclusiveMinimum:5` → **200 `{"n":2}`**（应 n≥6，违反）；`maximum:50 + exclusiveMaximum:20` → **200 `{"n":23}`**（应 n≤19，违反）。更严 inclusive 侧正常（`minimum:5 + exclusiveMinimum:1` → n≥5）。两层都漏：宽/单侧走 shape 层、窄侧（span≤15）走候选层，都取 inclusive 优先、丢弃更严 exclusive。
**F423 已完成**：同一侧可同时给 inclusive+exclusive，**更严者胜**（下界取两者较大、上界取两者较小）。①shape 层：`$hasExLo/$hasExHi` 不再被 `!$hasLo/!$hasHi` 门控，两侧各自独立解析后 `max`（下界）/`min`（上界）合并（seed 于 `PHP_INT_MIN/MAX`）；②候选层：inclusive 与 exclusive 各自读、`max/min` 合并后枚举，使窄侧的枚举从更严 exclusive 推出的界起（`minimum:1, exclusiveMinimum:4, maximum:10` 枚举 5..10，不再发 1..4）；③拒绝函数与 400 消息沿用 F422（混合可服务 shape 非空即放行、空区间/多叶仍拒）。
**门**：`SCHEMA-NUM-EXCL` 加 `mix_strict=[n|6|-1|0] mix_strictMax=[n|-1|19|0] mix_loose=[n|5|-1|0] mix_both=[n|6|50|0]`；新门 `SCHEMA-EXCL-MIX`（候选层更严 exclusive 起枚举，n=5..10、无 1..4）；`SCHEMA-BOUND-REJECT` 加 `mix_sat=0 mix_unsat=1`（`minimum:5, exclusiveMaximum:5` 空区间拒）。
**活体**：bounds harness 加 4 例——`mix_strict_min`（值≥6）、`mix_strict_max`（值≤19）、`mix_incl_stricter`（值≥5，inclusive 胜）、`mix_unsatisfiable`（n≥5 且 n<5 → **400**）；F422 exclusive 四例不回退。
**梯**：自检 **180/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.565×**、spec **1.792×/1.449×**、哨兵 **0**；required/pattern/count 复跑不回退。
**记法**：**同侧多个界（inclusive/exclusive）的诚实服务是"更严者胜"（下界取 max、上界取 min），任一层取 inclusive 优先都会静默丢更严 exclusive；合并要在每个会发射值/枚举的层各做一次（shape 与候选），只修一层会在另一层的 span 上复发。**
**明确留拒（排队）**：number 叶浮点界、多叶 multipleOf、`multipleOf` > 1000。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）——exclusive 系（exclusive-only + 同侧混合面）本轮全部关掉。


## F424 计划与完成（有界 multipleOf > 1000：拆掉人为上限）
**动作（计划口径）**：`multipleOf` > 1000（有界面）。
**复核结果**：改前活体取证（`serve_f424pre.log`）——有界 multipleOf > 1000 被**过度拒绝**（干净 400）：`min1 max1000000 m5000`（可服务，最小倍数 5000）→ **400**；`min1 m5000`（单侧可服务）→ **400**。根因：shape 检测器里 F420 抄了无界 suffix 机制的 `m ≤ 1000` 上限，但**有界路径是"折叠到区间内最小倍数"，该运算对任意 m 都成立**（模运算），上限是人为的。
**F424 已完成**：拆掉 shape 检测器有界路径的 `m > 1000` 上限（保留 `m < 1` 拒绝 multipleOf:0）。有界可服务（区间内有倍数）⇒ 折叠到最小倍数、数字走位发射该值；区间内无倍数 ⇒ 形状空 ⇒ F420 的"带 multipleOf 而形状不认 ⇒ 拒"照旧 400。**无界 multipleOf > 999 不动**（`api_multiple_fill` 的三位 suffix 机制封顶 m ≤ 999，属另一条更大机制，继续排队）。
**门**：`SCHEMA-NUM-BND` 加 `bigmult_shape=[n|5000|5000|5000] v9=5000`；`SCHEMA-BOUND-REJECT` 加 `bigmult_sat=0 bigmult_unsat=1`。
**活体**：bounds harness 加 2 例——`bigmult_served`（`min1 max1000000 m5000` → **200 `{"n":5000}`**，精确发射最小倍数）、`bigmult_unsatisfiable`（`min1 max4000 m5000`，区间内无 5000 倍数 → **400**）；F422/F423 各例不回退。
**梯**：自检 **180/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.391×**、spec **1.886×/1.522×**、哨兵 **0**；required/pattern/count 复跑不回退。
**记法**：**"抄来的上限"要问它约束的是哪条机制——有界路径靠"折叠到最小倍数"（对任意 m 成立），无界路径靠三位 suffix（m ≤ 999）；把无界机制的上限套到有界路径上就是人为过度拒绝。拆上限时保留真正的那条（无界 suffix 封顶不动）。**
**明确留拒（排队）**：number 叶浮点界、多叶 multipleOf、**无界** `multipleOf` > 999（三位 suffix 机制封顶，需更长 suffix）。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F425 计划与完成（`uniqueItems`：先修"掉出结构化路径"，再做值感知拒绝）
**改前活体**：`uniqueItems:true` 与 `false` 两方向都返回 **200 + 散文**——陌生键打断了数组检测器的精确正则，整个请求降级为无约束文本（比"重复元素"严重）。
**F425 已完成**：①两个数组检测器尾巴容忍 `"uniqueItems": false`（数组文法恢复、约束构造上满足）；②拒绝列表加 `uniqueItems`，例外**值感知**（布尔 `false` 且全 schema 只出现一次才放行，`true` 一律 400）；③400 消息补支持面。
**门**：`SCHEMA-KEYWORDS` 增至 30 例（`uniq_true=[uniqueItems] uniq_false=[] uniq_wire=[uniqueItems] uniq_route=[cities]`）；**路由断言是必需的**——改前两个方向也都是 200，只看状态码会漏。
**活体**：`engine/schema_count_f425.log` —— `unique_true_refuse` **400**、`unique_false_served` **200 真 JSON**；改前成对证据 `schema_count_f425pre.log`（两例 FAIL）。bounds/required/pattern 不回退。
**梯**：自检 **180/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.558×**、哨兵 **0**（`engine/selftest_f425b.log`、`engine/api_smoke_f425.log`、`engine/throughput_f425.log`）。
**过程错误**：第一版只加了值感知例外、**忘了把 `uniqueItems` 加回关键字名单**（早先为避让并行会话的 F424 撤回过那行）⇒ 例外是死代码，门 `uniq_true=[]` 抓住，补回名单后 PASS。**记法：例外与名单成对改；门必须断言该拒的一侧确实被拒。**
**明确留拒（排队）**：`contains`、`not`、`if/then/else`、`dependentRequired`、`propertyNames`、`unevaluatedItems/Properties`（同样会静默降级或违反，需逐个判断"是否构造上满足"再决定实现/拒绝）；number 叶浮点界、多叶 multipleOf、无界 `multipleOf` > 999。
**顺位**：结构类关键字的"实现或显式拒绝"清扫 → 分片多卡 / FR-05（本机不可验证，保持排队）。


## 日志卫生（2026-11-08，随 F426 同轮）
**规则**：①**证据与噪音的分界是"是否入库"**——`.gitignore` 覆盖的产物（`*.log`、`*.kv`、`*.bak`、`__pycache__/`、`engine/par_gate_*.txt`）与未跟踪的一次性文件可删；**已提交的日志只有"零引用且属编译转录"一类才删**，且必须逐类说明。②"改前 FAIL"的成对证据、梯的收尾日志、parity oracle（`llama_logits*.bin`）与构建缓存（`engine/build/`）**不删**。③删完必须核 `git status` 无删除条目（证明没误删已跟踪文件）与体积变化，并记录保留项的理由。
**本轮执行**：已忽略日志 2198 个、`.kv` 8 个（27 MB）、未跟踪 stray 1 个、零引用编译转录 36 个（2026 行）、`main.php.f160.bak`/`par_gate_result.txt`/`__pycache__`；体积 **259→197 MB**；已跟踪日志从 453 降到 417（只减 36）。
**规则补充（由一次失误换来的）**：④**"忽略"不等于"无效"**——删除 `.gitignore` 覆盖的文件前，若文档可能引用，必须先 `git status --ignored` **留存文件名清单**并与 `findings/progress/task_plan/docs/specs` 交叉核对；本轮那 2198 个旧日志是整批删的、没留名单，事后发现 findings 35 处 / audit 2 处引用 `build_f1xx.log` 已无文件（且从未入库、无副本可恢复）。被删的是编译转录而非结果证据，结论仍由 run/gate/自检日志支撑；但**下一步该做的是把这条清单核对写进清理流程**，而不是继续批量删。
**⑤清理不得插在"产出证据"与"提交证据"之间**：同一轮里 F426 的改前活体日志（`schema_count_f426pre.log`，13:25 生成）被 13:29 的清扫当成旧日志删掉，早于它被 `git add -f`。规则：清理只处理**当前轮 tag 之前**的文件，或先把本轮已产出的日志入库再清理。
**仍保留（待决定）**：216 个零引用的 run/gate/analysis 转录（约 2.3 MB）——它们是各轮梯的原始记录，删不删属于"是否裁剪提交历史"的决定，不擅自处理。
**操作注意**：`git add -A -- 'glob'` 对**已删除**文件不展开，须 `git ls-files 'glob' | xargs git rm`；本机偶尔 `cd` 报 `No such file or directory`，但 Bash 工作目录持久化，相对路径仍在仓库内执行 ⇒ 每次删除前先核 `pwd` 与 `git status`。
**工具（把规则变成可执行的）**：`bash scripts/check_citations.sh [--list]` 扫描三份计划文件 + `docs/` + `specs/` 里被引用的 `*.log` 名，报告"被引用但未入库"的数量。首次运行：**cited_logs=627 tracked=279 missing=348**——文档引用的日志数远超仓库实际持有的，说明"引用悬空"是长期现象而非本轮造成；该脚本只报告不判失败。
**工具（F435）**：`bash scripts/check_gates.sh [--list]` 抽取**约束支持表**里所有反引号名字（门名/字段/活体用例），要求每个能在最新自检日志或任一已提交活体日志里找到。首跑 **cited_names=54 unresolved=0**；支持表每行"有证据"从声明变成可执行验证。
**悬空引用的分布（首跑）**：`run` 91、`api_smoke` 71、`build` 47、`par_smoke` 33、`fairness` 33、`serve` 9、`throughput` 5 等 —— 绝大多数是**历史轮次写了日志名但那批日志从未入库**（`.gitignore` 的 `*.log` 之下、只有选择性地 `git add -f`）。**处理口径**：不改写历史文档（那会篡改当时的记录），而是①此后每轮把**被引用的**日志强制入库；②用该脚本在每次清理前报告增量，而不是事后发现。


## F426 计划与完成（结构类关键字：`contains`/`not`/`if`/`dependentRequired`/`propertyNames`/`unevaluated*`）
**F425 已完成**：`uniqueItems:true` 拒绝、`false` 构造满足（检测器容忍该拼写，路由恢复）。
**改前证据**：11 个关键字在 `main.php` 引用数全 0；活体 `contains` **200 忽略**、`propertyNames` **200 + 散文**、`not` 一例 **400 但点 `required`**（夹具污染）。
**F426 已完成**：`api_schema_structural_unsupported` 按值形状匹配并点名拒绝；新门 `SCHEMA-STRUCT`（8 点名 + 2 对照）；活体三例 **400 且含关键字名**；bounds/required/pattern 不回退。
**梯**：自检 **181/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.553×**、哨兵 **0**（`engine/selftest_f426b.log`、`engine/schema_count_f426.log`、`engine/api_smoke_f426.log`、`engine/throughput_f426.log`）。
**过程错误**：`$stA` 撞 session 的 int 同作用域 ⇒ `TPC_BUILD_FAILED` + exe 不变 + 管道吞退出码 ⇒ 自检跑了旧二进制；改名 `$sfA` 并核 mtime 后通过。
**明确留拒（排队）**：number 叶浮点界、多叶 multipleOf、无界 `multipleOf` > 999；结构类关键字目前是**一律拒绝**，后续可逐个判断"是否构造上满足"（如 `propertyNames` 只约束键名，键名来自声明表）再决定实现。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）；约束面按"能否产出违反值"继续清点。


## F427 计划与完成（`minProperties`/`maxProperties`：先算键数，再决定支持或拒绝）
**F426 已完成**：11 个结构类关键字点名拒绝。
**改前活体**：2 键对象上 `minProperties:3` / `maxProperties:1` 返回 **200 且 2 个键**（`schema_required_f427pre.log`，两例违反）；关键字引用数 0。
**F427 已完成**：`api_schema_plan_count` 数 plan；例外分支只在扁平全字符串形状上、按"required 子集优先"的会发键数判断——可满足则放行（F402 口径：构造满足的约束不该拒），不可满足则点名拒绝；其他形状键集合未证明，仍拒（`mixed` 边界钉在门里）。
**门**：`SCHEMA-PROPS-COUNT` 8 例全过；`SCHEMA-KEYWORDS` 40 例同时通过。
**活体**：三例（可满足 200、不可满足两例 400 点名）+ count/bounds/pattern 不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.493×**、哨兵 **0**（`engine/selftest_f427.log`、`engine/schema_required_f427.log`、`engine/api_smoke_f427.log`、`engine/throughput_f427.log`）。
**明确留拒（排队）**：`format`、`patternProperties`、`contentEncoding`/`contentMediaType`（尚未复核口径）；number 叶浮点界、多叶 multipleOf、无界 `multipleOf` > 999；结构类关键字中"可能构造满足"的（如 `propertyNames` 只约束键名）逐个评估。
**顺位**：`format`/`patternProperties` 口径复核 → 分片多卡 / FR-05（本机不可验证）。


## F428 计划与完成（值级约束：`format` / `patternProperties` / `contentEncoding`·`contentMediaType`）
**F427 已完成**：`minProperties`/`maxProperties` 按键数可满足则支持。
**改前活体**：三条均 200 违反（非邮箱 / 字符串代替整数 / 散文代替 base64）；`patternProperties`/`contentEncoding`/`contentMediaType` 引用数 0，`format` 的 3 处引用属于 `response_format`。
**F428 已完成**：结构判据改**分形状四段匹配**（对象值 / 布尔或对象 / 字符串值 / 整数值），新增四个关键字，并保留 `format_prop=[]` 对照（名为 format 的属性不误判）。
**门**：`SCHEMA-STRUCT` 15 项。
**活体**：三例 400 点名（改前三例 200）。
**梯（新二进制 14:17:44）**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.530×**、哨兵 **0**（`engine/selftest_f428.log`、`engine/schema_required_f428.log`、`engine/api_smoke_f428.log`、`engine/throughput_f428.log`）。
**明确留拒（排队）**：number 叶浮点界、多叶 multipleOf、无界 `multipleOf` > 999；`propertyNames` 等"可能构造满足"的结构关键字逐个评估。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F429 计划与完成（`propertyNames`：键名来自声明表 ⇒ 生成前可求值）
**F428 已完成**：`format`/`patternProperties`/`contentEncoding`·`contentMediaType` 按名拒绝。
**改前活体**：满足的 `pattern:"^[a-z]+$"` 被 **400 误拒**（`schema_required_f429pre.log`），另两例拒绝正确。
**F429 已完成**：`api_schema_property_names_ok` 在扁平全字符串形状上按**白名单**求值（pattern ≤200 / min-maxLength / enum / const / type:string），子 schema 含不能评估的约束即判不支持；结构判据先单独判 `propertyNames`，不通过才点名拒绝。
**门**：`SCHEMA-STRUCT` 20 项（含 `pnames_mixed` 边界：混合形状仍拒）**+ 路由断言 `pnames_route=2`**。
**第二层**：只放宽拒绝不够——值生成器的检测器把 `propertyNames` 里的 `pattern` 当成值模式，接受后返回散文；已把该块摘出扫描面并在门里断言路由（F425 同族）。
**活体**：`pnames_pattern_ok` 200 真 JSON、`pnames_pattern_bad`/`pnames_len_bad` 400 点名。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.564×**、哨兵 **0**（`engine/selftest_f429b.log`、`engine/schema_required_f429.log`、`engine/api_smoke_f429.log`、`engine/throughput_f429.log`）。
**明确留拒（排队）**：number 叶浮点界、多叶 multipleOf、无界 `multipleOf` > 999；`propertyNames` 的其他求值形式（如嵌套 schema、format）。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F430 计划与完成（无界 `multipleOf`：后缀宽度按乘数位数，超出即拒绝）
**F429 已完成**：`propertyNames` 对声明键名求值。
**改前活体**：无界 `multipleOf:5000`/`:250000` → **200 `{"n":}`（非法 JSON）**。
**F430 已完成**：`api_multiple_fill` 上限 999→999999、后缀宽度 `$k` 按位数（3–6）与 `$pow` 配套；`api_multiple_value` 空 body 返回 ""（不再发裸头部）；无界 `m>999999` 显式拒绝；`m=1` 交给普通整数生成器；消息补支持范围。
**门**：`SCHEMA-BOUND-REJECT` 加 `unbounded_big/unbounded_ok/mult_one_ub`。
**活体**：`{"n":23450000}`（%5000=0）、`{"n":2345000000}`（%250000=0）。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.512×**、哨兵 **0**（`engine/selftest_f430.log`、`engine/schema_bounds_f430.log`、`engine/api_smoke_f430.log`、`engine/throughput_f430.log`）。
**明确留拒（排队）**：number 叶浮点界、多叶 multipleOf、无界 `multipleOf` > 999999；`propertyNames` 的其他求值形式。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F431 计划与完成（多叶 `multipleOf`：要么完整、要么拒绝）
**F430 已完成**：无界 multipleOf 后缀宽度按位数。
**改前活体**：两叶 multipleOf → **200 `{"a":…}`**，第二键被静默丢弃。
**F431 已完成**：`multipleOf` 存在且 `api_schema_plan_count > 1` ⇒ 拒绝（无生成器能补其余键）；消息补该支持面。
**门**：`SCHEMA-BOUND-REJECT` 加 `mult_multi=1`、`mult_mixed=1`。
**活体**：`multi_leaf_multipleof` 400 点名（探针截断窗口 200→900 后通过）。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.525×**、哨兵 **0**。
**明确留拒（排队）**：number 叶浮点界、无界 `multipleOf` > 999999、多叶 multipleOf 的**实现**（需多叶数字生成器）；`propertyNames` 的其他求值形式。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F432 计划与完成（`dependentRequired`：条件按"会发出的键"判定）
**F431 已完成**：多叶 multipleOf 显式拒绝。
**改前活体**：满足的 `dependentRequired` 被 400 误拒。
**F432 已完成**：`api_schema_dependent_required_ok` —— 会发键集合＝required 子集或 plan；trigger 缺席则跳过；依赖缺席/未声明、形状未证明 ⇒ 拒。
**门**：`SCHEMA-STRUCT` 24 项（`dep_ok=[]` / `dep_subset` / `dep_undeclared` / `dep_mixed`）。
**活体**：`dep_ok` 200 双键、两例 400 点名。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.532×**、哨兵 **0**（`engine/selftest_f432.log`、`engine/schema_required_f432.log`、`engine/api_smoke_f432.log`、`engine/throughput_f432.log`）。
**明确留拒（排队）**：number 叶浮点界、无界 `multipleOf` > 999999、多叶 multipleOf 的实现；`dependentSchemas`/`dependencies` 仍未实现（条件 schema，不是键集合）。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F433 计划与完成（`number` 叶 + `multipleOf`：无路由 ⇒ 点名拒绝）
**F432 已完成**：`dependentRequired` 按发出键集判定。
**改前活体**：`number` 叶 + `multipleOf` → **200 正文 `10`**（散文，不是 JSON）。
**F433 已完成**：无界分支拒绝"`multipleOf` + `type:number`"（写入器只服务整数叶）；消息补明。
**门**：`SCHEMA-BOUND-REJECT` 加 `mult_number`。
**活体**：`number_leaf_multipleof` 400 点名；bounds/required/count/pattern 不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.606×**、哨兵 **0**（`engine/selftest_f433.log`、`engine/schema_bounds_f433.log`、`engine/api_smoke_f433.log`、`engine/throughput_f433.log`）。
**明确留拒（排队）**：number 叶浮点界的**实现**（需小数写入器）；无界 `multipleOf` > 999999；多叶 multipleOf 的实现；`dependentSchemas`/`dependencies`。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F434 计划与完成（tuple 校验：`items` 数组形式 / `prefixItems`）
**F433 已完成**：number 叶 multipleOf 拒绝。
**改前活体**：`"items":[…]` 与 `"prefixItems":[…]` → 200 + 散文。
**F434 已完成**：结构判据按值形状加两条 ⇒ 点名拒绝；对象形式 `items` 保持服务（门里对照）。
**门**：`SCHEMA-STRUCT` 27 项。
**活体**：两例 400 点名。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.559×**、哨兵 **0**（`engine/selftest_f434.log`、`engine/schema_required_f434.log`、`engine/api_smoke_f434.log`、`engine/throughput_f434.log`）。
**明确留拒（排队）**：number 叶浮点界实现；无界 `multipleOf` > 999999；多叶 multipleOf 实现；`dependentSchemas`/`dependencies`；tuple 校验的实现（需按位置发不同类型）。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F436 计划与完成（布尔形式的 exclusive 界）
**F435 已完成**：支持表引用检查器（54 引用 / 0 未解析）。
**改前活体**：`minimum:5 + exclusiveMinimum:true` → **200 `{"n":5}`**。
**F436 已完成**：`":true"` 拼写点名拒绝；`false` 保持服务（对照）；消息补明。
**门**：`SCHEMA-BOUND-REJECT` 加 `excl_bool`/`excl_bool_false`。
**活体**：`exclusive_bool_refuse` 400；其余不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.534×**、哨兵 **0**。
**明确留拒（排队）**：number 叶浮点界实现；无界 `multipleOf` > 999999；多叶 multipleOf 实现；`dependentSchemas`/`dependencies`；tuple 实现；布尔 exclusive 的**实现**（需按布尔语义收紧数值界）。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F437 计划与完成（布尔 exclusive 的**实现**：收紧同侧数值界）
**F436 已完成**：布尔形式点名 400（当时的权宜）。
**现状证据**（`engine/schema_bounds_f436pre.log`）：`{"n":{"type":"integer","minimum":5,"exclusiveMinimum":true}}` → **200 `{"n":5}`**；当前行为是**点名 400**（F436），因此这是"从拒绝变实现"的一步，不是修违反。
**语义**：draft-4 里 `exclusiveMinimum:true` 表示把同侧 `minimum` 收紧为严格（整数叶 ⇒ 下界 `floor(min)+1`），`exclusiveMaximum:true` 同理（`ceil(max)-1`）。只有布尔、没有同侧数值界时无意义 ⇒ 继续拒绝。
**精确改动点**（`engine/main.php`）：
1. `api_schema_number_bounded_shape`：在 `$hasExLo/$hasExHi` 的数值探测之后加两段——`/"exclusiveMinimum"\s*:\s*true/` 且 `$hasLo` ⇒ `$exn = $mn; $hasExLo = 1;`；`exclusiveMaximum` 同理用 `$mx`。已有的"同侧更严者胜"组合逻辑随即生效（`minimum:5` + 布尔 ⇒ `max(5, floor(5)+1)=6`），无需新代码。
2. `api_schema_bounds_unsupported`：把 F436 的早退条件收窄为 `preg_match(...":true") == 1 && api_schema_number_bounded_shape($body) == ""`（形状能处理就放行；布尔孤立仍拒）。
**门**（`SCHEMA-BOUND-REJECT`）：`excl_bool` 期望由 **1 改 0**（旧期望被新行为证伪 ⇒ 改门），新增 `excl_bool_alone=1`（`exclusiveMinimum:true` 无 `minimum`）；**再加一个取值断言**：用 `api_number_bounded_value($ids,"n",6,6,0)` 生成并断言输出 `{"n":6}`（证明收紧到 6 而不是放行 5）。
**活体**（`engine/tests/schema_bounds_check.py`）：把 `exclusive_bool_refuse` 的断言从"400 或 200 且 n>5"收紧为"**200 且 n>5**"，并改名 `exclusive_bool_served`。
**验收**：①布尔+数值界 ⇒ 200 且值 > 下界（门 + 活体）②布尔孤立 ⇒ 400 ③自检与四个 harness 全绿 ④8000/8001 不回退。
**锚点核对（执行前实测）**：`api_schema_number_bounded_shape`、`$hasExLo/$hasExHi = preg_match`、`$bdBD = api_schema_bounds_unsupported`、活体用例名均在执行前存在于工作树（各 1 处），规格按锚点直接落地，未重新定位。
**F437 已完成**：布尔 `":true"` 喂进相邻整数换算（`bool_shape=[n|6|-1|0]`）、拒绝收窄为布尔孤立（`bool_alone=1`）、活体收紧为"必须 200 且 >5"（实测 `{"n":7}`）。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.547×**、哨兵 **0**（`engine/selftest_f437.log`、`engine/schema_bounds_f437.log`、`engine/api_smoke_f437.log`、`engine/throughput_f437.log`）。
**顺位**（其后）：多叶 multipleOf 的实现 → number 叶浮点界（小数写入器 + 4 个路由点）→ 分片多卡 / FR-05。


## F438 计划与完成（多叶 `multipleOf`：逐叶后缀写入）
**F437 已完成**：布尔 exclusive 实现。
**改前**：两叶全倍数 → 200 `{"a":…}`（丢键）→ F431 拒绝。
**F438 已完成**：`api_multi_multiple_value`（逐叶 `api_multiple_fill`，首叶开对象、其余续写）+ `api_schema_multi_multiple` 检测器（全叶 `i` 且各带 multipleOf）；四处路由在多叶优先；拒绝改为"plan>1 且多叶检测器为空"。
**门**：`mult_multi` 1→0、新增 `multi_plan=[a|3,b|5]`；`mult_mixed=1` 保持。
**活体**：`multi_leaf_multipleof` 200 双键且各自整除、`multi_leaf_mixed_refuse` 400。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.506×**、哨兵 **0**（`engine/selftest_f438.log`、`engine/schema_bounds_f438.log`、`engine/api_smoke_f438.log`、`engine/throughput_f438.log`）。
**明确留拒（排队）**：number 叶浮点界（小数写入器 + 4 个路由点）；无界 `multipleOf` > 999999；tuple 实现；`dependentSchemas`/`dependencies`；多叶中混有非倍数叶的情形（仍 400）。
**顺位**：number 叶浮点界 → 分片多卡 / FR-05（本机不可验证）。


## F439 计划与完成（`number` 叶小数界：整数即合法 number）
**F438 已完成**：多叶 multipleOf 逐叶写入。
**思路**：不写小数写入器——整数是合法 `number`；区间含整数时收紧到 `[ceil(lo), floor(hi)]` 复用整数写入器，无整数的区间仍拒。
**F439 已完成**：形状叶子 kind 放宽到 `i|n`；`number+multipleOf` 的拒绝提到最前；门改三处旧期望（`bdB/bdJ/bdG` 1→0）+ `SCHEMA-NUM-FRAC` 四例。
**活体**：`float_served` 200、`float_empty_refuse` 400。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.513×**、哨兵 **0**（`engine/selftest_f439.log`、`engine/schema_bounds_f439.log`、`engine/api_smoke_f439.log`、`engine/throughput_f439.log`）。
**明确留拒（排队）**：**无整数的** number 区间（需小数写入器）；无界 `multipleOf` > 999999；tuple 实现；`dependentSchemas`/`dependencies`；多叶混合情形。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F440 计划与完成（无整数区间：一位小数，不改路由）
**F439 已完成**：number 叶含整数时取整数。
**思路**：`hi=-999` 作"十进制标记"、`lo` 存十分位值，同一写入器发 `int.frac` ⇒ **不动四处路由**。
**F440 已完成**：形状"无整数"分支计算十分位区间（非负、≤1 位小数、无 multipleOf）；新增 `api_schema_tenths`；写入器标记分支。第一版回退未限叶子类型 ⇒ integer 叶也发小数，门抓住后加 `$kv[1]=="n"`。
**门**：`num_float_empty` "" → `n|15|-999|0`；新增 `num_dec`/`num_dec_fine`/`dec_text`。
**活体**：`float_dec_served` 200 `{"n":1.5}`、`float_dec_fine_refuse` 400。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.464×**、哨兵 **0**（`engine/selftest_f440c.log`、`engine/schema_bounds_f440.log`、`engine/api_smoke_f440.log`、`engine/throughput_f440.log`）。
**明确留拒（排队）**：更细的小数（两位及以上）、负数小数界、无界 `multipleOf` > 999999、tuple 实现、`dependentSchemas`/`dependencies`。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F441 计划与完成（小数网格推广到两位）
**F440 已完成**：无整数区间的一位小数（-999 标记）。
**F441 已完成**：`api_schema_scaled($lit,$digits)` 泛化；形状按边界最大小数位数选 `d∈{1,2}`，标记 -999/-998；写入器按 `d` 取比例并补零。门改 `num_dec_fine` 期望并加 `num_dec3`/`dec2_text`；活体 `float_dec2_served` 200 `1.51`、`float_dec3_refuse` 400。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.520×**、哨兵 **0**（`engine/selftest_f441.log`、`engine/schema_bounds_f441.log`、`engine/api_smoke_f441.log`、`engine/throughput_f441.log`）。
**明确留拒（排队）**：三位及以上小数、负数小数界、无界 `multipleOf` > 999999、tuple 实现、`dependentSchemas`/`dependencies`。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F442 计划与完成（小数界支持负数）
**F441 已完成**：两位小数。
**F442 已完成**：`api_schema_scaled` 接受符号并改用 `-1000000` 哨兵（`-1` 与 -0.1 碰撞）；形状去掉非负限制；写入器取幅值后加符号。门加 `num_dec_neg`/`neg_text`；活体 `float_dec_neg` 200 `{"n":-1.9}`。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.529×**、哨兵 **0**（`engine/selftest_f442.log`、`engine/schema_bounds_f442.log`、`engine/api_smoke_f442.log`、`engine/throughput_f442.log`）。
**明确留拒（排队）**：三位及以上小数、无界 `multipleOf` > 999999999（需更长后缀或换机制）、tuple 实现、`dependentSchemas`/`dependencies`。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。


## F444 计划（tuple 校验的**实现**：位置式标量元组，规格待执行）
**现状**：`items` 数组形式与 `prefixItems` 由 F434 **点名拒绝**（活体 400）；`SCHEMA-STRUCT` 的 `tuple_items`/`prefix_items` 钉住该结论。
**目标形状**：属性是数组、`items` 为 2–3 个**标量**子 schema（`string`/`integer`）的数组，无其它关键字。
**实现（四处，缺一不可）**：
1. 写入器 `api_tuple_value($ids,$prop,$kinds,$charset,$maxSteps,$useCache)`：`{"prop":[` 开头；逐元素发 `,` 分隔；`s` 元素走"开引号 → 字符集步进 → 闭引号"（与 `api_array_string_value` 的内层一致），`i` 元素走 `api_number_fill(0,$maxSteps)`；末尾强制 `]}`；任一元素失败则返回 ""（不发半成品）。
2. 检测器 `api_response_schema_tuple($schema)` → `prop|s,i`；只认上述窄形状。
3. 路由：四处数组分支（8000 非流约 5500/6490、流式约 9480、8001 约 10580/10970）在既有数组检测**之前**接入 tuple 分支。
4. 校验：`api_schema_structural_unsupported` 的 `"items"\s*:\s*\[` 分支改为"tuple 检测器为空才拒"。
**门**：`SCHEMA-STRUCT` 的 `tuple_items` 期望 **1→0**（旧期望被证伪就改门），`prefix_items` 仍 1（不在本轮范围），新增 `tuple_plan=[pair|s,i]` 与**文本断言** `tuple_text=[{"pair":["…",<digits>]}]`。
**活体**：`tuple_refuse` 改名为 `tuple_served`（**200** 且 `json.loads` 得到 2 元素、`[0]` 是 str、`[1]` 是 int）；`prefixitems_refuse` 保持 400。
**验收**：①元组被服务且元素类型按位置正确（门 + 活体）②`prefixItems` 仍拒 ③自检与四个 harness 全绿 ④8000/8001 不回退。
**顺位**：F444 → 三位小数 → 分片多卡 / FR-05（本机不可验证）。
**F444 已完成**：`api_tuple_value` + `api_schema_tuple`（单属性、items 数组、2–3 个 string/integer 子 schema）；四处路由接入；拒绝收窄；门改 `tuple_items=""` 并加 `tuple_plan`/`tuple_text`；活体 200 `{"pair":["1000",2000]}`、`prefixItems` 仍 400；梯 **182/0**、8000 **115/0**、8001 绿、scaling **1.611×**。**过程**：第一版在非流作用域用了 `$prefixCache` ⇒ `error C2065` + `TPC_BUILD_FAILED`（改为 `1`）。


## F446 计划与完成（dependentSchemas 窄形态）
**F444 已完成**：tuple 实现。
**F446 已完成**：`api_schema_dependent_schemas_ok`（子 schema 恰为 `{"required":[…]}`，按发出键集判定）；活体抓到"子 schema 的 required 被当根级 required ⇒ 键丢失"，新增 `api_schema_required_present` 并在两处先剥离 dependentSchemas 块。
**门**：`depsch_ok=[]`、`depsch_bad`/`depsch_other=[dependentSchemas]`。
**活体**：ok 200 双键、两例 400；其余不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.526×**、哨兵 **0**（`engine/selftest_f446c.log`、`engine/schema_required_f446.log`、`engine/api_smoke_f446.log`、`engine/throughput_f446.log`）。
**明确留拒（排队）**：`dependentSchemas` 的其他子 schema 体、`dependencies`（draft-7）、`contains`/`not`/`if`/`patternProperties`/`unevaluated*` 的实现；四位以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。


## F447 计划与完成（dependencies 两种拼写）
**F446 已完成**：dependentSchemas 窄形态 + 嵌套 required 剥离。
**F447 已完成**：`api_schema_dependencies_ok`（数组 / bare-required 对象）；`api_schema_emitted_set` 共用；`api_schema_required_scan` 同时剥离 dependentSchemas 与 dependencies。门加 `deps_arr/deps_obj/deps_bad/deps_other`；活体两拼写 200 双键、违反 400。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.537×**、哨兵 **0**（`engine/selftest_f447.log`、`engine/schema_required_f447.log`、`engine/api_smoke_f447.log`、`engine/throughput_f447.log`）。
**明确留拒（排队）**：`contains`/`not`/`if`/`patternProperties`/`unevaluated*` 的实现；`dependencies`/`dependentSchemas` 的更丰富子 schema 体；四位以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。


## F448 计划与完成（`not` 的类型矛盾形式）
**F447 已完成**：dependencies 两种拼写。
**F448 已完成**：`api_schema_not_ok`——扁平全字符串 + 根级 `"not"` + 子 schema 恰为 `{"type":"integer"|"number"|"boolean"|"array"|"object"}` ⇒ 放行（构造满足）；`not` 从 `contains|patternProperties|if` 的 alternation 拆出单独判定，其他子 schema（pattern/enum/嵌套）仍点名拒绝。根级守卫让"属性名恰好叫 `not`"不被当成关键字。
**门**：`SCHEMA-STRUCT` 加 `not_type=[]`、`not_pattern=[not]`、`not_mixed=[not]`、`not_route=2`。
**旧门翻转**：`schema_count_check.py` 的 `not_refuse` 改成 `not_type_served`（200、值为字符串），并加 `not_pattern_refuse`（400 点名）作对照。
**活体**：`not_type_ok` 200 双键；`not_pattern_refuse`/`not_mixed_refuse` 400 点名 not。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.554×**、哨兵 **0**（`engine/selftest_f448.log`、`engine/schema_required_f448.log`、`engine/schema_count_f448b.log`、`engine/schema_bounds_f448.log`、`engine/schema_pattern_f448.log`、`engine/api_smoke_f448.log`、`engine/throughput_f448.log`）。
**明确留拒（排队）**：`contains`/`if`/`patternProperties`/`unevaluated*` 的实现（需子 schema 求值器）、`minContains`/`maxContains`；`not` 的值级子 schema；四位以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：`contains`（需先写子 schema 求值规格）→ 分片多卡 / FR-05（本机不可验证）。


## F452 规格（`if/then/else` 的第一窄形态；**已写、锚点已核、未执行**）
**现状**：`api_schema_structural_unsupported` 里 `if` 直接 `return "if"`（`engine/main.php:8541`，全文件仅此一处）；门 `$sfC`（**属性名**恰好叫 `if` 的夹具）钉住"名字不当关键字"的边界；`engine/tests/*.py` 里没有 `if` 夹具。`then`/`else` 单独出现时按 JSON Schema 语义本就无效，不列为拒绝关键字是正确的。
**目标形状（无需值求值，可构造证明）**：
1. 键集可证明的**对象**形状：`api_schema_emitted_set($schema) != ""`（`engine/main.php:7975`）。
2. `"if"` 是**根级**关键字（位置须在 `properties` 块之外，同 F448/F449 的守卫）。
3. `if` 子 schema 恰为 `{"type":"<t>"}`，`t ∈ {object,string,integer,number,boolean,array}`。
4. 条件判定只看类型：`t == "object"` ⇒ 真，生效分支是 `then`；其余 ⇒ 假，生效分支是 `else`。
5. **生效分支**若存在，必须恰为 `{"type":"object"}`（对键集可证明的对象形状恒真）；更丰富的体（required/bounds/嵌套…）需要求值 ⇒ 拒绝。**不生效的分支按语义被忽略，不检查其内容**。
**实现（三处）**：
1. 新函数 `api_schema_if_ok(string $schema): int`（放在 `api_schema_pattern_props_ok` 之后、`api_schema_keywords_unsupported` 之前）：根级守卫 → `if` 块用 `api_balance_block` 取体 → 正则 `^\{\s*"type"\s*:\s*"(object|string|integer|number|boolean|array)"\s*\}$` → 按第 4 条选分支名 → 根级定位该分支块；存在则要求体恰为 `^\{\s*"type"\s*:\s*"object"\s*\}$`。
2. `api_schema_structural_unsupported` 第 8541 行改为谓词守卫：`if (preg_match('/"if"\s*:\s*\{/', $schema) == 1 && api_schema_if_ok($schema) != 1) { return "if"; }`。
3. 注意：`if` 分支目前**没有捕获组**（F451 已改为 `return "if";`），改守卫时不要重新引入 `$mk[1]`。
**门**（`SCHEMA-STRUCT`，变量名 `$sfAW`/`$sfAX`/`$sfAY`/`$sfAZ` 已确认全文件未用；插入点在 `$sfAV`（`engine/main.php:16018`）之后，echo 在 16038、条件在 16059）：
- `$sfAW`（`if:{"type":"string"}` + `else:{"type":"object"}`，扁平双键对象）⇒ `""`；
- `$sfAX`（同上但 `else` 为 `{"required":["city"]}`）⇒ `"if"`（生效分支需值级判断）；
- `$sfAY`（`if:{"type":"object"}` + `then:{"type":"object"}`）⇒ `""`；
- `$sfAZ = api_response_schema_string_props(... if/else ...)` ⇒ 2 键（路由保留）；
- 旧 `$sfC`（属性名 `if`）保持 `"if"`。
**活体**（`engine/tests/schema_required_check.py`，插在 `patternprops_vacuous_ok` 之后）：
- `if_else_ok`：`{"type":"object","properties":{"city":...,"note":...},"if":{"type":"string"},"else":{"type":"object"}}` ⇒ **200 双键**；
- `if_rich_refuse`：`else` 换成 `{"required":["city"]}` ⇒ **400 点名 if**。
**验收**：①生效分支按类型判定且被服务（门 + 活体）②丰富分支与属性名冒充仍拒绝 ③四个 harness + 8000/8001 不回退 ④自检 182 PASS 起、`SCHEMA-STRUCT` 字段齐全。
**顺位**：F452（本规格）→ `unevaluated*`（空约束形态，同 F451 思路）→ 分片多卡 / FR-05（本机不可验证）。
**F452 已完成**：`api_schema_if_ok` 落地；`if` 守卫接入；`api_schema_required_scan` 剥离列表扩到 `if,then,else`（活体 `if_true_ok` 断言被忽略的 `else` 里的 `required:["z"]` 不会收缩输出键集）。门 `if_else=[]`/`if_rich=[if]`/`if_noelse=[]`/`if_route=2`；活体真/假分支各 200 双键、丰富分支 400；梯 **182/0**、8000 **115/0**、8001 绿、scaling **1.579×**、哨兵 **0**。
**明确留拒（排队，F452 后）**：`unevaluated*` 的实现（空约束形态）、`if` 的丰富生效分支、`minContains`/`maxContains`、`contains`/`not`/`patternProperties` 的值级形式；五位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。


## F453 计划与完成（`unevaluated*` 的空判定形式）
**F452 已完成**：`if/then/else` 类型窄形态。
**F453 已完成**：`api_schema_unevaluated_props_ok`（键集可证明 + 根级关键字 ⇒ 无对象可判，任意值/子 schema 满足）与 `api_schema_unevaluated_items_ok`（单标量数组 + 根级 ⇒ 元素全由 `items` 求值）；结构判定从 alternation 拆出各自守卫。
**门（含旧期望翻转）**：旧 `$sfF` 由 `unevaluatedProperties` 翻为 `""`；新增 `uneval_props=[]`、`uneval_props_schema=[]`、`uneval_items=[]`、`uneval_items_wrong=[unevaluatedItems]`、`uneval_prop_named=[unevaluatedProperties]`；旧 `$sfJ` 仍 `unevaluatedItems`。
**活体**：`uneval_props_ok`/`uneval_props_schema_ok` 200 双键、`uneval_items_ok` 200 数组、`uneval_items_wrong_refuse` 400 点名；用故意违反的子 schema 证明空判定。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.505×**、哨兵 **0**（`engine/selftest_f453.log`、`engine/schema_required_f453.log`、`engine/schema_bounds_f453.log`、`engine/schema_count_f453.log`、`engine/schema_pattern_f453.log`、`engine/api_smoke_f453.log`、`engine/throughput_f453.log`）。
**明确留拒（排队，F453 后）**：`minContains`/`maxContains`、`contains`/`not`/`patternProperties` 的值级形式、`if` 的丰富生效分支、`unevaluated*` 会真正判定的形状；五位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：`minContains`/`maxContains`（在 F449 的 `contains` 构造上按元素计数判定，可先写规格）→ 分片多卡 / FR-05（本机不可验证）。


## F454 计划与完成（`minContains`/`maxContains` 折成元素上下界）
**F453 已完成**：`unevaluated*` 空判定形式。
**F454 已完成**：F449 形态每元素皆匹配 ⇒ `minContains: k` ≡ 元素数 ≥ k、`maxContains: m` ≡ 元素数 ≤ m。`api_response_schema_array_bounds` 折叠两组计数（取更严；`maxContains:0`、`min>bMax`、`min>8` 不可服务）；`api_schema_contains_ok` 去掉计数早退；新增 `api_schema_minmax_contains_ok` 谓词守卫，`minContains|maxContains` 分支改名守卫。
**门**：`cont_min=[]`、`cont_max=[]`、`cont_min_big=[minContains]`、`cont_min_over=[minContains]`、`cont_bounds=[2:3]`；旧 `$sfI` 仍 `minContains`。
**活体（断言个数）**：min 3 → 200 四元素；max 2 → 200 一元素；min 9 / min3+max2 → 400 点名。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.575×**、哨兵 **0**（`engine/selftest_f454.log`、`engine/schema_required_f454.log`、`engine/schema_bounds_f454.log`、`engine/schema_count_f454.log`、`engine/schema_pattern_f454.log`、`engine/api_smoke_f454.log`、`engine/throughput_f454.log`）。
**明确留拒（排队，F454 后）**：`contains`/`not` 的值级子 schema、`patternProperties` 的匹配/多条模式形式、`if` 的丰富生效分支、`unevaluated*` 会真正判定的形状；五位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：真正的子 schema 求值器（值级 `pattern`/`enum`/嵌套约束的统一入口，先写规格）→ 分片多卡 / FR-05（本机不可验证）。


## F455 计划与完成（`patternProperties` 的匹配键 + 裸类型）
**F454 已完成**：`minContains`/`maxContains` 折成元素上下界。
**F455 已完成**：命中键时若子 schema 恰为裸 type 且等于该键声明种类必然产出的类型（s/e/p→string、i→integer、i|n→number、b→boolean、a*→array），值构造满足 ⇒ 放行。新增 `api_schema_leaf_kind` + `api_schema_kind_is_type`；更丰富子 schema 或类型不匹配仍拒。
**门**：新增 `pp_match_ok=[]`；旧 `$sfL`/`$sfAT` 保持拒绝作对照；`pp_multi` 不变。
**活体**：`patternprops_match_ok` 200 双键；`patternprops_refuse` 400 点名。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.536×**、哨兵 **0**（`engine/selftest_f455.log`、`engine/schema_required_f455.log`、`engine/schema_bounds_f455.log`、`engine/schema_count_f455.log`、`engine/schema_pattern_f455.log`、`engine/api_smoke_f455.log`、`engine/throughput_f455.log`）。
**明确留拒（排队，F455 后）**：`contains`/`not`/`patternProperties` 的值级子 schema；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：基于 plan 的子 schema 判定规格（F457，见下节）→ 分片多卡 / FR-05（本机不可验证）。


## F456 计划与完成（number 叶小数界到五位）
**F455 已完成**：`patternProperties` 匹配键 + 裸类型。
**F456 已完成**：三处同构改动——`api_schema_scaled` `{1,4}`→`{1,5}` + `$digits==5`；计划构造两个字面正则同改、`$d=5` 与标记 `-995`；写入器 `-995`→`d=5, scale=100000`。
**门**：`num_dec5=n|151111|-995|0`、`dec5_text=[{"n":1.51111}]`、`num_dec6=[]`。
**活体**：`float_dec5_served` 200 `{"n":1.51111}`、`float_dec6_refuse` 400；1–4 位与负数不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.454×**、哨兵 **0**（`engine/selftest_f456.log`、`engine/schema_bounds_f456.log`、`engine/schema_required_f456.log`、`engine/schema_count_f456.log`、`engine/schema_pattern_f456.log`、`engine/api_smoke_f456.log`、`engine/throughput_f456.log`）。
**明确留拒（排队，F456 后）**：`contains`/`not`/`patternProperties` 的值级子 schema；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：基于 plan 的子 schema 判定规格（F457，见下节）→ 分片多卡 / FR-05（本机不可验证）。


## F457 规格与完成（基于 plan 的子 schema 判定；**已执行**）
**动机**：剩下所有"值级"关键字（`contains`/`not`/`patternProperties` 的丰富子 schema、`if` 的丰富生效分支）都需要回答同一个问题——"生成器**保证**产出的值是否满足子 schema"。静态答案（构造满足）比动态求值便宜，且与 F449–F455 是同一套证明。
**统一入口（拟）**：`api_schema_leaf_guarantees(string $kind, string $sub): int`——输入 plan 叶子种类与子 schema 文本，输出"该种类的所有可能输出都满足子 schema"。v1 支持的约束与判据：
1. `type`：复用 `api_schema_kind_is_type`（`engine/main.php:8625`）。
2. `enum`/`const`（字符串）：仅 `e:` 种类（有限枚举）可判；要求叶子枚举值集合**包含于**子 schema 的 enum（或全部等于 const），逐值字符串比较。
3. `pattern`（字符串）：仅 `e:` 种类可判；逐枚举值 `preg_match`（模式长度 1–200，读不出 ⇒ 不算保证）。`p:` 种类**不**提供此保证。
4. 其他种类（`s`/`i`/`n`/`b`/`p:`/`a*`/`en:`）在 v1 一律返回 0（不保证），除 `type` 外。
**落点（试点）**：`api_schema_pattern_props_ok`——子 schema 不是裸 type 时改为调用该判定；命中键的种类必须提供保证，否则拒绝。门/活体与 F455 同构（枚举命中正例 + 枚举子集不成立反例）。
**两个必须先回答的问题**（执行时以门/探针回答，不靠推断）：
1. **路由**：含 `enum` 叶子和 `patternProperties` 的 schema 是否仍走候选/枚举写入器（F429 的教训：拒绝层放行不等于值路由认领）。先用 `api_response_schema_*` 返回值做门断言，再跑活体。
2. **枚举值解析**：`api_list` 对子 schema enum 的空白/引号处理需与 `api_top_leaves` 的 `e:` 编码一致；用 PHP CLI 抽取式单测（同 `bound_inward_test.php`）钉住。
**验收**：①枚举包含的正例被服务且值来自枚举 ②子集不成立/自由字符串种类仍拒绝 ③四个 harness + 8000/8001 不回退 ④`check_gates` 引用全解析。
**顺位**：F457 执行 → 分片多卡 / FR-05（本机不可验证）。
**F457 已完成**：`api_schema_leaf_guarantees`（仅 `e:` 提供 enum/const/pattern 保证）+ `patternProperties` 试点；两问各以证据收口——门 `pp_enum_route=2`（候选写入器仍认领）、单测 `engine/tests/leaf_guarantees_test.php` **12/12**（含带空格枚举值）；门 `pp_enum=[]`/`pp_pat=[]`/`pp_enum_sub=[patternProperties]`/`pp_pat_miss=[patternProperties]`；活体 `patternprops_enum_ok` 200 `{"city":"paris"}`、子集 400。梯 **182/0**、8000 **115/0**、8001 绿、scaling **1.503×**、哨兵 **0**（`engine/selftest_f457.log` 等）。
**明确留拒（排队，F457 后）**：`contains`/`not` 的值级子 schema；`patternProperties` 对非枚举种类的值级约束与嵌套子 schema；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：`contains` 的值级形式（复用 `api_schema_leaf_guarantees` 判定元素种类的保证）→ 分片多卡 / FR-05（本机不可验证）。


## F458 计划与完成（修既有静默违约：带值约束的 `items`）
**F457 已完成**：基于 plan 的子 schema 判定。
**改前证据**：`items` enum/const/pattern → 200 `{"items":"paris"}`；`items` 数值界 → 200 `{"items":5}`；裸类型正常（`engine/items_gap_f458.log`）。即带约束的 items 未实现也未拒绝，且输出连形状都错。
**F458 已完成**：`api_schema_items_constrained` 扫描每个 `"items":{…}` 块（含嵌套）的 `enum|const|pattern|minimum|maximum|exclusive*|multipleOf|format|contentEncoding|contentMediaType`；命中返回 `"items"`。`properties`/`required` 故意排除（对象数组受支持）。
**门**：`items_enum`/`items_const`/`items_pattern`/`items_min` = `[items]`，`items_plain=[]`。
**活体**：`items_enum_refuse`/`items_min_refuse` 400 点名；`items_plain_ok` 200 数组；smoke 仍 115/0。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.509×**、哨兵 **0**（`engine/selftest_f458.log` 等 + `engine/items_gap_f458.log`）。
**明确留拒（排队，F458 后）**：数组项 schema 的值约束（需项感知写入器）；`contains`/`not` 的值级子 schema；`patternProperties` 非枚举种类的值级约束；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：项感知写入器（先写规格：枚举项数组的有限候选发射）→ 分片多卡 / FR-05（本机不可验证）。


## F459 计划与完成（枚举项数组的项感知写入器 v1）
**F458 已完成**：约束项 items 显式 400。
**F459 已完成**：`api_schema_array_enum`（单数组属性 + `items` 恰为 `{"type":"string","enum":[…]}`，值可进 `~` 编码）+ `api_array_enum_value`（受限解码逐元素发"枚举值+闭引号"，元素间只可达 `,`/`]`，元素数走既有 bounds）；四路由接入，候选/混合检测之前。
**路由上抓到的两个更早写者**：①`api_schema_candidates` 把 `items` 当属性（已剥离 items 块，门 `ae_route=0`）；②非流扁平枚举分支只回裸值（已加数组枚举守卫）。
**活体证据链**：`{"items":"paris"}` → `"paris"` → **`{"cities":["paris","paris"]}`**；const/数值界仍 400，裸数组不变。
**门**：`items_enum=[]`、`ae_plan`/`ae_text`/`ae_route=0`、`items_const/pattern/min=[items]`。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.529×**、哨兵 **0**（`engine/selftest_f459c.log`、`engine/schema_required_f459c.log`、`engine/schema_bounds_f459.log`、`engine/schema_count_f459.log`、`engine/schema_pattern_f459.log`、`engine/api_smoke_f459.log`、`engine/throughput_f459.log`）。
**明确留拒（排队，F459 后）**：数组项 schema 的非枚举值约束（const/pattern/数值界）；`contains`/`not` 的值级子 schema；`patternProperties` 非枚举种类的值级约束；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：数组项数值界（复用 `api_number_bounded_value` 逐元素发射）→ 分片多卡 / FR-05（本机不可验证）。


## F460 计划（数组项数值界：双侧整数 `items` 逐元素发射；**规格已写、实现已在树、待全梯验证**）
**F459 已完成**：枚举项数组逐元素发射。
**改前状态（F459 提交二进制）**：双侧 `items` 整数界由 F458 的 `items` 点名 400 钉住（门 `items_min=[items]`，`selftest_f459c.log`）；更早的静默违反形态是 200 `{"items":5}`（`items_gap_f458.log`）。
**目标形状（窄）**：单数组属性 + `items` 恰为 `{"type":"integer","minimum":X,"maximum":Y}`（双侧、纯十进制、X≤Y）⇒ 逐元素发射有界整数数组。元素体＝单属性写入器的同一套数字走位（F419 符号映射：全负区间走幅值＋强制负号、跨零下界钳 0），**每元素必落 [X,Y]（构造保证）**；元素数走既有 `minItems`/`maxItems` 预算（F400 同机制）。
**实现（已在树，四处）**：
1. 检测器 `api_schema_array_item_bounds($schema)` → `prop|lo|hi`：plan 恰一叶、kind（剥 `~` 界后缀后）为 `an`、`items` 块正则命中该形状、lo≤hi；否则 ""。
2. `api_number_bounded_element($lo,$hi)`（从 `api_number_bounded_value` 抽出数字体，无对象壳，F419 符号映射原样）＋数组写入器 `api_array_bounded_int_value`：头 `{"prop":[`，逐元素数字体，元素间只可达 `,`/`]`，`]`/`}` 收口。
3. `api_number_bounded_value` 改复用 `api_number_bounded_element`（单属性行为不变，`SCHEMA-NUM-BND`/`NUM-EXCL`/`EXCL-MIX`/`NUM-FRAC` 钉回归）。
4. 四路由（8000 非流／8000 流／8001 两处）在候选/混合检测前接入；`api_schema_structural_unsupported` 的 items 拒绝豁免双侧形态（与 F459 枚举豁免并列）。
**单侧界的去向（读码判定，写进活体口径）**：单侧 `minimum` 的 items 形态在链上先被 `api_schema_bounds_unsupported` 的成对走位抓住（`maximum` 缺席 ⇒ 400，消息为 "numeric bounds are honoured on a single integer property…"），到不了 items 检查 ⇒ **仍是点名 400**，活体断言两种消息都接。
**门**：`items_min`（双侧）期望 `items`→``（旧期望被证伪 ⇒ 翻门）；新增 `items_one=[items]`、`ib_plan=[nums|5|9]`、`ib_one=[]`、`ib_text=[{"nums":[<5–9 的数字>]}` 文本断言＋`api_json_ok`。
**活体**（`engine/tests/schema_required_check.py`）：`items_bounded_ok`（200，数组且每元素为 [5,9] 整数）、`items_one_sided_refuse`（400 点名）；`items_plain_ok` 对照不回退。
**验收**：①元素值在界内（门＋活体逐元素断言）②单侧与 const/pattern 仍拒 ③自检 182 起、`SCHEMA-STRUCT` 字段齐全且单属性有界门不回退 ④四个 harness＋8000 115/0＋8001 全绿不回退。
**顺位**：F460 验证 → 单侧 items 界／`number` 型 items 界（小数）→ 分片多卡 / FR-05（本机不可验证）。
**F460 已完成**：`api_schema_array_item_bounds`（双侧整数 items ⇒ `prop|lo|hi`）+ `api_array_bounded_int_value`（数字体抽成 `api_number_bounded_element` 共享，`api_number_bounded_value` 改复用，单属性行为由 `SCHEMA-NUM-*` 四门钉回归）；四路由在候选/混合检测前接入，items 拒绝并列豁免。门 `items_min` 翻 `[]`、新增 `items_one=[items]`/`ib_plan=[nums|5|9]`/`ib_text=[{"nums":[5]}]`；活体 `items_bounded_ok` 200 `{"nums":[5,6,7,8]}`（每元素 [5,9]）、`items_one_sided_refuse` 400（单侧先被界判据成对走位点名）。**过程**：前一会话已实现＋构建＋活体验证（`schema_required_f460b.log` 01:24）未提交；本轮从当前树重建（07:36）全梯复验后入库。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.528×**、哨兵 **0**（`engine/selftest_f460.log`、`engine/schema_required_f460.log`、`engine/schema_bounds_f460.log`、`engine/schema_count_f460.log`、`engine/schema_pattern_f460.log`、`engine/api_smoke_f460.log`、`engine/throughput_f460.log`）。
**明确留拒（排队，F460 后）**：数组项 schema 的 const/pattern/单侧或 `number` 型（小数）数值界；`contains`/`not` 的值级子 schema；`patternProperties` 非枚举种类的值级约束；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：单侧 items 界 / `number` 型 items 界（小数项写入器）→ 分片多卡 / FR-05（本机不可验证）。


## F461 计划（单侧整数 items 界：复用同一写入器＋哨兵约定）
**F460 已完成**：双侧整数 items 逐元素发射。
**改前状态**：单侧 `items`（`minimum` 或 `maximum` 仅一侧）由 `api_schema_bounds_unsupported` 的成对走位在路由前点名 400（"numeric bounds are honoured on a single integer property…"，`items_one_sided_refuse` 活体钉住）；门 `items_one=[items]` 钉结构判定侧。
**目标形状（窄）**：`items` 恰为 `{"type":"integer","minimum":X}`（单下界，**X ≥ 0**）或 `{"type":"integer","maximum":Y}`（单上界，**Y ≥ 0**）⇒ 逐元素发射，缺侧走既有哨兵 `-1`（`api_number_bounded_fill` 的约定，元素写入器**零改动**即支持）：
- 单下界：`lo=X, hi=-1`——走位从 X 起，值必 ≥ X（构造保证；X=0 起发）。
- 单上界：`lo=-1, hi=Y`——钳制后走位 0..Y，值必 ≤ Y（子集论证：发的是约束的一个合法解）。
- **负的单侧值一律仍拒**（`minimum:X<0` 与 `maximum:Y<0`，含 `minimum:-1`）：单侧负界需要幅值单侧走位（新机制，排队），且 `-1` 恰为缺侧哨兵、与真值 -1 碰撞（F442 同款教训）。
- 裸类型 `{"type":"integer"}` 与 `lo>hi` 不可满足 ⇒ 检测器不认（前者归裸数组写入器、后者仍 400）。
**实现（三处，写码前核过的锚点）**：
1. 检测器 `api_schema_array_item_bounds`（`engine/main.php:972`，F460 版双侧正则 `:1028`）：正则两侧改可选（自然序、`type` 在前），缺侧补哨兵 `-1`；加三条拒绝（双侧都在才比 lo≤hi、单上界负、裸类型不认）；注释同步（F460 注释写死"单侧维持拒绝"，必须改）。
2. 界判据早退（F459 教训：先找"更早写者"）：`api_schema_bounds_unsupported`（`:9522`）函数顶加 `api_schema_array_item_bounds($body) != "" ⇒ return 0`——否则单侧形态在成对走位（`:9598` 起）就被 400，路由根本到不了；该守卫对既有 `$bd*` 门零影响（检测器要求 plan 单叶 kind `an`＋items 块，门夹具全是单整属性/multipleOf）。
3. 路由**零改动**：四处路由调用 `api_response_schema_array_item_bounds`，检测器扩面后自动认领单侧；写入器把哨兵原样传给 `api_number_bounded_element`。
**门（含翻转）**：`items_one`（单下界）`items`→``；`ib_one`（单下界检测器）``→`nums|5|-1`；新增 `ib_max=[nums|-1|9]`（单上界检测器）、`ib_negmax=[]`（`maximum:-3` 仍拒）、单下界写入器文本断言（`{"nums":[<digits>≥5]}`＋json_ok）。
**活体**（`engine/tests/schema_required_check.py`）：`items_one_sided_refuse` 翻转改名 `items_one_sided_min_ok`（200、每元素 ≥5）；新增 `items_one_sided_max_ok`（200、每元素 ≤9）、`items_one_sided_negmax_refuse`（400 点名）；`items_bounded_ok`/`items_plain_ok` 对照不回退。
**验收**：①单侧元素值在界内（门＋活体逐元素断言）②负单上界与裸类型仍拒 ③自检 182 起、单属性有界门（`SCHEMA-NUM-*`/`BOUND-REJECT`）不回退 ④四 harness＋8000 115/0＋8001 不回退。
**F461 已完成**：检测器单侧正则（两侧可选、缺侧哨兵 `-1`）＋`api_schema_bounds_unsupported` 顶部早退豁免（成对走位此前在路由前点名单侧形态）＋门翻转（`items_one` `items`→``、`ib_one` ``→`nums|5|-1`、新 `ib_max`/`ib_negmax`/`ib_minone_text`）。活体 `items_one_sided_min_ok` 200 `[5,6,7,8]`（每元素 ≥5）、`items_one_sided_max_ok` 200 `[1,2,3,4]`（每元素 ≤9）、`items_one_sided_negmax_refuse` 400。
**过程（门抓到的缺陷）**：第一版 `array_key_exists` 判"该侧在场"——PHP 对**非尾部未匹配组**赋 `""` 而非省略键，`maximum` 单侧时 `$mm[1]=""` 被判在场、`(int)""=0` ⇒ `ib_max` 出 `nums|0|9`（下限被悄悄钉到 0，门必红）。改 `isset && != ""` 判定。与 F445 哨兵碰撞同族。
**梯**：自检 **182/0**（`selftest_f461b.log`；`selftest_f461.log` 留有第一版的 `SCHEMA-STRUCT FAIL` 证据）、8000 **115/0**、8001 par/fairness **0**、scaling **1.557×**、哨兵 **0**（`engine/schema_required_f461.log`、`engine/schema_bounds_f461.log`、`engine/schema_count_f461.log`、`engine/schema_pattern_f461.log`、`engine/api_smoke_f461.log`、`engine/throughput_f461.log`、改前证据 `engine/schema_required_f461pre.log`）。
**明确留拒（排队，F461 后）**：负的单侧 items 界（幅值单侧走位）、`number` 型（小数）items 界；数组项 schema 的 const/pattern；`contains`/`not` 的值级子 schema；`patternProperties` 非枚举种类的值级约束；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：`number` 型 items 界（小数项写入器，复用 F440-F456 的缩放编码）/ 负单侧 items 界 → 分片多卡 / FR-05（本机不可验证）。


## F462 计划（`number` 型 items 界：小数项写入器，复用 F440-F456 缩放编码）
**F461 已完成**：单侧整数 items 逐元素发射。
**改前状态**：`items` 为 `{"type":"number",…}` 带界（整数值/小数/单侧）⇒ 400（成对走位：number 叶过不了 integer 类型检查、小数 "float bounds are not enumerated"、单侧 "one-sided"）；将以 F461 二进制复取（`schema_bounds_f462pre.log`）。
**目标形状（窄）**：单数组属性＋`items` 恰为 `{"type":"number",…}` 值约束子 schema（**不含 multipleOf**）⇒ 逐元素发射：
- 整数值得到（区间含整数、含整数 items、单侧）：逐元素 `api_number_bounded_element` 数字走位（值必在界内，构造保证）；
- 区间无整数且两侧 ≤5 位小数（F456 上限）：逐元素**确定性单值** `int.frac`（F440-F456 缩放编码：lo＝放大值、hi＝marker -999…-995；确定性值＝放大 lo 还原，必落区间——子集论证同 F420）。
**判定复用（核心决策）**：不重造界判定——items 块 wrap 成 `{"properties":{"_item":<isub>}}` 喂给既有 `api_schema_number_bounded_shape`（单叶 plan＋exclusive/F421 取整/F440-456 网格全是现成判定）；取输出第 2-4 段，`mult≠0` ⇒ 拒（multipleOf items 留排队）。单属性与 items 两条判定路**共享同一份代码**，回归对齐免费。
**实现（四处＋一处抽取）**：
1. `api_schema_array_item_number($schema)` → `prop|lo|hi|mult`：plan 恰一叶、kind（剥 `~`）为 `af`、items 块 wrap→shape、mult 段须 0；＋body 包装器。
2. `api_number_decimal_element($lo,$hi)`（从 `api_number_bounded_value` 的 marker 分支抽出确定性 `int.frac` 计算；单属性 marker 分支改调用它，行为不变，`SCHEMA-NUM-FRAC`/F442-F456 各门钉回归）＋`api_array_item_number_value`（同整数数组写入器的循环；元素体＝marker 分支确定性值或数字走位）。
3. 四路由点（与 `scArrItemB` 并列；kind `af`/`an` 天然不相交，判定器互斥）。
4. 拒绝豁免：`api_schema_structural_unsupported` 的 items 检查加 `item_number == ""` 并列；`api_schema_bounds_unsupported` 顶部 F461 早退加 `|| item_number != ""`。
**门**：`in_plan=[nums|15|-999|0]`（双侧 1.5-1.9 小数）、`in_int=[nums|1|9|0]`（整数值）、`in_one=[nums|-1|9|0]`（单侧 9.5）、`in_multi=[]`（multipleOf 仍拒）、`in_kw=[]`（结构判定豁免）、`in_kw_multi=[items]`；写入器两条文本断言（小数 `{"nums":[1.5]}`、整数值 `{"nums":[<1-9>]}`＋json_ok）。
**活体**（`engine/tests/schema_bounds_check.py`）：`items_num_dec_ok`（200，每元素 ∈[1.5,1.9]）、`items_num_int_ok`（200，每元素 int ∈[1,9]）、`items_num_oneside_ok`（maximum:9.5，每元素 ≤9.5）、`items_num_frac6_refuse`（六位小数 400）、`items_num_multi_refuse`（400）；既有 float_dec* 不回退。
**验收**：①每元素值满足子 schema（门＋活体逐元素断言）②multipleOf／>5 位小数／enum 仍拒 ③自检 182 起、单属性小数门（`SCHEMA-NUM-FRAC` 等）不回退 ④四 harness＋8000 115/0＋8001 不回退。
**顺位**：F462 → 负单侧 items 界（幅值单侧走位）/ multipleOf items → 分片多卡 / FR-05（本机不可验证）。
**F462 已完成**：`api_schema_array_item_number`（items 块 wrap 成单属性 schema 喂给既有 `api_schema_number_bounded_shape`，取 2-4 段、mult≠0 拒）＋`api_number_decimal_element`（从 `api_number_bounded_value` 的 marker 分支抽出，单属性行为由 `SCHEMA-NUM-FRAC`/F442-F456 门钉回归）＋`api_array_item_number_value`（元素体＝marker 确定性值或数字走位，同一循环）；四路由接入、结构 items 检查与界判据早退并列豁免。门 `in_plan=[nums|15|-999|0]`、`in_int=[nums|1|9|0]`、`in_one=[nums|-1|9|0]`、`in_multi=[]`、`in_kw=[]`、`in_kw_multi=[items]`、`in_dec_text=[{"nums":[1.5]}]`、`in_intval_text`。活体 `items_num_dec_ok` 200 `[1.5,1.5,1.5,1.5]`（每元素＝缩放下界，必落 [1.5,1.9]）、`items_num_int_ok` 200 `[1,2,3,4]`、`items_num_oneside_ok` 200、`items_num_frac6_refuse`/`items_num_multi_refuse` 400；改前证据 `schema_bounds_f462pre.log`（F461 二进制上三例 400）。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.481×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f462.log`、`engine/schema_bounds_f462.log`、`engine/schema_required_f462.log`、`engine/schema_count_f462.log`、`engine/schema_pattern_f462.log`、`engine/api_smoke_f462.log`、`engine/throughput_f462.log`、改前证据 `engine/schema_bounds_f462pre.log`）。
**明确留拒（排队，F462 后）**：负单侧 items 界（幅值单侧走位）、multipleOf items（倍数项写入器）；数组项 schema 的 const/pattern；`contains`/`not` 的值级子 schema；`patternProperties` 非枚举种类的值级约束；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数（number 叶 items 同）；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：负单侧 items 界 / multipleOf items → 分片多卡 / FR-05（本机不可验证）。


## F463 计划（负单侧 items 界：单侧负上界 `maximum:-N` 走幅值单侧走位）
**F462 已完成**：number 型整数值/单侧/无整数小数网格 items。
**改前状态（关键：这是**静默违约**，不只是拒绝）**：
- 单属性 integer/number `maximum:-N`（无上界之外无下界）：shape 判据出 `n|-1|-N|0`（非空）⇒ `api_schema_bounds_unsupported` 判"支持" ⇒ 路由调 `api_number_bounded_value` ⇒ `api_number_bounded_element(-1, hi<0)` 走 both-negative 分支得 `wlo=-hi, whi=-lo=1` ⇒ `fill(-hi, 1)` 因 `-hi>1` 返回 "" ⇒ 写入器返回 "" ⇒ **回落无约束生成，静默忽略 `maximum:-N`**。
- number 型 items `maximum:-N`：F462 检测器经 shape 认领 `prop|-1|-N|0` ⇒ 路由调 `api_array_item_number_value` ⇒ 元素体 `api_number_bounded_element(-1,-N)` 同样 "" ⇒ **整个写入器 ""，静默违约**。
- integer 型 items `maximum:-N`：F461 检测器 `!$loSet && $hi<0` **点名 400**（过度拒绝，可解除）。
⇒ 三者同根：`api_number_bounded_element` 的 both-negative 分支不会处理"单侧负上界"（lo 是缺侧哨兵 -1、hi 是真实负值）。
**目标形状（窄）**：单侧负上界 `maximum:-N`（N≥2，无上界侧）⇒ 逐元素/单属性发射 ≤ -N 的整数。机制＝**幅值单侧走位**：强制负号后，幅值从 `|hi|` 起**无上界**走位（`fill(-hi, -1)`，-1 为缺侧哨兵），值 `= -(幅值) ≤ hi`（子集论证）。
**安全性（哨兵碰撞，必须写清）**：`element(-1, hi≤-2)` 只可能是"lo 缺侧＋hi 真实负值"——因为真实区间 `[-1, hi≤-2]` 为空（lo>hi）已被 shape 判据在上游拒绝；真实 `minimum:-1` 单独（lo=-1 真实、hi=-1 缺侧）走既有分支出 -1（≥-1 合法，子集）；`maximum:-1` 单独同理出 -1（≤-1 合法）。故新分支只在 `lo==-1 && hi<-1` 时把幅值上界放开为缺侧。
**实现（三处）**：
1. `api_number_bounded_element`（`engine/main.php:6305`）both-negative 分支：`if ($lo == -1 && $hi < -1) { $whi = -1; }`（幅值无上界）。
2. F461 integer 检测器 `api_schema_array_item_bounds`：解除 `!$loSet && $hi < 0` 的拒绝（单侧负上界现在可 honor）；`!$hiSet && $lo < 0`（单侧负下界）**保持拒绝**（本轮不收，负下界走幅值是另一形态）。
3. 门：F461 的 `ib_negmax` 期望 `[]`→`nums|-1|-3`（旧期望被新行为证伪 ⇒ 翻门）；新增单属性负上界 shape＋文本门；number 型 items 负上界文本门（检测器已认领，写入器修复后逐元素 ≤ -N）。
**活体**（`engine/tests/schema_bounds_check.py`＋`schema_required_check.py`）：`negmax_single_served`（单属性 integer `maximum:-3` → 200 且值 ≤ -3，**改前是静默违约**）、`items_int_negmax_ok`（integer items `maximum:-3` → 200 逐元素 ≤ -3）、`items_num_negmax_ok`（number items `maximum:-3` → 200 逐元素 ≤ -3）；`items_num_frac6_refuse`/`items_num_multi_refuse`/既有 float_dec* 不回退。
**验收**：①负上界值域正确（门＋活体逐元素断言，且**改前静默违约**有证据）②`maximum:-1`/`minimum:-1` 的 -1 边界不回退 ③自检 182 起、单属性有界门（`SCHEMA-NUM-*`/`EXCL`）不回退 ④四 harness＋8000 115/0＋8001 不回退。
**顺位**：F463 → 负单侧**下**界 items（幅值下侧走位）/ multipleOf items → 分片多卡 / FR-05（本机不可验证）。
**F463 已完成**：`api_number_bounded_element` both-negative 分支加单侧负上界（`$lo==-1 && $hi<-1` ⇒ 幅值无上界 `fill(-hi,-1)`，哨兵安全性：真实 `[-1,hi≤-2]` 空区间上游已拒、真实 `maximum:-1` 走旧分支出 -1 合法）＋F461 integer 检测器解除负 max 拒绝（负 min 保持拒绝）。门 `ib_negmax` 翻 `nums|-1|-3`、新 `bn_shape=[n|-1|-3|0]`/`bn_text=[{"n":-3}]`/`in_negmax=[nums|-1|-3|0]`/`in_negmax_text`。
**改前证据（静默违约）**：单属性 integer `maximum:-3` → 200 **`10`**（违约）；number items 同形 → 200 **"Sure, here are the numbers:"**（散文，非 JSON）；integer items → 400（`engine/schema_bounds_f463pre.log`、`engine/schema_required_f463pre.log`）。
**活体**：`negmax_single_served` 200 `{"n":-3}`、`items_num_negmax_ok` 200 `[-3,-4,-5,-6]`、`items_int_negmax_ok` 200 `[-3,-4,-5,-6]`（每元素 ≤ -3）。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.535×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f463.log`、`engine/schema_bounds_f463.log`、`engine/schema_required_f463.log`、`engine/schema_count_f463.log`、`engine/schema_pattern_f463.log`、`engine/api_smoke_f463.log`、`engine/throughput_f463.log`）。
**明确留拒（排队，F463 后）**：负单侧**下**界 items（`minimum:-N` 无 max，需幅值下侧走位）、multipleOf items（倍数项写入器）；数组项 schema 的 const/pattern；`contains`/`not` 的值级子 schema；`patternProperties` 非枚举种类的值级约束；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：负单侧下界 items / multipleOf items → 分片多卡 / FR-05（本机不可验证）。


## F464 计划（负单侧**下**界 `minimum:-N`：解除 integer items 检测器守卫，钉住三条路一致）
**F463 已完成**：单侧负上界幅值走位。
**读码判定（改前状态，执行前以活体取证）**：单侧负下界 `minimum:-N`（无 max）三条路现状**不一致**：
- 单属性 integer/number：shape 出 `n|lo|-1|0`（非空）⇒ bounds 判据放行 ⇒ `api_number_bounded_element(lo,-1)` 走 both-negative 分支得幅值 `[1,|lo|]` ⇒ 值 `-(幅值) ∈ [lo,-1] ⊂ [lo,∞)`（子集）⇒ **已在服务**（无门钉住，属无门盲区）。
- number 型 items：F462 检测器经 shape 认领 `nums|lo|-1|0` ⇒ 写入器整数路径同元素体 ⇒ **已在服务**（同样无门）。
- integer 型 items：F461 检测器 `!$hiSet && $lo < 0`（`engine/main.php:1049`）**点名 400** ⇒ 唯一的过度拒绝。
⇒ F464 ＝解除该守卫＋把三条路都钉上门与活体（一致性本身就是要钉的性质）。
**哨兵安全性（minimum:-1 与 -2 边界，必须写清）**：`element(lo,-1)` 的 both-negative 分支对 `minimum:-1`（lo=-1 真实、hi=-1 缺侧）出幅值 `[1,1]` ⇒ 值 -1 ≥ -1 ✓；`minimum:-2` 出幅值 `[1,2]` ⇒ 值 -1,-2 ≥ -2 ✓；F463 的 `lo==-1 && hi<-1` 守卫在 hi==-1 时不触发 ⇒ 旧路径保持。真实 `[-1, hi]` 区间（hi ≥ -1 双侧）不受影响。
**实现（一处＋门）**：
1. `api_schema_array_item_bounds`：删除 `!$hiSet && $lo < 0` 守卫（注释改为"单侧负下界走幅值走位 [1,|lo|]，值必 ≥ lo（子集）"）。
2. 门：新 `ib_negmin=[nums|-5|-1]`（integer items 检测器）＋integer items 写入器文本（`{"nums":[-<1..5>]}` 值 ≥ -5）；单属性 `ng_shape=[n|-5|-1|0]`＋文本门（值 ≥ -5）；number items `ng_items=[nums|-5|-1|0]`＋文本门（钉住"已服务"的事实，补上 F462 缺的门）。
**活体**（`schema_required_check.py`＋`schema_bounds_check.py`）：`items_int_negmin_ok`（integer items `minimum:-5` → 200 逐元素 ≥ -5，**改前 400**）、`negmin_single_served`（单属性 integer → 200 值 ≥ -5）、`items_num_negmin_ok`（number items → 200 逐元素 ≥ -5）；F463 的 negmax 三例与 frac6/multi 不回退。
**验收**：①三条路的负下界值域正确且一致（门＋活体）②`minimum:-1`/`-2` 边界合法 ③自检 182 起 ④四 harness＋8000 115/0＋8001 不回退。
**顺位**：F464 → multipleOf items（倍数项写入器）→ 分片多卡 / FR-05（本机不可验证）。
**F464 已完成**：`api_schema_array_item_bounds` 删除 `!$hiSet && $lo < 0` 守卫（单侧负下界现在可 honor——element 既有 both-negative 分支幅值走位 `[1,|lo|]`，值 `∈ [lo,-1] ⊂ [lo,∞)`，子集论证；`minimum:-1` 走幅值 `[1,1]` 出 -1 合法，F463 的 `hi<-1` 守卫不触发）。门新 `ib_negmin=[nums|-5|-1]`/`ib_negmin_text`、`ng_shape=[n|-5|-1|0]`/`ng_text`、`ng_items=[nums|-5|-1|0]`/`ng_items_text`。
**改前证据（三条路不一致，`engine/schema_bounds_f464pre.log`、`engine/schema_required_f464pre.log`，F463 二进制）**：integer items `minimum:-5` → **400**（过度拒绝）；单属性同形 → **200 `{"n":-1}`**（已在服务，无门）；number items 同形 → **200 `[-1,-2,-3,-4]`**（已在服务，无门）——即 F461 守卫是唯一挡住该形状的地方，另两条路早已放行。
**活体**：`items_int_negmin_ok` 200 `[-1,-2,-3,-4]`（每元素 ≥ -5）、`negmin_single_served` 200 `{"n":-1}`、`items_num_negmin_ok` 200 `[-1,-2,-3,-4]`；F463 negmax 三例不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.533×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f464.log`、`engine/schema_bounds_f464.log`、`engine/schema_required_f464.log`、`engine/schema_count_f464.log`、`engine/schema_pattern_f464.log`、`engine/api_smoke_f464.log`、`engine/throughput_f464.log`、改前证据 `engine/schema_bounds_f464pre.log`、`engine/schema_required_f464pre.log`）。
**明确留拒（排队，F464 后）**：multipleOf items（倍数项写入器，无界后缀机制与有界折叠各一条）；数组项 schema 的 const/pattern；`contains`/`not` 的值级子 schema；`patternProperties` 非枚举种类的值级约束；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：multipleOf items → 分片多卡 / FR-05（本机不可验证）。


## F465 计划（multipleOf items：倍数项写入器——无界后缀逐元素 / 有界折叠确定性单值）
**F464 已完成**：负单侧下界。
**改前状态（读码判定，执行前活体取证）**：`items` 带 `multipleOf` 两形态均被点名 400：
- 无界 `items:{"type":"integer","multipleOf":3}`：bounds 判据无界分支放行（F430 m≤999999999、F431 单叶）→ keywords 层 F458 `items_constrained`（multipleOf 在名单）→ **"items" 400**。
- 有界 `items:{"type":"integer","minimum":1,"maximum":1000000,"multipleOf":5000}`：bounds 判据 shape 检查对 plan 叶 kind `an` 返回 "" → F420 守卫（multipleOf 正则命中）→ **"numeric bounds…" 400**。
- number 型 items + multipleOf：F462 检测器 mult≠0 拒 → "items" 400（**保持拒绝**，倍数写入器是整数-only，F433 同族）。
**目标形状（窄）**：单数组属性＋`items` 恰为 `{"type":"integer",…,"multipleOf":M}`（自然序、M≥1、纯十进制）⇒ 逐元素发射：
- **无界**（min/max 缺）：逐元素 `api_multiple_fill($mult,4)` 后缀机制（F430/F443，M≤999999999）——每元素是 M 的倍数（构造保证）。
- **有界**（两侧 inclusive）：wrap→shape 复用 F420/F424 折叠（区间内最小倍数、不可满足即拒）→ `lo==hi==start` ⇒ 逐元素 `api_number_bounded_element(start,start)` 确定性单值（F420 子集论证）。
**实现（四处）**：
1. 检测器 `api_schema_array_item_multiple($schema)` → `prop|mult|lo|hi`（4 段；lo/hi 缺侧＝-1，有界＝折叠后 start）：plan 恰一叶 kind（剥 `~`）`an`、items 正则 `type/minimum?/maximum?/multipleOf` 自然序、mult≥1、无界 m>999999999 拒、有界 wrap→shape 取折叠段（shape 返回 `mult≠0` 时取其 lo==hi）。
2. 写入器 `api_array_item_multiple_value`：同 int 数组写入器循环；元素体＝无界 `api_multiple_fill` / 有界 `api_number_bounded_element(lo,hi)`（lo==hi 定值走位）。
3. 四路由（与 `scArrItemB`/`scArrItemN` 并列，enum 之前）。
4. 豁免：structural items 检查加 `item_multiple == ""` 并列；bounds 判据顶部早退加 `|| item_multiple != ""`（覆盖有界的 F420 守卫与无界的放行路径）。
**门**：`im_unb=[nums|3|-1|-1]`、`im_bnd=[nums|5000|5000|5000]`、`im_unsat=[]`（min1 max4000 m5000 区间无倍数）、`im_big=[]`（无界 m>999999999）、`im_num=[]`（number+multipleOf 仍拒）、`im_kw_bnd=[]`（keywords 层放行）；写入器两条文本断言（无界 m3 元素 %3==0；有界 m5000 值==5000）＋json_ok。
**活体**（`schema_bounds_check.py`）：`items_mult_unb_ok`（200 每元素 %3==0）、`items_mult_bnd_ok`（200 每元素==5000）、`items_mult_unsat_refuse`（400）、`items_mult_num_refuse`（400）；F462 的 frac6/multi 例不回退。
**验收**：①每元素是 M 的倍数／区间内最小倍数（门＋活体）②unsat/超大 M/number 型仍拒 ③自检 182 起、单属性 multipleOf 门（`SCHEMA-BOUND-REJECT`/`NUM-BND`）不回退 ④四 harness＋8000 115/0＋8001 不回退。
**顺位**：F465 → number 型 multipleOf items（需小数倍数机制）→ 分片多卡 / FR-05（本机不可验证）。
**F465 已完成**：`api_schema_array_item_multiple`（无界 → `prop|mult|-1|-1`；有界 wrap→shape 复用 F420/F424 折叠取 `lo==hi==start`，mult 段须一致）＋`api_array_item_multiple_value`（元素体＝无界 `api_multiple_fill` 后缀机制逐元素 / 有界定值走位）＋四路由＋两处豁免（structural items 检查、bounds 早退——后者压住有界形态的 F420 守卫）。门 `im_unb=[nums|3|-1|-1]`、`im_bnd=[nums|5000|5000|5000]`、`im_unsat=[]`、`im_big=[]`、`im_num=[]`、`im_kw_bnd=[]`、`im_unb_text`（元素 %3==0）、`im_bnd_text=[{"nums":[5000]}]`。
**改前证据（`engine/schema_bounds_f465pre.log`，F464 二进制）**：无界 → **400 "items"**（F458 keywords 层）、有界 → **400 "numeric bounds…"**（bounds 层 F420 守卫）、unsat/num 对照 400。
**活体**：`items_mult_unb_ok` 200 `[1234002,2345001,3456000,4567002]`（每元素 %3==0）、`items_mult_bnd_ok` 200 `[5000,5000,5000,5000]`、`items_mult_unsat_refuse`/`items_mult_num_refuse` 400。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.528×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f465.log`、`engine/schema_bounds_f465.log`、`engine/schema_required_f465.log`、`engine/schema_count_f465.log`、`engine/schema_pattern_f465.log`、`engine/api_smoke_f465.log`、`engine/throughput_f465.log`、改前证据 `engine/schema_bounds_f465pre.log`）。
**明确留拒（排队，F465 后）**：number 型 multipleOf items（需小数倍数机制）；items 上的 multipleOf 与 exclusive 组合（检测器正则只认 inclusive 自然序）；数组项 schema 的 const/pattern；`contains`/`not` 的值级子 schema；`patternProperties` 非枚举种类的值级约束；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：number 型 multipleOf items → 分片多卡 / FR-05（本机不可验证）。


## F466 计划（number 叶＋整数 multipleOf：整数倍数子集发射——单属性 F433 过度拒绝 + items F462 mult 拒一并解除）
**F465 已完成**：integer 型 multipleOf items。
**核心思路（F439"整数即合法 number"同手法）**：整数 M 的倍数里必有整数（M、2M、…）⇒ number 叶的 multipleOf 约束可以用**整数倍数**满足（子集论证：M 的整数倍 ⊆ M 的倍数）——不需要小数倍数机制。**小数 M（如 0.3）仍拒**（整数未必是其倍数，需真小数倍数机制）。
**改前状态（读码判定，执行前活体取证）**：
- 单属性有界 `{"n":{"type":"number","minimum":1.5,"maximum":9.5,"multipleOf":3}}`：F433 守卫（`engine/main.php:9961`，"multipleOf"+`"type":"number"` ⇒ 1）→ **400**——**过度拒绝**（合法解 3/6/9 存在；shape 层同款排除在 `:6145`，F439 时留下）。
- 单属性无界 `{"n":{"type":"number","multipleOf":3}}`：F433 → 400——**保持拒绝**（无界 number 叶没有倍数写入器认领，放行即静默回落）。
- 单属性小数 M `multipleOf:0.3`：F433 正则 `[0-9]+` 不匹配小数 ⇒ **去向未知，探针取证**（疑静默放行/拒缺口）。
- number items `{"type":"number","multipleOf":3}`：F462 检测器 mult≠0 拒 + F465 检测器 kind an 不认 af ⇒ "items" 400——过度拒绝。
**实现（四处）**：
1. shape 排除删除（`:6145-6147`）：number 叶 + multipleOf 不再排除——有界时向内取整后的整数区间走既有 multipleOf 折叠（F420/F424），无界时 shape 本就返回 ""（无界不认）。
2. F433 守卫改写（`:9961`）为两条：①number + **小数 M**（`[0-9]+\.[0-9]`）→ 拒；②number + 整数 M 且 `api_schema_number_bounded_shape == ""` 且 `api_schema_array_item_multiple == ""` → 拒（覆盖无界/unsat/多叶；items 认领的放行）。注释更新。
3. F465 检测器 `api_schema_array_item_multiple`：kind `an` → `an` 或 `af`、正则 type 段 `"integer"` → `integer|number`（number items 的整数倍数子集）；注释更新。
4. F462 检测器 mult≠0 拒**保持**（有界 number+multipleOf 由 F465 检测器认领——路由顺序 scArrItemN 先测 mult≠0 返 ""，scArrItemM 接住）。
**门**：`SCHEMA-BOUND-REJECT` 的 `mult_number` 1→0（旧期望被证伪 ⇒ 翻门）、新 `mult_frac=1`（小数 M）、`mult_num_unb=1`（无界 number+整数 M）；`SCHEMA-STRUCT` 的 `im_num`（F465）`[]`→`nums|3|-1|-1` 翻转＋number items 写入器文本（元素 %3==0）；新单属性 shape+文本门（`nummult_shape=[n|3|3|3]`＋`{"n":3}`）＋路由断言（候选层不抢先——值 %3==0 的活体兼作路由证据）。
**活体**（`schema_bounds_check.py`）：`num_mult_served`（单属性 number min1.5 max9.5 m3 → 200 值 ∈{3,6,9}）、`num_mult_frac_refuse`（m0.3 → 400）、`num_mult_unb_refuse`（无界 number m3 → 400）、`items_mult_num_ok`（翻转 `items_mult_num_refuse` → 200 每元素 %3==0）。
**验收**：①值是 M 的倍数（门＋活体）②小数 M／无界 number／unsat 仍拒 ③自检 182 起、单属性 integer multipleOf 门不回退 ④四 harness＋8000 115/0＋8001 不回退。
**顺位**：F466 → items 上 multipleOf 与 exclusive 组合（F465 检测器正则扩展）→ 分片多卡 / FR-05（本机不可验证）。
**F466 已完成**：shape 删 number+multipleOf 排除（`:6145` 原文）＋F433 守卫改写（小数 M 拒；整数 M 且 shape 不认且非 items 认领 → 拒——覆盖无界 number/unsat/多叶）＋F465 检测器 kind 放宽 `an`→`an|af`＋type 段 `integer|number`（number items 整数倍数子集）。门翻转 `nfH`（NUM-FRAC 的 `num_mult_refuse` 1→0）、`im_num` `[]`→`nums|3|-1|-1`、`in_kw_multi` `[items]`→``；新 `nm_shape=[n|3|3|3]`/`nm_text=[{"n":3}]`/`im_num_text`（元素 %3==0）/`nm_bnd=0`/`nm_frac=1`/`nm_unb=1`。
**改前证据（`engine/schema_bounds_f466pre.log`，F465 二进制）**：单属性有界 number m3 → **400**（过度拒绝，合法解 3/6/9 存在）；number items m3 → **400**；小数 M 0.3 → 400（旧 F433 正则 `[0-9]+` 前缀匹配 "0" 碰巧拒——F466 显式化）。
**过程（门抓到两处）**：①NUM-FRAC 的 `nfH`（有界 number m2）被翻成放行后 FAIL——旧期望证伪 ⇒ 翻门（本轮计划的"翻门"清单漏列了它）；②bounds 活体两例 FAIL 暴露 F465 检测器有界分支的 `lo>hi` 手写检查把**单侧哨兵 hi=-1 当真值**（`min1 m3` 单侧形态被拒）⇒ 删手写检查、有界/单侧一律 wrap→shape（shape 兜哨兵/空区间/unsat），`items_num_multi_refuse` 单侧形态翻 200 `[3,3,3,3]`；③`in_kw_multi`（number items+multipleOf 的 keywords 门）同步翻 `[]`。
**活体**：`num_mult_served` 200 `{"n":3}`（∈{3,6,9}）、`num_mult_frac_refuse`/`num_mult_unb_refuse` 400、`items_mult_num_ok`/`items_num_multi_refuse` 200（每元素 %3==0）；F465 的 mult 系与 frac6 例不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.510×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f466d.log`、`engine/schema_bounds_f466.log`、`engine/schema_required_f466.log`、`engine/schema_count_f466.log`、`engine/schema_pattern_f466.log`、`engine/api_smoke_f466.log`、`engine/throughput_f466.log`、改前证据 `engine/schema_bounds_f466pre.log`）。
**明确留拒（排队，F466 后）**：小数 multipleOf（真小数倍数机制）；items 上 multipleOf 与 exclusive 组合；数组项 schema 的 const/pattern；`contains`/`not` 的值级子 schema；`patternProperties` 非枚举种类的值级约束；`if` 的丰富生效分支；`unevaluated*` 会真正判定的形状；六位及以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：items 上 multipleOf 与 exclusive 组合 / 小数 multipleOf → 分片多卡 / FR-05（本机不可验证）。


## F467 计划（items 上的 multipleOf + exclusive 组合：检测器改走 wrap→shape，同一判定覆盖任意键序）
**F466 已完成**：number 叶＋整数 multipleOf。
**核心思路（F462/F464 的"同一判定"手法）**：F465 检测器用一条**顺序固定、只认 inclusive** 的正则解析 items 块，exclusive 形式（数值或 draft-4 布尔）不在其中 ⇒ 检测器返 ""，路由落到 F458 结构层点名 400；而**单属性**同形早已由 `api_schema_number_bounded_shape` 服务（exclusive 转相邻整数再折 multipleOf）。⇒ F467 把 items 检测器也接到**同一个 shape 判定**：items 块有界时一律 wrap→shape，exclusive/inclusive/布尔/单侧/空区间/unsat 全由一处决定，检测器只负责"确认这是单个 array 属性 + items 是 integer|number + 带整数 multipleOf + 没有其它值约束键"。
**改前状态（读码判定，执行前活体取证）**：
- integer items `{"type":"integer","exclusiveMinimum":0,"maximum":9,"multipleOf":3}`：F465 正则不匹配 ⇒ 检测器 ""；`api_schema_items_constrained` 命中 exclusive/multipleOf ⇒ F458 结构层 **400 "items"**——过度拒绝（合法解 3/6/9）。
- number items 同形：F462 检测器要求 `seg[3]=="0"`（mult 必须为 0）⇒ 拒；F465 正则不匹配 ⇒ 同样 400。
- exclusive 布尔形式（inclusive 字面量旁 `"exclusiveMinimum":true`）同样被 F465 正则排除。
- 对照：单属性同形 **应已服务**（shape 的 exclusive 转换 + multipleOf 折叠），活体一并钉住；小数 M 仍应拒；无倍数区间仍应拒。
**实现（检测器一处改写，路由零改动）**：
1. `api_schema_array_item_multiple`（`engine/main.php:1159`）解析出 `$isub` 后：①要求 `"type"` 为 `integer|number`；②用 `preg_match_all('/"([^"]+)"\s*:/')` 收集键名，除 `type/minimum/maximum/exclusiveMinimum/exclusiveMaximum/multipleOf` 外**任何键**（enum/const/pattern/format/…）一律返 ""——保持"只认纯值约束形态"的边界；③取整数 `multipleOf`（小数 M 的 `[0-9]+` 前缀会得 0 ⇒ 拒）；④四个界键全不在 ⇒ 无界路径（`mult<=999999999`）返回 `prop|mult|-1|-1`；⑤否则 wrap 成单属性 schema 喂 `api_schema_number_bounded_shape`，要求 4 段且 `seg[3]==mult`，返回 `prop|mult|seg[1]|seg[2]`。形状判定（哨兵/空区间/unsat/布尔/小数界）全部由 shape 拥有，检测器不再手写上下界比较（F466 哨兵教训）。
2. 路由与两处豁免**零改动**：检测器认领即可，`api_schema_bounds_unsupported` 顶部早退与结构层 items 检查都按"检测器非空"放行。
**门（SCHEMA-STRUCT 新字段）**：`im_excl`＝integer items `exclusiveMinimum:0,maximum:9,m3` → `nums|3|3|3`；`im_excl_bool`＝`minimum:0,exclusiveMinimum:true,m3` → `nums|3|3|3`；`im_excl_num`＝number items `exclusiveMinimum:2.5,maximum:9.5,m3` → `nums|3|3|3`；`im_excl_unsat`＝`exclusiveMinimum:0,maximum:2,m3` → `[]`；`im_excl_frac`＝`exclusiveMinimum:0,maximum:9,multipleOf:0.3` → `[]`；`im_excl_kw`＝items 带 `format` 的 multipleOf 形态仍 `[items]`；exclusive 写入器文本断言（每元素 == 3）。
**活体**（`schema_bounds_check.py`）：`items_mult_excl_ok`（integer exclusive 上下界 m3 → 200 每元素 %3==0 且 >0 且 ≤9）、`items_mult_excl_bool_ok`（布尔形式 → 200，同断言）、`items_mult_num_excl_ok`（number exclusive 2.5/9.5 m3 → 200 每元素 ∈{3,6,9}）、`items_mult_excl_unsat_refuse` / `items_mult_excl_frac_refuse` → 400 点名；对照：F465/F466 的 mult 系、exclusive 单属性用例不回退。
**验收**：①exclusive 组合的元素值是 M 的倍数且在界内（门＋活体逐元素）②空区间/小数 M 仍拒、带其它约束键仍拒 ③单属性 exclusive 形态不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F467 已完成**：`api_schema_array_item_multiple` 改为"只钉表面"——键名白名单（type/minimum/maximum/exclusiveMinimum/exclusiveMaximum/multipleOf）＋type integer|number＋整数 multipleOf；四界键全不在⇒无界后缀路径，否则 wrap→`api_schema_number_bounded_shape` 取折叠 `lo|hi`。门新 `im_excl=[nums|3|3|3]`/`im_excl_bool`/`im_excl_num`/`im_excl_unsat=[]`/`im_excl_frac=[]`/`im_excl_kw=[items]`/`im_excl_text=[{"nums":[3]}]`。
**改前证据（`engine/schema_bounds_f467pre.log`，F466 二进制）**：integer items excl0/max9/m3 → 400 "numeric bounds…"、布尔形 → 400、number items excl2.5/max9.5/m3 → 400；unsat/frac 对照 400 正确。
**活体**：`items_mult_excl_ok`/`items_mult_excl_bool_ok`/`items_mult_num_excl_ok` 200 `[3,3,3,3]`、unsat/frac 400；F465/F466 系不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.523×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f467.log`、`engine/schema_*_f467.log`、`engine/api_smoke_f467.log`、`engine/throughput_f467.log`）。
**顺位**：F467 → items exclusive **无 multipleOf** 的整数形态（同根：F460 检测器只认 inclusive）→ 小数 multipleOf → 分片多卡 / FR-05（本机不可验证）。


## F468 计划（integer items 的 exclusive 界、无 multipleOf：F460 检测器同款 wrap→shape）
**F467 已完成**：items 的 multipleOf＋exclusive。
**核心思路**：F460 的 `api_schema_array_item_bounds` 也是"顺序固定、只认 inclusive 数值界"的正则，exclusive 形式落到结构层点名 400；而单属性 integer 叶的 exclusive 早已服务（F422/F423/F437）。与 F467 同根同手法：检测器改为键名白名单 + wrap→`api_schema_number_bounded_shape`，取 `lo|hi`，`mult` 段必须为 0（带 multipleOf 的形态归 F465 检测器）。
**改前状态（读码判定，执行前活体取证）**：integer items `{"type":"integer","exclusiveMinimum":0,"maximum":9}` → F460 正则不匹配、F462 只认 number 叶、F465 要求 multipleOf ⇒ 结构层 **400 "items"**（过度拒绝，合法值 1..9）；布尔形同；unsat（excl2,max2）应保持 400。
**实现（检测器一处，路由零改动）**：`api_schema_array_item_bounds` 解析出 `$isub` 后：①键名白名单 `type/minimum/maximum/exclusiveMinimum/exclusiveMaximum`（含 `multipleOf` 或其它值约束键 ⇒ 返 ""，让位/拒绝）；②`type` 必须 `integer`；③四个界键全不在 ⇒ 返 ""（裸类型由普通数组写入器服务）；④wrap→shape，要求 4 段且 `seg[3]=="0"`，返回 `prop|seg[1]|seg[2]`。F463/F464 的负单侧与哨兵由 shape 原样给出（`max:-3`→`-1|-3`、`min:-5`→`-5|-1`），不再手写比较。
**门（SCHEMA-STRUCT 新字段）**：`ib_excl`＝`nums|1|9`、`ib_excl_bool`＝`nums|1|-1`（`minimum:0`＋`exclusiveMinimum:true`）、`ib_excl_unsat`＝``、`ib_excl_kw`＝`[items]`（items 带 `format`）；exclusive 写入器文本断言（值 1..9 且 json_ok）。
**活体**（`schema_bounds_check.py`）：`items_excl_ok`（excl0/max9 → 200 每元素 1..9）、`items_excl_bool_ok`（布尔形 → 200 每元素 1..9）、`items_excl_unsat_refuse`（400）、`items_num_excl_nomult_ok`（number 叶 excl2.5/max9.5 无 multipleOf → 200，F462 已服务的对照）；F460/F461/F463/F464 与 F467 的界/multipleOf 例不回退。
**验收**：①exclusive 元素值在界内（门＋活体逐元素）②unsat/带其它约束键仍拒 ③负单侧、单侧、双侧 inclusive 门与活体不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F468 已完成**：`api_schema_array_item_bounds` 改键名白名单（type/minimum/maximum/exclusiveMinimum/exclusiveMaximum；multipleOf 让位 F465）＋wrap→shape 取 `lo|hi`（mult 段必须 0），删手写 lo>hi；门新 `ib_excl=[nums|1|9]`/`ib_excl_bool=[nums|1|-1]`/`ib_excl_unsat=[]`/`ib_excl_kw=[items]`/`ib_excl_text`（1..9）。
**改前证据（`engine/schema_bounds_f468pre.log`，F467 二进制）**：integer items excl0/max9 → 400、布尔形 400；number 叶同形无 mult → 200 `[3,4,5,6]`（F462 已服务，两路不一致）；unsat 对照 400 正确。
**活体**：`items_excl_ok`/`items_excl_bool_ok` 200 `[1,2,3,4]`、unsat 400、number 对照 200；F460–F467 系不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.561×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f468.log`、`engine/schema_*_f468.log`、`engine/api_smoke_f468.log`、`engine/throughput_f468.log`）。
**顺位**：F468 → 小数 multipleOf（真小数倍数机制）→ 分片多卡 / FR-05（本机不可验证）。


## F469 计划（小数 multipleOf：缩放网格折叠到区间内最小倍数，复用 F440–F456 小数 marker 写入器）
**F468 已完成**：integer items 的 exclusive 界。
**核心思路（F420 子集论证 + F440 小数网格）**：M 为小数（如 0.3）时，把界与 M 一起缩放到 `d = max(界小数位, M 小数位)`（≤5）的整数网格，在 `[sLo,sHi]` 上取 `sM` 的最小倍数 `start = ceil(sLo/sM)*sM`（`start>sHi` ⇒ unsat 拒），返回 `prop|start|mark|0`（mark=-999..-995 按 d）——既有 `api_number_decimal_element`/marker 分支原样发出 `start/10^d`，它必是 M 的倍数且落界内（子集论证）。**不需要新写入器**。
**支持边界（明确留拒）**：仅 **number 叶 + 双侧 inclusive 普通小数界（≤5 位）+ 小数 M（≤5 位）**；integer 叶小数 M 拒（整数未必是其倍数）；单侧/无界小数 M 拒（无 writer）；exclusive＋小数 M 拒（相邻整数转换会丢小数精度，可能误判 unsat）；M/界含指数或 >5 位小数拒；`sM<1`（M=0.0）拒。
**实现**：
1. `api_schema_number_bounded_shape`：把 multipleOf 解析**上移**到 `lo>hi` 早退之前——完整字面量 `([0-9.eE+-]+)`＋`^[0-9]+(\.[0-9]+)?$` 校验（指数拼写显式拒，F421 教训）；含 "." ⇒ 小数分支（上述网格折叠，要求 n 叶/双侧 inclusive/无 exclusive，返回 marker 编码或 ""）；否则 `$mult=(int)` 走既有整数折叠（`lo>hi` 块对整数 M 仍返 ""，行为不变）。
2. F433 守卫（`api_schema_bounds_unsupported` 小数 M 条）加 `&& api_schema_number_bounded_shape($body)==""` 豁免（单属性认领放行）；items 小数 M 由顶部早退（`api_schema_array_item_number != ""`）放行——F462 检测器 wrap→shape 取 `seg[3]=="0"` 自动认领，number-items 写入器 marker 分支逐元素发小数。**路由零改动**。
3. F462 检测器注释更新（小数 M 现经 shape 认领）；F465 检测器小数 M 仍返 ""（mult<1），让位 F462。
**门（SCHEMA-STRUCT 新字段）**：`fm_shape=[n|12|-999|0]`（min1 max2 m0.3）＋`fm_text=[{"n":1.2}]`；`fm_unsat=[]`（min1 max1.1 m0.3）；`fm_neg=[n|-18|-999|0]`＋文本 `{"n":-1.8}`；`fm_d2=[n|120|-998|0]`（min1.05 max2 m0.3）＋文本 `{"n":1.20}`；`fm_noint=[n|15|-999|0]`（min1.5 max1.9 m0.3，lo>hi 路径）＋文本；`fm_items=[nums|12|-999|0]`＋items 文本 `{"nums":[1.2]}`；`fm_intleaf=1`（integer 叶小数 M 仍拒）。
**活体**（`schema_bounds_check.py`）：`num_mult_frac_served`（200 值 ∈[1,2] 且 %0.3≈0）、`num_mult_frac_neg_ok`、`num_mult_frac_noint_ok`、`items_mult_frac_ok`（逐元素）；`num_mult_frac_unsat_refuse`/`num_mult_frac_intleaf_refuse` 400；F466 的 `num_mult_frac_refuse`（无界）与 F467 的 `items_mult_excl_frac_refuse`（exclusive）不回退。
**验收**：①值是 M 的倍数且在界内（门＋活体，浮点用容差判倍数）②unsat/integer 叶/单侧/无界/exclusive/指数仍拒 ③整数 M 折叠与 F440–F456 小数网格门不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F469 已完成**：shape 上移 multipleOf 解析（完整字面量＋`^[0-9]+(\.[0-9]+)?$` 校验，指数显式拒）；小数 M 在 `d=max(界位,M位)` 网格折叠 `start=ceil(sLo/sM)*sM` 返回 `prop|start|mark|0`（复用 F440 marker 写入器）；F433 守卫加 `shape==""` 豁免；items 经 F462 检测器（seg[3]==0）自动认领，零路由改动。门 `fm_shape=[n|12|-999|0]`/`fm_text`/`fm_unsat=[]`/`fm_neg`/`fm_d2`/`fm_noint`/`fm_items`/`fm_intleaf=1`＋五文本断言。
**改前证据（`engine/schema_bounds_f469pre.log`，F468 二进制）**：number min1max2 m0.3 / 负界 / 无整数区间 / items → 400；unsat/integer 叶/无界/exclusive 对照 400。
**活体**：`num_mult_frac_served` 200 `{"n":1.2}`、neg 200 `{"n":-1.8}`、noint 200 `{"n":1.5}`、`items_mult_frac_ok` 200 `[1.2,…]`；unsat/integer 叶 400；F466/F467 小数 M 对照不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.544×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f469.log`、`engine/schema_*_f469.log`、`engine/api_smoke_f469.log`、`engine/throughput_f469.log`）。
**顺位**：F469 → 数组项 const/pattern、contains/not 值级子 schema 等排队项 → 分片多卡 / FR-05（本机不可验证）。


## F470 计划（数组项 string const：单值枚举，复用 F459 枚举项写入器）
**F469 已完成**：小数 multipleOf。
**核心思路**：`items:{"type":"string","const":"X"}` 等价于单值枚举 `enum:[X]`——每元素必等于 X。F459 的 `api_array_enum_value` 已能逐元素从值表发射，只需检测器 `api_schema_array_enum` 在 enum 正则之外再认 const 形式，返回 `prop|X`（单值表）。**写入器/路由零改动**。
**改前状态（读码判定，执行前活体取证）**：const items → `api_schema_array_enum` 只认 enum ⇒ ""；`api_schema_items_constrained` 命中 const ⇒ 结构层 **400 "items"**（过度拒绝，合法解每元素 "X"）。
**实现（检测器一处）**：`api_schema_array_enum` 的 enum 正则失败后试 `^\{\s*"type"\s*:\s*"string"\s*,\s*"const"\s*:\s*"([^"]*)"\s*\}$`；值非空且不含 `~,/"\`（同 enum 编码约束，含转义的 const 正则不匹配自然拒）⇒ 返回 `prop|值`。结构层豁免按"array_enum 非空"自动放行。
**门（含翻转）**：`SCHEMA-STRUCT` 的 `items_const`（sfCB）`items`→``；新 `ac_plan=[cities|paris]`、`ac_text=[{"cities":["paris"]}]`、`ac_route=0`（候选层不抢先）；`items_pattern` 保持 `items` 作对照。
**活体**（`schema_required_check.py`）：`items_const_refuse` 翻转改名 `items_const_ok`（200 每元素 == "paris"）；`items_enum_ok`/`items_pattern_refuse` 不回退。
**验收**：①每元素等于 const（门＋活体）②pattern/含转义 const 仍拒 ③自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F470 已完成**：`api_schema_array_enum` 在 enum 正则外再认 string const（同编码约束，含转义自然拒）返回 `prop|X`；结构层豁免自动放行，写入器/路由零改动。门翻转 `items_const` `items`→``、`ae_const` ``→`cities|paris`；新 `ac_text=[{"cities":["paris"]}]`/`ac_route=0`。
**改前证据（`engine/schema_required_f470pre.log`，F469 二进制）**：const items → 400 "items"；enum 对照 200。
**活体**：`items_const_ok` 200 `[paris,paris]`；enum/pattern 对照不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.545×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f470.log`、`engine/schema_*_f470.log`、`engine/api_smoke_f470.log`、`engine/throughput_f470.log`）。
**顺位**：F470 → 数组项 pattern、contains/not 值级子 schema 等排队项 → 分片多卡 / FR-05（本机不可验证）。


## F471 计划（数组项 string pattern：复用枚举项检测器/写入器家族，~ 前缀编码 pattern spec，4 路由零改动）
**F470 已完成**：数组项 string const。
**核心思路（低接线复用）**：单属性 pattern 已由 `api_schema_pattern_spec`（→段 spec `l<text>~c<n>x<chars>`）＋`api_object_mixed` 的 "p" 分支服务。items pattern 复用 **F459 枚举项检测器/写入器**（4 路由已接好 `api_response_schema_array_enum`/`api_array_enum_value`）：检测器再认 `items:{"type":"string","pattern":P}`，spec 非空 ⇒ 返回 `prop|~spec`（**~ 前缀**标记——enum 编码首值非空且不含 ~，故不可能以 ~ 开头，无碰撞；pattern 字符表 `[A-Za-z0-9 .\-_^]` 不含 `|`，路由 `explode("|")` 安全）。写入器见 valsCsv 以 ~ 开头 ⇒ pattern 模式：逐元素强制开引号＋`api_pattern_body(spec)`＋闭引号。**结构层豁免/路由零改动**（array_enum 非空自动放行）。
**实现**：
1. 新 helper `api_pattern_body($pSpec)`（无返回类型，成功返回 ids 串可空、失败返 `false`）——独立复刻 `api_object_mixed` "p" 分支的段发射（字面 run＋计数类，非末段发最小、末段 min..max 走位后由调用方闭引号）。**不改动 `api_object_mixed`**（单属性 pattern 门保持字节级不变，零回归）；items 侧由自己的门＋活体（逐元素正则匹配）钉住。空 body（如 `*` 末段发 0 字符）合法 ⇒ 用 `false` 区分失败与空。
2. `api_schema_array_enum`：enum/const 分支后加 pattern 分支（`"pattern":"([^"]*)"`→`api_schema_pattern_spec`，非空 ⇒ `prop|~spec`）。
3. `api_array_enum_value`：valsCsv 首字符 ~ ⇒ pattern 模式（spec=substr(1)），逐元素开引号＋body＋闭引号；否则既有 enum 模式。
**门（含翻转）**：`items_pattern`（sfCC）`items`→``；新 `ap_plan`（array_enum pattern items）＝`codes|~c3xABCDEFGHIJKLMNOPQRSTUVWXYZ`、`ap_text`（writer 文本每元素匹配 `^[A-Z]{3}$`）、`ap_route=0`；不支持的 pattern（如交替 `^(a|b)$` 无类）保持 `items` 对照。
**活体**（`schema_required_check.py`）：`items_pattern_refuse` 翻转改名 `items_pattern_ok`（items `^[A-Z]{3}$` → 200 每元素匹配正则）；新 `items_pattern_alt_refuse`（无类 pattern → 400）；enum/const 不回退。
**验收**：①每元素匹配 pattern（门＋活体正则）②不支持 pattern 仍拒 ③单属性 pattern 门（SCHEMA-PATTERN）字节级不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F471 已完成**：新 helper `api_pattern_body`（独立复刻段发射，false 区分失败/空，不触 `api_object_mixed`）＋`api_schema_array_enum` pattern 分支（`prop|~spec`）＋`api_array_enum_value` ~ 前缀 pattern 模式；4 路由/结构豁免零改动。**计划修正**：sfCC 的 pattern 是交替 `^(paris|lyon)$`（无类⇒spec 空⇒不认领），故 `items_pattern` **保持 `items`** 作拒绝对照（非翻转）；服务门另用 class pattern `^[A-Z]{3}$`：`ap_plan=[codes|~c3xABCDEFGHIJKLMNOPQRSTUVWXYZ]`/`ap_text`（正则）/`ap_route=0`。
**改前证据（`engine/schema_required_f471pre.log`，F470 二进制）**：items `^[A-Z]{3}$` → 400；交替对照 400。
**活体**：`items_pattern_ok` 200 `[CJL,CJL]`、`items_pattern_alt_refuse` 400；enum/const/单属性 pattern 不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.547×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f471.log`、`engine/schema_*_f471.log`、`engine/api_smoke_f471.log`、`engine/throughput_f471.log`）。
**顺位**：F471 → contains/not 值级子 schema、patternProperties 非枚举种类、if 丰富分支、unevaluated* 真判定、六位以上小数 → 分片多卡 / FR-05（本机不可验证）。


## F472 计划（contains 值级子 schema 的**子集形式**：items 有限 enum/const 的每个可能元素都满足 contains）
**F471 已完成**：数组项 string pattern。
**核心思路（子集论证 + 复用 F457 判定）**：`contains` 要求"至少一元素匹配子 schema"。当 items 是有限字符串 enum/const 时，写入器（F459 枚举项）每元素都从该值表发射、数组恒 ≥1 元素；若**每个**可能元素都满足 contains 子 schema，则 contains 构造满足。判定复用 `api_schema_leaf_guarantees("e:".valsCsv, sub)`（F457：const 相等/enum 包含/pattern 逐值匹配，含 optional type:string 前缀）。**零新写入器/路由**。
**支持边界**：单字符串数组属性（kind a）＋items 为有限 enum/const（`api_schema_array_enum` 非空且非 ~ 前缀；pattern items 无限 ⇒ 跳过）＋contains 为 const/enum/pattern（每 enumerant 满足）或 bare type（F449 已服务）。items 自由字符串（s）无法保证值级 contains ⇒ 仍拒（现有 sfAQ/contains_pattern_refuse 对照）；contains 子 schema 更丰富 ⇒ 拒。minContains/maxContains 由 F454 折成元素计数（每元素皆匹配）。
**改前状态（读码判定，执行前活体取证）**：items const paris + contains const paris → `api_schema_contains_ok` 只认 bare type ⇒ 0 ⇒ 结构层 **400 "contains"**（过度拒绝，每元素恒 paris）；items enum [paris,lyon] + contains enum [paris,lyon,nice] 同；子集不成立（contains const paris 但 items 含 lyon）应保持 400。
**实现（contains_ok 一处）**：bare-type 正则失败后，kind==a 时取 `api_schema_array_enum($schema)` 的 valsCsv（非 ~），`api_schema_leaf_guarantees("e:".valsCsv, $sub)==1 ⇒ return 1`。
**门（SCHEMA-STRUCT 新字段）**：`cv_const=[]`（items const paris + contains const paris）、`cv_enum=[]`（items enum + contains 超集 enum）、`cv_pat=[]`（items enum + contains `^[a-z]+$`）、`cv_miss=[contains]`（items enum [paris,lyon] + contains const paris，子集不成立）、`cv_route=[cities|paris]`（写入器认领）；现有 `contains_pattern`（自由字符串 items）保持 `contains` 对照。
**活体**（`schema_count_check.py`）：`contains_const_served`（200 每元素 ==paris）、`contains_enum_served`（200 每元素 ∈enum）、`contains_subset_miss_refuse`（400 contains）；contains_type_served/contains_pattern_refuse 不回退。
**验收**：①服务形态每元素满足 contains（门＋活体）②子集不成立/自由字符串 items/丰富子 schema 仍拒 ③F449/F454 contains 门与 minContains 不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F472 已完成**：`api_schema_contains_ok` bare-type 失败后加值级子集分支（kind a 取 array_enum valsCsv 非 ~，`leaf_guarantees("e:".valsCsv,$sub)==1 ⇒ 1`）；路由/写入器零改动。门 `cv_const`/`cv_enum`/`cv_pat`=[]、`cv_miss=[contains]`、`cv_route=[cities|paris]`；contains_pattern 保持对照。
**改前证据（`engine/schema_count_f472pre.log`，F471 二进制）**：items const+contains const → 400、items enum+contains 超集 → 400；子集不成立对照 400。
**活体**：`contains_const_served`/`contains_enum_served` 200 `[paris,paris]`、`contains_subset_miss_refuse` 400；type/pattern 对照不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.463×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f472.log`、`engine/schema_*_f472.log`、`engine/api_smoke_f472.log`、`engine/throughput_f472.log`）。
**顺位**：F472 → not 值级、patternProperties 非枚举种类、if 丰富分支、unevaluated* 真判定、六位以上小数 → 分片多卡 / FR-05（本机不可验证）。


## F473 计划（小数网格 5→6 位：F456 同款扩宽，新增 marker -994）
**F472 已完成**：contains 值级子集形式。
**核心思路**：F440–F456 的 marker 编码已覆盖 d=1..5（-999..-995）；把网格宽度扩到 **d=6**（marker **-994**、scale 10^6）——同一条小数写入链即可发六位小数，语义无歧义（`[1.511111,1.511119]` → `1.511111`）。七位仍拒。
**实现（六处）**：①`api_schema_scaled` 正则 `{1,5}`→`{1,6}` ＋ `$digits==6` 分支（×1000000）；②shape 小数网格分支：界正则 `{1,5}`→`{1,6}`、加 `\.[0-9]{6}` 探测得 d=6、mark -994；③`api_number_decimal_element` 加 -994 分支（d=6,scale=1000000）；④`api_number_bounded_value` marker 判定加 -994；⑤`api_array_item_number_value` 元素 marker 判定加 -994；⑥F469 小数 M 分支**保持 ≤5 位**（另行排队，不在本增量）。
**改前状态（读码判定，执行前活体取证）**：`minimum:1.511111,maximum:1.511119`（六位）→ 网格正则 {1,5} 不匹配 ⇒ shape "" ⇒ **400**（过度拒绝，确定解 1.511111 存在）；五位数对照已服务。
**门（SCHEMA-NUM-FRAC 含翻转）**：`num_dec6`（nfQ）`""`→`n|1511111|-994|0`；新 `num_dec7=[]`（七位仍拒）、`dec6_text=[{"n":1.511111}]`；`num_dec5`/`dec5_text` 与 1–4 位不回退。
**活体**（`schema_bounds_check.py`）：`float_dec6_refuse` 翻转改名 `float_dec6_served`（200 `{"n":1.511111}`）；新 `float_dec7_refuse`（400）；`float_dec5_served` 不回退。
**验收**：①六位小数确定值发出（门＋活体）②七位仍拒 ③1–5 位与负数不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F473 已完成**：`api_schema_scaled` `{1,6}`＋digits==6；shape 网格 `{1,6}`＋d=6＋mark -994；`api_number_decimal_element`/`api_number_bounded_value`/`api_array_item_number_value` 三处 marker 判定加 -994；F469 小数 M 保持 ≤5。门翻转 `num_dec6` ``→`n|1511111|-994|0`，新 `num_dec7=[]`、`dec6_text=[{"n":1.511111}]`。
**改前证据（`engine/schema_bounds_f473pre.log`，F472 二进制）**：六位单属性 → 400；七位对照 400；1–5 位/负数不变。
**波纹（已处理）**：`items_num_frac6_refuse`（six-decimal number items 旧拒绝例）经 F462 wrap→shape 随网格扩宽自动服务（200 `[0.000123,…]`，界内合法）⇒ 翻为 `items_num_frac6_ok` 并逐元素断言 0.000123。
**活体**：`float_dec6_served` 200 `{"n":1.511111}`、`float_dec7_refuse` 400；1–5 位/负数不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.542×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f473.log`、`engine/schema_*_f473.log`、`engine/api_smoke_f473.log`、`engine/throughput_f473.log`）。
**顺位**：F473 → not 值级、patternProperties 非枚举种类、if 丰富分支、unevaluated* 真判定 → 分片多卡 / FR-05（本机不可验证）。


## F474 计划（`not` 值级的**非字符串常量**形式：flat all-string 的字符串值与标量常量必然矛盾）
**F473 已完成**：小数网格 6 位。
**核心思路（沿用 F448 的值级模型）**：F448 已确立 flat all-string 形态下 `not:{type:T}` 按"每个发射值是字符串"判定（T≠string 必不违反，含 object）。同理由：`not:{const:C}` 当 C 是**非字符串标量**（数字/true/false/null）时，发射的字符串值永不等于 C ⇒ 必不违反；`not:{enum:[…]}` 当**每个**成员都是非字符串标量时同理。字符串常量/枚举成员可能被实际发射 ⇒ 保持拒绝（自由字符串值上的成员排除不可证）；pattern 保持拒绝（`contains_pattern` 同族）。
**支持边界**：flat all-string 形态（`api_schema_flat_all_string==1`，同 F448）；not 子 schema 恰为 `{"const":<标量非串>}` 或 `{"enum":[<标量非串>,…]}`；对象/数组常量、含字符串成员、pattern、嵌套规则、混合属性形态仍拒。
**改前状态（读码判定，执行前活体取证）**：`not:{const:5}` / `not:{enum:[1,2,3]}` 经 F448 只认 bare type ⇒ **400 "not"**（过度拒绝，字符串值永不为 5）；`not:{const:"paris"}` 对照应保持 400。
**实现（not_ok 一处）**：bare-type 正则命中即 1；否则 `const` 标量非串正则 `\{\s*"const"\s*:\s*(?:-?[0-9][0-9.eE+-]*|true|false|null)\s*\}` 命中 ⇒ 1；`enum` 全非串标量正则 ⇒ 1；其余 0。
**门（SCHEMA-STRUCT 新字段）**：`nt_const_num=[]`、`nt_enum_num=[]`（服务）；`nt_const_str=[not]`、`nt_enum_mixed=[not]`（含字符串成员仍拒）；现有 `not_pattern` 保持 `not` 对照。
**活体**（`schema_count_check.py`）：`not_const_num_served`（200 值为字符串）、`not_const_str_refuse`（400 "not"）；`not_type_served`/`not_pattern_refuse` 不回退。
**验收**：①服务形态值必为字符串（门＋活体）②字符串常量/混合枚举/pattern 仍拒 ③F448 的 type 门与 flat 形态限制不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F474 已完成**：`api_schema_not_ok` 加非字符串标量 const／全非字符串标量 enum 分支；**并修复** `api_response_schema_string_props` 把根级 `not` 块内的 const 误当值域、导致生成器放弃并落回散文的静默违约（与 propertyNames 同手法剥离 not 块；属性名恰为 not 的不剥），加 `nt_route=1` 路由门。门 `nt_const_num`/`nt_enum_num`=[]、`nt_const_str`/`nt_enum_mixed`=[not]；`not_pattern` 保持对照。
**改前证据（`engine/schema_count_f474pre.log`，F473 二进制）**：`not:{const:5}` → 400；字符串常量/pattern 对照 400。
**过程**：首版只改 not_ok，自检全绿但活体抓到 200 散文（结构放行、生成器未认领）⇒ 剥离 not 块后重构建（`build_f474b.log` 留 TPC 报错：首版编辑误把 `$plan=` 并入注释行，`build_f474c.log` 修复后成功）。
**活体**：`not_const_num_served` 200 `{"cities":"…"}`、`not_const_str_refuse` 400；type/pattern 不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.544×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f474.log`、`engine/schema_*_f474.log`、`engine/api_smoke_f474.log`、`engine/throughput_f474.log`）。
**顺位**：F474 → patternProperties 非枚举种类、if 丰富分支、unevaluated* 真判定 → 分片多卡 / FR-05（本机不可验证）。


## F475 计划（if/then/else 的 bare-`required` 条件与选中分支：用已证键集构造判定）
**F474 已完成**：not 值级非字符串常量。
**核心思路（复用 F453 的 emitted_set 证明）**：JSON Schema 的条件按实例求值；本引擎的 flat all-string 形状**键集已被证明**（`api_schema_emitted_set`：required 子集或全部声明键）。因此：①`if:{"required":[X]}` 的条件真值＝X ⊆ emitted（可判，无需求值）；②选中分支（then/else）若是 bare `{"required":[Y]}`，只要 Y ⊆ emitted 即恒满足（实例必有这些键）。F452 现只认 bare-type 条件与平凡 `{"type":"object"}` 分支，F475 把这两处扩到 bare-required。
**支持边界**：条件仍限于 bare type 或 bare required；选中分支限于缺省/`{"type":"object"}`/bare required ⊆ emitted；更丰富条件或分支（properties/嵌套/数值）仍拒。未选中分支按 JSON Schema 语义忽略。
**改前状态（读码判定，执行前活体取证）**：`if:{"required":["city"]},then:{"type":"object"}`（city 必发）→ **400 "if"**（条件非 bare type）；`if:{"type":"string"},else:{"required":["city"]}`（F452 的 if_rich 夹具，city 必发）→ **400**（选中分支非平凡 object）——两者都是过度拒绝。
**实现（if_ok 两处）**：①条件解析：bare type 命中走旧逻辑；否则 bare required 正则命中 ⇒ 用 emitted_set 判 X ⊆ emitted 选 then/else；②选中分支：缺省/`type:object` 命中即 1；否则 bare required 命中且 Y ⊆ emitted ⇒ 1；否则 0。
**门（含翻转）**：`if_req_true=[]`（条件真、then 平凡）、`if_req_false=[]`（条件假、else 平凡）、`if_req_then=[]`（条件真、then required 子集）、`if_req_then_miss=[if]`（then 要求未发键 ⇒ 不可满足）、`if_req_route=2`（生成器仍认领）；**旧 `if_rich`（sfAX）`if`→``**（else required city 恒满足）。
**活体**（`schema_required_check.py`）：新 `if_req_true_ok`/`if_req_then_ok`（200 双键）、`if_req_then_miss_refuse`（400 "if"）；**旧 `if_rich_refuse` 翻 `if_rich_served`**（200 双键）；if_true_ok/if_false_ok 不回退。
**验收**：①条件真值/分支满足按已证键集判定（门＋活体）②未发键的 then/required 仍拒 ③F452 的 bare-type 三例不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F475 已完成**：`api_schema_if_ok` 条件加 bare required（`api_schema_emitted_set` 判 X⊆emitted 选 then/else），选中分支加 bare required（Y⊆emitted ⇒ 1）；门新 `if_req_true`/`if_req_false`/`if_req_then`/`if_req_miss`/`if_req_route`，**旧 `if_rich`（sfAX）`if`→``**。
**改前证据（`engine/schema_required_f475pre.log`，F474 二进制）**：if required 条件/then、F452 if_rich 三例 → 400（皆过度拒绝，city/note 必发）。
**过程**：首版误把 count harness 的 `ask` 第五元素带进 required harness（后者只解 4 元组）⇒ `ValueError: too many values to unpack`，修正后重跑得干净改前证据。
**活体**：`if_req_true_ok`/`if_req_then_ok` 200 双键、`if_req_then_miss_refuse` 400、**`if_rich_refuse`→`if_rich_served` 200**；if_true/if_false 不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.532×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f475.log`、`engine/schema_*_f475.log`、`engine/api_smoke_f475.log`、`engine/throughput_f475.log`）。
**顺位**：F475 → patternProperties 非枚举种类、unevaluated* 真判定 → 分片多卡 / FR-05（本机不可验证）。


## F476 计划（patternProperties 的**数值 enum/const kind（`en:`）**：bare type / enum 超集 / const 相等）
**F475 已完成**：if bare-required。
**核心思路（F457 的数值版）**：plan 对数值 enum/const 属性编码为 `en:v1~v2`（见 `api_top_leaves`），但 `api_schema_kind_is_type`/`api_schema_leaf_guarantees` 只认 `e:`（字符串枚举）、`s/i/n/b`，于是匹配键为数值枚举属性时：bare `{"type":"integer"}`、`{"enum":[超集]}`、`{"const":X}` 全部**过度拒绝**。F476 给 `en:` 加判定：①type number 恒真；type integer 当且仅当每成员都是整数字面量；②const：每成员数值等于该常量；③enum：每成员数值 ∈ 允许集。**数值比较用 (float)，成员必须是未加引号的数字字面量**（带引号的 "1" 在 JSON 里不等于数字 1，遇引号一律不判）。
**支持边界**：单 patternProperties 条目（既有约束）；匹配键的 kind 为 `en:`；子 schema 为 bare integer/number、数值 const、全数值 enum。pattern（字符串）对数值实例按 JSON Schema 是 vacuous，但本判定保守起见仍拒（记录在案）；properties/嵌套等更丰富形态仍拒。
**改前状态（读码判定，执行前活体取证）**：properties `{count:{"type":"integer","enum":[1,2,3]}}` + `patternProperties:{"^c":{"type":"integer"}}` → kind_is_type("en:1~2~3","integer")=0 ⇒ **400 "patternProperties"**；`{"enum":[1,2,3,4]}`/`{"const":1}`（单值）同；对照 `{"enum":[5,6]}` 应保持 400。
**实现（两 helpers）**：①`api_schema_kind_is_type` 顶部加 `en:` 分支（number 恒 1；integer 需每成员 `^-?[0-9]+$`）；②`api_schema_leaf_guarantees` 加 `en:` 分支（裸数值 const／全裸数值 enum；带引号成员返 0）。patternProperties 判定本身零改动。
**门（SCHEMA-STRUCT 新字段）**：`pp_num_type=[]`、`pp_num_enum=[]`、`pp_num_const=[]`（单值）、`pp_num_miss=[patternProperties]`、`pp_num_mixed=[patternProperties]`（const 1 但枚举含 2）；既有 `pp_enum`/`pp_pat` 字符串门不回退。
**活体**（`schema_required_check.py`）：`patternprops_num_type_ok`（200 count∈{1,2,3}）、`patternprops_num_enum_ok`（超集枚举，200）、`patternprops_num_miss_refuse`（400）；既有 patternprops 系列不回退。
**验收**：①服务形态的数值必属枚举/等于常量（门＋活体逐值）②超集不成立/混合枚举仍拒 ③F455/F457 字符串枚举门不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F476 已完成**：`api_schema_kind_is_type` 加 `en:`（number 恒真；integer 需每成员整数字面量；置于 "~" 剥离前），`api_schema_leaf_guarantees` 加 `en:`（裸数值 const 相等、全裸数值 enum 包含；带引号成员不判）；patternProperties 判定本体零改动。门 `pp_num_type`/`pp_num_enum`/`pp_num_const`=[]、`pp_num_miss`/`pp_num_mixed`/`pp_num_str`=[patternProperties]。
**改前证据（`engine/schema_required_f476pre.log`，F475 二进制）**：数值枚举属性 + pp bare integer／enum 超集 → 400；不覆盖对照 400 正确。
**活体**：`patternprops_num_type_ok`/`patternprops_num_enum_ok` 200 `{"count":1}`、`patternprops_num_miss_refuse` 400；字符串 patternprops 系列不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.483×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f476.log`、`engine/schema_*_f476.log`、`engine/api_smoke_f476.log`、`engine/throughput_f476.log`）。
**顺位**：F476 → unevaluated* 真判定 → 分片多卡 / FR-05（本机不可验证）。


## F477 计划（数组项 pattern 的**无类交替/纯字面量**：有限集，复用 F459 枚举项写入器）
**F476 已完成**：patternProperties 数值 enum kind。
**核心思路**：`^(A|B|…)$`（完全锚定、成员为纯字面量）的匹配集就是有限枚举 ⇒ 每元素必属该集合，F459 枚举项写入器原样服务。F471 的 spec 分支对无类 pattern 返回 ""，故交替/纯字面量被名片 400（**过度拒绝**）。
**支持边界**：items 为 `{"type":"string","pattern":P}`；P 完全锚定 `^…$`（允许一对外层括号）；成员限 `[A-Za-z0-9 _-]+`（**不含正则元字符**——`.`/`*` 等会让字面文本不匹配自身；不含 `~,|,",\`）；单成员纯字面量 `^(X)$` 亦服务（单值枚举）。未锚定交替、含元字符、空成员、`^[A-Z]{3}$` 等类形态（F471 已服务）保持各自路径。
**改前状态（读码判定，执行前活体取证）**：items `^(paris|lyon)$` → array_enum ""（spec 空）⇒ **400 "items"**（过度拒绝）；`^a.c$` 元字符对照 400 正确。
**实现（检测器一处）**：`api_schema_array_enum` 的 pattern 分支：spec 非空走 F471；否则 `^\^\(?([A-Za-z0-9 _\-]+(?:\|[A-Za-z0-9 _\-]+)*)\)?\$$` 命中 ⇒ `prop|<members joined by ~>`（enum 编码，无 ~ 前缀）；其余返 ""。写入器/路由零改动。
**门（含翻转）**：**旧 `items_pattern`（sfCC，`^(paris|lyon)$`）`items`→``**；新 `items_pattern_bad=[items]`（`^a.c$` 元字符对照）、`ap_alt=[codes|paris~lyon]`、`ap_alt_text`（每元素 ∈ 集合）、`ap_lit=[codes|paris]`（纯字面量单值）、`ap_bad=[]`（array_enum 对元字符返空）；F471 的 `ap_plan` 类形态不回退。
**活体**（`schema_required_check.py`）：`items_pattern_alt_refuse` 翻 `items_pattern_alt_ok`（200 每元素 ∈{paris,lyon}）；新 `items_pattern_metachar_refuse`（`^a.c$` → 400）；`items_pattern_ok`（类形态）不回退。
**验收**：①每元素必属有限集（门＋活体逐元素）②元字符/未锚定仍拒 ③F471 类形态与 F459 enum/const 不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F477 已完成**：`api_schema_array_enum` pattern 分支加锚定纯字面量交替（成员 `[A-Za-z0-9 _-]`、可选外层括号、`|`→`~`）→ enum 编码；单成员＝单值枚举；元字符/未锚定仍拒。门翻转 `items_pattern`（sfCC）`items`→``，新 `sf_itb=[items]`/`ap_alt=[cities|paris~lyon]`/`ap_alt_text`/`ap_lit=[cities|paris]`/`ap_bad=[]`。
**改前证据（`engine/schema_required_f477pre.log`，F476 二进制）**：items `^(paris|lyon)$` → 400；`^a.c$` 元字符对照 400（pattern 层措辞）；类形态 200 不回退。
**过程**：元字符对照断言首版写 `"items" in txt`，实际拒绝措辞为 pattern 层 "metacharacters"（仍 400）⇒ 放宽断言，重跑得干净改前证据。
**活体**：`items_pattern_alt_ok` 200 `[paris,paris]`、`items_pattern_metachar_refuse` 400、类形态不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.565×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f477.log`、`engine/schema_*_f477.log`、`engine/api_smoke_f477.log`、`engine/throughput_f477.log`）。
**顺位**：unevaluated* 真判定（需 additionalProperties/额外键能力，暂不可切片）→ 分片多卡 / FR-05（本机不可验证）。


## F478 计划（小数 multipleOf 的**单侧界**：min-only 取最小上倍数；max-only 取 0 或最大负倍数）
**F477 已完成**：数组项锚定纯字面量交替。
**核心思路**：F469 的小数 M 只支持**双侧 inclusive**；单侧时同一网格折叠即可——`minimum:L` 无上限 ⇒ `start=ceil(sL/sM)*sM`（无上界检查）；`maximum:H` 无下限 ⇒ 0 是任意 M 的倍数，若 `sH>=0` 取 0，否则取最大负倍数 `floor(sH/sM)*sM`（≤ sH）。均经既有小数 marker 写入器发射（子集论证）。
**支持边界**：number 叶、小数 M（≤5 位）、界为 inclusive 普通小数（≤5 位）、至少一侧；exclusive／无界小数 M／integer 叶仍拒（同 F469 边界）。
**改前状态（读码判定，执行前活体取证）**：`{"type":"number","minimum":1,"multipleOf":0.3}` → F469 要求双侧 ⇒ **400**（过度拒绝，合法解 1.2/1.5/…）；`maximum:0.2` / `maximum:-1` 同；无界 0.3 对照 400 正确。
**实现（F469 分支一处泛化）**：条件由 `hasLo&&hasHi` 放宽为"至少一侧且无 exclusive"；`d` 由**在场的**界＋M 计算；min 侧算 `ceil` 最小倍数；max-only 侧若 `sH>=0` 取 0 否则 `floor` 最大负倍数；有上界时校验 `start<=sH`。
**门（SCHEMA-STRUCT 新字段）**：`fm_minonly=[n|12|-999|0]`（min1 m0.3）、`fm_maxpos=[n|0|-999|0]`（max2 m0.3）、`fm_maxneg=[n|-12|-999|0]`（max-1 m0.3）＋三条文本 `{"n":1.2}`/`{"n":0.0}`/`{"n":-1.2}`；双侧 `fm_shape`/`fm_d2`/`fm_noint` 与 `fm_unsat`/`fm_intleaf` 不回退。
**活体**（`schema_bounds_check.py`）：`num_mult_frac_minonly_ok`（200 v≥1 且 %0.3≈0）、`num_mult_frac_maxonly_ok`（max0.2 → 200 v≤0.2 且倍数，即 0）、`num_mult_frac_maxneg_ok`（max-1 → 200 v≤-1 且倍数）；`num_mult_frac_refuse`（无界）与 integer 叶对照不回退。
**验收**：①单侧值在界内且是 M 的倍数（门＋活体）②无界/exclusive/integer 叶仍拒 ③F469 双侧与 F473 六位小数不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F478 已完成**：F469 分支泛化为"至少一侧 inclusive"（`d` 由在场界＋M 计算；min 取 ceil 最小倍数；max-only 取 0 或 floor 最大负倍数；上界校验保留）；门 `fm_minonly`/`fm_maxpos`/`fm_maxneg`＋三文本；双侧与 `fm_unsat`/`fm_intleaf` 不回退。
**改前证据（`engine/schema_bounds_f478pre.log`，F477 二进制）**：min1/max0.2/max-1 + m0.3 → 400；无界/integer 叶对照 400 正确。
**活体**：`num_mult_frac_minonly_ok` 200 `{"n":1.2}`、`num_mult_frac_maxonly_ok` 200 `{"n":0.0}`、`num_mult_frac_maxneg_ok` 200 `{"n":-1.2}`；对照不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.482×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f478.log`、`engine/schema_*_f478.log`、`engine/api_smoke_f478.log`、`engine/throughput_f478.log`）。
**顺位**：F478 → 其余值级子集（contains/not 字符串形态、patternProperties 自由字符串）→ 分片多卡 / FR-05（本机不可验证）。


## F479 计划（`not` 的**字符串常量/枚举成员字符集排除**形式：含发射字符集外字符 ⇒ 永不被发出）
**F478 已完成**：小数 multipleOf 单侧界。
**核心思路**：flat all-string 写入器的字符集是 `api_schema_charset()`（`a-zA-Z0-9 .,'-`，路由 `api_string_value_multi` 确用）。若字符串常量的（无转义）字面含**至少一个字符集外字符**，任何发射值都不可能等于它 ⇒ `not:{const:C}` 构造满足；枚举同理——**每个**成员要么是非字符串标量（F474 已证不等），要么是含外字符的字符串。**只判无转义字面**（`\` 转义成员解码值可能与原文本不同，保守拒绝）。
**支持边界**：flat all-string 形态；const 为无转义字符串且含外字符；enum 为"非字符串标量 ∪ 含外字符字符串"混合；全字符集的字符串常量/成员（如 "paris"）、空串、含转义成员、对象/数组成员、pattern 仍拒。
**改前状态（读码判定，执行前活体取证）**：`not:{const:"Paris!"}`（`!` 不在字符集）→ F474 只认非字符串标量 ⇒ **400 "not"**（过度拒绝）；`not:{enum:["a!","b@"]}` 同；`not:{const:"paris"}` 对照 400 正确（可被发出）。
**实现（not_ok 一处＋一 helper）**：新 `api_charset_covers($s)`（每字符 ∈ `api_schema_charset()`）；`api_schema_not_ok` 在 F474 分支后加：string const 正则（无 `\`）且 `api_charset_covers==0 ⇒ 1`；enum 安全 inner 正则（仅引号串/裸标量）逐成员判"裸标量 或 无转义且含外字符"全过 ⇒ 1；其余 0。
**门（SCHEMA-STRUCT 新字段）**：`nt_const_bang=[]`（`Paris!`）、`nt_enum_bang=[]`（`["a!","b@"]`）、`nt_enum_scalars=[]`（`[1,"a!"]`）、`nt_const_charset=[not]`（`paris` 对照）、`nt_enum_miss=[not]`（`["a!","paris"]`）、`nt_escaped=[not]`（`\u0061` 含转义 ⇒ 拒）；F474 的 `nt_const_num`/`nt_enum_num` 不回退。
**活体**（`schema_count_check.py`）：`not_const_bang_served`（200 字符串值）、`not_enum_bang_served`（200）、`not_enum_charset_miss_refuse`（400）；not_type/not_pattern/not_const_str 不回退。
**验收**：①服务形态值必为字符串且必不等于常量/枚举（字符集论证）②全字符集/混合可命中/转义仍拒 ③F448/F474 not 门不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F479 已完成**：`api_charset_covers`＋`api_enum_all_unequalled`（手写字符扫描）接入 `api_schema_not_ok`；**并修**扁平枚举路由把根级 `not` 块内枚举当值域发裸值的静默违约——抽 `api_schema_strip_root_not`，在 `api_response_schema_string_props` 与扁平枚举扫描两处统一剥离。门 `nt_const_bang`/`nt_enum_bang`/`nt_enum_scalars`=[]、`nt_enum_miss`/`nt_escaped`=[not]。
**改前证据（`engine/schema_count_f479pre.log`，F478 二进制）**：`not:{const:"Paris!"}`、`not:{enum:["a!","b@"]}` → 400；可命中成员对照 400。
**过程（环境前提）**：加入整块后 tpc 报 PHP 栈溢出（类型推断）；二分定位到主文件巨型断言链贴近默认栈。修复＝tpc php.ini 追加 `zend.max_allowed_stack_size = 64M`（repo 外，备份 php.ini.bak-f479）——**重建/换机须保留**。
**活体**：`not_const_bang_served`/`not_enum_bang_served` 200 `{"cities":"…"}`、`not_enum_charset_miss_refuse` 400；对照不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.570×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f479.log`、`engine/schema_*_f479.log`、`engine/api_smoke_f479.log`、`engine/throughput_f479.log`）。
**顺位**：F479 → patternProperties 自由字符串值级（需 plan/写入器扩展）→ 分片多卡 / FR-05（本机不可验证）。


## F480 计划（patternProperties 对 **pattern-constrained 字符串属性（kind `p:spec`）**：pp 的 pattern 生成同一 spec ⇒ 同时匹配）
**F479 已完成**：not 字符集排除形式。
**核心思路**：属性带受支持 pattern 时 plan kind 为 `p:spec`（`api_top_leaves`），写入器按 spec 构造发射、每值匹配该 pattern。若 patternProperties 匹配键的子 schema 为 `{"pattern":Q}` 且 `api_schema_pattern_spec(Q)` **等于**该 spec，则同一构造同时满足 Q ⇒ pp 构造满足。**判定复用 F457 的 leaf_guarantees 入口**，只需加 `p:` 分支。
**支持边界**：单 pp 条目（既有）；匹配键 kind 为 `p:spec`；子 schema 为 `{"pattern":Q}`（可带 `"type":"string"` 前缀）且 spec(Q)==spec(P)；更丰富子 schema / 不同 spec / 自由字符串 kind `s` / 数值 kind 仍拒。
**改前状态（读码判定，执行前活体取证）**：properties `{code:{"type":"string","pattern":"^[A-Z]{3}$"}}` + `patternProperties:{"^c":{"pattern":"^[A-Z]{3}$"}}` → `leaf_guarantees("p:c3xABC…", sub)` 返 0 ⇒ **400 "patternProperties"**（过度拒绝）；pp pattern 不同（`^[a-z]+$`）对照 400 正确。
**实现（leaf_guarantees 一处）**：顶部加 `p:` 分支——kind 为 `p:<specK>`；子 schema 正则取 pattern（`api_unesc` 解码），`api_schema_pattern_spec` 得 specS；`specS != "" && specS == specK ⇒ 1`，否则 0。patternProperties 判定本体零改动。
**门（SCHEMA-STRUCT 新字段）**：`pp_pat_same=[]`（同 spec）、`pp_pat_diff=[patternProperties]`（不同 spec 对照）；既有 `pp_pat`/`pp_pat_miss`（枚举 kind 的 pattern）与 `pp_enum`/`pp_num_*` 不回退。
**活体**（`schema_required_check.py`）：`patternprops_pattern_same_ok`（200 code 为 3 大写字母）、`patternprops_pattern_diff_refuse`（400 "patternProperties"）；既有 patternprops 系列不回退。
**验收**：①服务形态值必匹配两个 pattern（门＋活体正则）②不同 spec/自由字符串/更丰富子 schema 仍拒 ③F455/F457/F476 pp 门不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F480 已完成**：`api_schema_leaf_guarantees` 顶部加 `p:` 分支（pp 子 schema 的 pattern spec 与属性 kind 的 spec 相等 ⇒ 1）；patternProperties 判定本体零改动。门 `pp_pat_same=[]`、`pp_pat_diff=[patternProperties]`。
**改前证据（`engine/schema_required_f480pre.log`，F479 二进制）**：pattern 属性 `^[A-Z]{3}$` + pp 同 pattern → 400；不同 spec 对照 400 正确。
**活体**：`patternprops_pattern_same_ok` 200 `{"code":"EYX"}`、`patternprops_pattern_diff_refuse` 400；既有 pp 系列不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.528×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f480.log`、`engine/schema_*_f480.log`、`engine/api_smoke_f480.log`、`engine/throughput_f480.log`）。
**顺位**：F480 → 其余自由字符串/数组值级子集（需 plan/写入器扩展）→ 分片多卡 / FR-05（本机不可验证）。


## F481 计划（`contains` 对 **pattern-items 的同 spec** 形式：每元素由同一构造生成 ⇒ 全匹配）
**F480 已完成**：patternProperties 的 p: 同 spec。
**核心思路**：items 为受支持 pattern 时 `api_schema_array_enum` 返回 `prop|~spec`（F471），写入器按 spec 逐元素构造、数组恒 ≥1 元素；若 contains 子 schema 为 `{"pattern":Q}` 且 `api_schema_pattern_spec(Q)` 等于该 spec，则**每元素**都匹配 Q ⇒ contains（至少一匹配）构造满足。F472 当前对 `~` 前缀直接跳过。
**支持边界**：单字符串数组属性（kind a）、items 为受支持 pattern；contains 为同 spec 的 pattern（可带 `"type":"string"` 前缀）；不同 spec / 更丰富子 schema / 自由字符串 items 仍拒。
**改前状态（读码判定，执行前活体取证）**：items `^[A-Z]{3}$` + `contains:{"pattern":"^[A-Z]{3}$"}` → F472 见 `~` 前缀跳过 ⇒ **400 "contains"**（过度拒绝）；不同 spec（`^[a-z]+$`）对照 400 正确。
**实现（contains_ok 的 F472 分支一处）**：`$aep[1]` 以 `~` 开头时取 specK，子 schema pattern 用 `api_unesc` 解码后 `api_schema_pattern_spec` 得 specS，`specS != "" && specS == specK ⇒ 1`；否则维持枚举分支（leaf_guarantees("e:"+vals)）。判定本体零改动。
**门（SCHEMA-STRUCT 新字段）**：`cv_pat_spec=[]`（items 与 contains 同 spec）、`cv_pat_spec_diff=[contains]`（不同 spec 对照）；既有 `cv_const`/`cv_enum`/`cv_pat`（枚举 items）不回退。
**活体**（`schema_count_check.py`）：`contains_pattern_items_same_ok`（200 每元素 3 大写）、`contains_pattern_items_diff_refuse`（400）；既有 contains 系列不回退。
**验收**：①服务形态每元素匹配 contains（门＋活体逐元素）②不同 spec/自由字符串仍拒 ③F449/F454/F472 contains 门不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**F481 已完成**：contains_ok 的 F472 分支加 `~` spec 路径（子 schema pattern spec 与 items spec 相等 ⇒ 1）；枚举分支保持。门 `cv_pat_spec=[]`、`cv_pat_spec_diff=[contains]`。
**改前证据（`engine/schema_count_f481pre.log`，F480 二进制）**：items `^[A-Z]{3}$` + contains 同 pattern → 400；不同 spec 对照 400 正确。
**活体**：`contains_pattern_items_same_ok` 200 `[CJL,CJL]`、`contains_pattern_items_diff_refuse` 400；既有 contains 系列不回退。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.497×**（单次测量，如实记录）、哨兵 **0**（`engine/selftest_f481.log`、`engine/schema_*_f481.log`、`engine/api_smoke_f481.log`、`engine/throughput_f481.log`）。
**顺位**：F481 → 其余自由字符串/数组值级子集 → 分片多卡 / FR-05（本机不可验证）。


## F482 计划与完成（`not:{pattern:P}` 的字符集排除形式：锚定纯字面量交替且每成员含字符集外字符）
**核心思路**：值来自 `api_schema_charset()`；P 为锚定纯字面量交替且每成员含外字符（如 `_`/`!`）⇒ 无发射值匹配 P ⇒ not 构造满足。pattern 层接受这类字面量（探测：`!`/`_` 到 not 层，元字符到 pattern 层）。
**改前证据**：`not:{pattern:"^(paris_|lyon_)$"}` → 400（`schema_count_f482pre.log`，F481 二进制；`serve_f482probe.log` 探测）。
**实现**：not_ok 加 pattern 分支（子 schema pattern 无 `\`；成员类 `[A-Za-z0-9 _!@%&,:;=?/-]`；每成员 `api_charset_covers==0` 全过 ⇒ 1）。
**门（缩减）**：仅 `nt_pat_bang=[]`（`^(paris_|lyon_)$`）。计划中的 `nt_pat_under/in/meta` 未入库——完整门集会让 **tpc 转换器静默中止**（exit 0、不编译、批处理用旧对象仍报 Build successful）⇒ 自检假绿；靠 `main.obj` mtime 诊断。php.ini 栈限 64M→256M（未能消除）；半删缓存会缺 rsp，需清整个 `build/gen/cache`。
**活体**：`not_pattern_bang_served` 200、`not_pattern_charset_refuse`/`not_pattern_refuse` 400。
**梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.525×**、哨兵 **0**。
**记法**：**"Build successful" ≠ 二进制已更新**——核对 `main.obj` mtime 与"Successfully compiled"行。
**顺位**：其余自由字符串/数组值级子集 → 分片多卡 / FR-05（本机不可验证）。


## F483 计划与完成（构建守卫：拦截 tpc 静默中止导致的陈旧二进制）
**动机**：F482 发现 tpc 可能静默中止转换（exit 0、不编译）而批处理仍报 Build successful，自检跑旧二进制。
**实现**：`scripts/build-check.sh`——清 ast、删 main.obj 强制重译、断言 mtime 前进；失败/陈旧非 0 退出。
**验证**：`build_f483a` → BUILD_OK（11 文件编译）；新建二进制自检 **182/0**（`engine/selftest_f483.log`）。
**用法**：后续一律 `bash scripts/build-check.sh <tag>`。


## F484 计划与完成（patternProperties：对象数组 kind `ao:` 的 bare `{"type":"array"}`）
**核心思路**：plan 对对象数组属性编码 `ao:<key>`（`api_top_leaves`），但 `api_schema_kind_is_type` 的 array 分支只认 `a/an/af/ab`，`ao:` 被当成不可证 ⇒ pp bare `{"type":"array"}` 过度拒绝。对象数组就是 array，加前缀判定即可。
**改前状态**：properties `rows:{type:array,items:{type:object,properties:{n:{type:string}}}}` + `patternProperties:{"^r":{"type":"array"}}` → **400 "patternProperties"**（执行前活体取证）。
**实现**：`api_schema_kind_is_type` 的 array 分支加 `substr($k,0,3)=="ao:"`。
**门（单个）**：`pp_ao_type=[]`（对象数组 + pp bare array）。
**活体**：`patternprops_ao_type_ok`（200 rows 为对象列表）、`patternprops_ao_str_refuse`（pp `{"type":"string"}` → 400 对照）。
**验收**：①服务形态 rows 为对象数组 ②类型不符仍拒 ③既有 pp 门不回退 ④自检 182 起、四 harness＋8000 115/0＋8001 不回退。
**用法**：构建走 `scripts/build-check.sh`，单门小步以规避 tpc 转换器容量限制。
**F484 已完成**：`api_schema_kind_is_type` 的 array 分支加 `substr($k,0,3)=="ao:"`；门 `pp_ao_type=[]`。
**改前证据**：对象数组属性 + pp bare array → 400（`schema_required_f484pre.log`，F483 二进制）。
**活体**：`patternprops_ao_type_ok` 200 `{"rows":[{"n":"100000"}]}`、pp string 400；四 harness PASS；8000 115/0、8001 par/fairness 0、scaling 1.544×、哨兵 0；构建 BUILD_OK（11 文件）。


## F485 计划与完成（contains + 显式 minContains:0 且无 maxContains：空判定）
**核心思路**：数组属性模型下，`contains` 要求至少 minContains 个元素匹配；显式 `minContains:0` 允许 0 个匹配 ⇒ 约束恒成立，与子 schema 及 items 无关。存在 `maxContains`（匹配数上界）时仍需判定，保持拒绝。
**改前状态**：`{properties:{cities:[string]},contains:{pattern:"^x"},minContains:0}` → 400（contains 值级不认 + minmax 需 array bounds）。
**实现**：`api_schema_contains_ok` 顶部加 `minContains:0 && 无 maxContains ⇒ 1`；`api_schema_minmax_contains_ok` 顶部同条件 ⇒ 1（不需 array bounds）。
**门（单个）**：`cont_min0=[]`。
**活体**：`contains_min0_served`（200 数组）、`contains_min0_max_refuse`（maxContains 存在 → 400 "minContains"）；既有 contains 系列不回退。
**用法**：`scripts/build-check.sh` 全量构建（F486 瘦身后容量已恢复）。
**F485 已完成（第二轮）**：两处早退（contains_ok/minmax_contains_ok）＋门 `cont_min0=[]`；**并修** `api_response_schema_array_string` 把 root `contains` 内 pattern 当值域导致的 200 散文（根级 contains 块剥离）。活体 200/400 各一；自检 **182/0**、四 harness PASS、8000 115/0、8001 par/fairness 0、scaling 1.552×、哨兵 0；构建 BUILD_OK（11 文件）。第一轮的 tpc 静默中止由 F486 切块解决。


## F487 计划与完成（contains：minContains:0 + maxContains:0 且 contains 与元素集合不相交 ⇒ 零匹配可证）
**核心思路**：`minContains:0 && maxContains:0` 要求**恰好 0 个匹配**。若能证明**没有任何可能元素**匹配 contains，则约束恒成立：①contains 为 bare type 且 items 种类不可能产出该 type（如 string items vs integer）；②items 为有限字符串 enum/const，contains 为无转义 string const/enum 且**与枚举值集合交集为空**。
**改前状态**：上述形态 → 400（contains_ok 走子集判定，不相交 ⇒ 0）。
**实现（一 helper＋两处早退＋单门）**：`api_schema_contains_all_miss($schema)` 做上述证明；`api_schema_contains_ok`/`api_schema_minmax_contains_ok` 顶部加 `min0 && max0 && all_miss ⇒ 1`（置于 F485 的 min0 分支之后）。
**门**：`cont_max0=[]`（items enum [paris,lyon] + contains const nice，min0/max0）。
**活体**：`contains_max0_served`（200 元素 ∈{paris,lyon}）、`contains_max0_overlap_refuse`（contains const paris → 400）、`contains_max0_type_ok`（items string + contains type integer：类型不匹配即零匹配 → 200）。
**用法**：`scripts/build-check.sh` 全量构建；容量已由 F486 恢复，仍按单门小步。
**F487 已完成**：`api_schema_contains_all_miss`（bare type 不可能 / 枚举值表与 contains 不相交）＋contains_ok/minmax_contains_ok 两处早退＋门 `cont_max0=[]`；**过程**：首版误用 plan kind 判枚举（数组 kind 是 a/an/af）→ 门 181/1，改用 `api_schema_array_enum` 后 182/0。活体 200/200/400；构建 BUILD_OK；全梯绿（8000 115/0、8001 par/fairness 0、scaling 1.536×、哨兵 0）。


## F488 计划与完成（propertyNames 扩到 mixed-omittable 形状；mixed 扫描剥离 propertyNames 块）
**核心思路**：propertyNames 判**键名**；mixed 形状的已发键 ⊆ 声明键（`api_top_leaves` 的计划），故对**全部声明键**做超集检查即可证明每个已发键满足。障碍：`api_schema_mixed_omittable` 的 `(enum|const|pattern|…)` 扫描把 **propertyNames 块内的 key-pattern** 当成值约束而拒绝（F429 在 string_props 已用剥离解决同一问题）。
**实现**：①`api_schema_mixed_omittable` 扫描前剥离 propertyNames 块；②`api_schema_property_names_ok` 的守卫从 `flat_all_string==1` 放宽为 `flat_all_string==1 || mixed_omittable==1`。
**门（单个）**：`pnames_mixed_ok=[]`（properties a:string,b:integer + propertyNames pattern ^[ab]$）。
**活体**：`propertynames_mixed_ok`（200 键 a,b）、`propertynames_mixed_bad_refuse`（pattern ^a$，b 违反 → 400）。
**用法**：`scripts/build-check.sh`；单门小步。
**F492 已完成**：additional_properties_honoured 去掉值形限制＋根级守卫；门翻转 `addprops_form`/`addprops_form_arr`，新 `addprops_schema=[]`。过程：首轮只翻 kwW → 181/1，补翻 kwAB 后 182/0。活体 schema 形式 200；全梯绿。
**F489 已完成**：dependent_required_ok 守卫放宽（flat||mixed；mixed 生成器精确发键）。门翻转 `dep_mixed` `dependentRequired`→``，新 `dep_mix_bad=[dependentRequired]`。活体 200/400；构建 BUILD_OK；全梯绿。
**F488 已完成**：mixed_omittable 扫描前剥离 propertyNames 块；property_names_ok 守卫放宽（flat||mixed）。门翻转 `pnames_mixed` `propertyNames`→``（旧对照被证伪：键名 city/count 满足 ^[a-z]+$），新 `pnames_mix_bad=[propertyNames]`。活体 200/400；构建 BUILD_OK；全梯绿（8000 115/0、8001 par/fairness 0、scaling 1.564×、哨兵 0）。


## F489 计划与完成（dependentRequired 扩到 mixed-omittable：键集精确性由 mixed 生成器保证）
**核心思路**：`api_response_schema_mixed_object` 明确"生成器只发计划里的键"，并按 `required_filter` 精确裁剪（无 required 则发全部声明键）⇒ mixed 形状的**已发键集是精确的**，dependentRequired 的"trigger 在则 dep 必在"检查与 flat 同构。F432 的 `flat_all_string` 守卫是保守遗漏。
**实现**：`api_schema_dependent_required_ok` 守卫放宽为 `flat_all_string==1 || mixed_omittable==1`（体内 emit 计算已走 required_filter/全计划，无需改）。
**门（单个）**：`dep_mixed=[]`（a:string,b:integer,c:string + dependentRequired a→c）。
**活体**：`dependent_required_mixed_ok`（200 三键）、`dependent_required_mixed_bad_refuse`（a→z 未声明 → 400）。
**用法**：`scripts/build-check.sh`；单门小步。


## F490 计划与完成（dependentSchemas 与 dependencies 一并扩到 mixed-omittable）
**核心思路**：与 F489 同一前提——mixed 生成器只发计划键（required 时精确裁剪）⇒ 键集精确；`dependentSchemas` 的 bare-required 形式与 `dependencies` 的数组/裸 required 对象形式都只做键集包含检查，可安全放宽。
**实现**：`api_schema_dependent_schemas_ok`、`api_schema_dependencies_ok` 守卫由 `flat_all_string==1` 放宽为 `flat||mixed_omittable`（体内键集计算已走 required_filter/全计划）。
**门**：`dep_sch_mix=[]`、`dep_deps_mix=[]`（mixed a:string,b:integer + 分别 dependentSchemas a→required[b] / dependencies a→[b]）。
**活体**：`dependent_schemas_mixed_ok`/`dependencies_mixed_ok`（200 双键）、各自 bad（依赖未声明 → 400）。
**用法**：`scripts/build-check.sh`；本轮两门仍在瘦身后容量内。
**F490 已完成**：dependent_schemas_ok / dependencies_ok 守卫放宽（flat||mixed）。门 `dep_sch_mix=[]`、`dep_deps_mix=[]`；活体两形态 200 双键、两 bad 400；构建 BUILD_OK；全梯绿（8000 115/0、8001 par/fairness 0、scaling 1.562×、哨兵 0）。


## F491 计划与完成（minProperties/maxProperties 扩到 mixed-omittable）
**核心思路**：键数与 dependentRequired 同源的精确性——mixed 生成器只发计划键（required 时精确裁剪），`api_schema_required_filter`/`api_schema_plan_count` 已给出精确计数 ⇒ 越界才拒。
**实现**：计数分支的 `flat_all_string==1` 条件放宽为 `flat||mixed_omittable`（计数来源不变）。
**门（含翻转）**：旧 `mixed`（a:string,b:integer + minProperties:1）**`minProperties`→``**；新 `pc_mix_min=[minProperties]`（min3，2 键）、`pc_mix_max=[maxProperties]`（max1，2 键）。
**活体**：`props_mixed_min_ok`（200 双键）、`props_mixed_min_refuse`/`props_mixed_max_refuse`（400）。
**用法**：`scripts/build-check.sh`；三门在瘦身后容量内。
**F491 已完成**：计数分支放宽为 flat||mixed（键数精确）。门翻转 `mixed` `minProperties`→``，新 `pc_mix_min`/`pc_mix_max`；活体 200/400/400；构建 BUILD_OK；全梯绿（8000 115/0、8001 par/fairness 0、scaling 1.561×、哨兵 0）。


## F492 计划与完成（additionalProperties 的 schema 值形式：真空满足）
**核心思路**：写入器只发声明键；additionalProperties 只判**未声明键**，因此该关键字判不到任何键——布尔值与 schema 值都成立。F402 只接受 true/false，schema 值被过度拒绝。
**实现**：`api_schema_additional_properties_honoured` 去掉值形限制（保留"恰一次出现"与根级判定）；其余"每叶 kind 可计划命名"的证明不变。
**门（单个）**：`addprops_schema=[]`（a:string,b:string + additionalProperties:{"type":"integer"}）。
**活体**：`addprops_form_with_arr_bounds`（schema 形式 + maxItems）翻为 `addprops_schema_served`（200 keys=[cities]）；`addprops_enum_refuse` 保持拒绝对照。
**用法**：`scripts/build-check.sh`；单门小步。


## F493 计划与完成（minLength/maxLength 扩到 mixed：mixed 写入器按叶加长度预算）
**核心思路**：flat 写入器已有长度实现（F399：按**字符数**计数、min 前不提供闭引号、max 时按剩余宽度过滤可加 token、闭引号 token 须解码为单个 `"`）。mixed 写入器的字符串叶（kind s）原样复用同一逻辑（根级 minLength/maxLength 对所有字符串值生效，单对界限即可）。
**实现**：①`api_object_mixed` 增 `$bounds` 参数（"min:max"）；s 叶改为有界步进（含 closeIds 过滤）；②路由调用传入 `api_response_schema_string_bounds($body)`；③关键字守卫 `minLength/maxLength` 由 flat-only 放宽为 `flat||mixed_omittable`。
**门（含翻转）**：`mixed_maxLength`（mixed a:string,b:integer + maxLength）`[maxLength]`→``；新增 `mixed_len_text` 不便（需会话）⇒ 以活体断言长度为准。
**活体**：`mixed_len_ok`（minLength:3 maxLength:8 → 200，a 长度 3..8、b 为整数）、`mixed_len_pattern_refuse`（含 p: 叶的混合形状 + minLength → 400，mixed 写入器不认领）。
**用法**：`scripts/build-check.sh`；写入器改动，跑全梯。
**F493 已完成**：api_object_mixed 增 bounds 并有界 s 步进（按字符预算、closeIds 过滤）；4 处路由传参；守卫放宽为 flat||mixed。门翻转 `mixed_maxLength`/`mixed_refusal`→``；活体 mixed 200 长度在界、pattern 叶 400；构建 BUILD_OK；全梯绿（8000 115/0、8001 par/fairness 0、scaling 1.480×、哨兵 0）。


## F494 计划与完成（minLength/maxLength 与定长 pattern 叶：界限包含固定长度即成立）
**核心思路**：pattern 叶（plan kind `p:spec`）由 spec 构造发射；若**每个段都精确**（字面量或 `c<n>x…` 无范围），发射长度 L 固定。该属性的 minLength/maxLength 只需**包含** L，写入器天然满足——零写入器改动，只放宽关键字守卫。
**实现**：新 helper `api_schema_pattern_length_ok`（单叶 p:、逐段求定长、取该属性 schema 内的 min/maxLength 并核对包含）；`api_schema_keywords_unsupported` 的 minLength/maxLength 分支在 flat||mixed 之外再加该判定。
**门**：`p_len_ok=[]`（`^[A-Z]{3}$` + min3/max8）、`p_len_bad=[minLength]`（minLength:4 > 3）、`p_len_range=[minLength]`（`^[A-Z]{2,4}$` 非定长）。
**活体**：`pattern_len_ok`（200 三字母）、`pattern_len_bad_refuse`/`pattern_len_range_refuse`（400）。
**用法**：`scripts/build-check.sh`；无写入器改动。
**F494 已完成**：`api_schema_pattern_length_ok`（定长 spec＋界限包含）接入关键字分支；门 `p_len_ok=[]`、`p_len_bad`/`p_len_range`=[minLength]；活体 200/400/400；构建 BUILD_OK；全梯绿。


## F522 计划与完成（顶层标量 enum 数组：`#` 有限值表 + 标量 enum 分支让位）
**核心思路**：根数组 `{"type":"array","items":{"type":"integer","enum":[…]}}`（无 properties 包装）此前被 `items` 点名拒绝。有限值表编码（`#v1~v2`）与裸字面量写入器适用于根数组；uniqueItems/minItems 由有限域规则继承。
**实现**：检测器 `api_schema_top_array_scalar_enum`（body/裸 schema 两用）、`api_schema_top_bounds`、写入器 `api_top_scalar_enum_value`、四处分发插入（流式、8001、`api_serve` 两条）、items/minItems/uniqueItems 守卫豁免。
**根因（判别量定位）**：非流式请求被 `$enumFlat` 分支吞掉——该分支只要 body 里出现**任意** `"enum":[…]`（包括 items 内的）就把它当成“单值 enum 答案”，返回一个元素 `2`。给它加 `api_response_schema_top_array_scalar_enum($body) == ""` 排他后，请求落到本路线，非流式返回 `[2,5,9,2]`、日志 `json=ok`。
**过程（一次回退 + 判别量）**：首版只接线路由，非流式仍返回自由文本 `2`（400 被换成静默违反）⇒ 全部回退并留证；随后加 `DBG_TOPSCALAR/DBG_TS_*` 判别量，证明链未到达/被吞，定位 `$enumFlat` 后再接线。
**门**：`top_scalar_enum=[#2~5~9]`、`top_scalar_const=[#true]`、`SCHEMA-TOPARR-SCALAR text=[[2,5,9,2]]` PASS。
**活体**：`top_num_enum_ok` 200 `[2,5,9,2]`、`top_scalar_enum_unique_ok` 200 `[2,5,9]`、`top_bool_enum_ok` 200 `[false]`、`const+unique+min2` 400。
**梯**：自检 **182/0**（`selftest_f522b.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.531×**；哨兵 **0**；BUILD_OK（`build_f522b`）。


**核心思路**：F520 的 `#` 裸字面量编码天然容纳 `true/false`；把检测器的标量 enum/const 分支扩到 `boolean`、kind 放宽到 `ab`，uniqueItems/minItems 自动继承（域 {true,false} 必须覆盖 minItems）。
**实现**：enum 分支类型正则加 `boolean`、值校验加 true/false；const 分支字面量加 `(true|false)`；kind 检查加 `ab`。
**门**：`bool_enum_items=[flags|#true~false]`、`bool_const_items=[flags|#true]`。
**活体**：`items_bool_enum_ok` 200 `{"flags":[false,false,false,false]}`（无 unique 允许重复）、`items_bool_enum_unique_ok` 200 `{"flags":[true,false]}`（域耗尽收尾）、`items_bool_const_ok` 200 `{"flags":[true,true,true,true]}`。
**梯**：自检 **182/0**（`selftest_f521.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.556×**；哨兵 **0**；BUILD_OK（`build_f521`）。


**核心思路**：字符串 enum items 已有写入器（F459/F470）；数值 enum/const 是同一类有限值表，只是元素要发**裸数字**。用 plan 前缀 `#` 区分：`nums|#2~5~9`，写入器剥掉 `#` 后按裸字面量逐个强制。uniqueItems 与 minItems 随之继承（F517 的机制：值域可枚举）。
**实现**：①`api_schema_array_enum` 承认 kind `a/an/af`，新增数值 enum/const 两个分支（plain decimal 校验、`#` 前缀）；②写入器识别 `#`：head 不带开引号、元素间不补开引号、candidate 为裸字面量；③items 守卫与 minItems/uniqueItems 守卫因检测器非空自动放行。
**过程（三处形状教训）**：宽松 kind 检查先只放 `a` ⇒ `items` 仍点名拒绝；放宽到 an/af 后输出 `{"nums":["2,"5,"9,"2]}`——字符串数组的引号假设还在 head 与元素分隔里；修正后 182/0。
**门**：`num_enum_items=[nums|#2~5~9]`、`num_const_items=[nums|#7]`、`num_enum_uniq=[]`。
**活体**：`items_num_enum_ok` 200 `{"nums":[2,5,9,2]}`；`items_num_enum_unique_ok` 200 `{"nums":[2,5,9]}`（互异）；`items_num_const_ok` 200 `{"nums":[7,7,7,7]}`；`const+unique+min2` 400 uniqueItems。
**梯**：自检 **182/0**（`selftest_f520e.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.528×**；哨兵 **0**；BUILD_OK（`build_f520e`）。


**结论**：F470（const→单值 enum）与 F477（锚定纯字面交替→enum 计划）产出的都是非 `~` 前缀的有限值表，因此 F517 的去重写入器**自动覆盖**：`{"codes":["paris"]}`（单值域，隐式 maxItems 4 但域耗尽收尾）、`{"cities":["paris","lyon"]}`（交替两值互异）；`const + minItems:2` 因域不足仍点名拒绝。
**变更**：仅 harness 三例，无引擎改动；二进制同 F518。
**记法**：值域是否可枚举由**检测器产出的计划形态**决定（`~` 前缀=pattern 行走），不是由 JSON 关键字决定——新增有限域来源会自动继承 uniqueItems。


**结论**：items-multiple 写入器本就消费 `api_response_schema_array_bounds`，`minItems:3/maxItems:3` 的整数 multipleOf 数组返回恰好 3 个 5000 的倍数（`items_mult_min_ok`）；`uniqueItems:true` 对这类**同值折叠**数组仍点名拒绝（每个元素都是同一折叠值，去重必然违反；enum 有限域之外不服务）。
**变更**：仅 harness 增两例（一正一拒），无引擎改动；二进制与 F517 相同，bounds harness `CHECK_EXIT=0`，其余三 harness 与 gate_live 沿用 F517 结论。
**记法**：同值折叠写入器与 `uniqueItems` 天然冲突——支持面必须按“值域是否可枚举”划线，而不是按数组类型。


**核心思路**：`uniqueItems` 约束元素取值；当 items 是**有限 enum** 时可以把值域枚举出来，按声明顺序逐个强制，互异性即构造满足。域大小必须覆盖 `minItems`，否则该约束不可满足、保持拒绝。pattern items（无界值域）仍拒绝。
**实现**：①`api_response_schema_array_enum` 接受 body 或裸 schema；新增 `api_schema_unique_true`；②`api_array_enum_value` 增 `$unique`：去重值表、`bMin > 域大小` 时返回空、元素按序强制、域耗尽即收尾；4 个分发调用点传入标志；③关键字守卫：`uniqueItems:true` 在 enum 域且 minItems 可满足时放行，否则点名拒绝；同时放宽 `minItems/maxItems` 到 enum-array 路线（其写入器本就消费 bounds）。
**门**：新 `uniq_enum=[]`、`uniq_enum_unsat=[uniqueItems]`（min4>3）、`uniq_enum_pat=[uniqueItems]`；`uniq_true`（普通 string items）/`uniq_false` 不回退。
**活体**：`uniq_enum_ok` 200 `{"cities":["paris","lyon","nice"]}`（三个互异、长度 3∈[2,3]）；unsat 与 pattern 均 400 点名 uniqueItems。
**梯**：自检 **182/0**（`selftest_f517b.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.468×**；哨兵 **0**；BUILD_OK（`build_f517b`）。
**过程**：首轮 `uniq_enum=[minItems]`——minItems 在关键字序里先被判定，enum 路线未被 `api_schema_array_element_bounds` 认领；放宽后 182/0。


**背景**：F514 留下的规则——"宽度扩展必须同步扩宽 marker 窗口"。本轮验证该规则：九位小数（-991）落地时把窗口守卫从 `[-999,-992]` 扩到 `[-999,-991]`，并加 `win_nine` 门钉住"maximum:-991 必须拒绝"。
**实现**（全部宽度站点）：写入器 marker 表（d=9, scale=10^9）、两处 marker 判定、`api_schema_scaled` 正则与 `digits==9` 分支、分子上限 9、multipleOf 分支 5 个字面正则与 marker 表、无乘数回退的 d 探测与 marker 表、mixed q marker 范围、数组项 marker 判定、窗口守卫。
**门**：新 `num_dec9=[n|1511111111|-991|0]`、`fm_d9=[n|246912|-991|0]`、`win_nine=[]`；1–8 位全部不回退。
**活体**：`float_dec9_served` 200 `{"n":1.511111111}`、`num_mult_frac_d9_ok` 200 `{"n":0.000246912}`、`marker_window_nine_refuse`（integer maximum:-991）400。
**梯**：自检 **182/0**（`selftest_f516.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.548×**；哨兵 **0**；BUILD_OK（`build_f516`）。
**注**：F515（候选枚举层四位负值截断）仍按原顺位挂账，与本轮独立。


**核心思路**：写入器的 hi 槽自 F441 起复用为小数网格 marker（-999…-992），整数计划的真实上界走同一槽——窗口内的 maximum 把写入器带进 marker 分支发出缩放小数。改前活体证据（F513 二进制，`probe_f514pre.log`）：[-2000000,-992] 宽跨 200 `{"n":-0.02000000}`、单侧 max=-992 200 `{"n":-0.00000001}`、multipleOf=992 塌缩 200 `{"n":-0.00000992}`——类型与上界双双违反。
**实现**：`api_schema_number_bounded_shape` 整数返回（仅该处）前加窗口守卫 `hi∈[-999,-992] ⇒ ""`（请求层 400）；窄跨窗口区间由候选枚举层先行认领不受影响，-1000 及以下幅值步进不变，小数/marker 分支在守卫前返回不受影响；未来宽度扩展须同步扩窗。
**门**：新 `win_wide`/`win_one`/`win_mult`/`win_narrow_shape` 全 ""、`win_below=[n|-2000000|-1000|0]`；活体 `marker_window_{wide,one,mult}_refuse` 400、`marker_window_narrow_ok` 200（[-998,-992] 候选层服务）、`marker_window_below_ok` 200。
**过程**：narrow 控制原用 [-1000,-992]，活体暴露**另一既有缺陷**——候选枚举层对 ≥4 位负值发截断 JSON（`{"n":-1000`，200，json=bad），同宽正值完整、≤3 位负值完整；tokenize oracle 证实 `":-`=52052 单 token、walk 末步 next_allowed 连败 ⇒ 与挂账的 F228"首调空返回/整行 NaN"同族，控制改 [-998,-992]，缺陷升为排队首位（F515，`probe_f514b/c.log` 留证）。
**梯**：自检 **182/0**（`selftest_f514.log`，`: FAIL` 0，首轮即绿）；四 harness **PASS**（`schema_*_f514.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.539×**；哨兵 **0**（`gate_live_f514.log`）；BUILD_OK（`build_f514`）。


## F513 计划与完成（小数位扩到八位：新 marker -992，一轮全绿）
**核心思路**：F512 的宽度位点清单原样推进一位——marker -992（d=8、scale=10^8）；位点：`api_schema_scaled` 正则与 `digits==8` 分支、`api_schema_multiplier_numerator` 位数上限 8、乘数分支字面正则（乘数＋四界）、bounds-only 分支字面正则＋`\.[0-9]{8}` 检测、两处 marker 表、`api_number_decimal_element`、两个写入器 marker 门、mixed q 分支 marker 区间（`mk+1000` 通用）。
**改前证据**：活体探针（F512 二进制）对 8 位界与 8 位乘数均 400（`schema_bounds_f513pre.log`），d=7 对照 200 不变。
**门**：`num_dec8` `""`→`[n|151111111|-992|0]`（自检 nfS）；新 `fm_d8=[n|24690|-992|0]`（乘数与界两个宽度门同验）。
**活体**：`float_dec8_served` 200 `{"n":1.51111111}`、`num_mult_frac_d8_ok` 200 `{"n":0.00024690}`、`items_num_dec8_ok` 200（数组项写入器 -992 门首次有活体覆盖）；1–7 位不回退。
**过程**：位点按 F512 清单一次改全，自检首轮即 182/0——F450/F511/F512 三轮的“宽度多处同改”教训兑现为清单化打法。非代码坑：直跑 exe 缺 oneAPI PATH（`libmmd.dll`）会 exit 127，自检必须带 gate_live 同款 PATH 导出。
**梯**：自检 **182/0**（`selftest_f513.log`，`: FAIL` 0、`RUN_EXIT=0`）；四 harness **PASS**（`schema_*_f513.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.523×**；哨兵 **0**（`gate_live_f513.log`）；BUILD_OK（`build_f513`）。


## F512 计划与完成（小数位扩到七位：新 marker -993，宽度位点一次改全）
**核心思路**：沿用 F450 的宽度阶梯打法——新 marker -993（d=7、scale=10^7）；位点包括 `api_schema_scaled` 正则与分支、`api_schema_multiplier_numerator` 位数上限、乘数分支字面正则（乘数＋四个界，d 按字面长度推导）、bounds-only 分支字面正则＋`\.[0-9]{7}` 检测、两处 marker 表、`api_number_decimal_element`、两个写入器 marker 门、mixed q 分支 marker 区间（还原公式 `mk+1000` 通用）。
**实现**：以上位点同轮全部落改；写入门 `api_number_bounded_value`/`api_array_item_number_value` 加 -993；mixed 检测器接受 `mk∈[-999,-993]`。
**门（含翻转）**：`num_dec7` `""`→`[n|15111111|-993|0]`；新 `fm_d7=[n|2468|-993|0]`（乘数与界两个宽度门——单位宽用例不能证明宽度映射）。
**过程**：首轮自检两 FAIL（`num_dec7=[n|1|-993|0]` 折叠错、`fm_d7=[]` 拒）——宽度位点仍有漏改；补齐后二轮 182/0。“宽度多处同改”教训第三次复现。
**活体**：`float_dec7_served` 200 `{"n":1.5111111}`（原 `float_dec7_refuse` 翻转）、`num_mult_frac_d7_ok` 200 `{"n":0.0002468}`；1–6 位与负数不回退。
**梯**：自检 **182/0**（`selftest_f512b.log`，`: FAIL` 0）；四 harness **PASS**（`schema_*_f512b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.487×**；哨兵 **0**（`gate_live_f512.log`）；BUILD_OK（`build_f512b`）。


## F512 计划与完成（小数位宽扩到 7 位：新 marker -993）
**核心思路**：小数写入器本就是“marker → (d, scale)”表，扩一位只需同步**全部**宽度站点（F450/F502 的教训）。七处一次改全：写入器 marker 表、两处 marker 判定、`api_schema_scaled`（**第四处隐藏站点**：digits if 链只到 6，7 位时退化为整数部分——首轮自检 `num_dec7=[n|1|…]` 正是它）、形状小数分支的 5 个字面正则与 marker 表、无乘数回退的 d 探测/marker 表、mixed 的 q marker 范围、分子解析上限。
**实现**：以上各站点 `{1,6}`→`{1,7}`、`d==7 → -993`、scale=10^7。
**门（含翻转）**：`num_dec7` `[]`→`[n|15111111|-993|0]`；新 `fm_d7=[n|2468|-993|0]`（M=0.0001234、min 0.0002 的最小平倍数 0.0002468）。
**活体**：`float_dec7_served` 200 `{"n":1.5111111}`（原拒绝用例翻转）、`num_mult_frac_d7_ok` 200 `{"n":0.0002468}`；1–6 位与既有族不回退。
**梯**：自检 **182/0**（`selftest_f512b.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.546×**；哨兵 **0**；BUILD_OK（`build_f512b`）。


**核心思路**：F510 只编码整数常量。number 叶 + 小数乘数的折叠结果是“缩放值 + marker”，把 marker 还原成位宽 d、再还原成字面小数（含符号与零填充），即可用同一个 `q` 分支发出——至此 mixed 内 multipleOf 的最后一个拒绝面闭合。
**实现**：检测器 q 分支区分 marker（-999…-994）与整数折叠：marker 时 `d = mk + 1000`、`pow=10^d`、`lit = sign + intdiv(|v|,pow) + "." + zeropad(|v|%pow, d)`，编码 `name:q:<lit>`；写入器无需改动（q 分支发字面量）。
**门（含翻转）**：`mix_q_bad` `[]`→`[city:s,count:q:1.2]`；新 `mix_q_unsat=[]`、`mix_q_d2=[city:s,count:q:1.25]`（**2 位小数**，专门覆盖位宽公式）。
**过程**：首版位宽公式写成 `-998 - mk`（对 -994 得负数），d=1 恰好正确因而首轮自检通过；发现后改为 `mk + 1000` 并**新增 d=2 门**重建复验——单位宽用例不能证明位宽映射正确。
**活体**：`mixed_mult_frac_bounded_ok` 200 `{"city":"…","count":1.2}`、`mixed_mult_frac_d2_ok` 200 `{"city":"…","count":1.25}`、`mixed_mult_frac_unsat_refuse` 400；number 叶不被候选层枚举，故 mixed 写入器同时发出 city。
**梯**：自检 **182/0**（`selftest_f511b.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.526×**；哨兵 **0**；BUILD_OK（`build_f511b`）。


**核心思路**：F509 只覆盖无界叶（发 0）。有界叶若其 `multipleOf` 折叠**塌缩为唯一整数常量**（单属性折叠的既有论证），则该常量同时满足界与乘数，可编码进 plan 由 mixed 写入器直接发出。
**实现**：①plan 新增 3 段 kind `q:<常量>`，`api_object_mixed` 的 kind 解析与分支发出该字面常量；②检测器把 `minimum|maximum` 从全局排除改为**逐叶**判定：带数值界的 i/n 叶必须有 multipleOf 且折叠结果 `lo==hi`、乘数为正整数、且不是小数 marker（-999…-994），否则整体拒绝；无乘数的数值界一律拒绝（顺带堵上“只有 exclusive 界”的旧静默口子）；③`api_schema_bounds_unsupported` 在 items 早退后加“mixed 检测器认领即放行”；④required 白名单加 `q`。
**门（含翻转）**：新 `mix_q_plan=[city:s,count:q:3]`、`mix_q_bad=[]`（number+小数 M 的网格折叠不可编码）、`mix_excl_bad=[]`（无乘数的 exclusive 界）；F509 的 `mix_z_bad` 被证伪 ⇒ 期望改为 `city:s,count:q:3`。
**活体**：`mixed_mult_bounded_ok` 200 `{"count":3}`（city 可选，候选层只发枚举键，3 是 0.3 的倍数）；`mixed_mult_bounded_allreq_ok` 200 `{"city":"…","count":3}`（**真正走到 q 写入分支**）；`mixed_mult_frac_bounded_refuse`、`mixed_excl_nofold_refuse` 400。
**梯**：自检 **182/0**（`selftest_f510b.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.574×**；哨兵 **0**；BUILD_OK（`build_f510b`）。
**记法**：断言“某键必须出现”前先问 schema 是否 required——可选键被候选层省略是**合法**的；要覆盖新写入分支，必须用 all-required 把候选层挤掉。


**可构造的子集**：mixed 对象里**无界**整数/number 叶带 `multipleOf`——发常量 `0` 即满足（0 是任何 M 的倍数），其余叶走既有分支。有界叶仍拒绝（需逐叶折叠进 mixed 计划，另做）。
**实现**：①plan 新增 2 段 kind `z`，`api_object_mixed` 增 `$ty == "z"` 分支（键与冒号后发字面 `0`）；②`api_response_schema_mixed_object` 接受 body 或裸 schema、`multipleOf` 退出全局排除，逐叶把无界 i/n 且带 multipleOf 的叶改写为 `name:z`；若**每叶**都是带乘数的整数则让位给 F438 专用写入器（`api_schema_multi_multiple != ""` 时返回空）；③三处守卫豁免：bounds 无界多叶规则、两条 number+multipleOf 规则、keywords 的 all-required 分支——后者**必须校验计划叶 kind 都是写入器真能发出的**（s/i/n/b/z、p、计数数组），否则普通数组叶（`an`）会被误放行。
**门（含翻转）**：`mult_mixed` 1→0；新 `mix_z_plan=[city:s,count:z]`、`mix_z_bad=[]`（有界对照）。
**过程**：首轮 required 豁免过宽，自检抓出 `req_arr_all` 由 `[required]` 变 `[]`（普通数组叶被误放行）⇒ 加 kind 白名单后 182/0；活体又抓出旧对照 `multi_leaf_mixed_refuse` 被证伪（`{"a":0,"b":100000}` 已构造满足）⇒ 翻转为 `multi_leaf_mixed_ok`。
**活体**：`mixed_mult_zero_ok`/`mixed_mult_allreq_ok` 200 `{"city":"…","count":0}`；`mixed_mult_bounded_refuse`（count 带 minimum）400；`mixed_mult_subset_refuse`（required 子集）400 点名 required；`multi_leaf_mixed_ok` 200 `{"a":0,"b":100000}`。
**梯**：自检 **182/0**（`selftest_f509b.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.520×**；哨兵 **0**；BUILD_OK（`build_f509b`）。
**记法**：放宽“全 required”豁免时，**必须按写入器真实能力校验 plan 的每个 kind**——检测器能命名不等于写入器能发出（普通数组叶就是反例）。


**核心思路**：与 F496 同一论证应用到多叶计划——每叶的整数元素是 p/q 的倍数 ⇔ 是 p 的倍数；检测器逐叶读完整字面并折成最简分子，写入器（F507 已支持任意大小与 0）按计划逐叶发出。
**实现**：`api_schema_multi_multiple` 的逐叶 multipleOf 解析由 `[0-9]+` 前缀改为完整字面（后随分隔符）＋`api_schema_multiplier_numerator`。
**门**：新 `multi_frac_plan=[a|3,b|1]`；`multi_big_plan`/`mult_multi` 不回退。
**活体**：`multi_mult_frac_ok` 200 `{"a":1000002,"b":1000000}`（a 被 3 整除 ⇒ 0.3 的倍数；b 是 0.5 的倍数）。
**梯**：自检 **182/0**（`selftest_f508b.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.566×**；哨兵 **0**；BUILD_OK（`build_f508b`）。
**过程**：首轮门条件未加上（自检仍绿但未断言新字段），补齐条件后重建自检复验；门字段打印≠门已断言。


**核心思路**：与 F505/F506 同一论证——0 是任何 M 的倍数；多叶写入器逐叶调用后缀填充，超宽时该叶改发常量 `0`，避免整对象因一叶失败而中止（旧行为会掉到通用生成器）。
**实现**：`api_multi_multiple_value` 的首叶与后续叶两处填充都加 `> 999999999 → 常量 0` 分支；检测器本就无上限。
**门**：新 `multi_big_plan=[a|2000000000,b|2000000001]`；`mult_multi`（小 M）不回退。
**活体**：`multi_mult_huge_ok` 200 `{"a":0,"b":0}`（两叶各满足自己的乘数）。
**梯**：自检 **182/0**（`selftest_f507.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.513×**；哨兵 **0**；BUILD_OK（`build_f507`）。


**核心思路**：与 F505 同一论证——0 是任何 M 的倍数；无界 items 的每个元素由后缀写入器产生，超出九位乘数时改为每个元素发常量 `0`。
**实现**：`api_array_item_multiple_value` 的无界分支在 `mult > 999999999` 时用 `api_constrained_continue(["0"])`；`api_schema_array_item_multiple` 的无界分支删除两处九位上限拒绝。
**门**：`im_big` `[]`→`[nums|2000000000|-1|-1]`；`im_unb`/`im_bnd`/有界小数例不回退。
**活体**：`items_mult_unb_huge_ok` 200 `{"nums":[0,0,0,0]}`。
**梯**：自检 **182/0**（`selftest_f506.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.508×**；哨兵 **0**；BUILD_OK（`build_f506`）。


**核心思路**：0 是任何 M 的倍数（含小数 M 与任意大的 M），而强制后缀写入器只能表达 999999999 以内的残差。超过宽度时不再拒绝可满足的形状，直接发常量 `0`（整数与 number 叶都合法）。
**实现**：`api_multiple_value` 在 `mult > 999999999` 时用 `api_constrained_continue(["0"])` 代替 `api_multiple_fill`；`api_response_schema_multiple_unbounded` 去掉上限（只留 `p ≥ 1`）；`api_schema_bounds_unsupported` 无界分支删除“>999999999 拒绝”规则。
**门**：`unbounded_big` 1→0、新 `unb_big_shape=[n|2000000000]`；`unbounded_ok`/`mult_one_ub` 不回退。
**活体**：`num_mult_unb_huge_ok`/`num_mult_unb_huge_num_ok`（M=2000000000，整数与 number 叶）均 200 `{"n":0}`；items 路线的 `im_big` 上限不变（本轮只开单属性路线）。
**梯**：自检 **182/0**（`selftest_f505.log`，`: FAIL` 0；构建日志 `SKIPPED_RUN` 为 norun 模式正常输出，exe 11:15 新于源码 11:12）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.518×**；哨兵 **0**。


**结论**：`api_schema_array_item_number`（F462）把 `items` 块包成单叶 properties 喂给 `api_schema_number_bounded_shape`，因此 F501 的严格侧网格折叠**无需另改 items 代码**即已生效。活体 `items_mult_frac_num_excl_ok`（`exclusiveMinimum:1.2, maximum:9, multipleOf:0.3`）返回 200 `{"nums":[1.5,1.5,1.5,1.5]}`——最小严格上倍数 1.5，且 1.5/0.3=5 为整数。
**变更**：仅 harness 增例（覆盖断言），无引擎改动；二进制与 F503 相同。
**验证**：bounds harness `CHECK_EXIT=0`（`schema_bounds_f504probe.log`）；其余三 harness 与 F503 同二进制、结论沿用（`*_f503b.log`）。
**记法**：形状折叠被多条路线**共享**时，新语义（严格侧）会自动到达这些路线；这类“已生效”必须用活体钉住，否则下次重构可能在某条路线上悄悄丢失。


**核心思路**：无界后缀写入器发出的值是一个整数；整数是合法 `number`，且同一最简分子 p 的倍数同时是 p/q 的倍数。故无界 number 叶（整数 M 与小 M 皆可）与整数叶同构，可由同一写入器服务。
**实现**：`api_response_schema_multiple_unbounded` 接受完整 body **或**裸 schema（数值守卫复用同一判定），plan 叶 kind 放宽到 `i|n`；`api_schema_bounds_unsupported` 的两条 number+fractional/integer 守卫追加“该探测器为空才拒绝”。
**门（含翻转）**：`nm_frac` 1→0、`nm_unb` 1→0、裸 schema 同族 `mult_number`（`$bdBC`）1→0（首轮漏翻，自检 1 FAIL 暴露）；新 `nm_frac_ub=[n|3]`、`nm_int_ub=[n|3]`。
**活体**：`num_mult_frac_unb_num_ok`/`num_mult_unb_num_ok` 均 200 `{"n":2345001}`（被 3 整除）；有界与 items 路线不回退。
**梯**：自检 **182/0**（`selftest_f503b.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.524×**；哨兵 **0**；BUILD_OK（`build_f503b`）。


**核心思路**：F473 已让无乘数的小数界写到 6 位（-994 marker）；本轮把 `multipleOf` 路线也开到 6 位——乘数最简分子解析、字面位数校验、网格位数上限、marker 映射四处一致放宽（F450 的“宽度假设一次改全”）。
**实现**：`api_schema_multiplier_numerator` 小数位上限 5→6；number 小数分支的 5 个字面正则 `{1,5}`→`{1,6}`；折叠 marker 表补 `d==6 → -994`。
**门**：`fm_d6=[n|1000001|-994|0]`（min=1.000001, M=0.000001）、`fm_d6_unsat=[]`（min=max=1.000001, M=0.000003）。
**活体**：`num_mult_frac_d6_ok` 200 `{"n":0.000246}`（0.000123×2）、`d6_unsat_refuse` 400。**测试坑**：`json.dumps(0.000001)` 写成 `1e-06`（指数形式被本引擎按设计拒绝），活体用例改用 `0.000123`/`0.0002` 保证普通小数拼写。
**梯**：自检 **182/0**（`selftest_f502.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.489×**；哨兵 **0**；BUILD_OK（`build_f502`）。


**核心思路**：小数乘数的折叠在十进制网格上进行（d=字面最细位数，sM=缩放乘数）。严格下界 ⇒ 取 `>= 字面` 的最小倍数后，若恰好落在字面上再加一格；严格上界 ⇒ 取 `< 字面` 的最大倍数（单侧时同样处理 0 与负区间）；同侧同时有 inclusive/exclusive 时取更严者、相等时 exclusive 胜（与 F422/F423 的整数规则一致）；draft-4 布尔形式此前已转成数值 exclusive。
**实现**：`api_schema_number_bounded_shape` 的小数分支允许 exclusive，字面/位数集合纳入 exclusive 字面，并按上述规则折叠；无写入器改动。
**门**：`fm_excl_lo=[n|15|-999|0]`、`fm_excl_hi=[n|12|-999|0]`、`fm_excl_bool=[n|12|-999|0]`、`fm_excl_unsat=[]`。
**活体**：`num_mult_frac_excl_ok` 200 `{"n":1.5}`（>1.2）、`num_mult_frac_exclmax_ok` 200 `{"n":1.2}`（<1.5）、`excl_unsat_refuse`（1.2<x<1.5 无 0.3 的倍数）400；既有 inclusive/单侧用例不回退。
**梯**：自检 **182/0**（`selftest_f501.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.546×**；哨兵 **0**；BUILD_OK（`build_f501`）。


**核心思路**：F499 拒绝 p=1 只因 `api_multiple_fill` 的 `$mult < 2` 守卫；算法对 1 天然成立——残差 `x=(1 - p·10^k % 1) % 1 = 0`，最终值 `prefix·10^k`，是 1 的倍数，也必是任何 p/q（p=1）的倍数。
**实现**：`api_multiple_fill` 守卫 `< 2` → `< 1`；`api_response_schema_multiple_unbounded` 接受 p=1；删除 F499 加的无界 p<2 拒绝规则。
**门**：`unb_int_quarter` `[]`→`[n|1]`；`unb_int_frac`/`unb_int_five` 不回退。
**活体**：`num_mult_frac_unb_quarter_ok` 200 `{"n":2345000}`（2345000/0.25=9380000 整数）、0.3 例仍 `{"n":2345001}`。
**梯**：自检 **182/0**（`selftest_f500.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.576×**；哨兵 **0**；BUILD_OK（`build_f500`）。


**改前证据**：活体探针（F498 二进制）对 `{"n":{"type":"integer","multipleOf":0.3}}` 返回 **200 `10`** —— 静默违反；检测器的 `[0-9]+` 前缀把 0.3 读成 0 后拒绝，普通整数回落路径输出了裸数字。
**实现**：`api_response_schema_multiple_unbounded` 改为 plan 定位（kind i）+ 完整字面解析；整数叶取最简分子 p，p≥2 交给无界后缀写入器（发出的 p 的倍数必为 p/q 的倍数）；p=1（0.25 等）虽恒满足，但本处无包装写入器（旧回落输出裸数字），在 `api_schema_bounds_unsupported` 的无界分支**显式 400**，避免 200 畸形答案。
**门**：`unb_int_frac=[n|3]`、`unb_int_quarter=[]`、`unb_int_five=[n|5]`。
**活体**：`num_mult_frac_unb_int_ok` 200 `{"n":2345001}`（被 3 整除）、`num_mult_frac_unb_quarter_refuse` 400 `numeric bounds`；`mult_one_ub` 等既有形不变。
**梯**：自检 **182/0**（`selftest_f499b.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.528×**；哨兵 **0**；BUILD_OK（`build_f499b`）。


**核心思路**：无界时既有 item writer 只需发出“所给乘数的倍数”（实测形状值 1234002 = 3×411334 等）；把乘数取成最简分子 p 后，发出的每个值都是 p 的倍数，而 p/(p/q)=q 为整数 ⇒ 必为 p/q 的倍数，构造满足。
**实现**：删除 `api_schema_array_item_multiple` 无界分支里对 fractional 的拒绝；九位上限沿用。无写入器改动。
**门**：`im_frac_unb` `[]`→`[nums|3|-1|-1]`；`im_frac_num`（number 型 items）保持 `[]` 对照。
**活体**：`items_mult_frac_unb_ok` 200 `{"nums":[1234002,2345001,3456000,4567002]}`，每项均被 3 整除（即 0.3 的倍数）；有界三例与 number-items 路线不回退。
**梯**：自检 **182/0**（`selftest_f498.log`，`: FAIL` 0）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.504×**；哨兵 **0**；BUILD_OK（`build_f498`）。


**核心思路**：`items` 的 `an` 叶与单整数叶同构——整数元素是 p/q 的倍数 ⇔ 是 p 的倍数，因此检测器把乘数读成最简分子 p 后交给既有 item writer（逐元素发出 p 的倍数）。数组路由不经候选枚举层，故无 F496 的“更早认领者”问题；本检测器已在 `api_schema_bounds_unsupported` 的早退名单里，路由归属直接由它决定。
**实现**：`api_schema_array_item_multiple` 的 multipleOf 解析由 `[0-9]+` 前缀改为完整字面（后随分隔符）＋`api_schema_multiplier_numerator`；无界 fractional 仍拒绝（无后缀写入器可达）；number 型 items 维持原先的拒绝语义（委托 shape 的第 4 段不可能等于分子）。
**门**：`im_excl_frac` `[]`→`[nums|3|3|3]`；新 `im_frac_unb=[]`、`im_frac_num=[]` 对照。
**活体**：`items_mult_excl_frac_ok` 200 `{"nums":[3,3,3,3]}`；number-items 小数乘数由既有 F462/F469 路线服务（`{"nums":[1.2,…]}`，1.2 是 0.3 的倍数）——原“拒绝”对照被证伪并翻转为 `items_mult_frac_num_ok`；无解的 `items_mult_excl_unsat_refuse` 仍 400。
**梯**：自检 **182/0**（`selftest_f497.log`，`: FAIL` 0）；bounds/required/count/pattern 全 **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.516×**；哨兵 **0**；BUILD_OK（`build_f497`）。
**用法**：`scripts/build-check.sh`；无写入器改动。


**核心思路**：整数 x 是 p/q 的倍数 ⇔ x 是 p 的倍数（x=(p/q)k 为整数 ⇒ q|k ⇒ x=p·(k/q)，p、q 互素）。两处必须同时按**完整字面**读乘数：形状折叠取最简分子 p；候选枚举层（链首）此前用 `[0-9]+` 前缀把 `0.3` 读成 0，因而不过滤、自由枚举。
**实现**：①helper `api_schema_multiplier_numerator`（1–5 位小数、gcd 约分）；②`api_schema_number_bounded_shape` 小数分支按 kind 分流（`i` 折分子落整数折叠、`n` 走原十进制网格）；③`api_schema_candidates` Pass 2 的 multipleOf 解析改为完整字面且要求后随分隔符（>5 位/指数形式枚举空集，交给折叠层裁决），整数 M 与分子 p 用同一过滤。
**门**：`fm_intleaf` 1→0；`fm_int03=[n|3|3|3]`、`fm_int15=[n|3|3|3]`、`fm_int_unsat=[]`、`fm_int_cand=[{"n":3},{"n":6},{"n":9}]`。
**过程**：首轮只改形状折叠 ⇒ 活体 **200 `{"n":2}`**；回退并定位认领者（非流式/流式链首 12180/7696 先调 `api_response_schema_candidates`，Pass 2 前缀读 0.3 为 0），第二轮同时修 Pass 2。**自检门只覆盖形状层，覆盖不到“谁先认领”**——本轮靠活体才暴露。
**活体**：0.3 与 1.5 → **`{"n":3}`**（3/6/9 的候选集），[1,2]+0.3 → 400；数组项 fractional/exclusive 路线（`items_mult_excl_frac_refuse`）不变。
**梯**：自检 **182/0**（`selftest_f496b.log`，`: FAIL` 0）；bounds/required/count/pattern 全 **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.526×**；哨兵 **0**；构建 BUILD_OK（`build_f496b`）。
**用法**：`scripts/build-check.sh`；无写入器改动。


**核心思路**：mixed 写入器（F493）已对每个 s 叶执行按属性长度预算；多叶形状中若其余字符串叶是**定长 pattern**（F494 的判定），整体长度约束即可证。判定的形状入口用与 mixed 检测器同源的裸 schema 资格扫描（`enum/const/minimum/maximum/multipleOf` 排除、`api_schema_is_object`、properties 块 plan），**不能**用 `api_response_schema_mixed_object`（它要求 `json_schema` 包装）或 `mixed_omittable`（后者拒绝 p 叶）。
**实现**：新 helper `api_schema_lengths_ok`（遍历 plan：s 叶跳过（写入器保证）、p 叶要求 spec 全精确且其属性内 min/maxLength 包含固定长度、其它 kind 跳过）；关键字分支加该判定。
**过程（两轮修正，均为“守卫声称支持但实际没兑现”类）**：①首版 helper 误用 `api_response_schema_mixed_object($body)`——关键字守卫拿到的是**裸 schema**，该检测器见不到 `json_schema` ⇒ 恒 0；门 `len_mix_p` 失败（181/1），改为裸 schema 资格扫描。②修完后活体暴露更严重的问题：`api_response_schema_string_bounds` 基于 `api_response_schema_string_props`，后者**见任何 `pattern` 就返回空** ⇒ 含 p 叶的 mixed 形状 bounds 为 `""`，city 叶无预算地跑出 maxLength（`"New York City, …"`）。F493 的前提“s 叶由写入器保证”只有在预算真正送到写入器时才成立。修法：预算列表按**实际消费它的写入器**生成——mixed 形状只列 plan 中的 s 叶（按 plan 顺序，p 叶自带 spec 不消费），否则走 flat 的 required 字符串属性；并加门 `len_mix_bounds=[3:8]` 直接钉住“预算到达写入器”。
**门**：`len_mix_p=[]`、`len_mix_p_bad=[minLength]`（code minLength:4 > 3）、`len_mix_bounds=[3:8]`；旧活体对照 `mixed_len_pattern_refuse` 被证伪并翻转（F493 的拒绝只因预算提取器失灵，非形状不可服务）。
**活体**：`mixed_len_p_ok`（200：city 长 3..8、code 三字母、count 整数）、`mixed_len_pattern_ok`（200：city ≥3、code 三字母）、`mixed_len_p_bad_refuse`（400 点名 minLength）。
**用法**：`scripts/build-check.sh`；无写入器改动（改的是预算提取器与关键字守卫）。
**梯（完成）**：自检 **182/0**（`selftest_f495c.log`）；bounds/required/count/pattern 全 PASS；8000 **115/0**；8001 par/fairness **0**；scaling **1.447×**；哨兵 **0**；BUILD_OK。


## F451 计划与完成（`patternProperties` 的空约束形式）
**F450 已完成**：number 叶小数界到四位。
**F451 已完成**：`api_schema_pattern_props_ok`——恰一条模式、长度 1–200、键集用 `api_schema_emitted_set`；任一键命中或模式不可读 ⇒ 拒绝，全不命中 ⇒ 放行（空约束）。`patternProperties` 从 `patternProperties|if` 拆出单独判定。
**过程**：拆 alternation 时捕获组消失但 `return $mk[1];` 未改 ⇒ 含 `"if"` 的 schema 触发 `null returned`，自检 127 PASS 后致命退出；修为 `return "if";`。
**门**：`pp_vacuous=[]`、`pp_match=[patternProperties]`、`pp_multi=[patternProperties]`、`pp_route=2`；旧 `$sfL` 边界不变。
**活体**：`patternprops_vacuous_ok` 200 双键、`patternprops_refuse` 400 点名。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.519×**、哨兵 **0**（`engine/selftest_f451b.log`、`engine/schema_required_f451.log`、`engine/schema_bounds_f451.log`、`engine/schema_count_f451.log`、`engine/schema_pattern_f451.log`、`engine/api_smoke_f451.log`、`engine/throughput_f451.log`）。
**明确留拒（排队）**：`if`/`unevaluated*`（需子 schema 求值器）、`minContains`/`maxContains`、`contains`/`not` 的值级子 schema、`patternProperties` 的匹配/多条模式、五位及以上小数、无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：`if/then/else`（先写子 schema 求值规格）→ 分片多卡 / FR-05（本机不可验证）。


## F450 计划与完成（number 叶小数界到四位）
**F449 已完成**：`contains` 的单项类型形式。
**F450 已完成**：三处宽度假设一次改全——`api_schema_scaled` 正则 `{1,3}`→`{1,4}` + `$digits==4`；计划构造两个字面正则同改、`$d=4` 与标记 `-996`；写入器 `-996` → `d=4, scale=10000`。沿用既有缩放编码，无新路由。
**门**：`num_dec4` `""` → `n|15111|-996|0`；加 `dec4_text=[{"n":1.5111}]`。
**活体**：`float_dec4_served` 200 `{"n":1.5111}`、`float_dec5_refuse` 400；1/2/3 位与负数不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.548×**、哨兵 **0**（`engine/selftest_f450.log`、`engine/schema_bounds_f450.log`、`engine/schema_required_f450.log`、`engine/schema_count_f450.log`、`engine/schema_pattern_f450.log`、`engine/api_smoke_f450.log`、`engine/throughput_f450.log`）。
**明确留拒（排队）**：五位及以上小数；`if`/`patternProperties`/`unevaluated*`（需子 schema 求值器）、`minContains`/`maxContains`、`contains`/`not` 的值级子 schema；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：`if/then/else`（先写子 schema 求值规格）→ 分片多卡 / FR-05（本机不可验证）。


## F449 计划与完成（`contains` 的单项类型形式）
**F448 已完成**：`not` 的类型矛盾形式。
**F449 已完成**：`api_schema_contains_ok`——单数组属性 + 根级 `contains` + 子 schema 恰为配对类型（`a`↔string、`an`↔integer、`af`↔number）+ 不带 `minContains`/`maxContains` ⇒ 放行。依据是两个数组写出器**在闭括号可选前先发首元素** ⇒ 数组必然 ≥1 元素、元素类型由写出器保证。`contains` 从 `patternProperties|if` 的 alternation 拆出单独判定。
**门**：`SCHEMA-STRUCT` 加 `contains_type=[]`、`contains_mismatch=[contains]`、`contains_pattern=[contains]`、`contains_route=[cities]`。
**旧门翻转**：`schema_count_check.py` 的 `contains_refuse` 改成 `contains_type_served`（200、数组 ≥1 元素），加 `contains_pattern_refuse` 作对照。
**活体**：`contains_type_ok` 200、`contains_mismatch_refuse` 400 点名；其余 harness 不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.351×**、哨兵 **0**（`engine/selftest_f449.log`、`engine/schema_required_f449.log`、`engine/schema_count_f449.log`、`engine/schema_bounds_f449.log`、`engine/schema_pattern_f449.log`、`engine/api_smoke_f449.log`、`engine/throughput_f449.log`）。
**明确留拒（排队）**：`if`/`patternProperties`/`unevaluated*` 的实现（需子 schema 求值器）、`minContains`/`maxContains`、`contains`/`not` 的值级子 schema；四位以上小数；无界 `multipleOf` > 999999999；分片多卡 / FR-05（本机不可验证）。
**顺位**：`if/then/else`（先写子 schema 求值规格）→ 分片多卡 / FR-05（本机不可验证）。


## F445 计划与完成（小数网格到三位）
**F443 已完成**：无界 multipleOf 到九位。
**F445 已完成**：`api_schema_scaled` 支持 1–3 位；标记 -999/-998/-997；写入器按位数补零。两个连带问题：`$nfM` 与自检数组同名（改 `$nfN`）；全局替换哨兵把 inward/exclusive 的返回值改了却没改比较值（`frac_exp` 变 0 ⇒ 统一为 -2000000000）。
**门**：`num_dec3` "" → `n|1511|-997|0`；加 `num_dec4=[]`、`dec3_text=[{"n":1.511}]`。
**活体**：三位的 1.511 服务、四位 400；1.5/1.51/-1.9 不回退。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.521×**、哨兵 **0**（`engine/selftest_f445c.log`、`engine/schema_bounds_f445.log`、`engine/api_smoke_f445.log`、`engine/throughput_f445.log`）。
**明确留拒（排队）**：四位及以上小数、无界 `multipleOf` > 999999999、tuple 实现（F444 规格已写）、`dependentSchemas`/`dependencies`。
**顺位**：F444（tuple）→ 分片多卡 / FR-05（本机不可验证）。


## F443 计划与完成（无界 multipleOf 上限提到九位）
**F442 已完成**：小数界支持负数。
**F443 已完成**：后缀宽度阶梯到 9 位；拒绝阈值 `>999999999`；门改 `unbounded_big` 夹具（2e9）并加 `unbounded_2m=0`；活体 2,000,000 与 987,654,321 均 200 且整除。
**梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.523×**、哨兵 **0**（`engine/selftest_f443.log`、`engine/schema_bounds_f443.log`、`engine/api_smoke_f443.log`、`engine/throughput_f443.log`）。
**明确留拒（排队）**：三位及以上小数、无界 `multipleOf` > 999999999（需更长后缀或换机制）、tuple 实现、`dependentSchemas`/`dependencies`。
**顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。
## F523（尝试后回退）：顶层 string enum 数组——分词级引号畸形
**目标**：把 F522 的根数组路线扩到 string enum/const items（`@` 带引号标记）。
**结果**：检测器/写入器/门全部实现；写入器级门在 cache=0 下通过（`["paris","lyon"]`），但**活体**（prefixCache 上下文）输出 `["paris,"paris,"paris,"paris]`——闭引号位置被分词成逗号，属**静默坏 JSON**。与 F515 同族（tokenizer 上下文相关）。
**处置**：string 分支与相关门/活体全部回退（源码回到 F522 的提交内容），重建并自检 182/0、bounds harness 绿。证据：`schema_bounds_f523probe.log`、`selftest_f523c.log`、`serve_f523probe.log`。
**下一步**：先解决 tokenizer 语境下的引号分词（与 F515 一并），再启用 string 根数组；写入器级门通过不能代替活体。
## F525（调查）：候选层四位负值截断——门内复现成立
**复现（自检门，无需活体）**：对 `{"n":{"type":"integer","minimum":-1000,"maximum":-992}}`，`api_response_schema_candidates` 产出 **9 个候选**，`api_constrained(...,cache=0)` 的输出文本为 `{"n":-1000`、`api_json_ok=0`（`selftest_f525probe.log: SCHEMA-NARROW cands=9 text=[{"n":-1000] json=0`）。3 位负值与同宽正值完整 ⇒ 与 F515 活体一致，且**与 prefixCache 无关**。
**已排除**：候选串分词（`TOKPROBE`：`-1000`→`-|1|0|0|0`、`paris"`→`par|is|"` 正确）；`api_constrained_continue` 与界写入器的容错闭合（F524 的 strict 实验未改变本复现）。
**下一步（规格）**：①恢复 strict 循环（被 block 的强制步返回哨兵）；②候选层分支 `count($schemaCands)>0 && $enumOut==""` 时 **fail-closed**：走与守卫一致的 400（"structured decode failed"），绝不落到自由生成；③把本门升级为断言（text 必须为空或合法 JSON），作为 F515 的回归门。判据：门内 `text=[]`，活体窄区间 400 或合法 JSON。

## F527 计划与完成（候选前缀匹配顺序修复 + 并行门控：F515 真因闭合）
**真因**：`api_constrain_loop` 在 `count($cand[$i]) == $d` 时**不校验已选前缀**就设 `$done=1`；对 `[-1000,-992]`，7 token 的 `{"n":-999}` 等短候选在 d=7 提前宣告完成，`-1000` 的末尾 `}` 被砍（200 截断 JSON）。**不是 tokenizer**（TOKPROBE 证明编码正确）。
**实现**：①匹配顺序改为“先逐 token 比对已选前缀，再判完成/提供下一步”；②`api_constrain_loop` 增 strict 模式（强制步被 block 且重启耗尽 ⇒ 哨兵），`api_constrained(-continue)` 以空返回失败；③候选层 `schemaCands>0 && enumOut==""` 时 **fail-closed 400**（绝不落自由生成）；④顺带修复 F199 门控：`tool_choice:"required"` 的成对骨架原先**无条件**加入候选，单调用控制会回两调用（旧截断 bug 恰好掩盖）——pair 循环改为仅 `parReq==1` 构建。
**门**：`SCHEMA-NARROW-STRICT cands=9 text=[{"n":-1000}]` PASS（常驻断言：候选层不得吐截断 JSON）。
**活体**：`narrow_neg_no_bad_json` 200 `{"n":-1000}`（合法且在界内）；smoke 115/0（含 `chat-tool-parallel-calls` 控制恢复为 1 调用）。
**梯**：自检 **182/0**（`selftest_f527c.log`）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.545×**；哨兵 **0**；BUILD_OK（`build_f527c`）。

## F528 计划与完成（顶层 string enum/const 数组：F523 失败真因为 F527 的完成逻辑）
**结论**：F523 的引号畸形（`["paris,"paris,…]`）并非 tokenizer，而是 `api_constrain_loop` 的前缀完成 bug——较短的兄弟候选在元素/闭引号前提前结束，输出停在半个引号。F527 修复后，同一实现（`@` 带引号标记 + 共享写入器）**活体正确**。
**实现**：检测器新增 string enum/const 根数组分支（`@v1~v2`，值受 keyed enum 同款编码约束）；写入器按 `@` 标记发带引号字面量（head/分隔/元素一致）；`$enumFlat` 排他条件已覆盖（检测器非空）。
**门**：`top_str_enum=[@paris~lyon~nice]`、`top_str_const=[@paris]`、`SCHEMA-TOPARR-STRENUM text=[["paris","lyon"]]` PASS。
**活体**：`top_str_enum_ok` 200 `["paris","paris"]`、`top_str_enum_unique_ok` 200 `["paris","lyon","nice"]`、`top_str_const_ok` 200 `["paris"]`。
**梯**：自检 **182/0**（`selftest_f528.log`）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.592×**；哨兵 **0**；BUILD_OK（`build_f528`）。

## F529 计划与完成（小数位宽扩到 10 位：marker -990，窗口同步）
**实现**：全部宽度站点一次改全（marker 表/两处 marker 判定/scaled 正则与 `digits==10` 分支/分子上限/形状 5 正则与 marker 表/回退 d 探测与 marker 表/mixed q 范围/窗口守卫 `[-999,-990]`）。
**门**：`num_dec10=[n|15111111111|-990|0]`、`fm_d10=[n|2469134|-990|0]`、`win_ten=[]`；`win_nine`/1–9 位不回退。
**活体**：`float_dec10_served` 200 `{"n":1.5111111111}`、`num_mult_frac_d10_ok` 200 `{"n":0.0002469134}`、`marker_window_nine/ten_refuse` 400。
**梯**：自检 **182/0**（`selftest_f529.log`）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.582×**；哨兵 **0**；BUILD_OK（`build_f529`）。
**代价（如实记录）**：窗口再内缩一位——整数上界 −990…−999 现为显式 400（此前 −991 起）；10 位小数为最后一档（double 有效位约 15–16）。

## F530 计划与完成（unevaluatedItems 的根数组真空形态）
**核心思路**：JSON Schema 中 `items` 的 schema 形式会评估**所有**元素，故根数组上的 `unevaluatedItems` 对任何值（false/true/schema）都作用于零个元素 → 真空满足。`items` 为元组（`items` 数组 / `prefixItems`）时后续元素未评估，仍拒绝。
**实现**：`api_schema_unevaluated_items_ok` 增根数组分支（无 properties + 有 `unevaluatedItems`）：前缀项/元组 items ⇒ 0；`type:array` + `items:{schema}` ⇒ 1。根 string 数组检测器的尾巴容忍 `unevaluatedItems`（真空语义不改变发射）。
**过程**：首轮门用错入口（`prefixItems` 由 structural 判）与**变量名撞名**（`$sfBF/$sfBG/$sfBH/$sfBI` 已被 contains 门复用）导致 1 FAIL；改为唯一名 `$ueRootVac/$ueRootTuple` 后 182/0。活体曾返回对象 `{"unevaluatedItems":102409}`——根因而非守卫而是根数组检测器拒绝多余关键字；修正后返回字符串数组。
**门**：`ueRootVac=[]`、`ueRootTuple=[prefixItems]`。
**活体**：`uneval_root_vacuous_ok` 200 `["New York City,"]`（字符串数组、合法）；`uneval_root_tuple_refuse` 400 点名 prefixItems。
**梯**：自检 **182/0**（`selftest_f530e.log`）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling **1.581×**；哨兵 **0**；BUILD_OK（`build_f530e`）。

## F531 计划与完成（根 number/boolean 数组的 unevaluatedItems 真空形态）
**核心思路**：与 F530 同一论证——`items`（schema）评估所有元素 ⇒ 真空。落地方式改为**前置剥离**：检测器先删去 `,"unevaluatedItems":<浅对象|true|false>`，再跑原有形状正则（深子 schema 保持拒绝）。
**实现**：`api_response_schema_top_array_number/string/boolean` 三个检测器统一加剥离行（string 的原尾部容忍表达式回退为剥离，行为等价且覆盖 true/false）。
**过程**：先试“正则尾部容忍”，门内 `ue_num_det=-1`、`ue_bool_det=0`——尾部拼接未匹配；PHP CLI 直测亦 0 ⇒ 改剥离后两者均为 1。
**门**：`ue_num_det=1`、`ue_bool_det=1`；`ueRootVac=[]`/`ueRootTuple=[prefixItems]` 不回退。
**活体**：`uneval_root_num_ok` 200 `[123,456,789]`、`uneval_root_bool_ok` 200 `[false]`、`uneval_root_vacuous_ok` 200 `["New York City,"]`。
**梯**：自检 **182/0**（`selftest_f531e.log`）；四 harness **PASS**；8000 **115/0**；8001 par/fairness **0**；scaling ~1.58×；哨兵 **0**；BUILD_OK（`build_f531e`）。

## F532 规格与计划（11 位小数：marker -989，窗口 [-999,-989]）
**规格**：小数网格再进一位——d=11 用标记 -989、scale=10^11；整数上界窗口随标记同步内缩一位（−999…−989 全部拒绝，−1000 及以下与 −988 及以上保持原路径）。宽度站点按 F529 清单 +1 位一次改全：scaled 正则与 `digits==11` 分支、分子小数位上限 11、multipleOf 分支 5 条字面量正则、bounded 分支 2 条字面量正则与 `\.[0-9]{11}` 宽度探测、两处 marker 表、mixed q 标记范围（−999…−989）、decimal element 写入器（d=11/scale=10^11）、两处写入器 marker 门、窗口守卫。
**门**：`num_dec11=[n|151111111111|-989|0]`、`fm_d11=[n|24691356|-989|0]`、`win_eleven=[]`；1–10 位与 `win_nine/win_ten` 不回退。
**活体**：`float_dec11_served` 200 `{"n":1.51111111111}`、`num_mult_frac_d11_ok` 200（0.00012345678×2=0.00024691356）、`marker_window_eleven_refuse` 400。
**代价（如实记录）**：窗口再内缩一位——整数上界 −989…−999 现为显式 400（此前 −990 起）；double 有效位约 15–16，11 位小数是最后一档。
**过程（活体红证 → 修复）**：首轮门全绿，但活体 `float_dec11_served` 返回 `{"n":1.5111111111}`——小数被截成 10 位。真因是两处小数写入器的续写预算写死为 `api_constrained_continue([$dec], 12)`：10 位字面量恰为 12 字符、12 步够用，11 位（13 字符）差一步被截掉末位；旧 `num_mult_frac_d11_ok` 的 `is_mult` 浮点容差还把截断值放过（现改为**精确文本**断言）。修复：预算改为 `strlen($dec) + 4`，宽度增长不再依赖魔法常数。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f532c.log`；`num_dec11=[n|151111111111|-989|0]`、`fm_d11=[n|24691356|-989|0]`、`win_eleven=[]`）；四 harness **PASS**（bounds `f532e`：`float_dec11_served` 200 `{"n":1.51111111111}`、`num_mult_frac_d11_ok` 200 `{"n":0.00024691356}`、`marker_window_eleven_refuse` 400）；8000 **115/0**；8001 par/fairness **0**；scaling **1.542×**；哨兵 **0**；BUILD_OK（`build_f532c`）。
**教训**：计划型门只看形状（`n|…|-989|0`），看不见写出器截断；宽度扩宽必须同时有**发射文本**的判据（本次由活体精确文本提供）。

## F533 规格与计划（把“发射文本”探针补齐到 7–11 位，含数组元素路径）
**动机**：F532 的截断只能被活体发现——自检里 `dec*_text` 只覆盖到 6 位（`nfText8`），而 `api_array_item_number_value` 的小数续写站点**没有任何文本探针**。把 F532 的教训固化成门。
**做法**：在 SCHEMA-NUM-FRAC 内新增对象路径 7/8/9/10/11 位与负 11 位六条文本探针（`api_number_bounded_value` + `gguf_token_str` 解码，按 `$nbBase` 跳过前缀），再新增数组元素 11 位探针（`api_array_item_number_value`，从 token 0 解码，正则断言每个元素都是 `1.51111111111`）。断言全部精确文本 + `api_json_ok`。
**判据**：若把续写预算退回 12 步，这些探针必须 FAIL（F532 前二进制即此状态）；修复后 7–11 位以及负值、数组元素都完整。
**性质**：只改自检代码，不动服务路径；仍按梯子重建并跑自检 + 四 harness + gate_live。
**过程**：首轮 6 条对象文本探针全绿（`dec7_text`…`neg11_text`），数组探针 FAIL——不是写出器错，而是解码从 token 0 开始、缓存 prompt 前缀也在串里，`^` 锚定的 JSON 守卫失败；改为取最后一个 `{` 之后的尾段再断言（值本身始终是三个 11 位元素）。
**门**：`dec7_text={"n":1.5111111}`、`dec8_text={"n":1.51111111}`、`dec9_text={"n":1.511111111}`、`dec10_text={"n":1.5111111111}`、`dec11_text={"n":1.51111111111}`、`neg11_text={"n":-1.51111111111}`、`arr11_text={"nums":[1.51111111111×3]}`；活体另加 `items_num_dec11_ok` 200 `{"nums":[1.51111111111×4]}`（`f533c`）。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f533b.log`，SCHEMA-NUM-FRAC PASS）；四 harness **PASS**、8000 **115/0**、8001 par/fair **0**、scaling **1.487×**、哨兵 **0**（`gate_live f533`）；`f533b` 只改自检探针代码、服务路径不变，仍对最终二进制复核了 bounds 活体（`f533b`）。BUILD_OK（`build_f533b`）。

## F534 计划与完成（HTTP 200 层嵌套 schema 的静默错误答案：下降窗口 8000 字节）
**活体取证**：把 200 层嵌套对象 schema 作为 bounds harness 的**首请求**（`deep200_cold_first_ok`，F534 新增）后，返回 `{"count":100000}`（200、JSON 合法但缺 k0…k199 与 city）。手动同会话扫描深度：40/64/96/128/129/150 全部正确组装，**200 稳定错误**；非冷启动特有（重复请求同结果）。
**根因**：`api_response_schema_nested_object` 从首个键起只扫描 `substr($schema, $ip, 8000)`，200 层文本约 8.4 KB，`api_balance_block` 在窗口内找不到闭合 ⇒ 返回空计划，HTTP 路由**静默落到浅层检测器**并回 200——违反“要么实现要么显式 400”。
**修复**：去掉初始窗口（`$win = substr($schema, $ip)`）；每层重切片的 20000 上限大于 256 级最大文本（~10 KB），保留不构成限制。**未做**：256 级以上的 fail-closed（超限仍可能落到浅层）——记入队列。
**门**：`deep200_cold_first_ok`（首请求、200 层、逐层校验 city/count）。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f534.log`；`SCHEMA-DEEP200 plan=ok opens=200 json=1`、`first_len=3887`）；bounds **PASS**（`f534d`：`deep200_cold_first_ok` 200 且逐层校验通过、148 条用例全绿）；required/count/pattern **CHECK_EXIT=0**（`f534d`）；8000 **115/0**；8001 par/fair **0**；scaling **1.562×**；哨兵 **0**（`gate_live f534`）。BUILD_OK（`build_f534`）。

## F535 规格与计划（>256 层嵌套的 fail-closed）
**动机**：F534 修掉了 8000 字节窗口，但检测器循环上限仍是 256 层；超过后返回空计划，路由会再次落到浅层检测器——同型的静默违反只是往外推了一步。
**做法**：新增 `api_schema_nested_overflow($body)`，按检测器同样的键链下降并计数，第 257 层返回 1；在非流式拒绝链（`api_schema_bounds_unsupported` 与 `api_schema_keywords_unsupported` 之间）插入 400 “nested object depth beyond 256 levels is not supported”。
**门**：SCHEMA-DEEP200 增 `over200=0`、`over260=1`；bounds harness 增 `deep260_refuse`（260 层 → 400 且含 “256”）。
**已知边界**：本次只接非流式链；流式/其他传输若也走浅层 fallback，需各自接拒绝（入队）。
**诚实记录**：260 层的“修复前活体红证”未单独采集——旧二进制已被本次重建覆盖，红证来自 F534 的 200 层同机制（窗口修复前 `{"count":100000}`）。
**梯（待填）**：`selftest_f535.log`、bounds、其余 harness + gate_live。
**过程（两轮活体红证）**：(1) 首轮 260 层仍 200——拒绝链传的是 **schema 对象**而非整包 body，谓词首行要求 `"json_schema"` 直接返回 0；改为两形兼容（含 `json_schema` 才提取）。(2) 仍 200——滑窗后按**上一层键文本**定位，运行时键名逐层不同（k0→k1），depth 1 即失败；改为在 block 上**重新匹配下一层键**（与检测器同一打法）。修复后 `deep260_refuse` 400 且消息含 “256”。
**门**：SCHEMA-DEEP200 `over200=0 / over260=1`；`deep260_refuse` 400；`deep200_cold_first_ok` 仍 200。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f535c.log`，SCHEMA-DEEP200 PASS）；bounds **PASS**（`f535c`，deep260 400）；8000 **115/0**、8001 par/fair **0**、scaling **1.559×**、哨兵 **0**（`gate_live f535c`）；required/count/pattern 在 `f535b` 上绿（同一服务路径，最终改动只影响谓词自身）。BUILD_OK（`build_f535c`）。
**诚实边界**：8000 的四个嵌套分发点都在 `api_serve` 内、共用这一条拒绝链——流式 `stream:true` 实测 260 层同样 400（`serve_f535e` 探针），200 层正常流式输出 k0…k199；8001 并行服务对任何 `json_schema` 直接 400 “parallel mode: streaming text only”（`serve_par_f535d` 探针），不存在静默降级。故未接的传输仅为未来新增入口。

## F537（2026-11-12）：`unevaluatedProperties:false` 真空形态已服务（补活体门）
**发现**：扁平对象在键全部声明时，`unevaluatedProperties:false`（带/不带 `additionalProperties:false`）返回 200 且值合法（键集恰为声明集）——该关键字作用于零个属性，真空满足。静态判定 `api_schema_keywords_unsupported` 仍列出关键字名，说明**静态拒绝清单是超集、不是运行时答案**（形状检测器先认领）。
**改动**：bounds harness 增 `uneval_props_closed_ok` / `uneval_props_open_ok`（断言 200 + 精确键集）；无引擎改动。
**梯**：bounds **PASS**（`f536b`）；其余不受影响。
**队列修正**：此形态从“未实现”移入已服务（以活体值为准）。

## F538（2026-11-12）：六条支持表声明的活体核对
**结果**（`claims_f538.log`）：`not:{const:5}` 与根级 `contains:{const:"paris"}` 运行时 **400** 点名关键字（自检静态字段却显示可判）；`if:{required:["city"]}`、`propertyNames` ok pattern **200 合法**；`dependentRequired` 子集、items pattern 含元字符 400（与表一致）。
**修正**：支持表头改为“以活体为准”；`not` 数值 const / 根级 `contains` const 从“支持”降为“静态可判、运行时 400”。
**记法**：静态“可判”≠运行时“被认领”（与 F537 反向同型——静态拒绝≠运行时拒绝）；两个方向都必须发真请求核对。
**梯**：无需重建（无引擎改动）；六条请求已在全新服务上取证。

## F539（2026-11-12）：两类静默违反（200 + 自由文本）——P0
**D1**：本地 `$ref` 顺序依赖——`definitions` 在 `properties` **之前**时返回 200 自由文本（schema 被忽略）；放在**之后**则 200 `{"city":…}` 合法。
**D2**：oneOf/anyOf（属性级 string **const** 分支、根级 oneOf const 分支、属性级整数 anyOf）既未被认领也未 400，返回 200 自由文本——直接违反“要么实现要么显式 400”。**可工作的形态**是 smoke 里的 **enum 分支** oneOf（`chat-json-schema-oneof`/`chat-json-schema-root-oneof`）；const 分支本可折叠为单值 enum，是低风险修复点，anyOf 需先定策略（拒绝或折叠）。
**对照**：allOf+required、additionalProperties:true+required 子集、数组 min/maxItems 的活体值均合法。
**处置**：P0 入队，按“先成对门取证再改”执行——D1 加“定义在前/在后”成对门后修检测器定位；D2 先查 smoke 是否已有可工作的 oneOf 夹具，再决定 blanket 400 还是新增认领检测器。
**证据**：`claims_f539.log`。

## F540（2026-11-12）：oneOf/anyOf 的 const 分支折叠（F539-D2 的低风险部分）
**实现**：`api_schema_flatten_choices` 在收集各分支 `"enum"` 之后，再用一次 `preg_match_all` 收集分支的 `"const": <字符串|数字|布尔|null>` 字面量并入同一候选并集——单值 const 与 `"enum":[X]` 等价。仅带数值界、无 enum/const 的分支仍不认领（anyOf 界分支仍是 fail-closed 工作，不静默服务）。
**门/活体**：`oneof_const_ok` / `anyof_const_ok` 均 200 `{"city":"paris"}`（F539 时同形为 200 自由文本）。自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f540.log`）；bounds **PASS**（`f540`）。gate_live 见 `api_smoke_f540.log` 等。
**剩余（P0）**：D1 `$ref` 顺序依赖；D2 的 anyOf 纯界分支需 fail-closed 或折叠。

## F541（2026-11-12）：本地 `$ref` 键序依赖（D1）——定义容器整对删除
**红证（成对活体）**：plain string 定义（无 enum）时，`definitions` 在 `properties` **之后** → 200 `{"city":…}`；**之前** → 200 自由散文（`schema_bounds_f541pre2.log`，`CHECK_EXIT=1`）。enum 定义走候选路径，不做这对夹具会掩盖问题。
**根因**：`api_schema_resolve_refs` 内联后把定义容器**清空为 `{}`** 而非删除；定义在前时 schema 以 `{"definitions":{},…` 开头，而形状检测器锚定 `^\{"type"/"properties"` ⇒ 锚点失配 ⇒ 无人认领 ⇒ 无约束生成。
**修复**：整对删除定义容器（键+值+相邻逗号，含空白处理），使两种键序解析结果逐字节相同；新增静态门 `SCHEMA-REF-ORDER`（两种键序结果相等、无 `definitions`、无 `$ref`）。
**门/活体**：`ref_defs_plain_first_ok`、`ref_defs_plain_last_ok`、`ref_defs_first_ok`、`ref_defs_last_ok`（含 enum 定义对照）。
**梯（待填）**：`selftest`、bounds、其余 harness + gate_live。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f541.log`；`SCHEMA-REF-ORDER: PASS`，两序解析均为 `{"json_schema":{"schema":{"type":"object","properties":{"city":{"type":"string"}}}}}`）；bounds **PASS**（`f541`：`ref_defs_plain_first_ok` 200 `{"city":…}`，此前为散文；`ref_defs_*`/enum 对照全绿）；required/count/pattern **CHECK_EXIT=0**；8000 **115/0**；8001 par/fair **0**；scaling **1.538×**；哨兵 **0**。BUILD_OK（`build_f541`）。
**记法**：内联后**把容器清空为 `{}` 与删除不同**——`{}` 留在键位会改变 schema 首键，锚定型检测器随即失配；修复要连键带逗号删除，并加“两序字节相等”的静态门。

## F542（2026-11-12）：纯数值界 oneOf/anyOf 分支 fail-closed（D2 的另一半）
**背景**：F540 只折叠 enum/const 分支；仅带数值界的分支既无 writer 也无检测器认领，F539 实测返回 200 免费文本。
**做法**：新增 `api_schema_choices_pure_bounds`——扫描每个 oneOf/anyOf 数组，若**没有任何分支**含可认领形态（`enum`/`const`/`properties`/`required`/`pattern`）则返回 1；拒绝链给出 400 “oneOf/anyOf branches carrying only numeric bounds are not supported…”。折叠在链前执行，因此 enum/const 分支此时已不存在或仍可判为安全。
**门**：静态 `SCHEMA-CHOICES-BOUNDS`（`pure=1`、`root_enum=0`、`const=0`、`enum_int=0`）；活体 `anyof_bounds_refuse` 400。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f542.log`）；bounds **PASS**（`f542`：`anyof_bounds_refuse` 400 点名 oneOf/anyOf，`oneof_const_ok` 仍 200 `{"city":"paris"}`）；required/count/pattern **CHECK_EXIT=0**；8000 **115/0**；8001 par/fair **0**；scaling **1.577×**；哨兵 **0**。BUILD_OK（`build_f542`）。

## F543（2026-11-12）：根级 string enum 加引号 + 根级 const 折叠
**活体证据**：根级 `{"type":"string","enum":["paris","lyon"]}` 返回裸 `paris`（非法 JSON）；根级 `{"type":"string","const":"paris"}` 与 `{"const":"paris"}` 返回 **200 散文**（const 未接 enum 分支）；根级整数 enum 返回裸 `2`（合法）。
**改动**：`$enumFlat` 分支把“全为字符串”的根级 enum 改写为带引号候选（用 `api_esc` 转义），混合 enum 保持裸字面量；新增根级 const 分支（无 `properties` 时），字符串 const 保留引号、数字/布尔裸写。
**门**：活体 `root_str_enum_quoted_ok`（JSON 可解析且值 ∈ enum）、`root_str_const_quoted_ok`（`"paris"`）、`root_int_enum_ok`（裸 2/5/9）。
**边界**：本次门覆盖非流式路径；流式根级字符串 enum 的引号需另验（入队）。
**过程（一轮回归）**：首版 const 分支没有限定“根层直接子键”，把根**数组** items 里的 const 抢走了——`top_str_const_ok` 由 `["paris"]` 退化为 `"paris"`（`f543`，VERDICT FAIL）。改为 `"schema"\s*:\s*\{[^{}]*"const"` 只认 schema 对象直接子键后恢复。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f543b.log`）；bounds **PASS**（`f543b`：`top_str_const_ok=["paris"]`、`root_str_enum_quoted_ok`/`root_str_const_quoted_ok`=`"paris"`、`root_int_enum_ok`=2）；required/count/pattern **CHECK_EXIT=0**；8000 **115/0**；8001 par/fair **0**；scaling **1.579×**；哨兵 **0**。BUILD_OK（`build_f543b`）。
**记法**：根层判定要用“直接子键”正则（`[^{}]*`），不要用全文存在性——否则 items/properties 里的同名关键字会被误认领。

## F544（2026-11-12）：流式路径缺根标量 enum/const 折叠（P0，待修）
**红证**：`stream:true` 时根级 string enum 与 const 都返回 200 自由散文（`claims_f544.log`）。非流式已由 F543 修复；流式分支没有对应折叠；对象形状流式正常，缺口只在根标量。
**下一步**：在流式分支镜像 F543 的 enum/const 折叠（字符串加引号、const 折叠），加 `stream:true` 活体门；修前红证已留档。
**队列**：D4 流式根标量折叠（P0）→ `unevaluated*` 子 schema 求值器 → FR-05/多分片。

## F545（2026-11-12）：流式路径的根标量 enum/const 折叠（D4）
**实现**：新增 `api_root_scalar_choices($body)`——仅当无 `properties`、且 `const`/`enum` 是 schema 对象**直接子键**（`[^{}]*`）时返回候选；字符串枚举加引号（`api_esc`），混合枚举保留裸字面量，items 里的 const/enum 不算根标量。流式骨架链在顶层标量 enum 分支前接入该候选表，复用既有 SSE 发射路径。
**门**：静态 `SCHEMA-ROOT-SCALAR`（`enum=["paris","lyon"]`、`const` 折叠为 `"paris"`、items 内 const 不认领、整数 enum 3 值）。
**活体（待填）**：`stream:true` 的 string enum/const 应流式返回 `"paris"`。
**梯（待填）**。
**过程（两次定位错误）**：① 新增 helper 时替换掉了 `api_response_schema_nested_object` 的函数声明，留下裸块 → `TPC_BUILD_FAILED: Unsupported statement: Stmt_Block`（main.php:4594），补回声明即可；② 首版把分支插进了**非流式**骨架链（`$enumFlat` 先命中，分支成死码），流式探针仍散文；改用**流式发射点**（14585 `$sJOut != ""` 之前的兜底）后命中。
**活体**：`stream:true` → `str_enum` `"paris"`（json_ok=True）、`str_const` `"paris"`、`int_enum` `2`（`stream_root_f545b.log`）。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f545c.log`；`SCHEMA-ROOT-SCALAR: PASS`）；bounds **PASS**（`f545c`）；required/count/pattern **CHECK_EXIT=0**；8000 **115/0**；8001 par/fair **0**；scaling **1.569×**；哨兵 **0**。BUILD_OK（`build_f545c`）。
**记法**：**改“另一个传输”先确认分支被谁包围**——同名分支链可能在非流式里；最稳的是把兜底放在**发射点之前**（`$sJOut != ""`），统一覆盖所有骨架来源。helper 插入时锚点要包含函数声明整行。

## F546（2026-11-12）：流式八项成对核对（无新缺陷）
**结果**（`stream_claims_f546.log`）：allOf+required、if+required、`$ref`（定义在前）、const oneOf、propertyNames、unevaluatedProperties 闭包、200 层对象——流式值全部合法；纯界 anyOf 流式 400。F540 的 const 折叠在流式同样生效（`{"city":"paris"}`）。
**结论**：传输级差异只在根标量折叠（已由 F544/F545 闭合）；未发现第三处。
**队列**：`unevaluated*` 子 schema 求值器 → FR-05/多分片。

## F547（2026-11-12）：流式根标量的永久门（smoke 115 → 117）
**改动**：`api_smoke.py` 增 `chat-stream-root-strenum`/`chat-stream-root-strconst`（`stream:true` 下值必须是带引号的 JSON 字符串），把 F545 的一次性探针落成常驻门。
**结果**：`SMOKE: PASS=117 FAIL=0`；8001 par/fair 0；哨兵 0。**基线更新：8000 smoke 自 F547 起为 117/0。**

## 下一步设计（还没开工，供下一轮直接执行）
**contains 非真空形态（`{"contains":{"const":"X"}}` + items 字符串枚举）**：当前 `api_schema_contains_ok` 只接受真空形态（minContains:0 / 空交集），普通 minContains=1 一律 400（F538 活体）。构造满足的落点：
1. **检测器** `api_schema_contains_required($schema)`：单数组属性、items 为 string enum/const 且域含 X、无 maxContains 且 minContains≤1 ⇒ 返回 X。
2. **写入器** `api_array_enum_value` 增参数 `$must`：非 unique 模式下**首个元素**只约束到 `[X]`（后续元素仍走全域）；unique 模式下把 X 排到域首位即可（该模式按序强制）。
3. **关键字豁免**：`api_schema_contains_ok` 在检测器非空时返回 1（先豁免、后保证，两者必须同一次提交，避免“豁免了但没保证”的静默违反）。
4. **四处调用点**（8645/13350/14508/14971）传入检测值；**成对门**：活体 `contains_const_ok`（值里必须出现 X）与 `contains_miss_refuse`（域不含 X ⇒ 400）；流式同形门。
**风险提示**：豁免与保证必须同提交；只改判定不改写入器会制造新的静默违反（F537 的反面）。

## F548（2026-11-12）：contains 的构造满足形态（const/enum ∩ items 域）
**实现**：① `api_schema_contains_required`——无 `maxContains`、`minContains≤1`、单数组属性 items 为 string enum，且根 `contains` 的 `const`（或 `enum` 交集）在该域内 ⇒ 返回必需字面量；② `api_array_enum_value` 增 `$must`：非 unique 模式强制**元素 0** 为该值、`bMin` 提到 1；unique 模式把该值排到域首位；③ `api_schema_contains_ok` 在其非空时豁免（判定与保证同一次提交）；④ 四个调用点传入检测值。
**门**：活体 `contains_const_ok`（200 且 `cities` 含 `paris`）、`contains_miss_refuse`（域不含 `nice` ⇒ 400）。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f548.log`）；bounds **PASS**（`f548`：`contains_const_ok` 200 `{"cities":["paris","paris"]}`、`contains_miss_refuse` 400）；count **PASS**（`f548b`：旧期望 `contains_subset_miss_refuse` 已翻转为 `contains_const_served` 200）；required/pattern **CHECK_EXIT=0**；8000 **117/0**；8001 par/fair **0**；scaling **1.560×**；哨兵 **0**。BUILD_OK（`build_f548`）。
**过程（翻转旧期望）**：count harness 原有一个用例把同形 schema 期望为 400（当时无写入器保证）；放宽后必须**翻转该期望**并改名 `contains_const_served`，否则会被当成回归。这正是“放宽拒绝后还要断言值”的既有规程。
**记法**：**“contains 可判”与“contains 可发”是两件事**——静态能判交集只说明可构造，必须同时给写入器加保证（元素 0 强制）并翻转旧拒绝夹具，否则门与行为会背离。

## F549（2026-11-12）：流式 contains 常驻门
**改动**：smoke 增 `chat-stream-contains-const`（`stream:true` 下数组必须含 `Paris`），把 F548 的保证在流式传输上钉住。
**结果**：`SMOKE: PASS=118 FAIL=0`；8001 par/fair 0；哨兵 0。**基线：8000 smoke 118/0（F549 起）。**

## F550（2026-11-12）：contains 的“同 pattern”构造满足形态
**实现**：`api_schema_contains_pattern_ok`——无 `maxContains`、`minContains≤1`、单数组属性 items 为 pattern 模式（plan `~spec`），且 schema 内 `"pattern"` 字面量与 contains 子 schema 的字面量相同（出现≥2 次）⇒ 返回 1；`api_schema_contains_ok` 豁免。无需改写入器：pattern 模式下**每个元素**都由同一 spec 生成，contains 构造成立。
**门**：活体 `contains_pattern_same_ok`（200，三个大写字母码）、`contains_pattern_diff_refuse`（contains 为不同 pattern ⇒ 400）。
**梯**：自检 **RUN_EXIT=0 / 0 FAIL**（`selftest_f550.log`）；bounds **PASS**（`f550`：`contains_pattern_same_ok` 200 `["CJL","CJL"]`、`contains_pattern_diff_refuse` 400）；required/count/pattern **CHECK_EXIT=0**；8000 **118/0**；8001 par/fair **0**；scaling **1.571×**；哨兵 **0**。BUILD_OK（`build_f550`）。
**记法**：**“全部元素同 spec”是最省力的构造满足**——不需要强制某个元素，只要确认 items 与 contains 是同一 spec；不同 spec 则必须有一个元素同时匹配两者，仍拒绝。


