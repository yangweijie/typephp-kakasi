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
