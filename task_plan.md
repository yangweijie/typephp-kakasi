# Task Plan: typephp-kakasi — TypePHP 多后端推理框架（Kapitsa 引擎移植）

> 本计划基于需求文档 `specs/typephp-inference-framework/requirements.md`（v0.2，决策已闭合）。
> 目的：为接手开发的其他软件/团队提供可执行的阶段化计划。需求已冻结，进入实现阶段。
> 编写日期：2026-11-01　·　当前状态（F209 后）：**Phase 0 complete（GO）· Phase 1 功能面闭环 · Phase 2 主体完成 · Phase 3 已开工（单链投机 2.5× / SSD 前缀缓存 3.3× / OpenAI+Anthropic 四形状 API 含两种流式 + 工具调用含 Anthropic `tool_use` / models import-pull-list-rm 含目录式文件夹与 /v1/models / 并发连接正确性（含流式）/ 工具历史两种 API 形状闭环 F60–F68 / doctor / grammar-constrained 覆盖至 DEEP12·6 维数组·元素内混合叶子·元素内嵌套链·oneOf 分支含 grammar F109–F147·F152–F190）**。
> 回归口径（每次改动都要过）：单二进制全项自检 + 跨后端 logit drift（f16 驻留 5.7e-6；**int8 驻留 1.6e-2**，断言阈值 0.05）+ `logp_parity.py` 的 window-0 判据（f32 0.00132 / f16 0.00130 nats）+ 起服务后 `api_smoke.py`（96 种 API 形状一次验完）。
> **完成度审计**：`docs/audit-v0.2.md`（逐条 FR → 证据/缺口）。当前：FR-01/14/16 完全达成，FR-03 以 DPC++ 替代达成；FR-02/04/06/07/08/09/10/12/13/17/18 部分达成（缺口逐条列出）；FR-05/11/15 未做或本机不可验。**整体未完成**。
> **下一步（按"缺口 × 可验证性"，2026-11-04 更新）**：⓪ **冷启动首调返回空＝功能上已闭合**（F226 修复＋F232 收窄版复验：`run_f228.log` 137 PASS/0 FAIL、`SCHEMA-DEEP200` 按首调判据 PASS、`first_len=3909`）。**该增量仍未收口的三件**：(a) 8000 `engine/tests/api_smoke.py`（含新门 `chat-json-schema-deep40-first-call`）与 8001 `par_smoke.py`/`fairness_test.py` 门禁**正在跑**（后台链 `b92wbrol6`，8000→停→8001，一次一个引擎进程）；(b) native 根因未证——只剩 F228 两条分支（`rows==0` / 整行 NaN），探针已写好但构建前 revert（`82cc910`→`8afcf73`），要单独一轮加 `model_sycl.cc:1323` 判别量再测；(c) `session_next_allowed` 里 `g_sess_len += 1` 早于 `r.empty()` 判定（`src/model_sycl.cc:1496`）未修，且收窄的时间收益缺**未收窄版的秒表对照**，不得报数。
> （以下为历史条目，按时间保留：）⓪ **冷启动空返回（唯一未闭合的功能性 bug，F208→F221）**：同一会话内第一条 200 层约束解码返回空、第二条 3909 字符合法 JSON（`run_f221.log` 以**干净全量重建**的二进制复现：137 PASS / 0 FAIL / `first_len=0 second_len=3909`）。F221 已把嫌疑**重新打开到前缀阶段**（F215/F218 的"前缀无辜"依赖错误的产物文件，作废）。下一次的打法：在 `api_nested_object_value` 的**每个** `return "";` 前打编号标记，一次构建后**只看自检输出**（输出出现＝证据；`extension-*.cc` 里的字符串不作判据，见 F221 规程），一次枚举全部出口；若输出一个都不出现，则在调用点打出入参。**判据**：`SCHEMA-DEEP200-RETRY` 的 `first_len` 转非零。
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
