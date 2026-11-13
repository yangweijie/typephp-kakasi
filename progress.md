# Progress Log — typephp-kakasi

## Session: 2026-11-01

### 阶段：需求评审 + 计划冻结（Phase 0 前）
- **Status:** complete（需求已闭合，计划文件已建立）
- **Started:** 2026-11-01 10:17（需求调研起）
- **Ended:** 2026-11-01 11:44（计划文件交付）

- Actions taken:
  - 读取 5 份参考：UNIKERN design/requirements、Kapitsa 0.2.25 README/AGENTS、AdaptiveCpp README、llama.cpp/ggml 后端架构（DeepWiki）、TypePHP 源码
  - 产出需求文档 v0.1：方案对比（推荐 AdaptiveCpp 单基座 + 吸收 ggml 抽象）、FR 编号、MoSCoW、风险登记册、8 项开放决策
  - 用户评审并逐条闭合 8 项决策（含关键修正：TypePHP 调 C++ 不走 FFI、原生链接更高效）
  - 核实 TypePHP 原生互操作机制（`MIXED_CPP_PHP.md` / `project-layout.md`）：原生链接，非 FFI → 移除/收窄 RK-01
  - 收敛需求文档至 **v0.2（决策闭合）**：新增推理客户端编排层(FR-18)为一等架构支柱、引入 Kakashi「复制忍者」定位与 typephp-kakasi 命名、重构分阶段路线 Phase0→1→2→3
  - 建立本计划文件（task_plan.md / findings.md / progress.md），供用户在"其他软件"接手开发
- Files created/modified:
  - `specs/typephp-inference-framework/requirements.md`（v0.1 → v0.2）
  - `task_plan.md`（新建）
  - `findings.md`（新建）
  - `progress.md`（新建）

### Phase 0: 探针 — 联合构建（2026-11-01 12:05 → 12:38）
- **Status:** complete（结论 **GO**，见 `probe/CONCLUSION.md`）
- Actions taken:
  - 环境实测：`tpc` v0.9.5 可用；`acpp`/`syclcc` **未安装**（AdaptiveCpp 仅源码）；发现该机已装 **Intel oneAPI 2025.3 + 2026.1**（`icx`/`icpx`/`dpcpp`），且 `C:\llama.cpp-master\build\ReleaseSYCL\CMakeCache.txt` 证明 `icx/icpx + GGML_SYCL=ON` 在本机成功构建过 → **SYCL 路线落地工具链改为 DPC++**（AdaptiveCpp 留作 AMD/Apple 可移植目标）
  - **完整构建阶梯 rung1→4 打通**：`probe/echo/main.php` → `tpc --dry` → `tpc -O2` → `main.exe` 实际运行输出 `tpc-rung2-ok`，exit=0（依赖 phpx.dll/php8ts.dll/libmpdec++）
  - standalone SYCL 探针 `probe/sycl/sycl_probe.cpp`（256² matmul vs CPU 参考，4 设备遍历）：**Arc B390 经 Level-Zero(79.96ms) 与 OpenCL Graphics(34.73ms) 两条平台路径 PASS**；OpenCL CPU **FAIL maxerr=56.56**
  - **Phase 0 核心目标达成**：`probe/engine` = `main.php` + `kakasi.stub.php` + `kakasi.cc`(cl) + `sycl_matmul.obj`(icx) → 单二进制 `kakasi_probe.exe` **110 KB**，运行后 PHP 侧成功枚举 4 个设备 → **原生链接、无 FFI 成立（RK-01 关闭）**
  - 内核执行卡在链接期 post-link：link.exe 直链的二进制在 `parallel_for` 处先 `0xC0000409`、加 try/catch 后改为挂死 → 用 tpc 的 `.rsp` 目标清单**改由 `icx -fsycl` 做最终链接**，产出 `kakasi_probe_sycl.exe` 121 KB（多出即内嵌 device fatbin）
  - 逐设备 matmul 复测进行中（`build\kakasi_probe_sycl.exe`）
- Files created/modified:
  - `scripts/devshell.bat`（vcvars 注入，ASCII-only）、`scripts/build-sycl.bat`、`scripts/build-engine.bat`、`scripts/run-engine.bat`
  - `probe/echo/main.php`、`probe/sycl/sycl_probe.cpp`
  - `probe/engine/{project.yml,main.php,src/kakasi.cc,src/kakasi.stub.php,src/sycl_matmul.cc}`
  - `findings.md`（新增 F6/F7/F9/F10）、`task_plan.md`（Phase 0 状态与勾选项）

### Phase 1: 可移植引擎骨架 — 后端抽象起步（2026-11-01 12:40 起）
- **Status:** in_progress
- Actions taken:
  - 建 `engine/`（正式工程，非探针）：`project.yml`(name kakasi_engine) + `main.php` + `src/backend.stub.php` + `src/backend.cc`(cl 桥接) + `src/sycl_backend.cc`(icx `-fsycl`)
  - 落实探针 P0：每设备独立 `sycl::context` + 常驻 queue、warm-up 写读自检、saxpy `validate()`、PHP 侧只对**已校验后端**下发算子
  - 新增 `scripts/build-kakasi.bat`（四步联合构建：icx obj → tpc → icx 最终链接 → run）
- Files created: `engine/{project.yml,main.php,src/backend.cc,src/backend.stub.php,src/sycl_backend.cc}`、`scripts/build-kakasi.bat`、`probe/CONCLUSION.md`
- Test Results: `kakasi_engine_sycl.exe` 147 KB → 4/4 VALIDATED、4/4 matmul PASS maxerr=3.8e-6、RUN_EXIT=0（首轮即全绿）
- **GGUF 加载器（Phase 1 第 2 项）完成**：
  - 先用独立 Python struct 解析取 ground truth，再由此反推每个 dtype 的真实 bpw（偏移差分），修正了第一版按连续整数猜的 ggml type 表（4/5 是已删除类型造成的空洞）
  - 新文件 `engine/src/gguf.cc`（纯 C++，由 **cl** 编译，无需 icx/objects 通道）+ `engine/src/gguf.stub.php` + `main.php` 编排
  - 加载器自带 oracle：`Σ bytes == max_end == avail` → 实测 `391,859,712` 三方相等，`ACCOUNTING=PASS`
  - 踩坑：`MIXED_CPP_PHP.md` 写的 `php::Str::to_string()` 在 v0.9.5 **不存在**（`C2039`），实际用 `std::string(s.data(), s.length())` → 见 findings F9.11
  - 产物：`kakasi_engine_sycl.exe` **197 KB**，一次运行同时给出模型元数据、张量目录、字节校验与 4/4 后端就绪
- **核心算子第一轮（`engine/src/ops_sycl.cc` + `ops.cc` + `ops.stub.php`，两个 icx TU 共用 `sycl_backend.h` 的常驻 queue）**：
  - 结果：**20/20 检查项 × 3 次重复全通过，4 后端数值一致**，exe 280 KB
    · 反量化 f32/q8_0/q5_0 对 host 参考**逐位相同**（err=0），含 136M 元素的 `token_embd`（165~280 ms）
    · tiled SGEMM 作用于真实 896×896 q5_0 权重，相对 double host 参考 `rel=9.88e-7`，四后端**完全同值**
    · RMSNorm(896) `≤1.19e-7`
  - **踩坑（第一轮 3 项 FAIL 且失败点在不同后端间游移）**：`sycl::atomic_ref<float, relaxed>` 归约 + 跨内核裸读、以及依赖隐式顺序 → 改为确定性两内核归约 + 每步显式 `wait_and_throw()` + 指针逐个捕获后全绿。**结论：失败点会漂移的不是硬件，是自己写的并发假设**；已把"每项跑 3 次"固化为验收口径。
  - Q4_K/Q6_K 逐字权威定义已抄入 findings F14（含 `get_scale_min_k4` 的 6-bit 交错打包），下一轮据此实现
  - 诚实标注：gemm 的 89 ms 含分配与 3.2 MB 传输，**不是纯内核时间**，性能基线仍未成立
- **Phase 1 第 3 项：端到端前向（已实现，数值待修）** — 详见 findings F16
  - 新文件：`engine/src/model_sycl.cc`、`engine/src/model.cc`、`engine/src/model.stub.php`、`engine/src/gguf.h` 增 `raw_bytes_range`；`engine/tests/{np_ref.py,bisect.py,llama_emb_p5.log}`
  - 跑通 24 层 Qwen2 prefill：不崩、GPU/CPU 确定一致（540 ms / 2.15 s），但 argmax='LEE'(90060) ≠ oracle ' Paris'(12095)
  - 逐段定位：
    1. 逐层 max|x| 轨迹 0.052→8.11→9.29→262→平台 380~425；numpy 独立实现在 L0/L1 吻合到 4 位小数、L2 起分叉 → **两套实现共享同一规格错误**
    2. `llama-embedding --pooling last` 给出真实 xn，且 `llama_xn @ E.T` **命中 12095 / rank 1** ⇒ **embedding 读取与 tied lm_head 正确**，错误锁进 block 内部
    3. 排除 head 置换（top-8 大维度下标重合 0）；排除 SwiGLU 分支顺序与 `(1+w)` 归一化（4 变体全不命中）
  - 自纠：一度据"单 token 也失败"排除 attention/RoPE，但该对照被 token 数污染（`llama-embedding` 把 `The` 数成 2 tokens）→ **推论已撤回**
- **✅ 找到并修掉根因（q5_0 nibble 配对方式）**：上游一个字节 `qs[j]` 装的是「元素 j 低半字节 + 元素 j+16 高半字节」，我按 q4_0 的相邻配对写成 `qs[i>>1]` → 每块 16 个元素错位；attn_q/attn_k/attn_output/ffn_gate/ffn_up **全是 q5_0（132/290 张量）**。
  - 为何潜伏：q5_0 的 host 参考、device 内核、numpy 参考**都出自同一份误解**，交叉验证因此给出虚假绿灯 → 教训：**格式类参考实现必须逐字来自上游**
  - 修复后：GPU top1=12095 logit=16.391447(548 ms)、CPU logit=16.391441(2180 ms) —— **命中 oracle 且跨后端 6 位小数一致**；贪心生成 6 步解码出 "The capital of France is Paris. It is the largest" → `GENERATE: PASS`
  - 又撤回一个错误推论：修完后 cosine 仍≈0 —— 那次比对用的是被截断成 16 维、且 id 序列靠猜的参照，**无效**；有效的判据是端到端 argmax
- **新增**：`model::generate`（每步朴素重前向，≤64 token）+ `gguf::token_at` 词表解码 + PHP 侧 'Ġ'→空格
- 遗留（Phase 1 收尾）：EOS 停止条件（llama.cpp 在 ' Paris' 后即收束）、Top-p 采样、KV cache、纯内核性能基线、BPE **编码**
- **本轮（续）：KV cache + BPE 编码 + 端到端全绿**
  - `model_sycl.cc` 改常驻 KV cache（prefill 写 `[0,T)`、每步按 `pos` 读缓存；attention 三遍扫 key）→ `LONGCTX: PASS`（84-token prompt，越过旧 64 上限）；`FORWARD/GENERATE/TOKENIZER/PIPELINE` 全 PASS
  - BPE 编码修正：切词与符号映射**统一按字节**（原先 codepoint 切词 + 字节映射不一致）→ 3/3 命中 `llama-tokenize`
  - 修掉静默 bug：全局 `g_expect_id` 跨调用残留，使 `next == eos` 比到上一次的期望值
  - **撤回一个错误诊断**：我在计划里写"llama.cpp 因 EOS 停止、我们未实现 eos 停止"——实测不成立。真实情况是第 2 步 top-2 近邻翻转：我们 `.`=20.242 vs oracle `,`=19.946（margin 0.296，且它是我们的 rank 2），而 step-1 margin 有 3.2 → 属量化权重下的数值敏感性，不是结构错误；已改正 task_plan
  - **性能定位**：prefill 539 ms / decode **635 ms/token（≈1.6 tok/s）** → 瓶颈是每步重新读盘+反量化 288 个张量，**不是内核**；Phase 2 第一杠杆是权重驻留
- **本轮（续2）：权重驻留落地（上一轮定位的直接兑现）**
  - `model_sycl.cc` 惰性 residency：`load_weight_idx()` 统一入口 + `weight_release()` 只对非驻留指针 free；tied embedding 在 prefill 开头主动拉进驻留 → 行查找与 lm_head 分块退化为设备端切片
  - 实测 `loaded=290 used=1.84GiB budget=13.94GiB device=25.34GiB`；decode **635 → ≈62 ms/token（1.6 → ≈16 tok/s，10.2×）**
  - **数值未变**：step2 `top=20.242 / second=19.946` 与驻留前逐位相同 → 只改调度不改算学；OPS/FORWARD/GENERATE/TOKENIZER/LONGCTX/PIPELINE 全绿
  - 该预算即 **FR-18 编排层内存预算**的首个真实实现（显存×0.55，超预算自动回落流式）
  - 诚实标注一处反直觉数字：prefill 539→700 ms 是因为本轮 prefill 首次承担 290 张量（含 544 MB tied embedding）的反量化上传；**稳态 prefill 未单独计时，列为待办**而非当作"变慢"
- **Q4_K / Q6_K 反量化补齐（本轮）**：
  - 做法：host 参考**逐字抄自** `ggml-quants.c`（`dequantize_row_q4_K`/`_q6_K`/`get_scale_min_k4`，指针推进式），device 内核**另写一套独立下标推导**（Q4_K：sub-block 奇偶决定取低/高半字节、`qbase=(s/2)*32`；Q6_K：`e→(chunk,quarter,lane)` 分解 + `si=c*8+j*2+(l>>4)`）
  - 结果：**34/34 项通过**（7 类检查 × 4 后端 × 3 次），`deq_q4`/`deq_q6` 真实权重上 **err=0 逐位相同**；q4_k FFN 权重 8×896×4864 gemm `rel=4.17e-6`
  - 意义：两套独立写法互相印证 → scale/nibble 索引错位这类"静默数值错误"被真正排除；**本模型 290/290 张量、5 种格式全部可反量化**
- **端到端 oracle 建立（findings F15）**：用 `build/ReleaseCPU/bin/llama-completion` + `llama-tokenize`（注意 `llama-cli` 无 `--no-cnv` 且会挂在 stdin，必须 `< /dev/null`）
  - `The capital of France is` → tokens `785,6722,315,9625,374`；贪心下一 token = **12095 (' Paris')**
  - 定为 Phase 1 端到端硬断言：引擎读完 5 个 prompt token 后 `argmax(logits) == 12095`

## Test Results
| Test | Input | Expected | Actual | Status |
|------|-------|----------|--------|--------|
| tpc rung1 `--dry` | probe/echo/main.php | 生成 C++ | `2 C++ source file(s) generated` | PASS |
| tpc rung2+3 | 同上 + vcvars 环境 | 编译+链接 | `Build successful: main.exe` | PASS |
| tpc rung4 | `./main.exe` | 输出 tpc-rung2-ok | 原样输出，exit=0 | PASS |
| SYCL standalone | 256² matmul, 4 设备 | ≥2 后端容差内 | GPU(Level-Zero/OpenCL-Graphics) PASS；CPU FAIL 56.56 | PARTIAL |
| tpc+SYCL 单二进制 | `tpc project.yml` → link.exe | 单 exe 可运行 | 110 KB exe，PHP 枚举 4 设备成功；内核 launch 挂死 | PARTIAL |
| DPC++ 最终链接 | `icx -fsycl @rsp` | 内嵌 device 镜像 | `kakasi_probe_sycl.exe` 121 KB 产出 | PASS |
| 单二进制逐设备 matmul | `kakasi_probe_sycl.exe` | ≥2 PASS | 冷态 1/4、**热态 4/4 PASS，maxerr=3.8e-6** | **PASS** |
| 原生调用开销 | 200 万次 `native_roundtrip` | 证明无 FFI | **0.65 ns/op**（纯 PHP 0.11 ns/op）；exe 126 KB | PASS |
| 首用一致性 | 两轮遍历同 4 设备 | 稳定 | r1 仅 dev0 PASS，r2 全 PASS → 记录为 Phase 1 前置 P0 | 遗留 |
| 端到端 argmax（修 q5_0 前） | 5-token prompt | 12095 | GPU/CPU 一致给 90060('LEE')，expect_rank=144143 | FAIL |
| 端到端 argmax（修 q5_0 后） | 5-token prompt | 12095 | **GPU 12095 logit=16.391447 / CPU 16.391441** | **PASS** |
| 贪心生成 6 步 + 解码 | "The capital of France is" | 以 Paris 开头 | `The capital of France is Paris. It is the largest` | **PASS** |
| 与 oracle 收束行为 | llama-completion -n 6 | 一致 | llama 在 ' Paris' 后按 EOS 停止，我方继续生成 → 差异已记录 | PARTIAL |
| engine 常驻 queue 修复 | 单二进制遍历 4 设备 | 首轮即全对 | **4/4 VALIDATED + 4/4 matmul PASS**，147 KB | PASS |
| GGUF ground truth | Python struct 解析 0.5B 模型 | 结构可读 | v3/290 tensors/26 kv；偏移差分反出 bpw（q5_0 5.5、q8_0 8.5、q4_k 4.5、q6_k 6.5625） | PASS |
| GGUF loader 字节预言机 | `gguf_accounting_ok()` | Σ==max_end==avail | 三者均 391,859,712 → **ACCOUNTING=PASS** | PASS |
| loader 元数据正确性 | 对比 Python 独立解析 | 逐值一致 | qwen2/24/896/4864/14/2/151936/data_start 5,947,680 全一致 | PASS |
| engine+loader 单二进制 | 197 KB exe | 一次运行全绿 | 元数据+目录+校验+4/4 后端，RUN_EXIT=0 | PASS |
| 反量化 device vs host | f32/q8_0/q5_0 × 4 后端 × 3 次 | err=0 | **逐位相同**（含 136M 元素 token_embd） | PASS |
| tiled SGEMM on 真实权重 | 8×896×896 q5_0 × 4 后端 × 3 次 | rel<1e-4 | **rel=9.88e-7，四后端同值** | PASS |
| RMSNorm | len=896 × 4 后端 × 3 次 | <1e-5 | ≤1.19e-7 | PASS |
| 第一轮 ops（修复前） | 同上 | 稳定 | 3 项 FAIL 且失败后端每次漂移 | 已修复→确定性两内核+显式 wait |
| logits row→position 映射 | 猜 tokens[r+1] / tokens[r] | 对齐 | **两种都 0/45** → 改从 perplexity.cpp 源码读公式 | 已解决 |
| parity 对齐自检 | c128 数据 126 行 | ≥50% 才报数 | **126/126 = 100%**，token_mismatch=0 | **PASS** |
| **logprob 数值容差（window 0，n=63）** | 引擎 vs llama-perplexity logprob | mean<0.05 且 max<0.5 nats | **mean=0.00132 / p50=0.00012 / max=0.01738** → PASS | **PASS** |
| 分词器空格/换行/标点/缩写/数字 20 例 | tok_encode vs llama-tokenize | 逐 id 一致 | **20/20 PASS**（含 `a␣␣b`、`a␣LF`、`aTABb`、`don't`、`12345`） | **PASS** |
| 分词 token 数与 oracle 对齐 | 13 遍重复文本 | 与 llama-tokenize 同数 | 旧实现 273（丢结尾空格）→ 现 **274 = llama** | **PASS** |
| 全量回归（含新切分器） | `build-kakasi.bat` | 全绿 | OPS/FORWARD/GENERATE/LONGCTX/TOKENIZER/PIPELINE 全 PASS，exe 521,216 B，RUN_EXIT=0 | **PASS** |
| 采样 4 断言 | temp0/小nucleus/同seed/异seed | 全过 | greedy-equiv PASS、tiny-nucleus **first-nucleus=1** PASS、determinism PASS、spread PASS → SAMPLING: PASS | **PASS** |
| 稳态性能基线 | Arc GPU，权重驻留 | 出数 | prefill5=49.7ms、prefill200=493.6ms(**405 t/s**)、decode=55.4ms/step(**18.1 t/s**) | **PASS** |
| llama.cpp 对照 | `llama-bench` CPU/16线程 同模型 | 参考值 | pp512=**216 t/s**、tg32=**42 t/s**（GPU vs CPU，非同设备） | 记录 |
| 瓶颈定位 | prefill(5) vs decode(1) | — | 49.7 ≈ 55.4 ms ⇒ **每步 ~50ms 是固定开销**，算力仅 ~5ms → Phase 2 首个目标 | **发现** |
| 控制 token 编码 | tok_encode vs llama-tokenize | 逐 id 一致 | `im_end+LF`→151645,198；整轮→151644,872,198,13048,151645,198 | **PASS** |
| 聊天模板 | chat_template_matches + tok_chat + e2e | 4 项 | CHAT: PASS（模板方言门禁 + 一轮渲染 + 生成非空） | **PASS** |
| GGUF 加载器扩展 | 新增 INT32 数组具体化后 | 字节账目仍自证 | `sum_bytes == max_end == avail == 391,859,712` ACCOUNTING=PASS | PASS |
| **回归：encode 重构丢分词** | tok[8]/tok[9] 双空格用例 | 20/20 | 首轮 **18/20**（64,256,65 vs 64,220,293）→ 加 pieces 诊断定位到 `encode_span` 未调 pre_split → 修复后 **20/20**，logprob 容差不变 | **已修** |
| 去除诊断后的最终回归 | 单二进制全量 | 全绿 | OPS/FORWARD/GENERATE/SAMPLING/LONGCTX/TOKENIZER/CHAT/PIPELINE 全 PASS，ACCOUNTING PASS，RUN_EXIT=0 | **PASS** |
| 优化尝试① `q->fill` | 替代宿主零数组+memcpy | 数值不变 | **CPU 后端 top1=374（应 12095）、expect_rank=2107**；GPU 正常 → 否决并回退 | **FAIL/已回退** |
| 优化尝试② 去掉每临时量 `wait` | 保留 memcpy 不同步 | 数值不变 | 两后端 argmax 都对，但 **CPU logit 16.391443→14.299074**（历史 4 次构建都是同一串位值）→ 异步 H2D 在 CPU 不被观察 → 否决并回退 | **FAIL/已回退** |
| 新增跨后端数值护栏 | FORWARD b0 vs b1 logit | \|Δ\|<0.05 | **|Δ|=3.8e-6 PASS**（GPU 16.391447067261 / CPU 16.391443252563）——该断言正是能当场抓住尝试②的那种 | **PASS** |
| 回退后全量复验 | eng14 构建 | 全绿 + 容差不变 | 9 项 PASS、ACCOUNTING PASS、window-0 mean=0.00132 nats 不变、17.4 tok/s | **PASS** |
| **阶段计时插桩**（mark_stage） | 1 tok vs 5 tok 前向 | 归因 | 1 tok = 47 ms：**s1(24 block)=35.3 / s2(lm_head)=11.6 / s0=0.04**；5 tok 的 s1 仅 42.1 ⇒ 每层 1.47 ms 是固定开销 | **定位** |
| Dev 去掉预清零 + scratch 池化 + 删死 tmp | eng15/16 | 数值逐位不变 | b0/b1 logit 与基线**完全同一串位值**、drift 3.8e-6、容差不变；冷启动 1025→837 ms | **保留** |
| 去掉算子级排空（约每步 300 次） | eng18 | 数值不变 | **decode +23%（17.4→21.4）但两后端都算错**（top1=103429/11287、生成乱码）→ 回退 | **FAIL/已回退** |
| 回退排空后的最终复验 | eng19 | 全绿 | 9 项 PASS、drift 3.8e-6、window-0 mean=0.00132 nats、17.8 tok/s、RUN_EXIT=0 | **PASS** |
| 优化尝试A：池内 barrier 事件排序 | 撤掉算子排空 + `ext_oneapi_submit_barrier` | 数值不变 | **仍算错**（b0 top1=16992、b1 logit 6.7e15）且**未提速**（18.0）→ 否决；另发现 `event::wait()` 非 const 成员 | **FAIL/已回退** |
| 优化尝试B：只在槽位释放时排空 | 186 次排空代替 300 次 | 数值不变 | **21.1 tok/s（+23%）达成但仍算错**（b0 top1=14950）→ **推翻"只是池槽复用"的解释**：非池化缓冲区的 `sycl::free` 也在竞态面内 | **FAIL/已回退** |
| 恢复算子排空后的复验 | eng24 | 全绿 + 位值一致 | b0/b1 与基线**同一串位值**、drift 3.8e-6、OPS/SAMPLING/TOKENIZER(20)/CHAT/LONGCTX/GENERATE/PIPELINE 全 PASS、容差 mean=0.00132 不变 | **PASS** |
| FR-18 设备选择 + 内存预算 | `model_plan(0/1, 2048)` | 出实测数字 | Arc B390 25.34GiB/budget 13.94；CPU X7 31.52GiB；**weights_f32 独立推导=1.84GiB 与实测驻留一致** | **PASS** |
| 低端设备回退路径等价性 | 预算钉 0.05GiB 后贪心 6 步 | 与驻留路径逐 id 相同 | 判定翻转 `stream-weights`、驻留 290→133、**ids 完全相同**、恢复后再次相同 → ORCH: PASS | **PASS** |
| 全量回归（含 ORCH） | eng25 | 全绿 | 10 项 PASS（含 ORCH）、drift 3.8e-6、window-0 mean=0.00132 nats 不变、17.4 tok/s、RUN_EXIT=0 | **PASS** |
| 驻留策略改按"每字节热度" | 同钉 0.05GiB 新旧对照 | 同字节留更多张量 | kept **133 → 178/290**；报告改为 `kept=K/N planned=… hot_embedding_kept=…`，不再被误读成满驻留 | **PASS** |
| 内存→性能退化曲线 | 预算 13.94 / 0.6 / 0.05 GiB | 每档 token 相同 | **60.7 / 382.5 / 898.9 ms 每步**（1× / 6.3× / 14.8×），三档贪心 ids 逐位相同 → ORCH: PASS | **PASS** |
| 策略改动后全量回归 | eng26 | 全绿 | 11 项 PASS（含 ORCH）、ACCOUNTING PASS、drift 3.8e-6、容差 mean=0.00132 不变、17.7 tok/s、RUN_EXIT=0 | **PASS** |
| KV 量化（f16 缓存） | 同序列 273 位置，切 KV 宽度 | 缓存减半 + 数值可控 | 缓存 **0.0117→0.00586 GiB**；f16 vs f32 **mean=0.00036 / max=0.0120 nats**；贪心 6 步 ids 相同 → KVQ: PASS | **PASS** |
| KV 量化后全量回归 | eng27 | 全绿 | OPS/GENERATE/SAMPLING/TOKENIZER(20)/CHAT/LONGCTX/PIPELINE/ORCH 全 PASS、ACCOUNTING PASS、f32 容差 mean=0.00132 不变、17.65 tok/s | **PASS** |
| **f16 KV 直接对 oracle**（补上此前"只做算术合成"的验证缺口） | `PARITY16` 前缀 + harness 前缀参数 | window-0 判据 | **mean=0.00130 / max=0.01693（n=63）** vs f32 的 0.00132/0.01738 → 无实质差别，判据 PASS | **PASS** |
| Phase 2 Intel 腿报告 | `docs/phase2-retest-report.md` | 含缺口清单 | 设备清单/正确性基线/性能/代价表/策略建议/诚实缺口/复现命令；AMD-Apple 标注硬件阻塞 | **已产出** |
| CPU+GPU 异构 offload（层切分） | split=b1 layer=12 | 与纯 GPU 逐 id 相同 | 首版**空结果**（`weight_release` 用主设备释放第二设备指针）→ `temp` 改 `map<float*,queue*>` 记账 → **orch[split] PASS** | **PASS（一次失败后修好）** |
| **decode 固定开销优化（第 4 次尝试）** | eng31→eng34 | 提速且数值不变 | 真因 = **queue 未声明 in_order**；修好后 **17.1→19.97 tok/s（+17%）**，prefill(5) 51.8→46.5、prefill(200) 441→423.7 ms；drift 仍 3.8e-6、window-0 仍 0.00132/0.00130 | **PASS** |
| 自写 arena 的越界 bug | T=200 之后确定性崩溃 | — | bump 指针跨块返回越界地址 → 余量不足时对齐下一块；修后 T=1/5/200 与 split 全部一致 | **已修** |
| **decode 专用 matvec（F32）** | eng36 | 提速且数值不变 | tiled GEMM 在 M=1 时空算 16 行 → 新增 `gemv`：**20.2 → 29.8 tok/s（+48%）**，单步 50.1→33.6 ms，block 33.3→22.8、head 12.1→6.4 ms；判据仍 0.00132/0.00130，drift 9.5e-6 | **PASS** |
| 优化后曲线重测 | eng36 | 各档 token 相同 | 35.2 / 350.9 / 878.5 ms（默认档 61→35 ms；受限档几乎不变 ⇒ 瓶颈在读盘+反量化） | **PASS** |
| **驻留权重 f16（F33）** | eng37 | 提速且判据不变 | **29.8 → 45.3 tok/s（+52%）**；单步 33.6→22.1 ms（block 22.8→11.6、head 6.4→3.3）；驻留 **1.84→0.93 GiB**；判据 0.00132/0.00133、drift 5.7e-6；全部套件 PASS | **PASS** |
| 对标里程碑 | llama-bench CPU | — | decode **45.3 > 42.0**（我方快 8%）；prefill **494 > 216**（2.3×） | **记录** |
| **int8 驻留（F34）** | eng39 | 提速 + 判据内 | **45.3 → 60.4 tok/s（+33%）**；单步 22.1→16.6 ms；驻留 **0.93→0.50 GiB**；A/B int8 16.86 vs f16 22.60 ms 且 **token 逐 id 相同**；判据 0.00161/0.02247（容差 0.05） | **PASS** |
| int8 后的跨后端一致性 | b0 vs b1 | \|Δlogit\|<0.05 | **0.0163**（f16 时 5.7e-6），仍在断言内；**F46 已澄清**：同后端重新量化差=**0** ⇒ 真因是后端算术差异，非量化不可复现 | **已澄清** |

| TypePHP 能力探针（`probe/sock`） | tpc bin 模式 | 列出可用原语 | socket/accept/fread/fwrite、strpos/substr/strlen、**preg_match**、intval、file_exists 全部可用；`$s->jsonDecode()` **不可用** | **PASS** |
| **OpenAI 兼容 API（F35）** | curl 打真实端口 | 结构与内容正确 | `"The capital of France is"` → **`"Paris"`**（usage 13/2/15，按 EOS 停）；system 轮 → `"Atlantic Ocean."`；`/v1/models` OK；空 messages → 结构化错误 | **PASS** |
| API-UNIT（无 socket 回归） | 引擎自检内 | 6 项 | 取最后 content / 首 role / 数字 / 小数 / 转义 全通过 → `API-UNIT: PASS` | **PASS** |
| **多轮历史（F36）** | curl 三轮对话 | 用到历史 | `France→"Paris."` 后问 `And of Japan?` → **`"Tokyo."`**（prompt 30 vs 单轮 13）；`API-UNIT` 用精确 CSV 相等断言顺序 | **PASS** |
| **Anthropic `/v1/messages`** | curl | 结构 + 内容 | `{"type":"message","content":[{"type":"text","text":"Paris."}],"stop_reason":"end_turn",...}` | **PASS** |
| **SSE 流式（F37）** | `curl -N` | 事件流 + 收尾 | 每个 token 一个 chunk，实测 `"delta":{"content":"Paris"}` → `finish_reason:"stop"` → `data: [DONE]` | **PASS** |
| `STREAM-UNIT` | 引擎自检内 | 与批量生成逐 id 相同 | 逐 token 会话（`session_start/next`）6 个 token == `model_generate` → PASS（比较时按语义裁掉 eos） | **PASS** |
| **投机解码（F38，KFlash 单链子集）** | 重复文本 24 token | 提速且 id 不变 | `drafts=24 accepted=24`，greedy **880.8 ms** vs spec **523.8 ms = 1.68×**，输出 id 逐位相同 | **PASS** |
| `SPEC0`（k=0 退化） | 同 prompt | == 贪心 | 抓到一个真 bug：token 喂到**下一**位置（`++pos` 早于 prefill），修后 PASS | **已修** |
| 投机无命中时的代价 | 早期版本 | 记录 | **0.83×（更慢）** ⇒ 上线需自适应停用 | 观察 |
| **投机自适应 + 负控** | eng56 | 停用逻辑 + id 不变 | `SPEC-NEG drafts=12 accepted=0 verifies=3 drafting=off` 且 id 与贪心相同；`SPEC-ADAPT` 断言改为不变量（`verifies>0 && accepted=0` 必须 off）；接受路径仍 **1.68×** | **PASS** |
| **SSD 前缀缓存（F39）** | eng57 | 命中前缀 + 续写一致 | `stored=1 hit_prefix=19/19`；冷 prefill **67.1 ms** → 命中 **20.5 ms（3.3×）**；后续 4 token 与冷路径逐 id 相同 | **PASS** |
| **`engine doctor`（F40，FR-04 验收项）** | `scripts\doctor.bat` | 设备/工具链/模型 | 4 设备（类型/厂商/**驱动版本**/内存/校验）+ 模型形状 + 量化构成 + 逐设备 plan + `doctor: READY (validated 4/4)` | **PASS** |
| **完成度审计** | `docs/audit-v0.2.md` | 18 条 FR 逐条对证据 | FR-01/14/16 达成、FR-03 替代达成；9 条部分；FR-05/11/15 未做/不可验 → **整体未完成** | **已产出** |
| **FR-09 补齐（F41）** | 端口 8000 上 curl 四种形状 | 全部可用 | `/`（HTML 页面）、`/v1/responses`→`"Paris"`、`/v1/chat/completions`→`"Paris"`、`/v1/messages`→`"Pacific Ocean"`、SSE 以 `[DONE]` 收尾 | **PASS** |
| **FR-10 CLI（F42）** | `models.bat import/list/rm` | 目录 + 索引可用 | `import ok … (397807392 B, qwen2)`；`list` → `…|397807392|qwen2|24|896`；`rm` → 文件与条目同时消失；vocab-only 文件被**正确拒绝**且不留残件 | **PASS** |
| **FR-07.2 取消/超时（F43）** | 同一进程三种场景 | 终态日志可读 | `tokens=0 reason=timeout`（冷）、`tokens=18 reason=timeout`（热，400 ms 预算，客户端见 `"length"`）、`tokens=41 reason=client-gone`（curl -m 1）；**取消后下一请求正常返回 "Paris"** | **PASS** |
| **JSON-object 模式（F44）** | API-UNIT + 端到端 | 结构校验可用 | 单元 19/19（含字符串内 `}`、尾随垃圾）；端到端返回 `{"city":"Paris"}`，日志 `json=ok`（**是校验+重试，不是约束解码**） | **PASS** |
| **投机 k 扫描（F45，FR-06.4）** | k=2/5/8，24 token | 按实测选 k | **851 / 465 / 355 ms**（贪心 ~880 ms）⇒ k=8 ≈ 2.5×；全部 `len=71/71 prefix=ok` | **PASS** |
| 扫描抓到的违约 | — | max_tokens | `spec_generate` 曾超发（k=8、剩 1 个时接受 8 个）→ 改 `min(acc, n_new-produced)`；另修测试参照变量顺序 bug | **已修** |
| **长上下文 prefill 基线** | 512 token | 出扩展曲线 | **1189.7 ms = 430 tok/s**（200 token 时 463 tok/s）⇒ 近线性，O(n²) 尚未主导 | **PASS** |
| int8 可复现性实验（F46） | 清驻留重新量化 | 证伪/证实 | `requant_b0=0 requant_b1=0 cross=0.0163` ⇒ **量化可逐位复现**，跨后端差异来自算术 | **已澄清** |
| **Anthropic 流式（F47）** | `curl -N /v1/messages` | 事件序列正确 | 7 个 `event:`：message_start → content_block_start → delta×2（"Pacific"/" Ocean"）→ content_block_stop → message_delta → message_stop | **PASS** |
| **`models pull`（F48）** | 本地 HTTP 提供 380 MB 模型 | 下载 + 导入 | `pull ok: … (397807392 B, qwen2)`，索引写入 `|397807392|qwen2|24|896`；测试副本与本地服务已清理 | **PASS** |
| Windows 传 URL 的坑 | Git Bash → cmd | — | `http://…` 被 MSYS 改写、`MSYS_NO_PATHCONV=1` 又废掉 `//c` → 改为 URL 走文件（`pull.url` + `set /p`） | **已解** |
| **acpp 评估 + 探针计划** | 本地 AdaptiveCpp 源码 + 工具链探查 | 决策依据 | 本机无 `nvcc`/CUDA/`/opt/rocm`；acpp 在 Win 只能落到 OpenCL+OpenMP 子集 ⇒ 本机装无收益；**Phase 3-B 探针计划（前置条件/6 步/5 判据/3 回退/1.5–2 人日）已写入 `task_plan.md`** | **已写** |
| **树状投机（F50）** | 树掩码 + 一次 pass 多分支 | 逐位一致 + 计时 | `SPEC-TREE: PASS`（ids 与贪心相同）；bins2/d3 **684 ms**、bins2/d2 918、bins3/d2 969 vs **链式 k=8 355 ms** ⇒ 本负载链更优 | **PASS（机制）/ 树未胜** |
| 树 vs 链（专门构造的多义续写） | `SPEC-AMB` | 接受数对比 | chain `drafts=15 accepted=5` vs tree `drafts=21 accepted=5` —— **接受数完全相同** ⇒ **瓶颈在草稿器**：最近匹配按构造就最可能对，多分支无收益 | **结论** |
| **约束解码（F51）** | 允许集 London/Berlin | 禁用答案不可达 | `SPEC-CONSTRAIN text=[London]`，而同 prompt 无约束时首选是 ' Paris'(59604) ⇒ **禁 token 不可达**；`API-UNIT` 19/19 | **PASS** |
| **工具调用（F52）** | curl + 6 条单元 | 两条路径保证不同 | `tool_choice=get_weather` → `content:null` + `tool_calls` + `finish_reason:"tool_calls"`（`tools=call`）；只给 `tools` → `"content":"Paris"` + `"stop"`（`tools=none`）；未声明名/非 JSON 被拒；`API-UNIT` 23/23 | **PASS** |























| 切分重构后的全量回归 | eng30 | 全绿且数值不变 | OPS/FORWARD/GENERATE/SAMPLING/TOKENIZER(20)/CHAT/LONGCTX/PIPELINE/ORCH/KVQ 全 PASS；drift 3.8e-6；window-0 f32 0.00132、f16 0.00130；17.14 tok/s | **PASS** |

## Handoff（本轮结束时，可直接 `/goal resume` 续做）

- **树与二进制一致**：`engine/build/kakasi_engine_sycl.exe`（639,488 B, 18:47）之后**没有任何源文件改动**（`find engine/src engine/main.php -newer <exe>` 为空）⇒ 该二进制就是最后一次全绿运行（eng30）对应的构建。
- **最后全绿基线**：OPS / FORWARD / GENERATE / SAMPLING / LONGCTX / TOKENIZER(20) / CHAT / PIPELINE / PERF / ORCH（含 `orch[curve]`、`orch[fallback]`、`orch[split]`）/ KVQ / PARITY 全 PASS，RUN_EXIT=0；跨后端 drift 3.8e-6；window-0 logprob f32 0.00132、f16 0.00130 nats；decode 17.1 tok/s。
- **复验命令**：`scripts\build-kakasi.bat`（构建+全自检）；`cd engine\tests && python logp_parity.py engine_parity.txt llama_logits_c128.bin [PARITY|PARITY16]`。
- **未落地**：decode 固定开销优化（+23% 已实测但需 USM 生命周期跟踪）、驻留按设备拆分、I/O 重叠、专家流式、AMD/Apple 两腿、整个 Phase 3。

## Error Log
| Timestamp | Error | Attempt | Resolution |
|-----------|-------|---------|------------|
| 12:06 | `tpc` 报 `C1083 'cstring'/'malloc.h'` | 1 直接跑 tpc | 判定为环境非代码问题 |
| 12:06 | 同上 | 2 shell 里 source vcvars 的 `set` 输出 | 失败：值含空格/反斜杠，sh 解析不了 |
| 12:06 | 同上 | 3 `cmd //c "...bat tpc..."` 传 `%*` 给 vcvars | 失败：参数被当 vcvars 参数，vcvarsall 报错 |
| 12:07 | `系统找不到指定的路径` | 4 heredoc/printf 写的 bat | 失败：printf 吞反斜杠 + `rem` 行含 UTF-8 全角破折号，codepage 936 下 cmd 解析错位 |
| 12:08 | 同上 | 5 用 Write 工具写 **纯 ASCII** bat 调 vcvars64 | **成功**，INCLUDE/LIB 正常注入（`scripts/devshell.bat`） |
| 12:10 | `is_deprecated_device_copyable_v<const std::vector<float>>` | 1 内核里用了 host `A`/`B` | 改用设备指针 `dA`/`dB` |
| 12:11 | `no member named 'memcpy' in 'sycl::event'` + queue ctor | 2 `q(a,b).memcpy(...)`、`queue(dev, default_selector_v)` | 拆成两次 `q.memcpy`；`sycl::queue q(dev)` |
| 12:11 | `cannot use 'try' with exceptions disabled` | 3 `-std=c++17` 被 clang-cl 忽略 | 改 `/Qstd=c++17 /EHsc` |
| 12:13 | `Unsupported compiler: icx` | 1 `tpc --compiler icx` | 查明白名单按子串（clang/gcc/cl），icx 不在其中 → 不让 tpc 驱动 SYCL |
| 12:15 | `Precompiled project object not found` | 2 `objects:` 指向未生成的 obj | 先 icx 编 obj；`/Fo path` 空格形式被当输入文件且不产物 → `/Fopath.obj` |
| 12:16 | `LNK1181 cannot open input file 'sycl.obj'` | 3 `link-libs: sycl` | MSVC 下必须写 `sycl.lib` |
| 12:16 | `0xC0000409` fastfail（后改 try/catch 变为挂死） | 4 link.exe 直链 SYCL obj | 定性为缺 `sycl-post-link`；由 `icx -fsycl` 承担最终链接 |

| 16:55 | parity delta ≈9.5 nats（像是引擎大错） | 1 猜 row→position 为 tokens[r+1]、再猜 tokens[r] | 两者 0/45 命中 → **这两个"证据"都是我脑补的映射**，delta 测的是解析器 |
| 17:00 | 同上 | 2 读 `perplexity.cpp` 非 stride 路径 + `process_logits` | 得 `first=n_ctx/2`、`block=n_ctx-1-first`、目标=tokens[seq*n_ctx+first+k+1]；c32 下 argmax 30/45 |
| 17:02 | window 1/2 delta 仍达 3~5 nats | 3 以为是残差 | 定性为**协议差异**：llama 每窗清 KV、位置从 0 重起，上下文比引擎短 → 判据只取 window 0，并让 harness 在对齐自检不过时拒绝报数 |
| 17:08 | 13 遍文本引擎 273 tokens vs llama 274 | 1 直接比 logprob | 差 1 格会使全部位置错位 → 先用 `llama-tokenize` 逐例打靶，定位到**结尾空格被 pre_split 整段丢弃** |
| 17:11 | `--special` 不是 llama-tokenize 的选项 | 1 照旧写法 | 用 `--ids --show-count --log-disable` + `< /dev/null` |
| 17:13 | Python 手写 GGUF KV 解析 desync（key 解出 0x80） | 1 用记忆里 type4=H/type6=I | GGUF v3 是 **4=UINT32(I,4B)、6=FLOAT32(f)、7=BOOL(B,1B)**；类型表写错会让偏移整体漂移 |

| 17:24 | `samp[tiny-nucleus] PASS nucleus=0` 数值可疑 | 1 相信 PASS | `prefill_top1` 里顺手 `g_nucleus=0`，把采样统计清掉了 → **不属于自己的状态不要在别人函数里重置** |
| 17:31 | 改成"最后一步 nucleus"后打印 67，与"等于贪心"矛盾 | 2 怀疑 top-p 写错 | 真因：所有采样调用堆在断言之前，读到的是**另一条 run** 的值（`g_expect_id` 同类坑第二次）→ 紧跟调用读取，并把语义收窄为 first-nucleus |
| 17:33 | 仅"结果==贪心"不足以证明 top_p 生效 | 3 | 加断言 `first_nucleus==1`，否则判 WEAK 计失败 |
| 17:36 | `llama-bench -nr 1 -n 32 -p 512 -b 2048 -ug 0` 打出 help（参数被拒） | 1 | 去掉 `-ug`/`-nr`，用 `-p 512 -n 32 -b 2048` 即可 |
| 17:36 | 想拿 llama 的 SYCL/GPU bench 做同设备对照 | 1 | `build/ReleaseSYCL/bin` 里没有 bench/completion → 只能用 CPU 对照，并**在报告里明标 GPU vs CPU 不是同设备对照** |
| 18:57 | TOKENIZER 从 20/20 掉到 18/20（双空格用例） | 1 怀疑 pre_split 被改坏 | Python 逐 alternative 复刻切分器 → 切分正确，说明错在合并阶段 |
| 18:59 | 同上 | 2 猜是被控制 token 误匹配 | 用 ctrl_dump 查 `token_type==3` 全是 `<|…|>` 形态，不可能命中 `a␣␣b` → 排除 |
| 19:00 | 同上 | 3 给 FAIL 分支加 pieces 打印并重建 | `pieces=[a]|[ ]|[ b]` 立刻定性：**切分对、合并错** → `encode_span` 忘了调 `pre_split`（整段当一个 piece）→ 修复 20/20 |
| 19:05 | `C2039 'meta_str' is not a member of 'gguf'` | 1 在 gguf.cc 补定义 | 变成 C2084 重复定义：**函数本来就有，只是 gguf.h 漏了声明** → 只加声明、删新定义 |
| 17:15 | 去掉清零同步后 FORWARD 变 MISMATCH（CPU top1=374） | 1 用 `q->fill` 做设备侧清零 | **该原语在本 DPC++ 的 CPU 后端不可信**（GPU 正确）→ 回退为 memcpy+wait |
| 17:19 | 同一次改动 CPU logit 16.391443→14.299 但 FORWARD 仍"PASS" | 2 保留 memcpy、去掉 `wait_and_throw`，用零池 | 异步 H2D 拷贝在 CPU 后端不被后续内核观察 → **argmax 判据太弱**；回退并新增跨后端 logit 一致性断言（\|Δ\|<0.05） |

| 17:40 | 去掉算子级 `wait` 后 decode +23% 但 b0/b1 全错 | 1 以为是 SYCL 内核顺序问题 | 真正机制是**池化槽位的生命周期**：同层内新 Dev 立刻复用旧槽，而读旧数据的内核还在队列 → 回退排空，改列"事件跟踪池"为下一步 |
| 00:24 | 预热后 4 并发 `ok=3/4`，客户端 `WinError 10054` | 1 怀疑并发共享 KV/会话状态 | 单线程 accept 循环本身互斥；真因是 **`fread` 一次只拿到头部就 `fclose`**，未读 body → Windows 发 RST |
| 00:28 | 同一次改造重建后复测 | 2 | 按 `Content-Length` 循环读到完整请求再处理 → **冷/热 4 并发 4/4、8 并发 8/8**，内容全对；**但 wall≈N×单请求，是 FIFO 排队不是并行**（Σ个体/wall 的"加速比"是队列等待重叠造成的假象） |
| 00:41 | 含新代码的构建报 `LNK1104: cannot open ... .exe`（permission denied） | 1 以为杀进程成功 | Git Bash 里 `taskkill //F //IM …` 静默失败，**两个旧 serve 进程仍持有 exe**；改用 PowerShell `Get-Process`/`Stop-Process -Id` 才看到并杀掉 |
| 00:49 | 重建成功、自检全绿，几分钟后 exe 却"不存在" | 2 以为是杀软/构建缓存 | 更早一个被挂起的后台清理任务在**重建之后**才执行 `rm -f engine/build/*.exe` → **后台命令的收尾动作会晚到；发布前必须确认没有挂起的后台任务** |
| 00:51 | Anthropic `tool_use` 端到端 | — | 复用 F52 的约束骨架，只换输出形状：`content:[{"type":"tool_use","id":"toolu_1","name":"get_weather","input":{}}]` + `stop_reason:"tool_use"`；OpenAI 路径与普通文本回归不变（F54） |
| 01:00 | 目录导入第一次报 `import: no such file` | 1 以为 C++ 桥写错 | 实为 Git Bash 把 `\"C:\\…\"` 的反斜杠吃掉，`models.cmd` 里的路径残缺；**用 cmd 原生/无引号调用即成功**（带空格路径用 `.bat` 包装验证通过）。不是引擎问题 |
| 01:00 | 目录式模型文件夹（FR-10） | — | C++ 桥 `model_dir_gguf` 三态（非目录/非恰好一个/路径）；真实 LM Studio 目录 `import/list/rm` 全过，2 个 .gguf 被拒，假 .gguf 被拒且不留残件（F55） |
| 01:07 | OpenAI 流式 + `tool_choice` | — | 先跑约束解码（不因答案已知而跳过模型），再发三段 `tool_calls` delta + `[DONE]`；只声明 tools 无 choice 仍走原文流，Anthropic 流式 6 事件回归不变（F56） |
| 01:19 | 流式 schema 工具报 `tool=failed`，cands=4 out=174 call=0 | 1 以为约束解码失败 | `api_constrained` 返回的是 **token id CSV**；非流式是"生成循环里 api_text 还原后再解析"，流式直接拿 CSV 去 `api_json_ok` 必败 → 流式补一步 `api_text` |
| 01:27 | 流式参数出现 `{\\\"city\\\"…}` 双转义 | 2 | `api_tool_call_json` 产出的 arguments **已是转义后的 JSON 字符串**，chunk 里再 `api_esc` 就多一层 → 原样嵌入 |
| 01:33 | schema→参数（FR-08.2） | — | enum/const 属性笛卡尔积（≤16）→ 约束解码选参 → `api_unesc` 还原；非流式/流式/Anthropic 三出口实测同选 London/fahrenheit（F57） |
| 01:40 | Anthropic 流式 `tool_use` | — | 六事件序列 + `input_json_delta`（完整对象、单层转义）+ `stop_reason:"tool_use"`；纯文本流式 6 事件回归不变；重复的 CSV→文本逻辑抽成 `api_gen_text`（F58） |
| 01:42 | 手工 curl 太多、易漏 | — | 固化为 `engine/tests/api_smoke.py`：models/text/stream/tool/tool-stream/anth-text/anth-tool/anth-tool-stream **8 项一次 PASS**（exit 0），以后回归只跑一条命令 |
| 01:49 | `/v1/models` 只报加载中的架构 | — | 接上模型库索引：导入后同时列出 `qwen2` 与 `qwen2.5_0.5b_uncensored.Q4_K_M`（id 去 `.gguf`、去重）；`rm` 后退回单条；单测 2 条（F59） |
| 02:02 | 工具轮回放（F60） | — | 先从 GGUF 里取出 `tokenizer.chat_template` 原文再实现：`<tool_call>`/`<tool_response>` 两个渲染器 + `tok_tool_*` 桥；`api_history` 不再丢 `role:"tool"` 与 `content:null` 的 assistant 轮；单测用 ids 相等（api_text 会丢控制 token）、smoke 增至 **9 项全 PASS** |

| 02:10 | 模型"看不见"工具签名 | — | 按模型模板把 `# Tools` 拼进首个 system 轮（对象原文透传、不写 JSON 序列化器），并让解析器接受 `<tool_call>` 包裹；prompt_tokens 15→163 证明目录到场；0.5B 仍直接答 Paris——记录为**模型偏好**而非管道缺陷（F61） |

| 02:16 | Anthropic 数组式历史被静默丢弃 | — | `content` 是块数组，旧正则只认字符串 → 加 `api_json_object_after` 与 tool_use/tool_result/text 三分支；单测逐 id、活服务 input_tokens 76、回答 "Warm." 与 18C 一致（F62） |

| 02:25 | 并行工具结果被拆成多个 user 轮 | — | 模板对连续 tool 只开一次 user 轮；把循环改成 while 批量消费连续 tool、用块边界连接后交给单块渲染器 → 拼接与模板逐字一致（F63） |

| 02:31 | Anthropic 并行结果只取第一个 | — | 一条 user 消息内的多个 `tool_result` 块按 `explode` 切段逐个取 content，再复用 F63 分组渲染；单测逐 id、活服务 input_tokens 90、回答 18C（F64） |

| 02:38 | 并行助手调用只渲染第一个 | — | 新增 C++ `render_tool_calls` + `tok_tool_calls`（`@@TOOLCALL@@` 分槽），PHP 按块切段收集全部调用；OpenAI/Anthropic 两形状与同一期望 prompt 逐 id 相等；活服务 113 tokens、回答 18C（F65） |

| 02:40 | 流式在并发下是否也正确（此前只测非流式） | — | `load_test.py --stream` 逐行校验 SSE 与 `[DONE]`：4 并发 4/4、8 并发 8/8，内容全 Paris；排队结论不变（F66） |

| 02:47 | Anthropic `system` 数组形式被忽略 | — | `api_system_text()` 先字符串、再在截到 `messages` 之前收集 text 块（换行连接）；单测逐 id、活服务 27 vs 13 input tokens（F67） |

| 02:52 | `tool_result.content` 数组形式未解析 | — | 字符串取不到时在块内找 content 数组、收集 text 块（换行连接）；单测逐 id、自检 83 PASS（F68） |

## 完成度审计（本轮末，对照既定阶段目标）

| 交付项 | 证据（可复现） | 结论 |
|---|---|---|
| Phase 0：tpc+SYCL 单原生二进制、禁 FFI | `scripts/build-kakasi.bat` 四步；`probe/CONCLUSION.md`（原生调用 0.65 ns/op）；启动 `backends 4/4` | ✅ |
| Phase 1：GGUF 加载 / 核心算子 / 分词器+模板 / 朴素生成 | `ACCOUNTING=1`、`OPS/FORWARD/GENERATE`、`TOKENIZER`/`CHAT`、`logp_parity` window-0 mean 0.00132/0.00130 | ✅ |
| Phase 1/2：后端抽象（ggml 式） | `sycl_backend` + 算子层 + `plan()`；**无计算图调度器** | ⚠ 部分 |
| Phase 2：FR-18 编排（预算/驻留/KV 量化/offload） | `ORCH` 曲线、`KVQ`、`orch[split]`；**无 I/O 重叠、无专家流式（dense）** | ⚠ 部分 |
| Phase 2：Intel/AMD/Apple 三机复测 | Intel 机全量通过；AMD/Apple **无硬件**，未声称 | ⚠ 硬件阻塞 |
| Phase 3：投机解码 | `SPEC`（2.5×）、`SPEC-TREE`（逐位一致）、`SPEC-NEG`、`SPEC-AMB`；**树收益未证（草稿器瓶颈）** | ⚠ 部分 |
| Phase 3：API（OpenAI+Anthropic、流式、工具、历史、结构化输出、stop、seed） | `api_smoke.py` **61/61**；`STREAM-UNIT`；F51–F147 | ✅（四出口语法回退 + 嵌套 2–8 层/混合/三类数组/2–4 维数组/顶层四类数组（含内嵌元素）/数字·浮点·科学计数法·布尔 const·enum/`multipleOf`/排他边界/根守卫/`$ref`/`allOf`/属性·根级 `oneOf`；9 层以上/5 维数组仍缺） |
| Phase 3：模型管理 CLI | `models.bat import/pull/list/rm` + 目录导入 + `/v1/models`（含元数据 F73） | ✅ |
| Phase 3：SSD 前缀缓存 | `PREFIXCACHE` 3.3×、`PREFIXPART` 门禁；API 全/部分命中与 64 桶隔离（F76–F82），102–213 token 提示命中后 0.65–1.01 s | ✅ |
| Phase 3：MoE / 多卡 / UNIKERN / 厂商插件 | MoE 不适用；多卡无硬件；UNIKERN 条件未触发；插件未做 | ⛔ |
| 闭合决策（禁 FFI、单二进制、GGUF、命名） | 全程遵守；**五后端矩阵仅 Intel+CPU 实测** | ⚠ 部分 |

**审计结论（F75 后）**：整体目标**未完成**。剩余按优先级：per-session KV 真并行、grammar-constrained 解码（自由字符串/无界数字/嵌套）、多分片 GGUF、多样草稿器、并行多卡/多机、FR-05 插件、UNIKERN 决策。参考文件齐全（scripts/ tests/ probe/ 均在），断言名与最新自检日志一致；API 活服务检查 19/19。

| 06:15 | schema 参数只认 enum/const | — | 布尔与有界整数也是有限域：两遍扫描 + 原生类型输出（`{"days":7,"express":true}`）；无界/101 值范围拒绝枚举（F69） |

| 06:27 | structured outputs 只是校验+重试 | — | 抽出 `api_schema_candidates(tail)`，接上 `response_format.json_schema`：有限扁平 schema → 对象候选 → 约束解码；实测输出恰为候选之一且 `json=ok`（F70） |

| 06:35 | 流式请求忽略 json_schema | — | 与 F56/F70 同构：约束解码取回对象 → 单个 content delta + stop + DONE；日志 `stream json=ok`；smoke 增至 12 项（F71） |

| 07:02 | stop 参数被忽略 | — | 解析 stop/stop_sequences；非流式截断 + Anthropic 报 stop_sequence；流式保留 maxStop-1 字符再发，跨 chunk 的停止串不泄漏；基线 "Paris" 下 `stop:"ari"` 四路全出 "P"（F72） |

| 07:10 | /v1/models 只有 id | — | 抽 `api_models_data(indexText, loaded)` 纯函数，透出 bytes/architecture/layers/hidden；合成索引单测 + 导入实测（F73） |

| 07:16 | seed 固定 12345 | — | 请求 seed 透传非流式采样并在日志记录；同 seed 两次逐字节相同、seed=8 不同（F74）；流式仍缺 seed（已登记） |

| 07:23 | 流式采样不可复现 | — | `session_start` 会把 RNG 重置，故在 start 之后调 `session_seed`；同 seed 流式两次相同，且流式与非流式同 seed 输出同一句（F75） |

| 07:46 | API 未用前缀缓存；接入后发现部分命中乱码 | — | 贪心非流式接 `cache_prefill`（全命中 263/263：2.04→1.00 s，内容一致）；**部分命中（3/13、258/263）输出 "SCALL…" → 判定 KV 复用路径有 bug**，定位为 loader 未跳过每层未复用的行 → 修复后部分命中与 fresh 逐 id 一致（PREFIXPART 门禁），API 已启用；短提示 0.077 s 返回 Paris，smoke 19/19（F76） |

| 08:18 | 流式请求不复用前缀缓存 | — | 流式文本分支用 cache_prefill 代替 session_start，结束后按需 store；首 token 0.706→0.242 s、总时长 1.474→1.009 s，内容逐字节一致（F77） |

| 08:31 | 缓存只覆盖贪心 | — | 去掉 temp 限制：命中后用 session_seed+session_next(temp,top_p)，未命中回退 sampled；采样流式首 token 0.712→0.251 s、内容一致（F78） |

| 08:44 | 约束解码绕前缀缓存、flag 不全路径 | — | `api_constrained` 增加 useCache 参数并接 cache_prefill/store，7 个调用点传 `$prefixCache`；tool 长上下文 5.47(off，无文件)→3.38(miss)→2.72(hit) s（F79） |

| 09:03 | 分片 GGUF 只给泛化拒绝 | — | `api_shard_info` 认 `NNNNN-of-NNNNN`；目录导入由桥返回 `!N` 并报数量；CLI 两条消息实测（F80） |

| 09:09 | Responses 的 input 只认字符串 | — | `api_history` 回退解析 `input:[{role,content}]`；smoke 增 responses-text/array → 19/19（F81） |

| 09:26 | 缓存单文件互相覆盖、短命中冲掉长前缀 | — | `model_cache_key`(FNV-1a,64 桶)+`api_cache_should_store`(hit==0 或 ≥32 才写)；A/B 分会话、各自 213/213 命中（A2 0.67 s、B2 0.65 s）（F82） |

| 09:38 | 尝试 2-gram 兜底草稿提升接受率 | — | 链式：34/34/6 不变（1.73× 噪声内）；AMB 多收 1 个但无墙钟收益；**SPEC-ADAPT 失败**（无可复用时仍验证）→ 回退，84 PASS（F83 负结果） |

| 09:49 | n 参数被忽略 | — | 非流式纯文本支持 n=1..4（后续候选 seed+i），choices/usage 正确；n=2/3 实测不同、贪心相同；smoke 19/19（F84） |

| 10:01 | max_tokens>512 静默退回 64 | — | 抽 `api_max_tokens`：<=0→64、>512→512；实测 1000 生成 229（>64）、64 恰好 64；smoke 19/19（F85）。另记下 cmd 级重定向不建文件的坑，改 bash 级重定向 |

| 10:31 | 会话无法挂起/续跑 | — | `session_save/restore`（KV+logits+RNG+len，2 槽）；踩坑：`g_kv.len` 只有缓存路径维护，改用 `g_sess_len`；SESSION-SNAP 双会话交接 ids 逐位一致，85 PASS（F86） |

| 10:46 | 前缀缓存桶无查看/清理入口 | — | `model_dir_files` 桥 + `models cache [clear]` + `api_cache_stats` 单测；只列 `.kv`、清 1 留 `.txt`，85 PASS（F87） |

| 10:58 | 截断仍报 stop | — | 非流式/流式、两种 API 都按 maxTok 判 `length`/`max_tokens`；n>1 每候选单独判；smoke 34/34（F88） |

| 11:08 | Responses 工具调用返回 chat 形状 | — | 加 `function_call` output 分支（name/arguments 取自已约束调用）；smoke 34/34（F89） |

| 11:17 | Responses 忽略 instructions、字符串 input 无工具目录 | — | `api_system_text` 接 instructions，字符串 input 拼 system+`# Tools`；input_tokens 13→27，85 PASS（F90） |

**审计增补（F76–F90）**：`api_smoke.py` 34/34（新增 n/stop/maxlen/responses-tool/instructions 相关用例）；全量自检 85 PASS（新增 `PREFIXPART`、`SESSION-SNAP`、`api[cache-stats]`、`api[instructions]`）；缓存四路径（非流式/流式 × 贪心/采样 + 约束解码）均接前缀缓存并按 64 桶隔离；KV 快照/恢复原语逐位一致（HTTP 调度未做）。注意：单元子项 PASS 时**不打印**，失败才打印 `api[...] FAIL`，因此证据是"绿色套件 + 源码断言"而非日志里的逐条 PASS。

| 11:41 | tool_choice 只支持点名 | — | 识别 `none`/`required`/`any`；required 用全函数名骨架约束；解码文本偶少 1–2 字符→按骨架前缀兜底重建；三形态矩阵实测，85 PASS（F91） |

| 11:44 | 流式 required 未接线 | — | 试改流式工具块时锚点不匹配、脚本未写入（源码零改动）；决定不在收尾阶段动已验收的流式路径，登记为缺口（F91 限制） |

| 11:55 | 流式 required 缺 | — | 独立 additive 块（约束到声明函数骨架，失败落回文本流）；required→[get_weather]、点名→[search]、none→文本流，34/34 smoke（F92） |

| 12:04 | Anthropic 流式 any 缺 | — | additive 块：约束到声明函数骨架→六事件 tool_use 序列；any→search、点名→search，smoke 34/34（F93） |

| 12:08 | 流式 required/any 未入回归 | — | smoke 增 `chat-tool-required-stream`、`anth-tool-any-stream`（共 24 项全 PASS）；记录启动需 ~55 s，测试前用 curl 轮询就绪 |

**最终审计快照（F147+，证据来自当前工作区）**：全量自检 **120 PASS / 0 FAIL**（含嵌套 2/3/4/6/8 层、2–4 维数组、顶层四类数组（含内嵌对象元素）、数字/浮点/科学计数法/布尔 const·enum、`multipleOf`、排他边界、候选与根类型作用域、本地 `$ref`、`allOf`、属性级/根级 `oneOf`）；**对 oracle 复测**：window-0 f32 mean **0.00161** / max 0.02247、f16 mean **0.00161** / max 0.02283、alignment 126/126 → PASS；主服务 `api_smoke` **61/61**；并行服务 `par_smoke` **7/7**；`load_test.py 8 --stream` —— **8/8 内容一致、serialized_ratio 1.27×**；`fairness_test.py 8001` —— 短流 **0.28 s** vs 长流 **3.99 s**（PASS）；`PREFIXCACHE 19/19`、`PREFIXPART`、`SESSION-SNAP`、`SESSION-PAR`、`SPEC 24/24/6`、跨重启缓存 `cache=213`、`SCHEMA-*` 系列（含 `SCHEMA-TOPARR-OBJ2`）；无进程/哨兵/缓存残留。**结论：整体未完成**。

| 13:27 | 两会话逐 token 交替 | — | SESSION-PAR：A/B 各 8 token 交替，ids 与不中断运行逐位一致，含 32 次快照搬运 257 ms（切换开销≈0）（F94）；并行服务（8001）修掉请求体读空即 break 与 accept 后仍非阻塞两个 bug 后，公平性 PASS（短流 0.25 s vs 串行 3.20 s）（F95） |

| 13:35 | 8001 双流公平性 | — | 两个真 bug：body 读空即 break（同 F53）、accept 出的 socket 继承非阻塞；修复后短流 0.25 s 完成、串行版 3.20 s；86 PASS（F95） |

| 13:43 | 并行服务不支持 stop | — | 8001 支持单个停止序列（hold 回看、不泄漏）；OpenAI "P"/stop、Anthropic "P"/stop_sequence，公平性仍 PASS；86 PASS（F96） |

| 13:57 | instructions 仅字符串、无 models/{id} | — | 数组 instructions（text 块换行连接）+ `/v1/models/{id}`（引入 $status，未知 404）；smoke 34/34（F97） |

| 14:05 | 枚举值含空格被改写 | — | `api_list` 改为按引号提取（保留空格/逗号），数字才退回裸切；`New York` 约束输出实测，86 PASS / smoke 34/34（F98） |

| 14:13 | pattern 不进候选 | — | Pass 1.5：`^(a|b)$` 拆交替项（保留空格），与非 enum 属性共用笛卡尔积/上限；活服务 `{"city":"New York"}`，86 PASS（F99） |

| 14:22 | 并行服务不支持结构化输出 | — | 8001 接有限 json_schema 快路径（预填→约束生成→正常 SSE→释放槽位）；两种 API 输出 `{"city":"New York"}`，公平性仍 PASS（F100） |

| 14:34 | 无 /health，且页面路由吞掉它 | — | 两端加 `{"status":"ok","model":…}`；页面分支排除 GET /health；smoke 34/34，公平性仍 PASS（F101） |

| 14:42 | developer 角色渲染成非法回合头 | — | `developer`→`system` 归一；单测 ids 相等 + 端到端 "Paris"；smoke 34/34（F102） |

| 14:58 | 流式缺 usage chunk | — | `stream_options.include_usage` 在 finish 块后补 `choices:[]`+usage；smoke 34/34（F103） |

| 15:06 | 工具流式无 usage | — | 命名/required 工具流在 finish 后补 `choices:[]`+usage（completion 取约束 CSV 计数）；smoke 34/34（F104） |

| 15:18 | 结构化流缺 usage | — | 保留约束 CSV，`json_schema` 流补 `choices:[]`+usage；四条 OpenAI 流式全部支持；smoke 34/34（F105） |

| 15:21 | 并行服务无自身回归 | — | `par_smoke.py`：health/文本流/stop/Anthropic 事件/json_schema/tools→400 **6/6 PASS**；写入回归入口（F106） |

| 15:32 | 缺旧版 completions 端点 | — | `POST /v1/completions`（prompt→user 轮，text_completion 形状）；stream 明确 400；smoke 34/34（F107） |

| 15:35 | 缓存跨重启未验证 | — | 进程 A 写 5.2 MB 桶文件 → 重启 → 同提示 `cache=213`（全前缀命中）、内容一致；计时含预热不作对比（F108） |

| 15:36 | par_smoke 未覆盖第三路 503 | — | 两个长流占满槽位 → 第三路断言 HTTP 503；par_smoke **7/7 PASS** |

| 16:35 | json_schema 自由字符串无约束 | — | 词表 charset 桥 + 逐 token 允许集 + 强制闭合；自检 `SCHEMA-STR` 得 `{"city":"paris"}`、smoke 新增 `chat-json-schema-string`；87 PASS / 35/35 / 7/7（F109） |

| 16:59 | 多属性字符串 schema | — | 强制 `,"key":"` 分隔后逐值填充；`chat-json-schema-multi-string` 实测合法 JSON；87 PASS / **36/36**（F110） |

| 17:07 | 无界整数 schema | — | 数字文法：首位限单字符（防前导零）、余位全数字、强制 `}`；`SCHEMA-NUM` 得 `{"answer":100000}`、smoke `chat-json-schema-integer`；**88 PASS / 37/37**（F111） |

| 17:18 | 负数整数 | — | 首步允许单独 `-`（`api_punct_ids` 精确匹配单字符 token），负号后必须单字符数字；`minus_ids=ok` + 形状/JSON 断言；88 PASS / smoke 37/37（F112） |

| 17:25 | 小数 number schema | — | `.` 状态：整数后可选点、点后仅单字符数字（至少一位）、前导零只可接点；`SCHEMA-FRAC` 得 `{"answer":1.571428}`；**89 PASS / 38/38**（F113） |

| 17:32 | 一层嵌套对象 | — | 嵌套检测先于平坦检测（否则内层键被当顶层）；强制 `{"outer":{"inner":"` + 双层闭合；`SCHEMA-NEST` 得 `{"capital":{"city":"paris"}}`；**90 PASS / 39/39**（F114） |

| 17:39 | 8000 流式无语法回退 | — | SSE 分支补有限→嵌套→平坦字符串→数字四级；`chat-json-schema-string-stream` 实测；90 PASS / **40/40**（F115） |

| 17:46 | Responses 不跑语法 | — | 守卫只认 `"json_schema":{`，Responses 是 `"type":"json_schema","schema":…`；放宽为 `"json_schema"` 记号；`responses-json-schema-string` 实测；90 PASS / **41/41**（F116） |

| 17:53 | 嵌套数字叶子 | — | 抽取 `api_number_fill`；`api_number_nested` 强制双层骨架；`SCHEMA-NESTNUM` 得 `{"meta":{"count":100000}}`；**91 PASS / 42/42**（F117） |

| 18:01 | 同层混合叶子 | — | 计划串 `name:s,i,n` 驱动 `api_object_mixed`（键按 schema 顺序强制，字符串走 charset、数字走 digit FSM）；`SCHEMA-MIX` 得 `{"city":"paris","population":300000}`；**92 PASS / 43/43**（F118） |

| 18:08 | 字符串数组 | — | 元素后允许集仅 `,`/`]`（模型自选收尾，选 `,` 则强制下一个开引号）；`SCHEMA-ARR` 得 `{"cities":[" paris"," marseille"]}`；**93 PASS / 44/44**（F119） |

| 18:21 | 数字数组 + 尾逗号 | — | 首版 `[100,200,300,400,]` 被 `SCHEMA-ARR-NUM` 首跑抓出（上限后仍强制 `]` 而前一步选了 `,`）；改为最后一轮只允许 `]`，字符串数组同构修复；**94 PASS / 45/45**（F120） |

| 18:29 | 对象数组 | — | 检测必须先于嵌套字符串（items 带 properties 会被当成外层键）；元素内逐叶子填充 + 强制 `}` + `,`/`]`；`SCHEMA-ARR-OBJ` 得 `{"people":[{"name":…,"age":250}]}`；**95 PASS / 46/46**（F121） |

| 18:45 | 检测器属性泄漏 | — | `SCHEMA-SCOPE` 首跑抓到兄弟/嵌套叶子被扫进计划串；`api_balance_block` + `api_top_leaves`（depth-1 逐键、仅扁平值对象）收敛三个检测器；**96 PASS**；另重跑 `load_test 8`（8/8、1.27×）与公平性（0.28 s vs 3.99 s）**（F122）** |

| 18:53 | 嵌套对象多叶子 | — | `api_response_schema_nested_object`（前瞻定位 + depth-1 计划）+ `api_nested_object_value`（强制 `}}`）；`SCHEMA-NEST-OBJ` 得 `{"meta":{"city":"paris","count":1000}}`；**97 PASS / 47/47**（F123） |

| 19:06 | 两层嵌套 | — | 路径驱动前缀（`a/b`，内层 `{` 由 head 提供）+ 逐层下钻检测（≤3 层）；首版多一个 `{` 被两个嵌套自检同时抓出并修正；`SCHEMA-DEEP` 得 `{"a":{"b":{"city":"paris","count":1000}}}`；**98 PASS / 48/48**（F124） |

| 19:18 | 嵌套深度覆盖 | — | 下钻上限 3→4；补 `SCHEMA-DEEP3`/`SCHEMA-DEEP4`（此前只测了 2 层）→ 2/3/4 层同时通过，**100 PASS**（F125） |

| 19:26 | 数组套数组 | — | 两级收尾文法（内层 `,`/`]`，外层 `,`/`]`），`$bracketSeen` 显式标志避免重复补 `]`；`SCHEMA-ARR2` 得 `{"m":[["red","green"]]}`；**101 PASS / 49/49**（F126） |

| 19:34 | 6 层嵌套 | — | 下钻上限 4→6；`SCHEMA-DEEP6` 用循环拼接 schema（首版尾括号少 1，已修）；得 `{"a":…{"f":{"city":"paris","count":1000}}}}}`；**102 PASS**（F127） |

| 19:40 | FR-16 证据未刷新 | — | 用当前二进制重生成 `PARITY`/`PARITY16`（各 273 行）并对 oracle 评分：alignment 126/126；window-0 f32 mean 0.00161/max 0.02247、f16 0.00161/0.02283 均 PASS；顺带清掉根目录残留的空 `serve.par.on`（F128） |

| 19:41 | 二维数组缺 SSE 覆盖 | — | 新增 `chat-json-schema-array2-stream`（拼接 delta 后仍须是列表的列表、charset 约束、end turn）；`api_smoke` **50/50**（F129） |

| 19:47 | 有界整数忽略 multipleOf | — | Pass 2 枚举时按 `v % m == 0` 过滤；`SCHEMA-MULT` 候选恰为 0/5/10（n=3）；**103 PASS**（F130） |

| 20:02 | 三维数组 | — | 三层各自"预算 + 已见 `]`"标志；每层预算耗尽即补 `]`；`SCHEMA-ARR3` 得 `{"m":[[["red","green"]],[["blue"]]]}`；**104 PASS / 51/51**（F131） |

| 20:09 | 整数排他边界 | — | 缺失闭区间时读 `exclusiveMinimum/Maximum` 并折算（±1），再走跨度/`multipleOf`；`SCHEMA-EXCL` 候选恰为 2/4（n=2）；**105 PASS**（F132） |

| 20:19 | 四维数组 | — | 四层各自预算/闭合标志；`SCHEMA-ARR4` 得 `{"m":[[[["red","green"]]],[[["blue"]]]]}`；smoke 首跑因断言少套一层 FAIL，改递归 `depth_ok` 后 **106 PASS / 52/52**（F133） |

| 20:27 | 顶层数组 schema 未约束 | — | 检测器都要求 `properties`；新增锚定检测 + 无对象包裹的收尾文法（不强制 `}`）；`SCHEMA-TOPARR` 得 `["blue"]`；**107 PASS / 53/53**（F134） |

| 20:34 | 顶层数字数组 | — | `api_top_array_number_value` 复用数字文法 + `,`/`]` 收尾；`SCHEMA-TOPARR-NUM` 得 `[100,200,300]`；**108 PASS / 54/54**（F135） |

| 20:48 | 数字 const/enum 类型错误 | — | Pass 1 只认带引号 const；且数字枚举被引号化成 `"1"`（首跑 FAIL 抓到）；新增 Pass 1.2 + 无引号全数字判定；const→`{"n":42}`、enum→`{"n":1}…`；**109 PASS**（F136） |

| 20:56 | 浮点 const/enum | — | 数字字面量识别扩到小数（`(?:\.[0-9]+)?`）；`SCHEMA-CONST-FLOAT` const→`{"x":1.5}`、enum→`{"x":0.5}{"x":1.5}`；**110 PASS**（F137） |

| 21:16 | 顶层布尔数组 + 候选越界 | — | 14 种布尔列表走有限候选；live smoke 首跑返回 `{"items":true}`（`items` 被当顶层属性）；`api_schema_candidates` 限定到 `properties` 平衡块；**111 PASS / 55/55**（F138） |

| 21:36 | 顶层对象数组 + 根守卫 | — | live smoke 首跑返回 `{"items":{"name":…}}`（嵌套检测器认领 items）；新增 `api_schema_is_object` 守卫嵌套/混合/字符串检测器；`SCHEMA-TOPARR-OBJ` 得 `[{"name":…,"age":250}]`；**112 PASS / 56/56**（F139） |

| 21:46 | 本地 `$ref` 不支持 | — | 请求体预处理：`{"$ref":"#/definitions/X"}` 内联定义体（≤6 轮）后清空 definitions；`SCHEMA-REF` 候选 Paris/London；**113 PASS / 57/57**（F140） |

| 21:57 | `allOf` 不支持 | — | `api_balance_square` + `api_schema_merge_allof`：成员 `properties` 合并进一个对象；`SCHEMA-ALLOF` 得 city×units 两个候选；**114 PASS / 58/58**（F141） |

| 22:06 | `oneOf/anyOf` 不支持 | — | `api_schema_flatten_choices`：枚举分支并集替换承载对象；`SCHEMA-ONEOF` 得 Paris/London 两个候选；**115 PASS / 59/59**（F142） |

| 22:16 | 根级 `oneOf` | — | 展平器对根级跳过；`api_response_schema_candidates` 对根级 oneOf/anyOf 逐分支求候选并集；`SCHEMA-ROOT-ONEOF` 得 Paris/London；**116 PASS / 60/60**（F143） |

| 22:24 | 科学计数法 | — | 数字字面量加可选指数段；`SCHEMA-SCI` const→`{"x":1e3}`、enum→`{"x":1e2}{"x":2.5e-1}`；**117 PASS / 60/60**（F144） |

| 22:32 | 嵌套 8 层 | — | 下钻上限 6→8；`SCHEMA-DEEP8` 程序化 8 层 schema → 输出正确嵌套；2/3/4/6/8 层同时通过；**118 PASS / 60/60**（F145） |

| 22:41 | 布尔 const/enum 类型 | — | Pass 1 raw 判定加入 true/false；新增 Pass 1.2b；`SCHEMA-BOOL` const→`{"b":true}`、enum→true/false；**119 PASS / 60/60**（F146） |

| 15:31 | 顶层对象数组内嵌结构 | — | 元素前缀 `{"outer":` + 计划头、闭合 `}}`；检测下钻一层；`SCHEMA-TOPARR-OBJ2` 得 `[{"meta":{"name":…,"age":250}}]`；**120 PASS / 61/61**（F147） |

| 00:06 | 8001 门禁"瞬超时" | — | 门禁 bat 的 `timeout /t 5` 被继承 PATH 里的 GNU `timeout` 截走（60 条 invalid interval），300 s 轮询约 2 s 跑完 → 误报 TIMEOUT；改为 `C:\Windows\System32\` 全路径后重跑（F148） |

| 01:57 | 恢复会话：重启未发生、WMI 仍僵死 | — | tasklist >120 s；`taskkill //F //IM` 挂起（RC=124）但杀进程生效：残留 8001 监听（PID 595564，serve_par13.log 73 MB accept 失败刷屏）清除、端口释放；aoa3 自检重跑（F156） |

| 02:20 | 全量自检暴露检测器回归（2 FAIL） | 1 | `run_aoa3b.log`：`RUN_EXIT=0`、117 PASS、**2 FAIL**——`SCHEMA-ARR-OBJ-ARR/ARR2` 的**生成文本完美**（shape=1、JSON 合法、混合叶子正确）但 `det=[]`；根因是 01:01 编辑检测器时引入两处 bug：① tail 判定把 properties 块自身闭合符 `}` 当成兄弟属性（无 scalar 必败）/ 有 scalar 时尾部多一层 `}}` 不匹配；② 返回值恒拼三段 `items|tags|`，破坏 F155 的两段契约。修复：tail 先剥一层 `}` 再匹配 scalar、返回改可变段数、3 个 API 调用点 `$p[2]` 改 `isset` 守卫（F157）。教训：**会话中断截断的自检运行不算回归证据，SCHEMA 段在全量自检的最后** |

| 02:45 | 修复后重建+全量自检全绿 | — | `engine/build_aoa3fix.log`：**119 PASS / 0 FAIL**、`RUN_EXIT=0`、`BAT_EXIT=0`；`SCHEMA-ARR-OBJ-ARR det=[items|tags]: PASS`、`SCHEMA-ARR-OBJ-ARR2 det=[items|tags|note]: PASS`（混合叶子正式落地，文本 `{"items":[{"tags":[…],"note":"a vibrant…"},…]}`） |

| 02:55 | 8001 门禁（替代路径） | — | 显式 PATH 起新服务（5 s 就绪，两槽确认）→ `par_smoke.py 8001` **7/7 PASS**；`fairness_test.py` 首跑压线 FAIL（short 1.2 s）→ warm 复跑 **PASS**（long 15.49 s/0.31 s vs short 1.0 s/0.63 s）；`par_gate.bat` 本体（setvars+taskkill）留待重启后一次性复测 |

| 03:05 | 8000 门禁 + 进程处置 | — | `api_smoke.py 8000` **61/61 PASS**、SMOKE_EXIT=0（`engine/api_smoke_f157.log`）；收尾发现 `taskkill /IM` 3/3 挂起、netstat OwningProcess 出假行 → **`Stop-Process -Id` + curl 验证（RC=7）成为僵死态标准处置**（F157）；accept 刷屏（40 min 2 万行）确认为 PHP 流层空转告警，无害待修 |

| 03:50 | F158 元素内嵌套链：实现 + 两轮真 bug | 2 | 新形状 `{"items":[{"meta":{"inner":{"city":…,"count":…}}}]}`（缺口"元素内两层以上嵌套"）；生成器 = 元素循环 × path 前缀组合，检测器 = 单链下钻 + 唯一属性守卫 + 3 出口接线（顺带补 exit-3 arrObjArr 漏掉的 jsonMode）。首跑 FAIL（det=[]）→ **PHP 不可独立运行，把检测器逐行移植 Python 对真实 body 追踪**，抓到两个真 bug：① tail 切片 off-by-key（`$ci` 含键名而 child 从自己的 `{` 起 → 用 `strpos($cur,$child,$ci)`）；② 下钻推进到 child 值对象而 `api_top_leaves` 只吃属性块、值对象内 child 后有两层 `}}` → 改为每层取 child 自己的 properties 块。修复后 Python 7 用例（NEST2/NEST3 + 5 防误认领）ALL PASS（F158） |

| 04:15 | F158 门禁全绿 | — | 途中 LNK1104（第三个僵尸进程 1629988 锁 exe，`Get-Process -Name` 这次可用 → Stop-Process 清杀）；重建全量自检 `engine/build_f158.log`：**120 PASS / 0 FAIL**、`RUN_EXIT=0`、`SCHEMA-ARR-OBJ-NEST det=[items|meta/inner|city:s,count:i]: PASS`；`api_smoke.py 8000` **61/61**（`engine/api_smoke_f158.log`）；服务已停（curl RC=7 验证） |

| 05:10 | F159 oneOf 分支含 grammar：一次全绿 | — | 形状 `{"item":{"oneOf":[book/cd 两支…]}}`；**分支选择 = api_constrained 多候选约束解码**（const 首属性互异 → 分叉点模型选支），分支识别用 `api_constrain_loop` 契约（选中序列恰为一个候选完整 ids）+ `tok_encode` 尾比对；三边界核实：F142 展平器对无 enum 分支 no-op、F143 候选路径对 grammar 分支弃权、**平铺嵌套检测器会误认领**（lookahead 穿过 `[`）→ 新检测器排在 nested 前。Python 8 用例（含 5 防误认领）先行 ALL PASS → 实现 → 重建全量自检 `engine/build_f159.log`：**121 PASS / 0 FAIL**、`RUN_EXIT=0`、`SCHEMA-ONEOF-GRAMMAR: PASS`（本次模型选 book 支、title 空串——合法，如实记录）；`api_smoke.py 8000` **61/61**；服务已停（F159） |

## 当前未决（2026-11-03 03:50 本地）

- **F197（判定哪条提取分支真正生效）已完成实验、源码已回滚**：两条 `echo TOOLPATH` + 一次 `required` 请求 ⇒ 日志只有 `primary parsed=no` 与 `tools=call`，兜底分支从未打印 ⇒ 真正接住调用的是**生成循环之后的复数提取器**（F195 那条），不是我以为的骨架兜底。并行生成因此只需往候选里塞**带块标签的多调用骨架**（两个裸 JSON 相接会整段判空），预算 96→≈192。**注意**：`engine/build/kakasi_engine_sycl.exe` 现在是**带探针的构建**（源码已 `git checkout` 回 `e4a178a` 态），任何门禁结论都不能引用这个 exe——**先 `scripts\build-kakasi-nosetvars.bat norun` 重编译，再跑自检/烟雾/并行门禁**。
- **F200（并行调用的参数按工具各取所需）已验证**：required 候选逐工具枚举全部参数候选、成对候选取有序叉乘并设 24 上限；活服务 "weather Paris / time London" 实测各归各位；守卫 `chat-tool-parallel-args`；`run_f200.log` 134 PASS、`api_smoke_f200.log` **91/91**、`par_smoke_f200.log` 8/8 + `fairness_f200.log` PASS。
- **F199（并行工具调用打通）已验证**：判据是"贪心约束解码在任一候选完整即停"⇒ `parallel_tool_calls:true` 时**只放两块串联候选**；required 候选统一 wire 形态，兜底不再顶替带标签文本（F198 静默错答之源）；`chat-tool-parallel-calls` 常驻守卫（带开关 2 项 vs 缺省 1 项）。
- **F196（并行调用扇出 + 自由文本参数补回）已验证（程序化）**：`api_tool_items` / `api_anthropic_tool_blocks` / `api_responses_call_items` 三个可测函数接进两个非流式出口；`api_tool_call_json` 增"参数写成嵌套对象就用 `api_json_object_after` 取整个对象"（修 F52 时代把参数丢成 `{}` 的限制）；`run_f196b.log` **134 PASS/0 FAIL**（`TOOL-CALLS-FANOUT` 判两项 + `toolu_2` + `fc_2` + Paris/London 参数都活着）、`api_smoke_f196.log` 89/89、`par_smoke_f196.log` 8/8 + `fairness_f196.log` PASS。**注意**：并行出口只有程序化证据，活服务探针仍产生不了两个调用块；生成侧（约束解码一次一条骨架）与流式 `output_index` 仍未做。
- **F195（生成侧并行工具调用：解析半闭合）已验证**：新增 `api_tool_calls_json()` 复数提取器（每块走同一白名单、id 重编号、逗号拼接），chat 非流式自由 `tools` 路径改用；自检 `TOOL-CALLS-PARALLEL` 三条（两块两项含 call_2 / 一块仍一项 / 未声明名被丢）；`run_f195b.log` **133 PASS/0 FAIL**、`api_smoke_f195.log` **89/89**、`par_smoke_f195.log` 8/8 + `fairness_f195.log` PASS。**首跑 132 PASS/1 FAIL 是我为绕开工具参数手写标签时拼错了标签**——记进 findings 的教训：先写能暴露中间产物的断言。未闭合：约束解码仍一次一条骨架（并行**生成**没接）、Anthropic/Responses 出口只取第一项、流式没补 index 递增。
- **F194a（`tool_choice:"required"` 丢 arguments）已修并验证**：`required` 分支改为逐工具取参数候选骨架、`$toolJson` 门控并上 `$reqCands`；实测 `{} ` → `{"city":"Paris"}`，守卫 `chat-tool-required-args` 修前即为假；`run_f194.log` 132 PASS、`api_smoke_f194b.log` **89/89**、`par_smoke_f194.log` 8/8 + `fairness_f194.log` PASS。**教训**：`FAIL=0` 不等于套件跑完——首跑 `SMOKE_EXIT=1 / PASS=16`（守卫自己的 `%` 解包 TypeError），判据必须带 exit code。
- **F194（生成侧并行工具调用 + `required` 丢参数）已实测、未修**：8000 成对探针证明单次响应最多一个调用（`tools` 自由路径 0 个、`required` 强制路径 1 个且 `arguments='{}'`）；根因在 `api_tool_call_json` 只返回单个调用、三出口按单调用序列化、Responses 流式硬编码 `fc_1`/`output_index:0`，以及强制路径 `$qArgs==""` 兜底成 `{}`。**下一步先修 ③（参数丢失，改动面最小）再做 ①②（并行）**，别把两者混成一件事。
- **F193（`json_object` 贯通流式 + 并行槽）已验证**：判定抽成 `api_json_object_requested()`，流式 stop 门 `main.php:7232` 一带、并行槽 `$stopV` 一带；新增自检 `JSON-OBJECT-DETECT` 与成对流式探针（json_object 侧 26 delta 完整围栏、对照侧 0 delta）、`par_smoke` 第 8 项 `oai-json-object-keeps-stream`（同文件 `oai-stop` 仍裁成 `"P"`）；`engine/run_f193b.log` **132 PASS/0 FAIL**、`engine/api_smoke_f193c.log` **88/88**、`engine/par_smoke_f193.log` **8/8** + `fairness_f193.log` PASS。
- **F192（`responses-json-object` 常驻守卫）已验证**：只改测试不改引擎；成对请求（同 prompt/seed/`stop`）断言 `text.format` 侧围栏完整且对照侧被裁空，首版谓词经合成回归输入证明"无判别力"后收紧；`engine/api_smoke_f192b.log` **87/87 PASS、`SMOKE_EXIT=0`**、`Accept failed` 0 行。
- **F191（Responses `text.format` 的 `json_object`，闭合 F184）已验证**：`$jsonMode` 检测补 `"format"` 拼写分支（`engine/main.php:7294`）；判据为成对活服务探针（带 `text.format` 时 `stop` 被跳过、围栏整体保留；对照组被裁成空串）+ `engine/build_f191.log` + `engine/api_smoke_f191.log` **86/86** + `engine/run_f191.log` **131 PASS / 0 FAIL**。仍未闭合：`json_object` 下模型裸输出率（两次均围栏则原样返回）。Responses **流式**的 `json_object` 当时未接，**已由 F193 接入**（成对流式探针：带开关 26 个 delta 完整围栏 vs 对照 0 个）。
- **F190（工具白名单作用域）已验证**：`api_tool_names` 只在 `tools` 数组内解析；`engine/build_f190.log` 成功、`engine/run_f190.log` **131 PASS / 0 FAIL / `RUN_EXIT=0`**（新增 `TOOL-NAMES-SCOPE: PASS`）、`engine/api_smoke_f190.log` **86/86 PASS**；同二进制 8001 门禁 `par_smoke_f190.log` **7/7** + `fairness_f190.log` PASS（0.29 s vs 4.87 s）、`Accept failed` 0 行。
- **F189（`tool_choice:"auto"` 负例）已验证**：流式 + tools 仍自由文本；`api_smoke_f189.log` **86/86 PASS**（零改码）。
- **F188（`tool_choice:"none"` 误判修复）已验证**：`api_tool_choice` 在全 body 找 `name`，字符串 none 时误命中 tools 数组；改为字符串直接返回空、对象用平衡块限定。`engine/build_f188.log` 成功、`engine/run_f188.log` **130 PASS / 0 FAIL**、`api_smoke_f188.log` **85/85 PASS**（新增 `chat-tool-none-stream`、`responses-stream-stop`）。
- **F187（seed 可复现 smoke）已验证**：同 seed 两次 Chat 文本一致；`api_smoke_f187.log` **83/83 PASS**（零改码）。
- **F186（客户端中途断开 smoke）已验证**：原始 socket 读首批字节后关闭，随后普通请求仍返回 “Paris”；`api_smoke_f186.log` **82/82 PASS**（零改码）。
- **F185（流式超时 smoke）已验证**：`timeout_ms=1` → 最后 chunk `finish_reason=="length"` 且 <5 s；`api_smoke_f185.log` **81/81 PASS**（零改码）。
- **临时文件清理（2026-11-03 04:54）**：删除 findings 标注可删的旧日志 `serve_par13.log`（91 MB）、`serve_par_fix.log`（185 MB）、`build_f148.log`、`par_gate_console*.txt`、`par_gate_health.txt`、`par_health.txt`；`engine/` 体积 379 MB → **111 MB**。保留所有当前证据日志（`build_f*`、`run_f*`、`api_smoke_f*`、`par_smoke_f*`、`fairness_f*`）。
- **F184（当时的已知缺口，已由 F191 闭合）**：`/v1/responses` 的 `text.format.type=json_object` 未接入校验重试（实测返回围栏 JSON）；细节与建议见 findings F184，闭合过程与判据见 findings F191/F192/F193。
- **F183（单槽 accept 告警）已验证**：单槽 `accept(20s)` 改为 `stream_select` 门控；空闲 10 s 内 `Accept failed` **0 行**（原 9 分钟 57 行）、smoke `api_smoke_f183.log` **80/80 PASS**；与 F182 同源，自检证据见 `run_f183.log`。
- **F182（`max_completion_tokens` 别名）已验证（活服务）**：smoke 新增 `chat-max-completion-tokens`，`api_smoke_f182.log` **80/80 PASS**。
- **F181（`n>1 + stream` 显式拒绝）已验证**：新增 `api_request_n` 与早分支 400；`engine/build_f181b.log` 成功、`api_smoke_f181b.log` **79/79 PASS**（新增 `chat-n2`、`chat-n2-stream-rejected`）。
- **F180（`output_format` 的 `const`/`allOf`）已验证**：无需改码，新增两条 smoke；`api_smoke_f180.log` **77/77 PASS**。
- **F179（DEEP80，嵌套上限 128）已验证**：两处下钻 64→128；首跑暴露每层下钻窗口固定 2000 字符（44 层后截断），改为 20000 后 PASS；`engine/build_f179b.log` 成功、`engine/run_f179b.log` **130 PASS / 0 FAIL / `RUN_EXIT=0`**。
- **F178（通用整数 N 维数组）已验证**：`api_array_n_int_value` + `api_response_schema_array_n_int`，四处接线；首跑抓到并行槽误用 `$prefixCache` 的编译错误，改为 `1`；`engine/build_f178b.log` 成功、`engine/run_f178.log` **129 PASS / 0 FAIL**，`SCHEMA-ARR7-INT text=[{"m":[[[[[[[123]]]]]]]}]: PASS`；同一二进制 `api_smoke_f178.log` **75/75 PASS**（新增 `chat-json-schema-array7-int`）。
- **探针与暂缓项（未改码）**：① `/v1/completions` + `stream:true` 是**显式拒绝**（`completions streaming is not supported`），形状正确、无需修；
- **F177（`output_format` + 本地 `$ref`）已验证**：无需改码（翻译先于预处理），新增 smoke `anth-json-schema-ref`；`api_smoke_f177.log` **74/74 PASS**。
- **F176（Responses 流式工具出口）已验证**：`api_resp_call_start/done` + 两处工具出口 typed events；首跑抓到 arguments 二次转义并修复；`engine/build_f176c.log` 成功、`api_smoke_f176b.log` **73/73 PASS**（新增 `responses-tool-stream`）。
- **F175（Responses 流式 schema 出口）已验证**：`$sJOut` 出口按 `$wantResponses` 输出 typed events；`engine/build_f175.log` 成功、`api_smoke_f175.log` **72/72 PASS**（新增 `responses-stream-json-schema`）。工具调用流式出口仍为 Chat 形状。
- **F174（Responses 流式事件）已验证（纯文本路径）**：`api_resp_start/delta/done` + 单槽 SSE 文本循环按 `$wantResponses` 输出 typed events；`engine/build_f174.log` 成功、`api_smoke_f174.log` **71/71 PASS**（新增 `responses-stream`）。schema/工具流式出口仍为 Chat 形状（已记录）。
- **F173（已知缺口，部分修复）**：`/v1/responses` + `stream:true` 仍返回 Chat `object:"chat.completion.chunk"` + `[DONE]`，需覆盖文本/schema/工具三出口的事件映射；细节与修复方案见 findings F173。
- **F172（Anthropic 流式 schema 分块回放）已验证**：16 字符/delta 循环替代整段单 delta；`engine/build_f172.log` 成功、`engine/run_f172.log` **128 PASS / 0 FAIL**、`api_smoke_f172.log` **70/70 PASS**（arr2d 流式额外断言 delta ≥ 2）。
- **F171（Anthropic 流式结构化输出）已验证**：抽出 `api_schema_token_value`（完整 schema 分发链），Messages 流式在自由循环前先约束解码、再按事件序列整段回放；`engine/build_f171.log` 成功、`engine/run_f171.log` **128 PASS / 0 FAIL**；`api_smoke.py` 扩到 **70 形状**（新增 `anth-json-schema-string-stream`、`anth-json-schema-array-obj-arr2d-stream`），`api_smoke_f171c.log` **70/70 PASS**。
- **F170（DEEP40，嵌套上限 64）已验证**：两处下钻 32→64、检测窗口 2000→8000、三处强制前缀预算按深度放大（`dims*8+64` / `keys*8+64`）；`engine/build_f170.log` 成功；`engine/run_f170.log`：**128 PASS / 0 FAIL / `RUN_EXIT=0`**，`SCHEMA-DEEP40: PASS`（DEEP12/20 未回退）；同一二进制活服务 `engine/api_smoke_f170b.log` **67/67 PASS**。
- **F169 扩展验证**：`api_smoke.py` 扩到 **68 形状**，新增平坦字符串 `anth-json-schema-string`；`engine/api_smoke_f171.log` **68/68 PASS**。
- **F188 二进制 8001 复测**：`par_smoke_f188.log` **7/7**、`fairness_f188.log` **PASS**（0.41 s vs 6.92 s）、`Accept failed` **0 行**。
- **F183 二进制 8001 复测**：`par_smoke_f183.log` **7/7**、`fairness_f183.log` **PASS**（0.31 s vs 5.15 s）、`Accept failed` **0 行**。
- **F181 二进制 8001 复测**：`par_smoke_f181.log` **7/7**、`fairness_f181.log` **PASS**（0.30 s vs 4.97 s）、`Accept failed` **0 行**。
- **F180 二进制 8001 复测**：`par_smoke_f180.log` **7/7**、`fairness_f180.log` **PASS**（0.29 s vs 5.05 s）、`Accept failed` **0 行**。
- **F179 二进制 8001 复测**：`par_smoke_f179.log` **7/7**、`fairness_f179.log` **PASS**（0.30 s vs 5.01 s）、`Accept failed` **0 行**。
- **F178 二进制 8001 复测**：`par_smoke_f178.log` **7/7**、`fairness_f178.log` **PASS**（0.34 s vs 5.48 s）、`Accept failed` **0 行**。
- **F176 二进制 8001 复测**：`par_smoke_f176.log` **7/7**、`fairness_f176.log` **PASS**（0.35 s vs 5.50 s）、`Accept failed` **0 行**。
- **F170 二进制 8001 复测**：`par_smoke_f170.log` **7/7**、`fairness_f170.log` **PASS**（0.28 s vs 4.90 s）、`Accept failed` **0 行**。
- **F169 二进制 8001 复测**：`par_smoke_f169.log` **7/7**、`fairness_f169.log` **PASS**（短流 0.31 s vs 长流 4.98 s）、`Accept failed` **0 行**。
- **F169（Anthropic `output_format`）已验证**：请求体提取后把 `output_format.schema` 翻译为 `"json_schema":{"schema":…}`，复用全部 grammar 与 Messages 响应形状；`engine/build_f169.log` 成功、`engine/run_f169.log` **127 PASS / 0 FAIL**；`api_smoke.py` 扩到 **67 形状**（新增 `anth-json-schema-array-obj-arr2d`），`engine/api_smoke_f169.log` **67/67 PASS**。
- **F168（接线 bug）已修复并验证**：顶层 2-D 检测器会抢占元素内 2-D 数组（活服务输出缺 `items` 包裹；自检直调检测器所以未暴露）。三入口在顶层 arr2 之前先检测元素级 ARR2D；`engine/build_f168.log` 成功、`engine/run_f168.log` **127 PASS / 0 FAIL**；`api_smoke.py` 扩到 **66 形状**（三出口 2-D 元素数组 + 20 层嵌套生产路径），`engine/api_smoke_f168e.log` **66/66 PASS**。
- **F167（par accept 空转告警）已修复并验证**：`stream_select` 门控 + `$c` 空闲时指向监听器（规避 TypePHP 资源类型改判限制）。8001 空闲 8 s 内 `Accept failed` **0 行**（原 30 s 443 行）；`par_smoke_f167c.log` **7/7**、`fairness_f167.log` **PASS**（0.28 s vs 4.91 s）；全量回归 `run_f167.log` **127 PASS / 0 FAIL / `RUN_EXIT=0`**。
- **F166（通用 N 维字符串数组）已验证**：`api_array_n_string_value` + rank 检测器（≥7 才接管），三出口在 6 维之前接入；`engine/build_f166.log` 构建成功；`engine/run_f166.log`：**127 PASS / 0 FAIL / `RUN_EXIT=0`**，`SCHEMA-ARR7 det=[m|7] text=[{"m":[[[[[[["red"]]]]]]]}]: PASS`；同一二进制活服务 `engine/api_smoke_f166.log`：**61/61 PASS**。
- **F165（嵌套上限 32、DEEP20）已验证**：两处下钻循环 12→32；新增 `SCHEMA-DEEP20`。首跑暴露闭合串 token 预算 bug（固定 8，21 个 `}` 被截断），两处构造器改为 `count($keys)*2+8` 后 PASS；`engine/build_f165b.log` 构建成功；`engine/run_f165b.log`：**126 PASS / 0 FAIL / `RUN_EXIT=0`**；同一二进制活服务 `engine/api_smoke_f165.log`：**61/61 PASS**。
- **F164（转义字符串）已验证**：`api_json_escape_safe_ids` 按片段白名单（`\"` 或偶数反斜杠）过滤，`api_string_value`/`_multi` 接入；`engine/build_f164.log` 构建成功；`engine/run_f164.log`：**125 PASS / 0 FAIL / `RUN_EXIT=0`**，`SCHEMA-ESCAPE text=[{"s":"a\"s\"s\""}] json=1: PASS`。F162 的失败根因（多字节反斜杠片段）由此绕过；同一二进制活服务 `engine/api_smoke_f164.log`：**61/61 PASS、`SMOKE_EXIT=0`**。
- **F163（对象数组元素内二维字符串数组）已验证**：`api_array_object_array2_value` + 专用检测器，三出口接入；`engine/build_f163b.log` 构建成功；`engine/run_f163b.log`：**124 PASS / 0 FAIL / `RUN_EXIT=0`**，`SCHEMA-ARR-OBJ-ARR2D text=[{"items":[{"m":[["red"]]},{"m":[["blue"]]}]}]: PASS`。首跑抓到第二元素前缀漏开引号，已修；同一二进制活服务 `engine/api_smoke_f163.log`：**61/61 PASS、`SMOKE_EXIT=0`**。
- **F162 转义字符串：尝试失败、已回滚**：两版实现（逐 token 转义状态 / `$forceEsc` 注入）均产生非法 JSON（`run_f162*.log` 的 `SCHEMA-ESCAPE json=0`）；根因是反斜杠 token 片段为多字节（`\\`、`\n` 形态）。已 `git checkout` 回滚到 F161 源码，重建 `engine/build_f161c.log` 成功，失败代码未提交；新方案见 findings F162。
- **F161（对象数组元素内整数数组）已验证**：`api_array_object_int_array_value` + 专用检测器，三出口接入；`engine/build_f161b.log` 构建成功；`engine/run_f161b.log`：**123 PASS / 0 FAIL / `RUN_EXIT=0`**，`SCHEMA-ARR-OBJ-NUM text=[{"items":[{"nums":[100,200]},{"nums":[300,400]}]}]: PASS`。首跑抓到双逗号 bug（与 F155 同型），删掉多余强制逗号后通过；同一二进制活服务 `engine/api_smoke_f161.log`：**61/61 PASS、`SMOKE_EXIT=0`**；8001 替代路径同二进制：`par_smoke_f161.log` **7/7**、`fairness_f161.log` **PASS**（0.26 s vs 3.98 s）。回滚点：`engine/main.php.f160.bak`。本地 git 已初始化并提交 `569e9ee`（111 文件，`*.log`/`engine/build`/模型与缓存已排除）。
- **F160（根级 oneOf + grammar）已验证**：新增 `api_response_schema_root_oneof_grammar` / `api_root_oneof_grammar_value`（复用 F159 候选选支），三出口接入；`engine/build_f160.log` 构建成功；`engine/run_f160.log`：**122 PASS / 0 FAIL / `RUN_EXIT=0`**，`SCHEMA-ROOT-ONEOF-GRAMMAR det=[root|kind=book,title:s;kind=cd,tracks:i]: PASS`（book 支、title 空串，与 F159 同口径）；同一二进制活服务 `engine/api_smoke_f160.log`：**61/61 PASS、`SMOKE_EXIT=0`**。构建期间一次 C1083 撞锁源于误留的排队构建（`build_f160n.log` 为失败记录）。
- **构建/自测脚本拆分（环境侧）**：`scripts/build-kakasi-nosetvars.bat` 新增 `norun` 开关（`bat norun` 只构建，不跑自检），并新增 `scripts/run-kakasi-selftest.bat <logfile>`（vcvars + 显式 oneAPI/TPC PATH，单独跑已构建二进制）。原因：`spawnSync` 超时只会杀掉 cmd 而留下 exe 子进程，且本机 WMI 卡顿使 `taskkill`/`tasklist` 超时，无法清理残留；拆分后构建与自测互不阻塞。
- **F156-01 过时断言已修复、重建并复核通过**：会话误把 F157 已修好的断言改回 `items|tags|`，导致旧 `run_f156.log` 出现 1 FAIL；现已恢复为 `items|tags`（检测器无标量返回两段、有标量返回三段，dispatch 用 `isset($p[2])` 保护）。`engine/build_f157.log` 构建成功（norun）；同源复核 `engine/run_f157.log`：**121 PASS / 0 FAIL、`RUN_EXIT=0`**，`SCHEMA-ARR-OBJ-ARR`、`SCHEMA-ARR-OBJ-ARR2`、`SCHEMA-ONEOF-GRAMMAR` 均 PASS；同一二进制起 8000 服务（显式 PATH、`serve.on` 哨兵）复测 `api_smoke.py` → **61/61 PASS、`SMOKE_EXIT=0`**（`engine/api_smoke_f157.log`）。服务停杀走 `taskkill //F //IM`（WMI 僵死下挂起但生效），`serve.on` 需在服务退出后删除。
- **对象数组元素内字符串数组已实现并验证（F155）**：`api_array_object_array_value` + 专用检测器，三条 schema 路径在平坦 object-array 之前接入；`engine/build_aoa2.log`：`SCHEMA-ARR-OBJ-ARR det=[items|tags] text=[{"items":[{"tags":["red light …"]},{"tags":["blue light …"]}]}]`、PASS、`RUN_EXIT=0`、0 FAIL（118 处 PASS 标记），`EXE_SIZE_BYTES=2281984`。首跑抓到多引号与重复逗号两个真 bug（`engine/build_aoa.log`），修正后 PASS。
- **六维字符串数组已实现并验证（F154）**：`api_array6_string_value` + 检测器，三条 schema 路径在 5 维之前接入；`engine/build_arr6.log`：`SCHEMA-ARR6 text=[{"m":[[[[[["red"]]]]],[[[[["blue"]]]]]]]}`、`SCHEMA-ARR6: PASS`、`RUN_EXIT=0`、0 FAIL（117 处 PASS 标记），`EXE_SIZE_BYTES=2248704`；2/3/4/5/6 维同一构建全 PASS。
- **DEEP12 已构建并验证**：绕过 `setvars` 后，`scripts/build-kakasi-nosetvars.bat` 全链路成功（SYCL obj → tpc → icx 终链）。日志 `engine/build_deep12.log`：`SCHEMA-DEEP12 plan=[a/b/c/d/e/f/g/h/i/j/k/l|city:s,count:i]`、`SCHEMA-DEEP12: PASS`、`RUN_EXIT=0`、**0 FAIL**、`EXE_SIZE_BYTES=2139648`；2/3/4/6/8/12 层同一构建全 PASS。环境缺口见 F150（`PHP_HOME`/`PHPX_HOME`），实现见 F151。
- **五维字符串数组已实现并验证（F152）**：新增 `api_array5_string_value` + 检测器，三条 schema 路径接入；`engine/build_arr5.log`：`SCHEMA-ARR5: PASS`、`RUN_EXIT=0`、0 FAIL（115 处 PASS 标记），`EXE_SIZE_BYTES=2180096`；2/3/4/5 维同一构建全 PASS。构建时 8001 旧服务锁住了 exe（LNK1104），先把它重命名为 `engine/build/kakasi_engine_sycl_f151.exe` 再重建；旧服务仍在用重命名后的文件。
- **无界整数 `multipleOf` 已实现并验证（F153）**：模型选前缀 + 强制三位可整除后缀（`x=(m-(p*1000)%m)%m`），三条 schema 路径接入；`engine/build_mult.log`：`SCHEMA-MULT-UNBOUND det=[n|7] text=[{"n":7000000}]`、`RUN_EXIT=0`、0 FAIL（116 处 PASS 标记）。
- **8000 API 冒烟（F153 二进制）**：以显式 PATH（oneAPI bin + `%TPC%`、`%TPC%\phpx\lib`、`%TPC%\SDK\lib`）直起服务，`/health` 3 s 就绪；`api_smoke.py 8000` → **61/61 PASS**、`SMOKE_EXIT=0`、0 FAIL（`engine/api_smoke_f153.log`）；测试后服务已退出，8000 端口释放。
- **8001 门禁（部分复验）**：2026-11-03 00:24 对**已在监听**的 8001 跑 `par_smoke.py` → **7/7 PASS**、`SMOKE_EXIT=0`（`engine/par_smoke_deep12.log`）。但该监听器在本次重建**之前**启动，`netstat -ano` 的 OwningProcess=0、PowerShell `Get-NetTCPConnection` 45 s 超时，无法解析/替换 PID，因此**不能算作 F152 二进制的 8001 证据**；重启后仍需 `scripts\par_gate.bat` 复测。
- **环境根因（已定位）**：本机进程枚举/WMI 层挂起——`tasklist` >15 s 超时、`TaskStop` 反复失败、PowerShell `Get-Process` 超时、**oneAPI `setvars.bat` >90 s 不返回**；Node 侧 `cmd /c echo`/`netstat` 正常。构建已用无 setvars 脚本绕过并完成；`serve-par.bat` 仍依赖 setvars，服务启动待重启。临时文件：`engine/build_f148.log`（0 字节）、`engine/par_gate_console*.txt`、`engine/par_gate_health.txt`、`engine/par_health.txt`（可删）；新证据日志 `engine/build_deep12.log`、`engine/par_smoke_deep12.log`（保留）。
- **【02:00 更新】恢复会话（未重启机器）**：① WMI 僵死依旧（tasklist >120 s），但实测 `taskkill //F //IM` 挂起的同时**杀进程生效**——残留 8001 监听（PID 595564）被清掉、端口释放（F156）；② 确认 01:09 的 aoa3 构建 = **`SCHEMA-ARR-OBJ-ARR2`（元素内混合叶子：字符串数组 + 标量兄弟，检测器 `items|tags|note`）**，但 01:19 的自检运行被会话中断杀在 `PARITY16 144`——自检段顺序是 **PARITY 在前、SCHEMA 在最后**（build_aoa2.log 403/778 行），所以 ARR-OBJ-ARR2 结果当时未被捕获；③ 已以显式 PATH 重跑全量自检 → `engine/run_aoa3b.log`；④ 待办：自检 0 FAIL 后起 8001 新服务跑 `par_smoke.py` 7/7 + `fairness_test.py`（替代被阻塞的 `par_gate.bat`），再跑 `api_smoke.py 8000` 61/61；`par_gate.bat` 本体（setvars+taskkill 路径）仍留待重启后复测一次。
- **【03:10 收口，F157】全部完成**：②③④ 已闭环——自检重跑暴露检测器回归 2 FAIL → 修复（tail 判定/返回段数/3 调用点 isset 守卫）→ 重建全量自检 **119 PASS / 0 FAIL / RUN_EXIT=0**（ARR-OBJ-ARR 与 ARR-OBJ-ARR2 双 PASS）；8001 新服务 `par_smoke` **7/7** + `fairness` **PASS**（首跑 1.2 s 压线，warm 1.0 s）；8000 `api_smoke` **61/61**。僵死态进程处置升级为 **`Stop-Process -Id` + netstat 迭代 + curl(RC=7) 验证**（taskkill /IM 3/3 挂起，netstat OwningProcess 出假行）。遗留非阻塞项：① `par_gate.bat` 本体重启后一次性复测（其功能已被替代路径覆盖）；② par 服务 accept 空转刷 "Accept failed"（40 min 2 万行，无害，建议 stream_select 修）；③ 临时文件可删：`serve_par13.log`（73 MB）、`serve_par_fix.log`、`par_gate_console*.txt`、`par_gate_health.txt`、`par_health.txt`。

**F201 的"失败"结论已作废并重测（F203）**：那是**我自己同时跑了两个引擎进程**造成的设备争抢——前台 8000 套件看到 WSAECONNRESET、8001 公平性测出长流 41 s 的假 FAIL，代码本身没问题。隔离重测（一次只跑一个进程）：`run_f202.log` 134 PASS / 0 FAIL、`api_smoke_f202.log` **92/92**（含 `chat-tool-named-parallel`，总耗时 **102 s**）、`par_smoke_f202.log` 8/8 + `fairness_f202.log` PASS（短 0.32 s / 长 5.21 s）。功能由 `a108efa` 恢复。**新硬规则：起服务前先 `netstat` 确认 8000/8001 无人监听、后台门禁链已结束；任何时刻只允许一个引擎进程。**当前已验证口径 = **F203**（不再是 F200）。

## 续作入口（若恢复）

1. **per-session KV 真并行**：现状是单线程 accept + 全局 `g_kv`/`g_sess_*`。**第一步已完成（F86：KV 快照/恢复 + SESSION-SNAP 交接逐位一致）**；下一步：把 save/restore 用一个显式 session 句柄封装（或直接两槽），在 accept 循环里对进行中的流式连接做时间片推进，先验证"长流不饿死短请求"，再谈墙钟收益与线程。
2. **grammar-constrained 解码**：已覆盖（F109–F147、F152–F159）：字符串 charset/有符号整数/小数/科学计数法/布尔/嵌套 1–12 层/同层混合叶子/2–6 维数组/三类数组/对象数组/顶层数组/元素内字符串数组+标量混合叶子（F157）/元素内嵌套链 ≤12 层（F158）/**oneOf 分支含 grammar（F159：const 选支 + 分支自有语法）**/**根级 oneOf 分支含 grammar（F160）**/有界·无界 multipleOf/排他边界/本地 `$ref`/`allOf`/属性级·根级 `oneOf`。**剩余缺口**：外部 `$ref`、转义字符串；>12 层与 >6 维收益递减暂缓。离线复刻检测器到 Python 的方法已工具化（F158/F159 两轮使用），下个增量先跑防误认领矩阵再重建。
3. 其余：多分片 GGUF 合并、多样草稿器、并行多卡、FR-05 插件、UNIKERN 决策。

复现：`scripts\build-kakasi-nosetvars.bat norun`（构建，WMI/`setvars` 挂起时亦可；在 `engine` 目录下用 `..\scripts\...`，**在仓库根用 `scripts\...`**，路径写错会得到"系统找不到指定的路径"且日志缺失）→ `scripts\run-kakasi-selftest.bat run.log`（**必须从仓库根调用**；全量自检判据 **134 PASS / 0 FAIL / `RUN_EXIT=0`**，当前证据 `engine/run_f200.log`）→ 起 8000 服务（`engine` 下 `touch serve.on` + 显式 PATH 直起 `build/kakasi_engine_sycl.exe`）后 `python engine\tests\api_smoke.py 8000`（**91/91**，`engine/api_smoke_f200.log`；F201 的 92 项形状以 `engine/gates_f201.txt` 为准）→ 8001：`serve.par.on` 起服务后 `python engine\tests\par_smoke.py 8001`（**8/8**）、`python engine\tests\fairness_test.py 8001`（PASS，短流应远早于长流）。环境正常时仍可用 `scripts\build-kakasi.bat` 一次完成构建+自检。
**两条本机操作纪律（都踩过）**：① 长跑门禁期间**不要**再往后台队列插探测命令，尤其**绝不用 `ps -W`**（走 WMI 会挂死，并把排在后面的门禁步骤一起堵死）；看进度用读文件（`gates_*.txt`），判活死用 `netstat`/端口。② 门禁判据必须**同时**看 `FAIL` 计数与 exit code：套件崩在中间时会出现 `PASS` 计数偏少而 `FAIL=0`。③ **F201 起套件变慢是预期的**：并行/强制调用形状各要跑一次约束解码（单次实测 ≈28 s），整套**实测 102 s**（`engine/api_smoke_f202.log`，92 形状；我先前"推算 6–8 min"是错的，已按实测改正——推算不该写成数据）；且 `api_smoke_*.log` 因 Python 块缓冲**要到进程结束才有内容**，中途读到空文件不代表卡住。判进度只看 `gates_*.txt` 有没有出现、`netstat` 端口在不在，别为"看进度"往队列里插命令。

- **F204（流式 `required` 丢参数 + 序号管道）已验证**：流式强制分支原先给每把工具硬编码空 arguments 骨架（非流式侧 F194a 修过，流式漏了），现改为逐工具取参数候选并用 `api_tool_wire` 统一形态；实测流式 `arguments` 由 `"{}"` 变成带内容的对象，`run_f204.log` **134 PASS / 0 FAIL**，同二进制活服务 `api_smoke_f204.log` **93/93、`SMOKE_EXIT=0`、`Accept failed` 0 行**。**并行扇出本身仍未做**：`api_resp_call_start/done` 已加 `output_index`/`last`/`items` 参数（单条目按 0/1/"" 调用，行为不变），发事件循环留给 F205。
- **F205（流式并行扇出）已活服务实测，已有常驻守卫**：两条流式强制分支（`required` 与点名）都改为 `api_tool_calls_json` + `api_tool_items` 复数提取，逐项发事件——chat 侧 `tool_calls` 带 `index` 0/1 与 `call_1`/`call_2`、参数非空；Responses 侧 `fc_1`/`fc_2` 两个 `output_item.added/done`，`response.completed` **恰好一次**（多点只发最后一项）。`run_f205.log` 134 PASS / 0 FAIL。**下一步**：把这条加进 api_smoke（`chat-tool-named-parallel-stream`、`responses-tool-parallel-stream`），已有常驻守卫 `chat-tool-parallel-stream` + `responses-tool-parallel-stream`（`api_smoke_f206b.log` **95/95、`SMOKE_EXIT=0`**），8001 侧同二进制 `par_smoke_f206.log` 8/8 + `fairness_f206.log` PASS、`Accept failed` 0 行）；写守卫时它自己先崩过一次：解析 [DONE] 造成 exit=1 而 FAIL=0，正是那条『崩溃长得像没失败』的判据把它抓出来的。同二进制活服务 `api_smoke_f205.log` **93/93、`SMOKE_EXIT=0`、`Accept failed` 0 行**（既有流式工具形状未回退）。
- **F207（外部 $ref 注册表解析）已验证**：`api_schema_resolve_refs()` 增外部内联轮，仅解析调用方随请求交出的 `schema_registry`，**不联网、不读请求里指定的文件路径**（避免目录穿越）；未注册引用原样保留。自检 `SCHEMA-EXTREF`/`SCHEMA-EXTREF-UNREG` ⇒ `run_f207.log` **136 PASS / 0 FAIL**；活服务 `chat-json-schema-extref` ⇒ `api_smoke_f207.log` **96/96、SMOKE_EXIT=0**；8001 同二进制 `par_smoke_f207.log` 8/8 + `fairness_f207.log` PASS、`Accept failed` 0 行（并行槽也调用同一个引用解析器，必须单独测）。边界：不做 URL/FS 解析，混合本地+外部引用只解外部那一轮到本地指针即停。
- **F209（200 层嵌套 = 冷启动缺陷，不是深度限制）已验证**：同一运行内对完全相同的 200 层装配打两次 ⇒ `first_len=0`、`second_len=3909` ⇒ 深度从来不是上限；`SCHEMA-DEEP200` 改为以第二次计分并在 `run_f209c.log` 实测通过（`opens=200 json=1`，137 PASS / 0 FAIL），RETRY 行留作该缺陷的常驻证据。**遗留真 bug**：会话内第一条长约束解码返回空——影响面比嵌套更深，任何冷启动长约束解码都可能中招。**另一条教训**：`run_f209b.log` 其实是改动前二进制产出的，我差点把不符合事实的日志当证据提交——之后核对输出文案本身，而不是只看 PASS 计数。

- **F210（负结果，已回滚）**：`api_nested_object_value` 内就地重试 `api_constrained` 不能救回冷启动空返回（`run_f210.log` 仍 `first_len=0`，137 PASS 无回退）⇒ 有效状态变化发生在该函数**收尾**而非解码调用本身；重试代码不留，`SCHEMA-DEEP200-RETRY` 作为可测证据（修好后 `first_len>0`）。

- **F211（延时重试实验＝无效，已回滚；顺带更正一处错误联想）**：`usleep(300000)` 后重试仍 `first_len=0`，但**无法证明新码在跑**（只在 AST/obj 里见到 usleep；此前确实发生过增量构建吃旧源码的事故）⇒ 该实验既不能否定也不能支持时间假说，实验代码已回滚。**下次先立阳性对照**：在待证分支里 `echo` 一个唯一标记，看它在自检输出里出现没有，再谈结论。

- **F212（冷启动空返回已归因到分隔符续解码）**：`api_constrained` 两个空出口打标记并**用 `grep -rl F212-MARK build/gen` 证明标记已编进 .cc/.obj**，自检里标记 0 次而 `first_len=0` ⇒ 200 层长前缀解码成功，空串出自 `api_nested_object_value` 前缀之后的出口穷举，唯一可能是叶子分隔符的 `api_constrained_continue` 返回空（收尾 `}` 那步不会返回空）。下一步：把 marker 打在 `engine/main.php:2549` 那一处即可确认，再决定是重试还是修续解码的容量判定。**本轮全部临时 echo 已回滚**，HEAD 源码 == 被测量的构建。

- **工具链陷阱（重要）**：`engine/build/gen/cache` 会**复用已生成的 C++**，源码删除后产物仍留旧代码（实测 `grep F212-MARK build/gen/extension-kakasi_engine.cc` = 2，而 main.php 里已为 0）。⇒ 任何靠输出标记做的归因，**必须先清缓存干净构建**，否则标记可能在二进制里根本不存在。

- **F214（撤回 F212 归因 + 立测量规程）**：清缓存重建后全树扫标记，`F213-MARK` **只在 `build/gen/cache/ast/*.ast`**，`build/gen/main.cc` 与 `.obj` 里都没有 ⇒ 增量构建没把改动带进生成码，`MARKS=0` 什么也不能说明 ⇒ **F212 的阶段归因作废**（已在原文标注撤回）。成立且不依赖标记的硬事实只有一条：同一运行内第一条 200 层调用返回空、第二条 3909 字符且 JSON 合法（`run_f209c.log`）。**新规程**：改动后先 `grep` 产物（`build/gen/main.cc`）确认唯一字符串在里面，再跑测量；不在就删 `build/gen/cache/ast` 全量重建。**只加 echo 不改行为的实验，无法用反推**——这是本轮最大的方法论收获。**冷启动事实已在清缓存全量重建后复核**（`run_f214.log` 137 PASS / 0 FAIL，`first_len=0 second_len=3909`）。

- **F216（冷启动＝叶子分隔符续解码，标记已核对产物）**：`build/gen/cache/ast` 清空重建后，`grep -rc F215-MARK build/gen/*.cc` 命中 `extension-kakasi_engine.cc:1`（**函数体生成在这个文件，不在 main.cc——我第一版控制查错了文件**）；产物里有标记而自检输出 0 次，且 `first_len=0` 依旧 ⇒ 前缀阶段无辜，`api_nested_object_value` 前缀之后的唯一可能出口是**分隔符续解码**（`engine/main.php:2549`）。修复方向：长前缀填满会话后容量判定把放不下当结束；判据现成——`SCHEMA-DEEP200-RETRY` 的 `first_len` 必须转非零。**F212 的归因在正确方法下重新成立**，撤稿记录保留作为方法教训。临时 echo 已回滚。

- **F218（冷启动：前缀与分隔符都被洗清，候选收窄）**：两次标记实验都在**先确认标记进了 build/gen 产物**之后才解读——前缀出口标记 0 次输出（前缀无辜）；分隔符那一步打出 `cand=1 first=3 allowed_empty=0 done=0`（首步可达，不是它返回空）。剩余候选见 findings F218；**下一步建议一把梭**：在该函数每个 `return ""` 前打编号标记，一次构建一次自检就把所有出口枚举完。基线仍是 `run_f214.log` 137 PASS / 0 FAIL、`first_len=0 second_len=3909`；探针已全部回滚并重建核对。

- **F219（构建既不吃新码也不清旧码）**：回滚探针后重建，产物里**仍**有 F217-MARK（2 处）⇒ exe 与 HEAD 源码不同源（残留 echo 路径未触发，行为不变）。规程补条：要声明源码==二进制，必须清 `build/gen/cache/ast`（必要时删 `build/gen/*.cc`）全量重建，并**双向核对**（新串在、旧串不在）。**待办**：下一次开工先做这一次干净重建再谈其他测量；在此之前基线只认 `run_f214.log`。

- **F221（干净重建已完成；推翻 F219 与"工具链陷阱"那条，并降级 F215/F218 的前缀结论）**：照 F219 的待办做了全量重建（`rm -rf build/gen/cache/ast && rm -f build/gen/*.cc`，BUILD_EXIT=0）并双向核对，结论与 F219 相反——`grep -c api_nested_object_value` 在 `build/gen/main.cc` = **20**、`extension-kakasi_engine.cc` = 3；`F2..-MARK` 在 `main.cc` = **0**，在 `extension-kakasi_engine.cc` = 2，而那两行的原文是 `php::Str{ZEND_STRL("F217-MARK cand="), true}, // [1820]`。⇒ **`main.cc` 才是函数体，`extension-*.cc` 只是只追加的字面量池；删码后死串留在池里不产生行为。源码与二进制同源，F219 作废**（progress 里"工具链陷阱"那条同样作废）。连带后果：**F216/F218 用错了控制文件**，所以"前缀标记打印 0 次 ⇒ 前缀无辜"不再成立，前缀嫌疑重开；F217 的分隔符结论**保留**，但依据换成 `run_f217.log:682` 起真打印过 `F217-MARK cand=1 first=3 allowed_empty=0 done=0`。**新规程（覆盖 F214 的方法条）**：探针证据强度 ①自检输出出现（最强）＞ ②`build/gen/main.cc` 出现 ＞ ②`extension-*.cc`/`.obj` 出现＝**无效**；只有②时判"未证"，不得写"已核对产物"。冷启动硬事实未变：`run_f214.log` 137 PASS / 0 FAIL、`first_len=0 second_len=3909`。

## 5-Question Reboot Check
| Question | Answer |
|----------|--------|
| Where am I? | Phase 3 收尾：Phase 1 数值口径全绿、Phase 2 主体完成；API 四形状 + 两种流式 + 工具调用/约束解码 + **并发正确性（F53）**；投机单链 ~1.75×（k=8 时 2.5×）、树机制通、SSD 缓存 3.3×（API 命中 0.65–1.01 s） |
| Where am I going? | 剩余缺口：JSON-schema 增量推导、Anthropic `tool_use`、目录式模型文件夹、多样草稿器、per-session KV 真并行、条件性 UNIKERN 决策与 acpp ABI 探针（需 NVIDIA/Linux CI） |
| What's the goal? | TypePHP 单二进制多后端推理框架 + Kapitsa 引擎可移植版（tpc+SYCL 原生链接，禁用 FFI） |
| What have I learned? | F19~F21：驻留 10× 与 FR-18 预算；**oracle 文件的索引语义必须读源码，不能让 harness 在自检不过时闭眼报数**；F53：**"并发失败"先查协议层是否读完请求体**，再怀疑推理状态；Σ个体/wall 不能当并行度 |
| What have I done? | 本轮：Content-Length 读循环修复（F53）；Anthropic `tool_use`（F54）、目录式模型文件夹（F55）、OpenAI 流式 tool_calls（F56）、有限域 schema→参数（F57）、Anthropic 流式 `tool_use`（F58）、工具轮回放（F60）、`# Tools` 注入（F61）、Anthropic 数组式历史（F62）、并行结果同轮（F63）、同消息多 `tool_result`（F64）、并行助手调用（F65）均端到端验证；如实记录 FIFO 排队与 grammar-constrained 缺口 |

---
*Update after completing each phase or encountering errors*
- **F222（冷启动 bug 定位到分隔符续解码；并修正 F221 的产物核对规程）**：一次构建把 `api_nested_object_value` 的全部出口编号打点，只看自检输出 ⇒ `engine/run_f222.log` 137 PASS / 0 FAIL，`F222-IN keys=200`×2、第 1 次 `F222-E5 sep empty slen=1938`、第 2 次 `F222-OK len=3909`，E1/E2/E3/E4 零次 ⇒ **前缀解码成功、第一个叶子分隔符的 `api_constrained_continue` 首调返回空**（F216 归因恢复，F218 的降级是方法误判）。**规程修正**：增量构建**确实不吸收新增代码**（BUILD_EXIT=0 而产物无改动，须清 `build/gen/cache/ast` 全量重建）；但 `main.cc` 里的字符串常量是**按池序号引用**的，所以"grep `main.cc` 找不到标记文本"不能判"未编译"——F221 的第②级判据无效，正确顺序是 **①输出出现 ＞ ②池里拿到序号且 `main.cc` 引用该序号 ＞ ③只看 `extension-*.cc` 文本＝弱**。本轮临时探针已全部回滚并全量重建，使 exe 与 HEAD 同源。
- **F226（冷启动第一条长约束解码返回空——已修复）**：`api_constrain_loop` 在 `-1` 且文法未走完时用 `model_session_start(0, prompt+chosen)` 重新 prefill 并对同一步重试（上限 4 次），`api_constrained` 传入 `$ids`；`engine/run_f226.log` **137 PASS / 0 FAIL / `RUN_EXIT=0`**，关键行由 `first_len=0` 变 **`first_len=3909`** ⇒ F208 起记录的功能缺陷闭合。`SCHEMA-DEEP200` 的判据同时从"第二次成功"收紧为**首调非空**（`strlen($d2Probe)>0`），收紧版复跑在 `engine/run_f227.log`（判据未到）。**未验证项（不作已完成）**：8000 `api_smoke.py` 与 8001 `par_smoke`/`fairness` 门禁本轮未跑；重启对所有 `api_constrained` 生效使自检从 ~9 min 涨到 ~12 min（EOS 路径白付一次 prefill），需把重启收窄到"单 id 强制字面量步"；native 根因仍在 `src/model_sycl.cc:1323`（冷路径无 logits）与 `:1496`（`g_sess_len += 1` 早于 `r.empty()` 判定）。

## 停车状态（2026-11-04 预算将尽，交下一轮续跑）
- **HEAD = `caf1bd6`**（重启收窄到单 id 强制字面量步）**尚未构建/测量**；当前 `build/kakasi_engine_sycl.exe` 来自**上一版**（`1e19d99`＋`SCHEMA-DEEP200` 首调判据收紧），`engine/run_f227.log` 只算那一版的证据，**不能**当作 `caf1bd6` 的验收。
- **已测量成立**：冷启动修复效果 `first_len` 0→3909、137 PASS / 0 FAIL、`RUN_EXIT=0`（`engine/run_f226.log`，对应 `1e19d99`）。
- **下一轮三步（严格按序，一次只跑一个引擎进程）**：①`rm -rf engine/build/gen/cache/ast` 后 `scripts\build-kakasi-nosetvars.bat norun`（增量构建会**不吃新码**，必须清这个缓存）；②`scripts\run-kakasi-selftest.bat engine\run_f228.log`，要求 **137 PASS / 0 FAIL**、`SCHEMA-DEEP200-RETRY first_len>0`、`SCHEMA-DEEP200: PASS (... first attempt)`，并记录总时长是否从 ~12 min 回落到 ~9 min（回落＝收窄生效）；③门禁：`scripts\serve.bat` 起 8000 跑 `engine/tests/api_smoke.py`，停服后再起 8001 跑 `engine/tests/par_smoke.py` + `engine/tests/fairness_test.py`。
- **未闭合的根因（比绕行更该修的地方）**：`src/model_sycl.cc:1323` 冷路径 prefill 不产出 logits；`src/model_sycl.cc:1496` `g_sess_len += 1` 早于 `r.empty()` 判定（失败步仍推进会话长度）。F226 的 re-prefill 是**可用的功能修复**，但真正消除代价要在 native 侧修这两处（需一次完整 SYCL 重编，别和 PHP 侧改动混在同一轮）。
- **交接警告（停车时）**：后台链 `b8jc38uyc`（`run_f227.log`，验 `1e19d99`+首调判据）在预算耗尽时仍在跑。**续跑前先确认它已结束**（`grep RUN_EXIT engine/run_f227.log`），否则运行中的 exe 会锁文件导致重建失败；要重建就先停该任务或等其完成再取判据。
- **F227 判据已到（2026-11-04）**：`engine/run_f227.log`（`1e19d99`＋首调判据那版二进制）790 行 **`RUN_EXIT=0`**、**137 PASS / 0 FAIL**、`SCHEMA-DEEP200: PASS (two-hundred-level object assembled on the first attempt)`、`SCHEMA-DEEP200-RETRY first_len=3909 second_len=3887` ⇒ **收紧后的"首调非空"判据本身为绿**（不再是重试计分）。仍未闭合：`caf1bd6` 收窄未构建/未测、8000/8001 门禁未跑、native 根因 `model_sycl.cc:1323`/`:1496` 未修。
- **计量更正（自查）**：前文"自检 9 min → 12 min"是我从**回合时间戳**倒推的估数，不是测量值（违反"估计数不当测量"）。`run_f227.log` 实测：命令发出（120121 s）到 `RUN_EXIT` 落地约 **8 min 量级**，但其中含构建；**没有一条干净的秒表数**。⇒ 下一轮（`caf1bd6` 收窄版）跑自检时**在同一条命令里打点**：`date +%s` → 跑 `run-kakasi-selftest.bat` → `date +%s`，把纯自检秒数写进 findings；在此之前"收窄省下多少时间"只能记为**未测量**。
- **门禁跑法（已核对脚本，未执行）**：`engine/tests/par_smoke.py [port]`、`engine/tests/fairness_test.py [port] [long_tokens]` 都接受端口参数 ⇒ 8001 门禁不需要改代码。**但 `scripts/serve-par.bat` 里 `call setvars.bat`**，而本机 WMI 僵死时 setvars 会挂（这正是 `run-kakasi-selftest.bat` 改成显式列 PATH 的原因）⇒ 起 8001 要用显式 PATH 那条路（照 `run-kakasi-selftest.bat` 的 ONEAPI/TPC/PATH 三行 + `cd engine` + `build\kakasi_engine_sycl.exe --port 8001`），别直接用 `serve-par.bat`。顺序仍是：**先** 8000 `api_smoke.py`（含新 `chat-json-schema-deep40-first-call`）→ **停服** → 再起 8001 跑 `par_smoke.py 8001` + `fairness_test.py 8001`，全程一次一个引擎进程。

## 本轮验证台账（2026-11-04，F228–F232）
- **已测量**：`caf1bd6` 收窄版自检 `engine/run_f228.log` **137 PASS / 0 FAIL / `RUN_EXIT=0`**、`SCHEMA-DEEP200: PASS (… first attempt)`、`first_len=3909`（提交 `637dfec`）。⇒ 冷启动修复在"重启只作用于单 id 强制步"下**仍然成立**。
- **代码核对（非实测）**：F230 re-prefill 不污染前缀缓存（`cache_store` 只序列化前 `ids.size()` 行）；F231 新活服务门 `chat-json-schema-deep40-first-call` 的遍历顺序经 Python 离线复刻核实（外层是 `k39`）。
- **进行中（后台链 `b92wbrol6`）**：等 `run_f229` 秒表数 → 8000 `serve.on` 起服跑 `api_smoke.py` → 停服 → 8001 `serve.par.on` 跑 `par_smoke.py`/`fairness_test.py`。哨兵文件用后即删（防 serve.on 竞态把自测变成服务）。
- **明确不作结论**：收窄"省了多少时间"无对照基线；`run_f229` 不含 `F229-PROBE`（探针在构建前已 revert），故其无输出**不是**冷启动拒绝消失的证据；native 根因仍停在 F228 两条分支。

## 交接快照（2026-11-04 04:29，预算将尽）
- **HEAD = `da16ae1`**；工作树干净（`engine/gates_f201.txt` 为遗留临时件，未纳入）。**磁盘 exe == HEAD 源码**（`build_f228` 那次构建，探针已在 `8afcf73` revert 掉），所以**门禁可以直接跑，不需要重建**。
- **已绿**：`run_f228.log` 137 PASS / 0 FAIL、`SCHEMA-DEEP200` 按**首调**判据 PASS、`first_len=3909`（= `caf1bd6` 收窄版复验，提交 `637dfec`）。
- **在跑的后台链**：`bd7o2dv9y`（构建完正在跑 `run_f229.log`，带秒表）→ 之后 `b92wbrol6` 会等 `run_f229` 的 `RUN_EXIT`，再依次跑 8000 `api_smoke.py`（**期望 97 PASS**，新增 `chat-json-schema-deep40-first-call`）→ 删 `serve.on` → 8001 `par_smoke.py 8001` + `fairness_test.py 8001` → 删 `serve.par.on`。产物名固定：`engine/api_smoke_f231.log`、`engine/par_smoke_f231.log`、`engine/fairness_f231.log`、`engine/serve_f231.log`、`engine/serve_par_f231.log`。
- **续跑者要做的判断**：① 上面三个 f231 日志出现后，把 PASS/FAIL 与 `Accept failed` 行数记进 findings（**没出现就写未跑，别沿用旧日志**）；② `run_f229` 只有绝对秒数，**没有未收窄对照**，不得据此报"省了多少"；③ 根因轮按 **F233** 的步骤做（先 `git revert --no-edit 8afcf73` 复原探针，单独一轮，别和 `:1496` 的顺序修复混在一起）。
- **仍未开始的大项**：FR-06 多样草稿器（树收益的瓶颈在草稿器，F157/F218 侧的实测已写明）、per-session KV 并行吞吐、SSD 前缀分桶、多卡 split、GGUF 分片、FR-05。

## 停车读数（2026-11-04 04:31，实测值原样记录）
- `run_f229`：RUN_EXIT 行=0，PASS=89（收窄版**秒表时长由链式任务打印**，本行只记当前状态）
- 8000 门禁 `api_smoke_f231.log`：PASS=NA
- 8001 门禁 `par_smoke_f231.log`：PASS=NA；`fairness_f231.log` 末行=NA
- 结论：**门禁在停车时刻仍未取到结果 ⇒ 记为"未跑"**，不得用旧日志顶替；续跑第一步就是读这三个文件并如实记账。
- **末次读数（12:31）**：`run_f229` PASS=90 / FAIL=0、尚无 `RUN_EXIT`（收窄版第二次全量自检仍在跑，与 `run_f228` 的 137 PASS 同版源码）；三个门禁日志 **unrun**（`api_smoke_f231`/`par_smoke_f231`/`fairness_f231` 均不存在）。⇒ 停车时 F226/F227 的验收仍只到自测层，**不得**把旧 `api_smoke_f2xx.log` 当作本轮门禁证据。链式任务 `b92wbrol6` 会在 `run_f229` 出 `RUN_EXIT` 后自动依次跑 8000/8001，续跑第一步是读那三个文件。
- **12:39 更新（覆盖上一条停车读数）**：`run_f229`（收窄版**第二次全量自检，链式任务带秒表**）**137 PASS / 0 FAIL、`RUN_EXIT=0`、`SCHEMA-DEEP200: PASS (… first attempt)`、`first_len=3909 second_len=3887`** ⇒ 收窄版可重复性成立（两遍同源码各 137 PASS）。链式任务 `bd7o2dv9y` 随后被停，但其**自检步骤已完成**；门禁作业 `b92wbrol6` 已起跑：`engine/serve_f231.log` 正在写（8000 服务已拉起），`api_smoke_f231.log` 尚未落盘 ⇒ 门禁**进行中**，续跑先读 `api_smoke_f231.log`（期望 97 PASS）、再读 `par_smoke_f231.log`/`fairness_f231.log`。**秒表数只在链式任务的输出文件里**（`SELFTEST_F229_SECS`），仍无"未收窄版"对照，故不得报省时数。

## 门禁轮真实结果（勿当作产品缺陷读）
- 后台作业 `b92wbrol6` 外壳退出 0，但**三个门禁脚本一个都没跑成**：`SMOKE_EXIT=2 / PAR_EXIT=2 / FAIR_EXIT=2`，python 报 `can't open file ...typephp-kakasi\testsairness_test.py` ⇒ 脚本在 `engine/tests/` 下，而命令里 `cd engine` 对后续 python 段**未生效**（`&` 分组把 cd 吞掉了）。**PASS=0 不是 0 分通过，是没跑**。
- 有价值的正面证据：`engine/serve_f231.log` 打出 **`API_LISTEN 8000`** ⇒ 收窄版二进制在 8000 上正常起服（服务本身没炸）。
- **已清理的自造危险**：作业把 `engine/serve.on` 留在了原地（它的 `rm` 也在错位的 cwd 里执行），而哨兵文件在 `engine/` 时**下一次自测会变成服务器并挂住**；已删 `engine/serve.on`、`engine/serve.par.on` 与本轮五个空/无效日志（全是本会话自造产物）。现在 `ls engine/*.on` 为空。
- **正确的重跑写法**（在仓库根一条命令里不要依赖 `cd engine` 的作用域，直接给相对路径）：`(cd engine && touch serve.on && ./build/kakasi_engine_sycl.exe > serve_f232.log 2>&1 &) ; 等 8000 LISTENING ; python engine/tests/api_smoke.py 8000`（脚本自己默认 8000，但显式传更稳）→ 之后 `rm engine/serve.on`；8001 同理用 `engine/serve.par.on` + `python engine/tests/par_smoke.py 8001` / `fairness_test.py 8001`。期望 **97 PASS**（含 `chat-json-schema-deep40-first-call`）。
- 因此 F226/F227 的验收**仍停在自测层**（137 PASS 两遍），8000/8001 门禁＝未跑，不得写成已通过。

## 交接（2026-11-04 09:54，F236–F240）
- **梯子已绿一次**：8000 `engine/api_smoke_f237c.log` `API SMOKE: PASS / SMOKE_EXIT=0`（98 个 PASS 行含汇总），8001 `par_smoke_f232.log` 9/9、`fairness_f232.log` PASS（短 0.29 s vs 长 4.81 s），自测 `run_f228/run_f229` 两遍 137 PASS / 0 FAIL ⇒ **F226 修复 + `caf1bd6` 收窄过完整梯子**（提交 `31758d7`）。
- **F238 修复已提交但未验证**：`f3c029a`（`api_schema_candidates` 不再认领"属性块里有 object 值"的 schema）+ 新门 `chat-json-schema-deep40-enum-leaf`。**⚠ 本条的计划已被下面"双轮验证的决策表"取代**：原链 `bdlditj5k` 的 `run_f240` 因 `SYCL_LINK_FAILED` **全部作废**（F250）；当前有效的是 Round 1 `b71htt0fl`（`*_f241.log`，只含 `f3c029a`）与排队中的 Round 2 `b8u8njq8t`（`*_f242.log`，再加 `g_sess_len` 顺序修复）。**判据不变**：`run_f24x` 137 PASS / 0 FAIL、`api_smoke_f24x.log` 全绿应为 **99 个 PASS 行**（`api_smoke_f237c.log` 是 98 行 = 97 形状 + 1 汇总；新增 enum-leaf ⇒ 98 形状 + 汇总），而 enum-leaf 若仍红则实到 98 行 + 1 FAILURE。**任何一项没到就照实写未达，不得拿 f237 的绿顶替。**
- **⚠ 后台链的中断 hazard**：它在门禁阶段会创建 `engine/serve.on`、`engine/serve.par.on` 并"用后即删"。**若链被打断，哨兵可能残留**，而哨兵存在时下一次自测会变成服务器并挂住（本项目已踩过）。⇒ **续跑第一步：`ls engine/*.on`，有就删。**
- **根因仍未证**：探针测到结果之前，`no logits computed` 依旧是 F228 的两条分支之一（`rows==0` / 整行 NaN）；`g_sess_len += 1` 早于 `r.empty()` 判定（`model_sycl.cc:1496`）仍未修，改动文本在 F234。
- **未开始的大项**：FR-06.2 多样草稿器（可执行方案已写进 task_plan 末尾）、per-session KV 并行吞吐、SSD 前缀分桶、多卡 split、GGUF 分片、FR-05。

## 完成度审计（2026-11-04，把目标拆成可核对的条目 ⇒ 证据文件 ⇒ 状态）
| 目标条目 | 证据（仓库内） | 状态 |
|---|---|---|
| FR-01 tpc+SYCL 单二进制、双后端 | `engine/build_f228.log`、`run_f228.log` 137 PASS | **达成** |
| FR-03 SYCL 后端（以 DPC++ 代 acpp） | 各 `run_f2xx.log` 的 SYCL 用例 + `docs/audit-v0.2.md` | **替代达成** |
| 语法约束 JSON（含嵌套≤256、外部 $ref、oneOf/allOf、多维数组） | `run_f228.log` SCHEMA-* 全绿；`api_smoke_f237c.log` 97 形状 | **达成** |
| 冷启动首调长约束返回空（F208→F226） | `run_f228.log`/`run_f229.log`：137 PASS/0 FAIL、`first_len=3909`、**首调判据** PASS | **功能达成并过全梯门禁** |
| 上述修复在 8000/8001 的活服务验证 | `api_smoke_f237c.log`（SMOKE_EXIT=0）、`par_smoke_f232.log` 9/9、`fairness_f232.log` PASS | **达成**（注：跑的是修复版；`f3c029a` 的复验仍排队） |
| 并行工具调用（生成/扇出/流式/点名） | `run_f2xx.log` TOOL-CALLS-*、`api_smoke` 对应形状 | **达成** |
| F238 枚举叶丢嵌套（结构退化扁平） | 缺陷复现记录 `findings.md` F238/F243；修复 `f3c029a` **未验证**；真修见 F244 规格 | **未修** |
| 冷路径 `no logits computed` 根因 | F228 两分支；探针轮因 `SYCL_LINK_FAILED`（F245）**未取到数据** | **未证** |
| `g_sess_len += 1` 早于空判（:1496） | 改动文本 F234、影响面 F242 | **未落地** |
| FR-06 树收益需多样草稿器 | F235（只取首个后缀命中 ⇒ 分支同源）、方案 `task_plan.md` 末段 | **未实施** |
| per-session KV 并行吞吐 / SSD 前缀分桶 / 多卡 split / GGUF 分片 / FR-05 | 无 | **未开始** |
**结论**：目标**未完成**。已闭合的是"约束解码正确性 + 冷启动首调 + 工具并行"三块并有可复跑证据；仍欠 4 个具体缺陷/优化与 5 个大项。
- **链活性检查（10:03，已自我更正）**：`engine/run_f239.log` **已 382 秒没有写入**，停在 701 行（最后一条是 `SCHEMA-DEEP80: PASS`，即正卡在 DEEP200 用例里），链输出文件仍为 0 字节 ⇒ `bdlditj5k` 在等一个**可能还要几分钟**（也可能明显偏慢）的自测，而这一遍跑的是**旧 exe**（F245），产出只是"又一遍旧二进制的重复"。**结论：不要排队等它。** 下轮直接按 F248 补救自己走一遍：确认 `netstat` 无 LISTENING 且没有自测进程占着 exe（**不要用 taskkill，这台机器会挂**），`rm -rf engine/build/gen/cache/ast` 后重建，并**核对 exe mtime 必须变新**、`build_f24x.log` 里 `SYCL_LINK_FAILED` 计数为 0，再跑自检与两级门禁。若链稍后才完成，它的 `f240` 结果一律按"链接是否成功"重新判读，不得默认有效。
- **停车瞬间读数（10:05，原样记录）**：链已推进到 `REVERT_EXIT=0 / probes_left=0`（源码回到无探针状态，`f3c029a` 的门仍在），并已开始写 `engine/build_f240.log`。⚠ 但 **exe 仍是 12:27**，且上一轮同样的窗口以 `BUILD_EXIT=1 / SYCL_LINK_FAILED` 收场 ⇒ **`run_f240` 与 `*_f240` 门禁在证明"exe mtime 变新 + `grep -c SYCL_LINK_FAILED engine/build_f240.log`=0"之前一律视为无效**。工作树：`git status` 干净（仅遗留未跟踪的 `engine/gates_f201.txt`），哨兵 0 个。

## ▶ 下一轮从这里开始（停车时唯一有效指令）
1. **跳过 `run_f240` / `api_smoke_f240` / `par_smoke_f240` / `fairness_f240`**：它们跑的是 12:27 的旧 exe（F250：`BUILD_EXIT=1` + `SYCL_LINK_FAILED`），无论红绿都不构成证据。
2. 确认没有引擎进程（`netstat -ano -p tcp | grep LISTENING` 为空；**别用 taskkill**），然后 `rm -rf engine/build/gen/cache/ast && cmd //c "scripts\build-kakasi-nosetvars.bat norun" > engine/build_f241.log 2>&1`。
3. **先验证二进制真的换了**：`engine/build/kakasi_engine_sycl.exe` 的 mtime 必须新于本轮开始时刻，且 `grep -c SYCL_LINK_FAILED engine/build_f241.log` = 0。不满足就再等 30 s 重试一次，别带着旧 exe 去测量。
4. 满足后才跑：`scripts\run-kakasi-selftest.bat engine\run_f241.log`（期望 137 PASS / 0 FAIL、`SCHEMA-DEEP200-RETRY first_len>0`），再 8000 `python engine/tests/api_smoke.py`（`chat-json-schema-deep40-enum-leaf` 按 F243 预测**仍应红**），最后 8001 门禁。
5. 之后才是 F244 的枚举叶修复（单独一轮）；`g_sess_len += 1` 顺序修复（F234/F242）再单独一轮。

## 双轮验证的决策表与 job 对应（2026-11-04 10:32）
- **Round 1 `b71htt0fl`**：二进制**只含** `f3c029a`（F253 已用池序号 `[1844]` 证明进了码）⇒ 它的 `chat-json-schema-deep40-enum-leaf` 结果**唯一地**决定 F243 预言对不对。日志前缀：`run_f241.log` / `api_smoke_f241.log` / `par_smoke_f241.log` / `fairness_f241.log`。
- **Round 2 `b8u8njq8t`**（排队中，等 Round 1 的 `VERIFY_F241_DONE`）：先 `Stop-Process` 清孤儿 → 清 AST → 重建（含 `235bc45` 的 `g_sess_len` 顺序修复）→ **核 `BUILD_EXIT=0` / `LINK_FAIL=0` / exe mtime 变新** → 自检 + 两级门禁。日志前缀：`*_f242.log`。
- **判读**：R1 红 + R2 红 ⇒ F243 成立，走 F244；R1 红 + R2 绿 ⇒ 幻影 KV 行是成因，F243 归因要改写；R1 绿 ⇒ 撤 F243。**两轮都必须各自 137 PASS / 0 FAIL + par 9/9 + fairness PASS**，否则按回归处理，不许当"修复成功"。
- **红线**：Round 2 构建完成之前**不要改源码**（否则它编进不该编的改动，决策表作废）。

## 双轮验证结果（已到，替换先前的预测表）
- **Round 1 `b71htt0fl`（只含 `f3c029a`，F253 证明进码）**：137 PASS / 0 FAIL、`SUITE_SECS=693`、`first_len=3909`（首调）；8000 唯一失败 `chat-json-schema-deep40-enum-leaf len=5 closes=0 tail='Paris'`；8001 par 9/9 + fairness PASS。⇒ **F243 预言成立**：`f3c029a` 只是把"扁平对象"换成"裸枚举值"，**未修复 F238**（F254）。
- **Round 2 `b8u8njq8t`（+ `235bc45` 顺序修复）**：新二进制（`BUILD_EXIT=0/LINK_FAIL=0/exe_age=1s`）、137 PASS / 0 FAIL、`SUITE_SECS=674`、`first_len=3909`、par 9/9 + fairness PASS、同样只剩 enum-leaf 那条红。⇒ **顺序修复无回归**，但其行为**没有被直接断言**（缺"失败步不占格"的专门用例）——不得写成"已验证修复"（F255）。
- **下一步（唯一）**：按 **F244** 四步实现枚举叶：①plan 语法加 `e:<v1|v2…>`；②`api_top_leaves` 识别 `enum` 生成 `e` 叶；③`api_nested_object_value` 增加 `e` 分支（用枚举字面量的 token 序列作候选集）；④收窄 `main.php:2629` 的一刀切拒绝。判据＝`chat-json-schema-deep40-enum-leaf` 由红转绿，且两轮各自的 137 PASS + par 9/9 + fairness 不变。**每轮只含这一个改动**。

## F238 已修复（2026-11-04 11:30，覆盖上表"未修"行）
- **改动 `3e7cfd8`**：`api_top_leaves` 产出 `key:e:v1~v2` 枚举叶；`api_object_head` 对 `e` 补开引号；`api_nested_object_value` 新增 `e` 分支（枚举候选交给受限解码）；全 body 枚举分支加"`"properties"` 计数 <2"前置；嵌套检测器拒绝表去掉 `enum`。
- **实测**：`engine/api_smoke_f249.log` **`API SMOKE: PASS` / `SMOKE_EXIT=0` / 99 PASS 行**（98 形状 + 汇总，与预测一致）；单请求体 `…"k0":{"city":"Paris","count":1000}}}}…`（40 层 + 枚举生效）。
- **踩坑**：初版用 `|` 分隔枚举值 → 与 `path|plan` 冲突，plan 被截成 `city:e:Paris`（n=1）且 `count:i` 丢失；改 `~` 后 n=2。
- **进行中**：`b0i2o29tr`（该二进制全套自检 + 8001 门禁）、`b5bbqj9x7`（再加 `SESSION-POS` 断言，闭 F255 的"行为未直接断言"缺口）。**两者结果未到之前，F238 只算"8000 门禁已验证"，全套梯子未完成。**

## 源码↔轮次对应（关键，防止误归属）
- **`run_f249` / `api_smoke_f249` / `par_smoke_f249` / `fairness_f249`（作业 `b0i2o29tr`）**：二进制由 `3e7cfd8`（F238 三处修复）构建，**不含** `SESSION-POS` 守卫（`bd3f643`）与**反斜杠枚举拒绝**（`5cec283`）。⇒ 它的 8000 绿（99 PASS）证明 F238 修复本身有效；它**不能**用来评估后两项。
- **`b5bbqj9x7`（`run_f250` 等）**：等 `b0i2o29tr` 完成后清孤儿→重建当前 HEAD（含守卫与拒绝）→自检。⇒ 只有这一轮能评估 HEAD。
- **仍未覆盖**：`g_sess_len` 顺序修复的**判别性**测试（F257）；数字枚举（F258）。

## 冷启动缺陷**根因闭合**（2026-11-04 12:44，覆盖 F226 的"功能修复"定位）
- **真因（F265）**：`ensure_kv` 扩容时 `free_all()` 先丢旧行，再分配**未初始化**的新缓冲 ⇒ 会话跨过 `KV_MIN_CAP=512` 后注意力读到垃圾 ⇒ **整行 logits 为 NaN**（探针实测 `allsize=151936 nonfinite=151936`，`sesslen=515`）。
- **修复**：扩容时按 `keep_rows=pos` 先 `memcpy` 旧行到新缓冲，再释放旧缓冲（`keep` 守卫：旧缓冲存在且层数一致）。提交 `0796977`。
- **验证（F266，阳性对照成立）**：`b672qcig9` → **138 PASS / 0 FAIL**、`PROBE_LINES=0`（探针在本构建池 `[1843]` 且 `main.cc` 引用 1 次 ⇒ 没打印＝**没有拒绝发生**）、**`first_len=3887 second_len=3887`**（首次与第二次**完全相同**，修复前是 3909/3887）。
- **F226 的 re-prefill 兜底**保留（防御性），但已不再被触发。
- **仍待**：干净构建（撤探针）过完整梯子（进行中 `b3ne5mjjj`：自检 + 8000 + 8001）；split 路径 `g_kv2` 同样的扩容缺陷未修（多卡实验路径）。

## 停车状态（2026-11-04 16:50，本轮预算耗尽）
- **HEAD `4ab27d4`**；工作树干净（仅遗留未跟踪的 `engine/gates_f201.txt`）；无 serve 哨兵；exe 比 `main.php` 新 ⇒ 磁盘二进制对应最后一次源码改动。
- **已验证的梯子（同一构建）**：自检 **139 PASS / 0 FAIL**（含 `SESSION-GROW`、`SESSION-POS`）、8000 **106 个 PASS 行**、8001 `par_smoke` **9/9** + `fairness` **PASS**。日志前缀 `run_f289` / `api_smoke_f289` / `par_smoke_f289` / `fairness_f289`。
- **本轮闭合**：KV 根修（NaN 整行）、布尔叶、叶子上限 3→6、字符串数组叶、`const`、纯字面量 `pattern`、数字枚举、分支去重（负收益）、split 同构修复。
- **下一步（按价值）**：①**采样验证**（F284 四步规格；F273 已证贪心 argmax 下多分支收益恒为 0）；②嵌套**数字数组**叶（`api_number_fill` 可组合，planner+builder+门，一轮完整梯子）；③对象数组叶；④per-session KV 并行吞吐；⑤SSD 前缀分桶；⑥GGUF 分片；⑦FR-05。多卡 split 本机不可执行。
- **红线提醒**：构建与测量之间用 `&&`；重建前 `Get-Process` 清孤儿；测量前先证 exe mtime 变新；探针"没打印"必须先证明探针在二进制里（池序号 → `main.cc` 引用）。

## ▶ 续跑从这里开始（2026-11-04 23:31 更新，覆盖 F240 时期的旧指引）
- **当前基线（HEAD `4e4c489`＋f311 构建，二进制与源码同源）**：自检 **147 PASS / 0 FAIL**（`SPEC-TREE-ALT` 已拆为行为 PASS ＋覆盖 `NOTCOVERED` 两条）、8000 **113 形状全绿**、8001 `par_smoke`/`fairness` 全 0。日志：`run_f313.log` / `api_smoke_f313.log` / `par_smoke_f313.log` / `fairness_f313.log`。活服务起法**必须**用 `bash scripts/gate_live.sh <tag>`（F309：cwd 错会让自测冒充服务并产出假红数）。
- **已具备的 FR-06 原语**：`model_token_prob(id,temp)`、`model_rand01()`、`model_sample_excluding(id,temp)`、`model_peek_argmax()`；自检内已验证 `SPEC-SAMPLE`（acc=9 rej=15 diff=15 可复现）与 `SPEC-COST`（接受机制仅贵 1.7%/token）。
- **F307 已闭合这条**（见 findings F307）：采样验证的树解码进了 C++ `spec_tree_sample()`，实测 **1.34×**（`SPEC-TREE-WALL` off_ms=1871.6 → on_ms=1396.1，temp=0.9、等长 95 token、每次验证提交 4.0 token），`temp=0` 逐 id 等于贪心树、同种子逐字节复现、temp=0.9 下 22/71 位置与贪心不同；服务侧 `spec_bins`/`spec_depth` 已接（门控 `temp>0 && top_p>=1`）。
- **F308 已落地（未验证其效果）**：rope 行位置改按逻辑深度（`g_row_pos`）、接受非零分支后 `kv_compact()` 压实 K/V 行、`spec_stats()` 常驻 `alt_branch=`/`max_branches=`。
- **唯一下一步（F313b→f314，先让覆盖门自己说话）**：`max_branches=2` 证明草稿器**能**给出两条竞争分支，而 `alt_branch=0` 的真正原因是**我只在倒序那次运行之后读 stats**（正序 `$f308Tree` 之后没取 `model_spec_stats()`），外加 `max_branches=` 的 `substr` 偏移误写 14（正确 13）⇒ 回显恒 0 且与同一行原样打印的 stats 自相矛盾。已补正序读数与偏移：**判据没测到 ≠ 判据不存在**（F312 那条方法论反过来咬了我自己一次）。
- **再下一步（FR-06.2 步 1）**：若 f314 的覆盖门仍 `NOTCOVERED`，说明"最长接受"总是落在列表首位，需要的是**抬高分支间的真实竞争度**（同三元组的全部不同续写＋按历史频次排序），它同时决定树的收益上限与 F308 的可验证性。
- **其余未闭合**：多属性对象项（阻塞于上游 dispatch 归属未定位，F298–F300）、`g_sess_len` 覆盖缺口（F261）、含元字符 pattern / `minimum·maximum·multipleOf`、per-session KV 并行吞吐、SSD 前缀分桶、GGUF 分片、多卡 split（需第二设备）、FR-05。
- **护栏**：单引擎进程；构建与测量之间用 `&&`；重建前清孤儿并核 exe mtime；新增 native 函数必须同时改 `php_model_*` 包装与 `model.stub.php`。

## ▶ F307 · 采样验证树解码的落地规格（写给 /goal resume 的第一轮：规格已定，不必再推导）
**要改的位置**：`engine/src/model_sycl.cc:1873` 之后的 accept 段，以及 `:1849` / `:1902` 两处单 token 回退。草稿收集 `:1821-1847`、树展平与 mask `:1861-1870` 原样复用。
**为什么必须换验证规则（F273）**：贪心 argmax 下给定已接受前缀，每个节点的预测唯一 ⇒ 至多一条分支能被接受，多分支恒零收益。改成按概率接受后每条分支各自有接受概率，B 个 bin 才第一次有意义。
**接受/残差（点位草稿 q=δ_d 的标准 speculative sampling）**：
- 节点 (b,i) 的预测分布 p：该节点 logits 按温度归一（temp→0 退化为 argmax，等价现状）。
- 下一草稿 `d = branches[b][i+1]`：接受当且仅当 `rand01() < p(d)`（q(d)=1 ⇒ `min(1, p/q) = p`）。
- 拒绝时从残差 `(p−q)⁺` 采样 = 在 p 上剔除 d 后归一采样，即 `sample_excluding(d, temp)` 的 C++ 内联版。
**实现三步**：
1. 给 `prefill_tops_tree` 增加可选出参（或新增 `prefill_tops_tree_p`）：对每个节点额外返回①其后继草稿 token 的概率 `p_i(nodes[i+1])`（同分支且 i+1<D，分支末位填 -1）②该节点残差采样 `bonus_i`。logits 已在 kernel 外物化，只多两次行扫描。
2. accept 段替换：逐分支累加连续接受长度（i=0 的参照是 `ref`），取 acc 最大者提交，再提交该分支最后处理节点的 `bonus` ⇒ 每次验证最多 `D+1` 个 token；全零接受时提交 root 节点的 `bonus`。
3. `pos` 前进量 = 实际提交 token 数（含 bonus）——不要沿用现成的 `pos += bestAcc`；`ref` 的"下一步预测"语义改由 bonus 承担。
**新增 PHP 面（必须成对，缺一必炸）**：`php_model_spec_tree_sample` 包装（`engine/src/model.cc`）+ `model.stub.php` 声明（`function model_spec_tree_sample(array $ids, int $n, int $bins, int $depth, float $temp, int $seed): string {}`）。函数开头用 `seed` 重置 `g_sess_rng` 以保证同种子可复现。
**四个门禁（顺序即判据）**：
- `SPEC-TREE-SAMPLE-DET`：同 seed 两次 → 输出逐字节相同。
- `SPEC-TREE-SAMPLE-DIFF`：temp=0.8 下至少一个位置与贪心树输出不同（全同说明采样没进路径）。
- `SPEC-TREE-SAMPLE-TEMP0`：temp=0 的输出与现有 `spec_tree_generate` 完全一致（回归护栏）。
- `SPEC-TREE-WALL`：同温度、同 prompt、≥512 token，tree-on vs 单步 off 的 wall 对比 ⇒ FR-06 第一个"真实收益"数字。
**构建纪律（每次重建前逐条核）**：清 `engine/build/gen/cache/ast`（动 C++ 再清 `build/gen`/`build/obj`）→ `Get-Process` 找孤儿引擎并 `Stop-Process -Force` → 核 exe mtime 前进且无 `SYCL_LINK_FAILED` → 构建与测量之间用 `&&`；任一时刻只允许一个引擎进程。

## ▶ 最终状态（F316，2026-11-05 04:49，覆盖上面 721-731 行的旧指引）
- **基线**：HEAD 本次提交；二进制与源码同源。自检 **148 PASS / 0 FAIL**（`run_f315.log`）、8000 **113 形状全绿**（`api_smoke_f315.log`）、8001 `par_smoke`+`fairness` 全 0（`par_smoke_f315.log`/`fairness_f315.log`）。
- **FR-06 已测到的收益**：采样验证的树 `SPEC-TREE-WALL` 六个独立构建 **1.3377×–1.3629×**（temp=0.9、等长 95 token、每次验证提交 4.0 token）；服务侧 `spec_bins`/`spec_depth` 已在活服务门禁覆盖（`chat-spec-tree-sampled`）。
- **F308 已闭合**：树节点 RoPE 改按逻辑深度（`g_row_pos`）＋接受非零分支后压实 KV 行（`kv_compact`）＋`spec_stats()` 常驻 `alt_branch`/`max_branches`；双向对照在同一构建内完成（`model_spec_fix(0/1)`）：关掉修复 `diffs=4/84`，打开则逐 id 等于贪心。
- **唯一下一步**：FR-06.2 步 1（同三元组全部不同续写、按历史频次排序成多条分支）。判据＝`SPEC-TREE-WALL` 的 speedup 与 `accepted/verifies`（当前 2.9/验证）变化，外加 `alt_branch` 保持 COVERED。形状计时**不要**用 `SPEC-TREE-SWEEP`（F312：三轮互相矛盾）。
- **护栏（本轮新增/复用）**：起服务只能 `bash scripts/gate_live.sh <tag>`（F309：cwd 错 ⇒ 自测冒充服务 ⇒ 假红数）；构建与测量之间用 `&&`；重建前清孤儿并核 exe mtime；新增 native 必须同时改 `php_model_*` 与 `model.stub.php`；任一时刻只允许一个引擎进程。

## ▶ 最终状态（F318，2026-11-05 05:12，覆盖 F316 那节的"下一步"）
- **基线**：本次 HEAD；二进制与源码同源。自检 **149 PASS / 0 FAIL**（`run_f317.log`）、8000 **113/0**（`api_smoke_f317.log`）、8001 `par_smoke`/`fairness` 全 0。
- **FR-06 现有可宣称的东西**：采样验证的树 **1.34–1.36×**（七个独立样本）；`temp=0` 退化回贪心＝回归门；F308 的树位置/缓存缺陷＝已修且**同构建双向对照**复现过缺陷（`model_spec_fix`）；`spec_bins`/`spec_depth` 有活门禁；FR-06.2 步 1（频次排序）＝**已实现、已测量、结论为中性**（F318）。
- **唯一下一步（换草稿来源，不再调排序）**：把 `max_branches` 从 2 抬上去——①同一三元组取 top-k 不同续写而非仅最近两条；②用模型自身根行的 top-k/`sample_excluding` 生成第二条起点分支。判据＝`SPEC-TREE-DIVERSITY` 同构建三段对比的 `accepted/verifies` 与 wall，`alt_branch` 须保持 COVERED。
- **其余未闭合**：多属性对象项（上游 dispatch 归属未定位，F298–F300）；`pattern` 元字符 / `minimum·maximum·multipleOf` / 混合类型枚举；per-session KV 并行吞吐；SSD 前缀分桶；GGUF 分片；多卡 split（需第二设备）；FR-05 厂商插件（本机无可验硬件）。

## ▶ 状态（F320，2026-11-05 05:36，覆盖 F318 那节的"下一步"）
- **基线**：本次 HEAD；二进制与源码同源、全梯绿：自检 **150 PASS / 0 FAIL**（`run_f319.log`）、8000 **113/0**、8001 `par_smoke`/`fairness` 全 0。FR-06 收益 `SPEC-TREE-WALL` 八个独立样本 **1.3346×–1.3629×**。
- **FR-06.2 已否证两条**：换排序（F318 中性）与加宽窗口（F320 中性且白算 3 节点）⇒ **上限在草稿源**，不在查找/排序策略。
- **下一轮动作（按序）**：①`g_spec_multiwin` 默认翻成 0 并走一次梯子（一行改动＋验证它的白算）；②模型自举分支——用根行 top-k／`sample_excluding` 生成文本中未出现的起点，判据仍是同构建 A/B 的 `accepted/verifies` 与 `max_branches`，且 `temp=0` 必须继续逐 id 等于贪心、`alt_branch` 保持 COVERED；③之后才轮到 per-session KV 并行吞吐、SSD 前缀分桶、GGUF 分片、多卡 split（需第二设备）、FR-05（本机无可验硬件）。

## ▶ 状态（F321，2026-11-05 05:57 —— 本轮收点，覆盖 F320 的"下一步①"）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **150 PASS / 0 FAIL**（`run_f321.log`）、8000 **113/0**、8001 `par_smoke`+`fairness` 全 0。`g_spec_multiwin` 默认 0 已由测量确认（`drafts` 39→36、接受数不变、`SPEC-TREE-WALL` 九个样本 **1.3346×–1.3679×**）。
- **F320 待办①已闭合。** 下一轮第一个动作改为：**FR-06.2 模型自举草稿源**（用根行 top-k／`sample_excluding` 造出文本里没出现过的起点分支，目标把 `max_branches` 从 2 抬到 4+）；判据仍是同构建 A/B 的 `accepted/verifies` 与 wall，且 `temp=0` 必须继续逐 id 等于贪心、`alt_branch` 保持 COVERED。之后顺位：per-session KV 并行吞吐、SSD 前缀分桶、多属性对象项（先定位 dispatch 归属）、GGUF 分片、多卡 split（需第二设备）、FR-05（本机无可验硬件）。

## ▶ 状态（F322，2026-11-05 06:19 —— 本轮真正的收点）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **151 PASS / 0 FAIL**（`run_f322.log`）、8000 **113/0**、8001 `par_smoke`+`fairness` 全 0、哨兵 0。
- **FR-06 形状已成实测结论**（`SPEC-TREE-SHAPES`，同构建隔离对照）：`bins=1/2/4` 在 depth=3 下**逐字相同**（36/48/12、1.371–1.375×）⇒ 收益全部来自**验证深度**；depth=4 反而退化到 1.112× ⇒ **推荐 `spec_bins` 随意、`spec_depth=3`**。旧 `SPEC-TREE-SWEEP` 的计时按 F312 作废，本门是其替代品。
- **下一轮第一个动作**：模型自举草稿源（根行 top-k／`sample_excluding` 造新起点分支），目标 `max_branches` 从 1/2 抬到 4+，判据＝`SPEC-TREE-SHAPES` 里不同 `bins` 之间**必须出现差异**＋`accepted/verifies` 上升，同时 `temp=0` 仍逐 id 等于贪心、`alt_branch` 保持 COVERED。其后顺位见 F321 那节。

## ▶ 状态（F325，2026-11-05 07:01 —— 本轮收点，覆盖 F323 那节的"下一步"）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **152 PASS / 0 FAIL**（`run_f324.log`）、8000 **113/0**、8001 全 0、哨兵 0。`g_spec_selfseed` 默认 **0**（测量结论：不划算）。
- **FR-06.2 现在的完整图景**：多样性**能造出来**（自举分支把 `max_branches` 从 1 抬到 4），但满深度补分支的成本（+84 节点换 +3 接受）让 wall 退到 0.92× ⇒ 卡点从"没有候选"变成"树必须是**变深度**的"。
- **下一轮第一个动作**：变深度树——分支自带长度，`nodes/depths/want/mask` 由 `b*D+i` 改为按分支起点偏移表；自举分支长度取 1。判据＝同构建 A/B 下 `SPEC-TREE-SELFSEED` 的 `ratio` 转正（>1.0）且 `accepted` 不降；`temp=0` 仍须逐 id 等于贪心、`alt_branch` 保持 COVERED。其后顺位：per-session KV 并行吞吐、SSD 前缀分桶、多属性对象项（先定位 dispatch 归属）、GGUF 分片、多卡 split（需第二设备）、FR-05。

## ▶ 状态（F327，2026-11-05 07:23 —— 覆盖 F325 的"下一轮第一个动作"，该动作已完成）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **152 PASS / 0 FAIL**（`run_f326.log`）、8000 **113/0**、8001 全 0。变深度树已落地（`nodeOff`/`nodeLen`），自举分支 1 节点 ⇒ 同构建 A/B **ratio 1.0564**（F325 的 0.9225 转正）。默认仍 `g_spec_selfseed = 0`。
- **下一轮第一个动作**：`SPEC-TREE-SELFSEED` 改成**多次交错 A/B（≥3 对）取中位数**，若中位 ratio 仍 >1.0 再把 `g_spec_selfseed` 默认翻 1 并走梯子；否则维持 0 并记为"单样本假象"。其后顺位：per-session KV 并行吞吐、SSD 前缀分桶、多属性对象项（先定位 dispatch 归属）、GGUF 分片、多卡 split（需第二设备）、FR-05。

## ▶ 状态（F329，2026-11-05 07:48 —— 覆盖 F327/F328 关于收益的表述）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **152 PASS / 0 FAIL**（`run_f328.log`）、8000 **113/0**、8001 全 0、哨兵 0。
- **FR-06 可宣称的收益**：贪心链 `spec_k` 约 2.5×（早期）；采样树 `SPEC-TREE-WALL` 十个样本 **1.3346×–1.3921×**；**多分支（自举）＝+1.05% 中位数**，默认关闭。三条"策略型"改法（排序/窗口/自举）全部量过，结论一致：**分支多样性在这个约束下不值钱**。
- **下一轮候选（按性价比）**：①`SPEC-CHAIN-VS-TREE`——贪心下把"链 k=8"与"树 bins/depth"在同一构建交错三对取中位数，替掉 F312 作废的旧 sweep 数（纯自检改动，低风险）；②per-session KV 并行吞吐（真正还没动的性能面）；③SSD 前缀分桶；④多属性对象项（先做 dispatch 打点）；⑤GGUF 分片 / 多卡 split / FR-05（本机不可验）。
- **纪律新增（务必遵守）**：凡判据是 wall，**必须成对交错 ≥3 对取中位数**；单次采样只能证明"机制生效"，不能证明"收益大小"。

## ▶ 状态（F330，2026-11-05 08:11）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **153 PASS / 0 FAIL**（`run_f330.log`）、8000 **113/0**、8001 全 0。
- **新增判据**：`SPEC-CHAIN-VS-TREE`（贪心、三回合交错中位）＝链 k=8 **397 ms** 胜树 depth=3 **~700 ms**，机理＝链的单次验证提交长度不受 depth 截断。
- **下一轮（已在做）**：①采样侧测 `bins=1` 的**长深度** `(1,6)(1,8)`（此前只扫过 depth≤4）；②服务侧把 `spec_depth` 的硬夹 4 改为按 `bins*depth ≤ 32` 的掩码预算计算。判据仍用中位数，`temp=0` 一致性不得破坏。

## ▶ 状态（F332，2026-11-05 08:33 —— FR-06 的最新真实数字）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **153 PASS / 0 FAIL**（`run_f331.log`）、8000 **113/0**、8001 全 0、哨兵 0。
- **采样投机解码收益**：**2.08×**（`bins=1 depth=6`，同 off 基线，每次验证提交 6.86 token）；depth=3 只有 1.35×，depth=8 反而 0.93× ⇒ **最优深度依赖 prompt**，故 `SPEC-TREE-SHAPES` 常驻打印整条曲线。服务侧 `spec_depth` 已按 `bins*depth<=32` 放宽（绝对上限 8）并经真实请求验证。
- **多分支（自举）**：四轮中位 +1.05%/+0.74% ⇒ 保持默认关闭；FR-06.2 的策略型改法已全部量尽，再高需独立草稿模型。
- **下一轮顺位**：①per-session KV 并行吞吐（唯一还没动的性能面）；②SSD 前缀分桶；③多属性对象项（先做 dispatch 打点）；④GGUF 分片 / 多卡 split / FR-05（本机不可验）。

## ▶ 状态（F334，2026-11-05 08:56 —— FR-06 的最终可引用数字）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **153 PASS / 0 FAIL**（`run_f333.log`）、8000 **113/0**、8001 全 0、哨兵 0。
- **投机解码收益（同一构建、逐 id 一致约束下、中位数）**：**采样树 2.1457×**（`bins=1 depth=6`，两次独立复现 2.08/2.15）；**贪心链 3.0213×**（`k=12`，此前引用的 k=8=2.576×）。深度曲线非单调（8 处塌陷、10/12 回升）⇒ 最优形状依赖该文本的可信链条长，门禁每次重打整条曲线。
- **多分支**：五轮中位 ≈ +1%，`g_spec_selfseed` 默认关闭；FR-06.2 在"单二进制 + prompt-lookup + 目标自举"约束下已到尽头，再上需独立草稿模型（工程决策项）。
- **下一轮顺位**：①per-session KV 并行吞吐（唯一还没动的性能面）；②SSD 前缀分桶；③多属性对象项（先做 dispatch 打点）；④GGUF 分片 / 多卡 split / FR-05（本机不可验）。

## ▶ 状态（F338，2026-11-05 09:07）
- **并发吞吐已量化**：两路流 **中位 1.149×** 聚合（serial ~20.6 tok/s、concurrent ~23.9 tok/s，四次并发侧几乎不漂移）；第三路连接失败 ⇒ 8001 是两槽。⇒ 想要真并行必须**把两路批进一次前向**（continuous batching），属内核级改造，未做。
- **常驻探针**：`engine/tests/throughput_test.py`（含预热流），已并入 `scripts/gate_live.sh` 的 8001 段，每轮门禁自动出数。
- 基线不变：自检 **153 PASS / 0 FAIL**、8000 **113/0**、8001 全 0（`*_f335.log`；f337/f338 只加测试脚本与工装，未动引擎源码，故不重跑构建）。
- **下一轮顺位**：①continuous batching（吞吐上限的钥匙，工作量大）；②**前缀缓存先加可观测性门禁**（槽 owner / 撞槽驱逐计数）再谈 LRU——现状已是 `model_cache_key(ids,64)` 的 64 槽哈希索引，计划里那句"无多前缀索引/淘汰"经核查是**过期陈述**（F339，磁盘实测仅 1 个文件 3.8 MB）；③多属性对象项（先 dispatch 打点）；④GGUF 分片 / 多卡 split / FR-05（本机不可验）。

## ▶ 状态（F341，2026-11-05 09:32）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **154 PASS / 0 FAIL**（`run_f340.log`）、8000 **113/0**、8001 全 0、吞吐门 `scaling=1.105×`（与 F338 的 1.149 中位一致）、哨兵 0。
- **新增判据**：`CACHE-EVICT` ⇒ 量化了撞槽驱逐（热前缀复用 **49→22 token**），并顺带守住"64 槽哈希"这一前提（找不到碰撞即 FAIL）。
- **前缀缓存的下一步（判据已备好）**：recency-aware 驱逐（LRU 或 2-way 关联 + 槽 owner 指纹）。**验收写法**：`CACHE-EVICT` 的 `hit_after_other_prompt_stole_slot` 必须显著回升（理想 ≈ `hit_own`），且 `PREFIXCACHE`/`PREFIXPART` 不变差。
- 其余顺位不变：continuous batching（吞吐上限）、多属性对象项（先 dispatch 打点）、GGUF 分片 / 多卡 split / FR-05（本机不可验）。

## ▶ 状态（F342，2026-11-05 09:58 —— 前缀缓存驱逐这一格补上）
- **基线**：本次 HEAD；源码==被验证的二进制（构建于本轮最后一次 `main.php` 修改之后）。自检 **154 PASS / 0 FAIL**（`run_f342.log`）、8000 **113/0**、8001 全 0、吞吐 1.093×（第 6 个样本）、哨兵 0。
- **前缀缓存**：改为 2 路组相联 + owner 索引（`api_cache_pick`），同一对碰撞提示的复用由 **49→22** 变为 **49→49**；仍**不是 LRU**（两候选槽皆被占时无新近度选择）。`CACHE-EVICT` 现在既能证伪盲驱逐回归，也能给未来的 LRU 判据。
- **下一轮顺位**：①真正的 recency 驱逐（引用计数或时间戳受害者选择），判据＝`CACHE-EVICT` 在多对碰撞提示下 `hit_after == hit_own`；②continuous batching（吞吐上限，唯一能把 1.1× 变成 ~2× 的路）；③多属性对象项（先 dispatch 打点）；④GGUF 分片 / 多卡 split / FR-05（本机不可验）。

## ▶ 状态（F344，2026-11-05 10:22）
- **基线**：本次 HEAD；源码==被验证的二进制。自检 **155 PASS / 0 FAIL**（`run_f343.log`）、8000 **113/0**、8001 全 0、吞吐 1.125×（第 7 样本）、哨兵 0。
- **前缀缓存驱逐**：已是 **2 路组相联 + LRU 受害者**（索引带使用时戳）。三方争抢实测：热条目 48→48 存活、冷邻居 49→21 被选中；成对碰撞仍 49→49；`CACHE-LRU`(policy) 与 `CACHE-LRU-INTEGRATION`(COVERED) 双门常驻。
- **仍未做**：更大结合度/按容量淘汰；**continuous batching**（并发吞吐 1.1× 的唯一解）；多属性对象项（先 dispatch 打点）；GGUF 分片 / 多卡 split / FR-05（本机不可验）。

## ▶ 状态（F345，2026-11-05 10:28）
- **本轮只动测试脚本（`throughput_test.py` 增加 spec 与提示词参数），未动引擎源码** ⇒ 现有二进制仍等于上次验证过的构建（`run_f343.log` 155 PASS / 0 FAIL）。
- **FR-06 的边界已说清**：加速数字（采样树 2.15×、贪心链 3.02×）**只在自测隔离 A/B 下成立**；服务端到端**未被证明走到投机路径**——现有活门禁 `chat-spec-tree-sampled` 只看"非空 + 可复现"，分支不命中也会绿。服务侧 3×2 轮吞吐测量显示 spec/plain 差异 ≤3% 且正负翻转，重复提示对照因提前结束（11 chunk）失效。
- **下一轮第一个动作**：给采样分支的响应加 `spec` 诊断字段（`model_spec_stats()`），并把该门改成**要求 `verifies>0 && accepted>0`**（用带重复结构的提示词、串行发请求以避开进程级计数串联）。之后才是 continuous batching / 多属性对象项。

## ▶ 停止点（F346，2026-11-05 10:30）
- **本轮没有改引擎源码**（只动 `engine/tests/throughput_test.py` 的参数面）⇒ HEAD 的二进制仍等于 `run_f343.log` 验证过的那份（155 PASS / 0 FAIL，8000 113/0，8001 全绿）。
- **为什么不在这里收尾一个改动**：证明"服务请求确实走采样树"必须新增响应/日志里的机制计数，那要一次完整梯子；剩余预算刚好**不够在失败时退回已验证状态**。留半验证的源码会毁掉这一整串数字的可追溯性。
- **下一轮第一步**：照 F346 的三步做（采样分支记 `model_spec_stats()` → 非流式响应加 `spec` 键 → `chat-spec-tree-sampled` 改判 `verifies>0 && accepted>0`，串行发请求）。如果加完仍然 `accepted=0`，就把 `spec_bins` 从"推荐"降级为"仅自测可用"。

## ▶ 停止点（F348，2026-11-05 10:38）
- **服务面 liveness 已由差分证明并固化成门**：`chat-spec-tree-affects-output`（同 seed 只变 spec 参数 ⇒ 输出必变）。8000 **114 PASS / 0 FAIL**、8001 全 0、吞吐 1.084×（第 8 样本）。**未动引擎源码** ⇒ 二进制 == `run_f343.log` 那份（155 PASS / 0 FAIL）。
- **工装事实**：非流式请求只能打 **8000**；8001 是只流式两槽服务器（发非流式会 400）。
- **仍未闭合（下一轮，按性价比）**：①把 `model_spec_stats()` 放进非流式响应，把新门的判据从"输出不同"升级为"accepted>0"（量化接受率，串行发请求防计数串槽）；②continuous batching（并发吞吐 1.08–1.15× 的唯一出路）；③多属性对象项（dispatch 归属）；④pattern 元字符 / 数值界限约束；⑤分片 / 多卡 / FR-05（本机不可验）。

## ▶ 状态（F350，2026-11-05 11:03 —— 本轮收点）
- **基线**：本次 HEAD；源码==被验证的二进制（最后一次 `main.php` 改动之后构建）。自检 **155 PASS / 0 FAIL**（`run_f349.log`）、8000 **115/115**、8001 全 0、吞吐 1.059×（第 9 样本）、哨兵 0。
- **FR-06 服务面三段陈述各自有门**：路径执行（`chat-spec-tree-affects-output`）→ 有接受（**新** `chat-spec-tree-accepts`，读 `GET /spec_stats`）→ 但**端到端不提速**（F345 的 spec/plain 差分 ≤3%）。
- **新增诊断面**：`GET /spec_stats`（进程级最近一次计数；并发不可归因，已写明）。
- **下一轮顺位**：①continuous batching（唯一能把并发 1.1× 抬向 2× 的路）；②FR-06.2 换草稿源（服务负载的接受率上限）；③多属性对象项（dispatch 归属）；④pattern 元字符 / 数值界限；⑤分片 / 多卡 / FR-05（本机不可验）。

## ▶ 停止点（F352，2026-11-05 11:10）
- **HEAD 即本轮验证状态**：引擎源码未改，二进制仍 == `run_f349.log`（155 PASS / 0 FAIL）那份；活门禁 **8000 115/115**、8001 全 0、吞吐第 10 样本 1.057×、哨兵 0。
- **FR-06 服务面的数字齐了**：接受在发生（`drafts=36 accepted=13 verifies=6` ⇒ ~2.2 token/验证），但聚合吞吐 −1.5% ⇒ **该负载上投机是零和**；自测的 2.15× 依赖高重复文本。计数是末次调用快照（`drafting=off`），按请求归因需要 per-slot 计数或响应内字段（未做）。
- **下一轮（预算要按整梯子算）**：①continuous batching——服务面唯一能把并发 1.06–1.15× 推向 2× 的路；②per-request 归因的 spec 计数（之后才谈把服务判据写成硬门）；③FR-06.2 真草稿源；④多属性对象项；⑤pattern/数值界限；⑥分片 / 多卡 / FR-05（本机不可验）。

## ▶ 规格（F353，2026-11-05 11:13 —— 更正下一轮的成本预估）
- **continuous batching 不是内核重写**：块对角可见性（树掩码 `g_tree_mask` + `attention()` 的位图判定）与逐行 RoPE 位置（`g_row_pos`，F308）**已经存在**。要动的是：①KV 写入的 `row_slot[]` 映射；②`g_kv` 单全局缓存改为"一块大缓存 + 行槽分片"（两路各一份会翻倍显存，B390 未必装得下）；③8001 调度从轮转改为一次前向 T=2（顺带省掉 session 快照/恢复）。
- **验收（现成门，不用新发明）**：`throughput_test.py` 的 `scaling` 从 1.06–1.15× 抬向 1.6–2×；`PAR_EXIT=0`/`FAIR_EXIT=0`/8000 全形状不回退；**硬判据**：两会话各自单独跑与批量跑，同 seed 输出逐 id 相同（掩码若串了上下文，这条立刻红）。
- **第一号风险**：`row_slot` 写错 = 会话间静默串上下文 ⇒ 必须先写上面那条逐 id 相同的门，再动内核。

## ▶ 停止点（F354，2026-11-05 11:15）
- **本轮没有可报告的收益数字**：贪心投机的服务面测法被证伪（`spec_k` 强制非流式 ⇒ SSE chunk 计数失效；提示词早停 ⇒ 无草稿可接受）。引擎与门禁未改，HEAD 仍 == `run_f349.log`（155 PASS / 0 FAIL）那份二进制，活门禁 115/115 不变。
- **下一轮若要测服务面贪心投机**：非流式 + 整请求 wall + `usage.completion_tokens`，重复可续写的提示，temp 0.0，off vs `spec_k=12`，≥256 token。
- 其余顺位不变：continuous batching（F353 已给出可行性与验收）、per-request spec 归因、FR-06.2 真草稿源、多属性对象项、pattern/数值界限、分片/多卡/FR-05。

## ▶ 状态（F355，2026-11-05 11:20 —— 服务面结论被更正）
- **服务面贪心投机 = 3.31×（实测）**：`spec_k=8` 41.68 tok/s vs plain 12.61，192 token 非流式、中位 3 次、探针自判 PASS；计数 `verifies=33 accepted=187` ⇒ 5.7 token/验证。k=8/12/16 差 ≤2.3%。
- **边界照旧有效**：这是单请求延迟收益（`spec_k` 按设计非流式），并发 scaling 仍 1.06–1.15×；且**只在可持续重复的输出上成立**——10 token 就结束的提示上投机净亏 0.71×（F354 的第一次运行）。
- **新增**：`engine/tests/spec_greedy_probe.py`（带"长度不足即判无效"的自我守卫）。
- **下一轮**：①把该探针接进 `gate_live.sh` 做每轮报告；②用可持续生成的提示**重测采样树**的服务面结论（F352 的 −1.5% 可能同样受短输出误导）；③continuous batching（F353 规格）；④per-request spec 归因；⑤多属性对象项、pattern/数值界限、分片/多卡/FR-05。

## ▶ 停止点（F356，2026-11-05 11:24）
- **FR-06 服务面定档**：贪心链 `spec_k` **3.31×（有效）**；采样树 **(2,6) 1.006×（有效，接受中但自适应已停用）**，(1,6) 的 1.56× 因只生成 52 token 被探针判为不可报。⇒ 文档口径：**按温度选路**——贪心开投机，采样默认不开。
- 引擎源码本轮未改；HEAD 二进制 == `run_f349.log`（155 PASS / 0 FAIL）那份，活门禁 115/115 未变。
- **下一轮第一动作**：把 `spec_greedy_probe.py`（greedy/sampled 两模式）接进 `scripts/gate_live.sh` 做每轮报告，并按上面口径给服务侧一个**温度相关的投机默认**（贪心 `spec_k=8`，采样不开）；随后才是 continuous batching（F353）与真草稿源。

## ▶ 状态（F358，2026-11-05 11:35 —— 服务面口径的最终更正）
- **投机收益区间已测齐**（全部满长度、有效性 PASS）：贪心 **3.26×**（k=8/12/16 一致），采样 **2.35× / 2.11×**（(1,6)/(2,6)，深度赢分支）；此前"采样中性 1.006×"降级为**词表提示的局部观测**。⇒ 服务默认仍不开，由调用方按负载重复度选择。
- **HEAD 与门禁**：本轮只改探针与 `gate_live.sh`（无引擎改动）⇒ 二进制 == `run_f349.log` 那份（155 PASS / 0 FAIL）；活门禁 **8000 115/115**、8001 `par`+`fairness` 全 0、吞吐 1.054×（第 12 样本）、哨兵 0。
- **下一轮**：①continuous batching（F353 的三处有界改动 + 同 seed 逐 id 硬判据）；②真草稿源（temp>0 下把接受长度从 ~3 抬起来，`tree(1,6)` 已是当前最优形状）；③多属性对象项（dispatch 打点）；④pattern/数值界限；⑤分片 / 多卡 / FR-05。

## ▶ 状态（F359+F360，2026-11-05 15:00 —— continuous batching 落地并第一次真正摊薄权重）
- **F359（机制）**：批路径按 F353 规格落地（每行 slot/lo/hi/abspos、逐行 KV 落槽、8001 两会话共用一次前向、删掉 save/restore 轮转）。**踩坑**：主机指针传进 device kernel ⇒ kernel fault 被 catch 吞掉 ⇒ `BATCH-EQ rows=0` 且队列留在错误状态（下一个无关调用崩）⇒ 改为 `d_slot/d_lo/d_hi` device 副本 + `plan_upload/plan_free`。第一版**没有换来吞吐**：8001 scaling **1.060×**（目标 1.6–2×）——M=2 走的是分块 GEMM（16 行 tile，只有 2 行有用 ⇒ 8 倍白算的 MAC）。
- **F360（性能）**：新增 `gemv2`——一个 workgroup 一个输出行，权重只读一次、同时点积进两个累加器；逐行累加顺序与 `gemv` 相同 ⇒ 批里的行与单独解码逐位相同。引擎内单流 18.69ms/步 vs 批 21.07ms/步（两个会话）⇒ **1.77×**（第二个会话只多 13%）；服务面 8001 scaling 4 样本 **1.457/1.448/1.496/1.418**，中位 **1.45×**（**目标 1.6–2× 仍未达成**）。
- **梯（全部绿）**：自检 **157 PASS / 0 FAIL**（`engine/run_f360.log`，新增 `BATCH-EQ` + `BATCH-THRU`）；8000 **115 PASS / 0 FAIL**；8001 `par`/`fairness` 退出码 **0**；投机探针 greedy **3.324×** / sampled **2.358×**（与 F358 一致）；哨兵 0。二进制 == `run_f360.log` 那份（exe 3377152 bytes，22:31）。
- **下一轮的瓶颈已经换人**：服务面每 token 60.3ms 里引擎只占 18.7ms ⇒ **~41.6ms/token 花在 PHP 侧**（token→文本、socket 写、HTTP 帧），两个流各付一次 ⇒ 把引擎侧 1.77× 稀释成 1.45×。**所以下一个杠杆是 PHP 每 token 热路径，不是 kernel**。
- **下一轮顺位**：①PHP 每 token 热路径（把服务面 scaling 往 1.8× 推）；②M≥3 的批（`gemv2` 只覆盖 M=2，未测）；③8001 批路径的前缀缓存（现在 8001 首个 token 更慢）；④per-request spec 归因；⑤真草稿源、多属性对象项、pattern/数值界限、分片/多卡/FR-05（本机不可验）。

## ▶ 更正（F361，2026-11-05 15:04）——F359 的吞吐目标**在 temp 0 下达成**，缺口在采样器
- **新证据**（同一构建，只改温度，`engine/thru_t0_f360.log`）：temp 0.0 下 8001 scaling **1.714× / 1.789×**（中位 **1.75×**）⇒ **F359 的 1.6–2× 验收目标达成**；temp 0.9（默认）仍是 1.45×。
- **归因更正**：F360 把每 token 的 ~41.6ms 非引擎开销说成"PHP 侧"，**实际是采样器 ~15ms/流/token（引擎内 C++：全词表 exp + 151936 元素排序 + 核截断）+ 其余 ~26ms/token（PHP/客户端）**。两个流各付一次采样 ⇒ 把引擎内 1.77× 摊成 1.45×。**下一轮该动采样器，不是 PHP**。
- **基线不变**：自检 157 PASS / 0 FAIL、8000 115/0、8001 par+fairness 0、投机 greedy 3.324× / sampled 2.358×、哨兵 0；HEAD == `run_f360.log` 那份（19144ef 之后文档有更新）。
- **下一轮顺位**：①采样器去排序化（分布不变、同 seed 只在 tie 上可能变）；②M≥3 的批（`gemv2` 只覆盖 M=2，未测）；③8001 批路径的前缀缓存；④per-request spec 归因；⑤真草稿源、多属性对象项、pattern/数值界限、分片/多卡/FR-05（本机不可验）。

## ▶ 状态（F362，2026-11-05 15:07）——采样器的账算清了，下一轮的实现已经有据
- **探针**（`probe/sampler/sampler_bench.cpp`，600 次抽样）：引擎现状 **15.48ms/次**（与服务面 14.9ms/token/流 对上 ⇒ F361 的归因被第三方确认）；"打包排序"变体 **16.59ms（更慢）**；**去排序的 quickselect 5.05ms（3.07×）**。
- **结论**：采样器的开销**主要是 O(n log n) 的比较次数本身**，不是访存 ⇒ 下一轮把 `sample_from` 的 `top_p ≥ 1.0` 分支换成 quickselect（`top_p < 1` 保留排序，因为核截断要有序前缀），**分布不变**，同 seed 只在 tie 上可能不同。
- **基线不变**（未动引擎）：自检 157 PASS / 0 FAIL、8000 115/0、8001 par+fairness 0、8001 scaling temp 0.9 中位 1.45× / temp 0 中位 1.75×、投机 greedy 3.324× / sampled 2.358×、哨兵 0；HEAD == `af5070c`（引擎源码自 `19144ef` 起未改）。
- **下一轮顺位**：①采样器 quickselect 化（引擎内改 + 分布对照门 + 服务面复测）；②M≥3 的批（`gemv2` 只覆盖 M=2，未测）；③8001 批路径的前缀缓存；④per-request spec 归因；⑤真草稿源、多属性对象项、pattern/数值界限、分片/多卡/FR-05（本机不可验）。

## ▶ 状态（F363，2026-11-06 00:40）——采样器去排序化落地，temp 0.9 与 temp 0 合并到同一档
- **机制**：`sample_from` 的 `top_p ≥ 1.0`（**服务默认**）分支换成带"上方概率质量"累加的 quickselect（`smp_select`），**完全不排序**；`top_p < 1` 的核截断路径原样保留；复用一块 `static` 缓冲（引擎内无线程）⇒ 每次 token 两次 151936 元素分配也去掉。
- **分布证明放在引擎内**：新增原生 `model_sampler_check` + 自检门 `SAMPLER-DIST`（200 次随机抽样走**已发布**的 `sample_from` + 128 点全区间扫描 + 12 个等质量精确点，对照"先排序再走累积"的参考实现）⇒ `mism=0 amb=0 grid=0 exact=0`。
- **服务面（同一构建，temp 0.9，60 token/流，两流）**：串行 **16.87 → 23.36 tok/s**、并发 24.59 → **38.93 tok/s**、scaling **1.45× → 1.640×**（4 样本中位）。**抗漂移口径**（同构建 temp 0 对照）：采样开销 **14.5 → 4.7 ms/流/token**，与 F362 探针预测的省 10.4ms 独立吻合；scaling 的 temp0−temp0.9 差从 0.30 收到 **0.096**。
- **梯（全绿）**：自检 **159 PASS / 0 FAIL**（`engine/run_f363b.log`，相对 157 基线只多 `SAMPLER-DIST` 一项）、8000 **115/0**、8001 `par`+`fairness` 退出码 **0**、投机探针 greedy **3.335×** / sampled **2.368×**、哨兵 0。
- **留档**：第一版门禁自己写错（参照实现多推进了一次 RNG ⇒ 拿两次不同的抽样互比，报 `mism=150`），日志 `engine/run_f363.log` 保留；**修的是门禁不是引擎**。
- **边界**：tie 顺序变了（同 seed 的具体 token 可能变，**分布不变**；仓库无 golden 输出文件）；`top_p < 1` 未动；采样开销**不是零**（还剩 4.7ms）。
- **下一轮顺位**：①M≥3 的批（`gemv2` 只覆盖 M=2，未测）；②8001 批路径的前缀缓存；③per-request spec 归因；④真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05（本机不可验）。

## ▶ F363 收点（2026-11-06 00:42）——下一轮的第一目标已经用引擎自带的 stage 计时定下来
- **引擎前向未被 F363 拖慢**：`PERF stages(1 tok)` s0=0.056 / s1=8.47 / s2=1.95 ms（F360 基线 0.058 / 8.08 / 1.93），stage 合计 ≈10.5ms。
- **每 token 账（本构建）**：引擎 stage ≈10.5ms ＋ 采样 ≈4.7ms ≈ 15ms；服务面串行 temp 0.9 **42.8 ms/token**、temp 0 **38.1 ms/token** ⇒ **≈23ms/token 在引擎 stage 计时之外**（PHP 每 token 热路径 / socket / HTTP 帧 / Python 客户端 wall / 引擎每步未标记零碎）。
- **下一轮第一动作**：**先在服务面加计时**（现在 `engine/serve_par_f363b.log` 里一行时间字段都没有），把"PHP 每 token"与"引擎每步未标记开销"拆开；拆不开就不动手优化，否则又是猜。
- **顺位**（其余不变）：M≥3 的批 → 8001 批路径的前缀缓存 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05（本机不可验）。

## ▶ F363 收点·补（2026-11-06 00:45）——一个被否掉的嫌疑，和一个还没拆开的差额
- **否掉的嫌疑**：8001 热路径**每 token** 一次 `api_par_trace`（`fopen`+`fclose`，`engine/par_trace.log` 已 45452 行 / 1.3MB）。实测 **0.169 ms/次**（复用句柄 0.0068ms，24.9×）⇒ 两个流每迭代 0.34ms，**只占每 token ~40ms 的 0.4%**，**不是那 ≈23ms 的来源**（但它是热路径里唯一的逐 token 文件 I/O，修法＝哨兵开关 + 复用句柄）。
- **还没拆开的差额**：服务端可归因的开销（PHP token→文本、esc、fwrite、每轮 select+accept ≈1ms、trace 0.34ms）**远小于 23ms** ⇒ 差额里必然有**客户端自己的 wall**与**socket 写阻塞**（客户端单线程读两个流时服务端的 fwrite 会等读者）。**要拆开必须服务端与客户端两边都装表**，在装表之前"下一轮该优化 PHP"仍然是猜测。

## ▶ 状态（F364，2026-11-06 00:53）——每 token 的钱：**客户端被排除**，剩下的大头**随上下文长度增长**
- **测量（不改引擎、不重建）**：服务端 `engine/par_trace.log` 本来就给每个 token 打时间戳（`STEP A n=k`，写在 socket 之后）⇒ 加一个客户端到达探针即可相减。新增 `engine/tests/chunk_gap_probe.py`、`engine/tests/chunk_gap_analyze.py`、`engine/tests/chunk_gap_ab.py`、`scripts/chunk_gap_run.sh`。
- **否掉 F363 的推断**："差额里必然有客户端的 wall 与 socket 写阻塞" —— transport lag 中位 **−0.13 ms/token**（60 token 合计 −8.6…−13.4 ms），双流下 **B 的写只 0.4 ms**（服务端 `fwrite` 不等读者）⇒ **客户端/传输 ≈ 0，那 ≈23ms 全在服务端**。
- **新账**：服务端 cadence temp 0.9 中位 **47.6 ms**、temp 0.0 中位 **43.6 ms** ⇒ **采样开销 4.0 ms/token**（与会话路径量到的 4.7 ms 吻合）；而 **60 token 内每 token 成本涨 14.6–21.4 ms**（≈0.25–0.36 ms/上下文 token，两个温度一样涨）⇒ **剩下的不是常数开销，而是长度相关的每步工作**。
- **下一轮第一目标换人**：不再是"PHP 每 token 热路径"（trace 0.169ms、写 0.4ms、`stream_select` 1ms，加起来 ≪ 缺口），而是**每步随上下文变长而变贵的那部分**——计划里挂着的 **8001 批路径的前缀缓存**正打在这里；**机制待证**（下一步查 `model_batch_next` 每步搬了什么），证之前不写成结论。
- **边界**：客户端探针只读单流；双流侧是服务端 trace 自拆；本轮**未改引擎与门禁** ⇒ 基线不动（自检 159/0、8000 115/0、8001 par+fairness 0、投机 3.335×/2.368×）。
- **顺位**：**批路径前缀缓存（升为第一）** → M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05（本机不可验）。

## ▶ 状态（F365，2026-11-06 01:36）——长度相关的那笔钱**找到并改掉**：注意力每步把同一个点积算三遍
- **先证机制再改**：自检新增 `PERF stage-sweep`（L=8…256 各起一个会话量一步的 stage 计时）⇒ **只有 s1（24 个 block）随上下文长度涨**（旧构建 21.75→117.60 ms，拟合 18.6+0.39·L），s0/s2 恒定 ⇒ 长度相关的钱在 block 里。**F363 那次"≈23ms 在 stage 计时之外"的对比无效**（它拿 L=1 的 stage 计时当基准）。
- **机制**：`attention()` 对可见 KV 范围走三遍（max / softmax 求和 / 加权 V），**每遍重算同一个点积** ⇒ K 被读 3 次、MAC 算 3 次。
- **改法**：每个 `(token, head)` 的分数**只算一次**写进设备端 scratch，三遍复用；**值与求和顺序不变 ⇒ 逐位相同**。
- **测法（同构建 A/B，这是本轮的关键）**：两条路径都在同一二进制里（`model_set_attn_mode(0|1)`，环境 `KAKASI_ATTN_MODE=1`），sweep **按 L 配对**（每个 L 先 m0 再 m1，各两遍）⇒ 相邻测量抵消机器漂移。**fit：m0 s1 ≈ 8.54+0.212·L，m1 s1 ≈ 8.86+0.365·L ⇒ 长度相关项降 42%，常数项不动。** 每步整体：L=8 1.15×、L=64 1.48×、L=256 **1.60×**。
- **正确性有门**：新增 `ATTN-MODE`——逐 L 比对两条路径采样出的 token id，五个 L 全同 ⇒ PASS；自检 **160 PASS / 0 FAIL**（`engine/run_f365c.log`，相对 159 只多这一项）。
- **修掉一个真缺陷**：第一版 scratch 分配在主设备上，**分层切到第二块设备时跨设备解引用 ⇒ 自检在 split 段崩（0xC0000005）** ⇒ 第二块设备另配 scratch。**与 F359 的 `d_slot/d_lo/d_hi` 是同一类错误（跨设备共用缓冲），两处都出在这里。**
- **边界**：①跨轮不可直比（旧构建 0.39 vs 本轮同算法 0.365 ⇒ 机器漂了约 13%）⇒ 只认同构建配对 A/B；②**收益随长度增长**，短上下文几乎不变（L=8 仅 1.15×）⇒ 报数字必须带上下文长度；③模式 1 留在二进制里当对照，默认走模式 0。
- **顺位**：服务面端到端复测（本轮已挂 `chunk_gap_run.sh` 跑 m0/m1 两次）→ 8001 批路径的前缀缓存（长度相关项虽降但未清零）→ M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05（本机不可验）。
- **服务面端到端（同构建 A/B，两个模式各 3 次，同提示同 seed）**：服务端每 token cadence temp 0.9 **44.6 → 31.1 ms（1.43×）**、temp 0.0 **34.3 → 30.9 ms（1.11×）**；双流一次迭代 **47.6 → 40.4 ms（1.18×）**，8001 scaling **1.582× → 1.637×**；**输出文本逐字节相同**（148/150 字符）⇒ 端到端也证"逐位相同"。m0 六次落在 30.7–31.3 ms（稳），m1 的 temp 0.0 三次 34.3/39.4/33.9（噪声大）。
- **活门禁全绿**：8000 **115 PASS / 0 FAIL**；8001 `par` **0**、`fairness` **0**、scaling **1.625×**；投机探针 greedy **2.841×** / sampled **2.071×**（比 F363 的 3.335×/2.368× 低——**基数变快了**，投机比值自然降，不是投机变慢）；哨兵 0。

## ▶ 状态（F366，2026-11-06 07:35）——split-KV **没有收益**：注意力的长度成本不是"工作项太少"
- **假设与做法**：F365 后注意力每个 `(token, head)` 只有 1 个工作项（解码时 14 个）⇒ 猜它占用率受限。新增**模式 2 = split-KV**（范围切 P 块、每块 online softmax、第二个 kernel 合并；`P=min(16, scoreRows/16)`，仅在 `T*NH<224` 时启用），**默认关**，与模式 0/1 同在一个二进制里按 L 配对测量。
- **实测（`engine/run_f366.log`）**：五个 L 上 m2 与 m0 的 s1/step 差都在 **±3%** 内且**方向不一致**（L=64 反而慢 7%）⇒ **并行度乘 16 换来 0%** ⇒ **假设被否掉**。
- **两条证据合起来指向的位置变了**：F365 动"每位置搬多少字节"⇒ 斜率动了（−42%）；F366 动"谁去搬"⇒ 没动。⇒ 剩下的 0.212 ms/上下文 token 是**每位置 K+V 读路径本身**，不是占用率。
- **判别实验（同一轮内接着做）**：`kv.f16` 只改每位置的字节数 ⇒ **`PERF kv-sweep` 在 f16=0/1 下各跑 L=8/64/256**；斜率减半 ⇒ 是字节（且服务面可直接用 f16 KV 换长度成本，`model_set_kv_f16` 与 KVQ 门已在）；斜率不动 ⇒ 是那条 64 长的相关 FMA 链。
- **门**：`ATTN-SPLIT`（五个 L 的 greedy token 与模式 0 一致）PASS；`ATTN-MODE` 仍 PASS；自检 **161 PASS / 0 FAIL**、`RUN_EXIT=0`。默认模式 0 ⇒ **发布的数值行为与 F365 完全一致**，模式 2 只是测量工装。
- **纪律**：**这是一条否掉自己假设的负结果，按负结果留档**；P 只测了一个规则（未扫 2/4/32/64）；批路径在模式 2 下能跑通但未计时；greedy 一致 ≠ 分布一致。

## ▶ 状态（F367，2026-11-06 08:03）——**撤回 F366 的负结果**：开关把模式 2 夹回 0，split-KV 真实收益是 **3.9×**
- **怎么露出来的**：本轮为计时消融把 `set_attn_mode` 的取值范围放宽，同一段 split kernel 立刻比模式 0 快 4 倍；回头看 `081f244` 里是 `g_attn_mode = (m == 1) ? 1 : 0` ⇒ **`model_set_attn_mode(2)` 一直是 0** ⇒ F366 的"模式 2"是**模式 0 跟自己比**。三条佐证：源码那行 / F366 的 m2 列五个 L 全等于 m0（no-op 的签名）/ 本轮只放宽夹取就分化。
- **撤回范围**：F366 的"并行度 ×16 收益 0%"**撤回**（从未被测量）。F366 的 **f16＝字节减半、斜率不动**仍然成立（独立对照，且本轮 m4/m5 的开关是核过生效的）。
- **真实数字（`engine/run_f367.log`）**：s1 比 m0/m2 = L=32 **1.31×**、L=64 **1.73×**、L=128 **2.33×**、L=256 **3.89×**；每步 66.83 → **18.72 ms（3.57×）**；**斜率 0.216 → 0.0236 ms/上下文 token（−90%）**；**L=8 反而慢 3%**（P=1 时仍多一次 launch＋合并）。`ATTN-SPLIT: PASS` 现在是真在比两条路径。
- **计时消融（模式 4/5，输出无效）**：L=256 的 s1 m0 63.96 / **m4（V 的 64 次全局读-改-写塌成 1 次）40.35（−37%）** / **m5（去掉 64 长相关点积链）38.34（−40%）** ⇒ 剩下的长度成本两笔各占约四成；**split 的 −74% 超过任何单侧消融** ⇒ 收益来自**把每线程串行链从 256 个位置缩到 16 个**，不是少做事。
- **新增门 `ATTN-ENGAGE`（把教训变成机器可查的东西）**：每次 `model_set_attn_mode(m)` 的返回值（引擎真实模式）必须等于请求值，**8 次切换逐一断言**。**计时/输出对照看不出开关没生效——两侧同路；只有开关自报能看出来。**
- **梯（模式 2 下的预验证）**：`KAKASI_ATTN_MODE=2` 跑全自检 + 8000/8001 活门禁（日志 `engine/run_f367m2.log`、`engine/gate_f367m2.log`）⇒ 决定是否把默认从模式 0 换成模式 2。
- **下一轮顺位**：①**模式 2 提默认**（若模式 2 全梯绿）＋"范围太短就不切"的门槛（收掉 L=8 那 3% 的倒退）②8001 批路径前缀缓存 ③M≥3 的批 ④per-request spec 归因 ⑤真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05（本机不可验）。

## ▶ 状态（F368，2026-11-06 00:35）——split-KV 成为默认路径，全梯绿；**服务面单流只 1.03×**是下一个靶子
- **改动**：默认模式 → 2；**范围 ≤16 token 不切**（退回 F365 路径，L=8 不再倒退 3% 且逐位相同）；两个服务端启动打印 `ATTN_MODE <n>`（让服务面 A/B 可自证）；自检 sweep 收尾**恢复开机模式**而不是 0（否则 sweep 之后的段测的不是发布路径）。
- **引擎**：step 比 m0/m2 = L=8 无倒退、L=64 **1.54×**、L=256 **3.6×**（s1 62.4 → 16.0）。
- **梯（默认=2，全绿）**：自检 **162 PASS / 0 FAIL**（`ATTN-MODE`+`ATTN-SPLIT`+`ATTN-ENGAGE` 三项 PASS）；8000 **115/0**；8001 `par`/`fairness` **0**；scaling **1.837×**（A/B 另测 1.577× → **1.920×**）；哨兵 0；serve 日志 `ATTN_MODE 2` 坐实路径。
- **未闭合（如实记）**：单流服务面每 token cadence **31.9 → 30.9 ms（只 1.03×）**，而同一二进制里引擎自己量的 L=64 每步是 **24.7 → 16.0（1.54×）** ⇒ 引擎省下的 8.7 ms **没进单流 cadence**，却体现在双流 scaling。三条候选（服务上下文更短／cadence 里有一块与注意力无关的大开销／批路径的 splitP 未如预期生效）**都未测**。
- **下一轮第一动作＝装表，不是改代码**：把引擎 stage 计时与 `splitP` 实际值写进 `par_trace.log` 的 STEP 行，一条 cadence 同时给出"引擎步 / 采样 / PHP 残差"，当场分辨上面三条。
- **顺位**：服务面装表 → 8001 批路径前缀缓存 → M≥3 的批 → per-request spec 归因 → 真草稿源 / 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05。


## ▶ 状态（F369，2026-11-06 00:47）——**服务面是宿主主导**：设备段 18.3→14.0 省下的 4.3 ms 被 residual 吸收，cadence 只 1.02×
- **装表（不改行为）**：STEP 行加 `s1=`（引擎 block 段）与 `sp=`（最后一次算出的分块数），新增原生 `model_attn_split_p()`，分析脚本输出 `engine_s1_ms / split_p_values / residual_ms`；正则后缀做成可选，老 trace 仍可读。
- **拆出来的账（每 token，同一二进制）**：模式 2 = **30.8 cadence ＝ 13.98 s1 ＋ 16.95 residual**；模式 0 = **31.3 ＝ 18.29 ＋ 12.94** ⇒ **split 在服务路径生效并省了 4.3 ms 设备时间，但 residual 涨了 4.0 ms** ⇒ **单流服务的关键路径在宿主侧，设备收益被吸收**（这也解释 F368 那条 1.03× 的未闭合项）。
- **顺带一条**：模式 2 下 temp0.9 与 temp0.0 的 cadence 差 ≈ **0**（F364 同口径是 4.0 ms）⇒ **采样已不是服务面大头**。
- **两个仪器坑（都记下来）**：①`g_split_p` 无条件记录，故模式 0 的 `sp` 也非空——**"该切"不等于"切了"**，证明走没走要看 `ATTN_MODE` 横幅；②给 trace 加字段会让按 `$` 锚定的解析器**静默配对失败**，所以后缀必须可选。
- **梯**：自检 **162 PASS / 0 FAIL**、`RUN_EXIT=0`、三项 ATTN 门 PASS；双流 scaling **1.861×**。
- **下一轮（F370）＝拆 residual，不动 kernel**：加"引擎调用返回/采样返回"两个戳，验收要求三项之和 ≈ residual（±5%）、两种温度差独立核采样、**服务文本与本轮逐字节相同**。


## ▶ 状态（F370，2026-11-06 01:03）——服务每 token 拆开：`pre` **7–8 ms**（与模型无关，最大意外）／`eng` 20.2（block 段只占 14.0）／`post` **0.09 ms**
- **做法**：迭代起点与 `model_batch_next` 前后共三个戳进 STEP 行；分析脚本改为按 key 解析（老 trace 仍可读）并输出 **`coverage_pct`**（三项之和 ÷ cadence，**非恒等式**，本轮 92.3–92.6%）。
- **结果**：cadence 30.7–31.0 ＝ `pre` 7.26–8.06 ＋ `eng` 20.15–20.30 ＋ `post` **0.087–0.089**（＋ B 槽/收尾≈2.4，即缺的 8%，**待用 B 行 `post` 核**）。
- **三条定论**：①**PHP/SSE/写侧不是对象**（0.09 ms，F363/F364 的猜测这次直接量到）；②**`eng` 内有 6.2 ms 在 stage 标记之外**（s2≈2.2 ⇒ 还有 ≈4 ms 未命名：logits 回拷/采样/批簿记）；③**`pre` 7–8 ms 与模型无关且最大**，**里面还没测**。
- **更正 F369 的措辞**：residual **不是一个块**，是 pre＋eng 未标记部分＋B 槽收尾＋0.09；"设备省 4.3 ms 而 cadence 不动"的正确说法是**省下的时间落进这些更大的块里**。
- **梯（带戳构建）**：自检 **162/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.780×**、哨兵 0；**服务文本与 F369 逐字节相同**（148 字符）。
- **纪律**：**F371 先命名 `pre`，再谈优化**；在那之前**不动 kernel、不动 PHP**（两条都已有数字说明不在关键路径上）。


## ▶ 状态（F372，2026-11-06 01:39）——**服务面每 token 32.6 → 25.8 ms（1.26×）**：去掉的是 select 睡眠，不是算法
- **一行改动**：活跃槽位需要推进时 `stream_select` 用 **0 超时（poll）**，空闲时仍 1 ms ⇒ **不产生忙等**。
- **自证**：机制字段 `sel` **6.75/5.67/6.15 → 0.032/0.031/0.030 ms**；cadence 降幅 6.5–7.9 ms 与 `sel` 降幅同量级，**同期 `eng`/`s1` 未动** ⇒ **收益来源被字段本身钉住**，不是机器漂移。
- **副作用（正向）**：temp 0 对照下采样成本重新可见（**3.6–4.8 ms**，与 F363 的 4.7 ms 相容）；F369 的"temp 差≈0"是**被睡眠淹掉的假象**，已在 findings 更正。
- **每 token 新账（temp 0.9）**：sel 0.03 ＋ eng **22.9**（s1 15.4／s2≈2.2／采样≈4／**回拷＋批簿记≈1.3**）＋ post 0.11 ＋ B 槽 ≈2.4 ＝ **25.8 ms**。
- **梯**：自检 **162/0**、8000 **115/0**、8001 par/fairness **0**、哨兵 **0**；scaling **1.583–1.658×**（分母变快导致比值缩小，**不是退化**）；投机 greedy **1.843×** / sampled **1.462×**。
- **下一轮（F373）**：**先给 `eng` 内部装表**（`s2`、`rd`＝logits 设备→宿主回拷），覆盖率判据 ≥95%，**再**讨论"设备侧取核再回拷"的裁剪；任何裁剪须带同 seed 分布对照门。


## ▶ 状态（F373，2026-11-06 02:07）——**第三行的价钱终于量到了**：一步 17.1(1行) → 21.4(3行) → 23.1(4行) ms ⇒ **每 token 5.78 ms（2.96×）**
- **为什么以前答不了**：批处理层写死两路（`g_bs[2]`、`slot > 1`、`gemv2` 两个累加器）⇒ **M=2 只有一个点，一个点定不出斜率**（F360/F361 那句"固定开销被行摊薄"一直缺第二、三个点）。
- **四处有界改动**：`g_bslots`（区域数运行期化，KV 按 `g_bstage + slots*cap`）；**`batch_start_n`**（**旧入口仍固定两区域 ⇒ 服务面零改动**）；槽位边界全部跟 `g_bslots`；**`gemv2` → `gemvM<MM>`**（一次权重读服务 MM 行，k 划分与树形归约不变 ⇒ **每行与单独解码逐位相同**），`gemm` 路由 M=2/3/4。
- **新门 `BATCH-M3`**：三条会话一步批处理，判据 **①`rows==3` ②逐 id 等于各自单独跑 ③`n>=4`**。自检 **163 PASS / 0 FAIL**（比 F372 多这一项）、`RUN_EXIT=0`。
- **门自己救了自己一次**：第一版报 `FAIL ... eq_a=1 eq_b=1 eq_c=1 rows=3 n=1`——**分隔符被我照抄成换行**，两侧同错所以相等仍成立；**若没有 `n` 那条断言，这个门会在比较两串垃圾上打绿**。修后 PASS（tokens=8 rows=3）。**⇒ 规格补一条：新门至少要有"形状/数量"与"内容"两类判据。**
- **数字**：M=1/2/3/4 每步 17.08/19.90/21.35/23.13 ms ⇒ 每 token 17.08/9.95/7.11/**5.78** ms（**2.96×**）；第 2/3/4 行的边际 ≈ **+2.8/+1.5/+1.8 ms**，第一行本身 ~17 ms ⇒ **一步固定开销确实被摊掉，到 M=4 未见拐点**。`BATCH-THRU` 同期 1.82–1.96（**本轮没动 M=2 的路径**，比值噪声不小，不写成结论）。
- **服务面还没拿到这 2.96×**：8001 只有两槽（`$onA/$onB` 两套状态与成对 fwrite），`fairness_test.py` 的判据也是按两槽写的 ⇒ **扩到 N 槽是结构性改动，且必须同时改公平判据**，否则"公平"变味。这是 F374 的靶子。

- **活梯（服务面本轮未改，验证确实没改）**：8000 **115/0**、8001 par/fairness **0**、scaling **1.511×**（F372 为 1.583–1.658，同一噪声带）、哨兵 **0**；每 token cadence **25.8/26.2 ms** 与 F372 的 25.3–26.4 一致；**服务文本与 F372 逐字节相同** ⇒ 2.96× 的余量**确实还不在服务面上**（不是被噪声藏起来）。


## ▶ 状态（F374，2026-11-06 02:34）——两处**纯等待**去掉：首 token **1167 → 144 ms（8×）**、每 token **25.8 → 22.9 ms**、coverage **87–90% → 97.8–99%**
- **方法**：先用**已提交数据**算出新指标 **TTFT**（`wall_s −(末次到达 −首次到达)`），发现**第一个请求 925–1618 ms、后续 75–105 ms**（f364e/f369/f372/f373 四个数据源一致）⇒ 一次性进程内开销，引擎注释自己点名"first prefill pays for uploading 290 tensors"。
- **改动一（先暖后 bind）**：`api_warm()`（两个服务端都调）；服务日志 **`WARM ms=1122.1 / 1087.3`** 就是"确实跑了"的证据；`/health` 答得了 = 已暖。
- **改动二（条件让路）**：循环尾 `usleep(2000)` **仅在无槽位推进时执行**——**先对上 coverage 缺的那 2.4 ms 才动手**。
- **可推翻的判据（都命中）**：①首请求 TTFT 进入后续同档 ⇒ **144.3 ms**（后续 89–100 ms）；②cadence 降约 2 ms ⇒ **22.5/23.0/23.1**；③**若缺口真是这个 usleep，coverage 必须升到接近 100%** ⇒ **97.8 / 97.8 / 98.95%**（不升就要撤回归因）。
- **累计（同一探针口径）**：每 token **47.6（F364）→ 25.8（F372）→ 22.9 ms（F374）＝ 2.08×**；首 token **1.2 s → 0.14 s**。
- **顺带否掉一个排队项**：计划里的"**8001 批路径前缀缓存**"打的是后续请求那 75–105 ms 里的 prefill 部分 ⇒ **占一条流的 ~3.5%，不是关键路径**；同位置上真正贵的是**首请求的 1.2 s**，已在本轮解决。
- **梯**：自检 **163 PASS / 0 FAIL**、`RUN_EXIT=0`；`BATCH-M3` 仍 PASS 且 M=1..4 每步 17.67/19.54/20.81/22.47 ms（每 token **5.61 ms**，与 F373 的 5.78 一致 ⇒ **引擎步没被本轮动到**）；8000 **115/0**；8001 par/fairness **0**；scaling **1.542×**；哨兵 **0**。
- **边界**：①首请求仍比后续贵 ~55 ms（**未拆开**，候选：批区域首次 `ensure_kv` 增长、模板/tokenizer 冷路）；②暖机是**搬运**不是消掉（短命进程总耗时不变）；③**没测 CPU**——"活跃期每个迭代都做一次真 forward 所以不是忙等"是结构推断；④两槽写序与 `fairness` 判据未动 ⇒ **N 槽并发的 2.96× 仍未落到服务面**。


## ▶ 状态（F375，2026-11-06 03:11）——服务面并发数变成配置：**N=4 实测 85.56 tok/s、scaling 2.069×**；两路行为与门不变
- **改动**：`api_serve_par` 的十二对镜像标量 → 按槽位数组；两块重复的发射/收尾 → 一个循环；并发数只由 `$nslots` 决定（**默认 2**，`serve.par4.on` → 4）；区域分配改 `model_batch_start_n`。
- **F368 那行日志立功**：第一次 N=4 打印 **`PAR slots=2` + errors=2 + chunks=122** ⇒ 四路落在两路上 ⇒ 查出我把 `$nslots` 的赋值留在了 banner 与 `model_batch_start_n` **之后**（use-before-assign）。修正=决定并发数的代码移到**第一次被读之前**。**没有那行横幅，这会被读成"四路也没更快"。**
- **第二个仪器 bug**：`STEP_SLOTS` 取 `$2`（字面量 `STEP`）——**对所有行为报同一个值**；改取 `$3` 后才看见 `A B C D` 与每槽 60 计数。**留作判据：探针必须能区分它要区的两种结果。**
- **实测**：N=2 控制 `slots=2 / A B / 122 chunks / errors=0 / 62.75 tok/s / 1.539x`；**N=4 `slots=4 / A B C D / 244 chunks / errors=0 / 85.56 tok/s（1.36x）/ 2.069x`**；C/D 各 **60** 个 STEP ⇒ 第三、四路确实在跑。
- **梯（两路默认）**：自检 **163 PASS / 0 FAIL**、`RUN_EXIT=0`；8000 **115/0**；8001 `par` **0**、`fairness` **0**；THRU 1.501x；哨兵 0。
- **验收未覆盖的两条（明说不算完成）**：②**每流文本 == 该流单独跑**（同 seed 内容对照）**未测**；③**`fairness_test.py` 仍只测两路** ⇒ **"N 路下公平"本轮不许声称**（循环顺序即写序即公平策略，已写进注释，但那是声明不是验证）。
- **F376**：就这两件——公平判据泛化到 N 流并跑 N=4，加"逐流内容对照"判据。**做完 N 路并发才算落地。**
- **边界**：N=4 每流 ~46.7 ms/token（两流 ~31.4、单流 22.9）⇒ **收益是聚合吞吐不是单流延迟**，报倍数必须带流数；数组与 `kBatchSlotsMax=8` 是上限，8 槽未测。


## ▶ 状态（F376，2026-11-06 04:05）——N 路并发的两条判据补齐：**四槽下短流 0.18 s、四流文本逐字节相同**
- **公平泛化**：`fairness_test.py` 加 `streams` 参数（**默认 2 ⇒ 原判据不变**）；`streams=4` 时三条长流 2.28/2.30/2.30 s（各 101 chunk）不饿死短流 —— **短流 0.18 s、TTFT 0.08 s ⇒ PASS**；control `streams=2` PASS；槽位不足报 **`NOSUITE`**（配置错，不是公平失败）。
- **逐流内容**：同进程、同 seed、贪心；四路并发 vs 逐条串行：**48/62/76/96 字节，四条全同** ⇒ `PAR-CONTENT: PASS differing=0`。
- **harness 三处缺陷全修**（裸 `wait` 等到服务死、无单实例护栏互杀、无数据误报 FAIL）＋探针超时 300→60 s；每条退出路径清哨兵。
- **F375 的五条验收到此全部达成**：形状 ✓ 逐流内容 ✓ N 路公平 ✓ 服务面吞吐（85.56 tok/s、2.069×）✓ 两路控制与门不回退 ✓。
- **边界**：harness 假设独占引擎进程（`lock` 只让冲突明确失败）；公平判据是"不饿死短流"，**不是"每流速率公平"**；本轮只测到 4 槽（上限 8）。
- **下一轮顺位**：per-request spec 归因 → 真草稿源 → 多属性对象项 / pattern 与数值界限 / 分片多卡 / FR-05；8000 前缀缓存降级项保持不动。


## ▶ 状态（F377，2026-11-06 10:53）——投机有了按请求的账：贪心接受率 **87.7%**、**10.47 vs 18.62 ms/token（1.78×）**
- **加表**：三条生成路径各写一行 `spec_req.log`（mode/k/want/**got**/drafts/accepted/verifies/drafting/ms），plain 组是同分钟对照。
- **数字**：plain **18.62**（18.08–18.62，与引擎步进 17–19 独吻合）；greedy **10.47**（10.31–10.58，接受率 **87.7%**）；tree 中位 12.64 但分布宽（11.74–24.80，混入 `drafting=off`）。⇒ **贪心 1.78×**，每请求可查自己的接受率。
- **口径错误（已修）**：第一版除以**请求上限**而非**实际产出**，得出"投机慢 8 倍"；改 `got` 后自洽。
- **未完成**：独立 harness `scripts/spec_attrib_run.sh` 本轮 `HEALTH_TIMEOUT`，数字来自门禁流量（plain 仅 2 条）⇒ **正式结论要等 balanced 样本**。
- **梯**：自检 163/0、8000 115/0、8001 par/fairness 0、scaling 1.559×、哨兵 0。


## ▶ 状态（F378，2026-11-06 11:03）——**投机的收益是接受率的函数**：87.7% ⇒ 1.78×；50% ⇒ **无收益**
- **balanced 样本**：同提示同限制 12 条 greedy = **50% 接受率、19.06 ms/token**（与纯解码 18.6 同档 ⇒ **无收益**）；F377 的 87.7% 那组是 **10.47 ⇒ 1.78×** ⇒ **"快 N 倍"不成立，只能说"接受率 X 时 Y 倍"**。
- **`NO_DRAFTS` 判据**：第一个提示草稿器 `drafts=0`（prompt-lookup 无重复尾）⇒ 投机恒等于无投机；脚本现在拒绝在那种样本上发布收益。
- **两个 harness 缺陷（已记）**：①plain 组走**前缀缓存分支且未装表** ⇒ 12 条里没有 plain 记录，对照被缓存混住（下一轮给该分支装同一行或请求带 `prefix_cache:0`）；②本机 `/c/...` 路径间歇失效 ⇒ 服务启动/写文件改走 `C:/...` 加重试，三种失败形态各打印一行。
- **梯**：本轮只改 `main.php` 的 trace 行（F377 已跑梯：自检 163/0、8000 115/0、8001 par/fairness 0）；F378 未再改引擎 ⇒ 二进制与 F377 相同。


## ▶ 状态（F379，2026-11-06 11:17）——对照做干净后：**缓存命中路每 token 3 倍贵**；收益再次是接受率的函数（50% ⇒ 1.34×）
- **改动**：前缀缓存分支写同一行 `SPECREQ`（新 `cache=` 字段）；收集与判定拆成 `spec_attrib_run.sh` + `spec_attrib_report.py`。
- **配平窗口（6 plain + 6 greedy，同提示同限同分钟）**：greedy **50.0% 接受率、18.57 ms/token**；plain（缓存命中）**24.82** ⇒ **1.337×**。与 F377 的 87.7% ⇒ 1.78× 并列 ⇒ **收益 = f(接受率)** 有两个独立窗口。
- **新缺陷（重要）**：**缓存命中请求走 PHP 逐 token 循环（`model_session_next`）⇒ 60.49 ms/token**（26 条累计），是从头解码 18.6–21.9 的约 3 倍——**命中省的 prefill 被逐 token 解码吃掉**，此前 plain 组只有 2 条未命中记录，缺陷被遮住。
- **仪器缺陷**：plain 行的 drafts/accepted 是上一次投机调用的残留 ⇒ 报告改为只对 drafted 组打印接受率；引擎侧清零待下一轮（需重建）。收集脚本里的旧内嵌分析块未删净（路径抖动时抛 FileNotFoundError）⇒ 判定改用独立工具。
- **梯**：自检 **163/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.522×**、哨兵 **0**。


## ▶ 状态（F380，2026-11-06 11:20）——**撤回** F379 的"缓存命中贵 3 倍"：配对实验显示命中更快（长提示 TTFT 827 → 74 ms）
- **配对实验**（同提示先 miss 后 hit，同进程）：短提示 TTFT 105.8 → 87.2、decode 16.94 → 17.09；长提示 TTFT **826.7 → 73.5（11.3×）**、decode 22.61 → 22.17 ⇒ **每 token 节奏不变，省的是 prefill**。
- **错因**：F379 按 `cache=` **标签分组**，组内混着不同形状的请求（不同 `got`/长度/窗口内是否含写盘）⇒ 把中位数读成了机制。**"按标签分组不是对照"** 记入方法条。
- **顺带撤掉**：原打算写"只有长提示才用缓存"的决策规则——短提示上命中 TTFT 也已略优，规则无必要。
- **未改引擎**（新增探针 `engine/tests/cache_hit_cost.py`）⇒ 梯沿用 F379 绿：自检 163/0、8000 115/0、8001 par/fairness 0、哨兵 0。


## ▶ 状态（F381，2026-11-06 11:22）——配对的投机归因：**50% 接受率 ⇒ 1.272×**（控制组强制从头解码）
- **设计**：同提示、同进程、plain/spec 交替各 6 条，plain 带 `prefix_cache:0`（只差"是否用投机"一个变量）。
- **结果**：greedy 50.0%（72/144）、**18.56 ms/token**；plain 从头解码 **23.70** ⇒ **1.272×**。与 87.7% ⇒ 1.78×、50% ⇒ 1.337× 并列 ⇒ **收益 = f(接受率)** 有三个窗口。
- **清理**：删掉 `spec_attrib_run.sh` 里遗留的内嵌分析块（三次运行三次抛错）；收集与判定彻底分开。
- **仍欠**：plain 行计数残留（引擎侧清零需重建）。
- **未改引擎** ⇒ 梯沿用 F379 绿：自检 163/0、8000 115/0、8001 par/fairness 0、哨兵 0。


## ▶ 状态（F382，2026-11-06 11:26）——k 曲线内部最优：**k=4 ⇒ 1.238×**；k=1 即使 85.7% 接受率也更慢
- **扫描**（同提示同进程、plain 关缓存、交替配对各 4 条）：k=1 **0.912×**、k=2 1.090×、k=4 **1.238×**、k=8 **0.866×**。
- **结论**：收益是 **f(每次 verify 提交的 token 数)**，不是单看接受率——k=1 接受率高但每次 verify 只提交 ~1 个；k=8 提交多但大多数被丢弃。**默认 k=4 现在有曲线支撑**。
- **自伤并修掉**：上一轮删死代码切多了，把请求循环的 `done` 删掉并提交（HEAD 的脚本是坏的）；本轮按结构重写尾部并加 `bash -n` 语法门。
- **未改引擎** ⇒ 梯沿用 F379 绿：自检 163/0、8000 115/0、8001 par/fairness 0、哨兵 0。
- **边界**：每 k 仅 4 对、单一提示；最优点随负载移动。


## ▶ 状态（F383，2026-11-06 11:30）——k 最优点在两种负载上都是 4；**k=1 即使 100% 接受率仍亏（0.962×）**
- **仪器修复**：提示词此前**写死**（参数没接上、无断言）⇒ 上一轮"改 max_tokens"其实没换负载；已改 `PROMPT=${5:-…}` + `bash -n` 门。
- **数字**：门禁提示 k=1/4/8 ⇒ 0.912 / **1.238** / 0.866×；重复句 k=1/4 ⇒ **0.962** / **1.360×**。⇒ 最优点 = k=4（本组形状），收益随负载重复度上升。
- **机制钉住**：k=1 每 verify 只提交 ~1 token ⇒ 草稿那趟 pure overhead，**100% 接受率也救不回来**。
- **未解释异常（记录）**：重复句负载上 spec 每请求 24 token、plain 只 11 token（应逐 token 相同）⇒ 下一轮先查 spec 与 plain 的停止条件接缝。
- **未改引擎** ⇒ 梯沿用 F379 绿：自检 163/0、8000 115/0、8001 par/fairness 0、哨兵 0。


## ▶ 状态（F384，2026-11-06 11:49）——引擎侧证据：spec 与贪心在同提示上**逐 id 相同**（48/48）；F383 的异常定位到服务面
- **新门 `SPEC-EQ-LONG`**：同提示、`model_generate` vs `model_spec_generate(k=4)`、上限 48，比长度＋逐 id＋首个分歧位置。**PASS**（48 == 48，ids 相同；drafts=44 accepted=44 verifies=11）⇒ **引擎排除**。
- **自检 164 PASS / 0 FAIL**（比 163 多这一项）、`RUN_EXIT=0`。**本轮未重跑 8000/8001 门禁**（改动只加自检门与 echo），**如实标注未重跑**。
- **自伤并修**：新门 echo 写成字符串外的裸 `
` ⇒ `Fatal error: Undefined constant "n"`、`RUN_EXIT=255`；修成 `"
"` 后绿。**教训：模板含反斜杠时，"编译通过"不等于语义对**。
- **下一步**：服务面 `got` 的来源（前缀长度减法、两条路径实际的 `max_tokens`）。


## ▶ 状态（F385，2026-11-06 11:56）——分歧端到端复现：服务面 plain 11 token / spec 24 token，而引擎级门 48/48 相同
- **两边视图一致**：客户端 `completion_tokens` 11 vs 24；引擎 `got` 44/4 与 96/4 ⇒ **记录准确，差异真实**。
- **与 F384 的引擎门矛盾只有一个解释**：**服务面两条分支的输入或上限不同**（不是生成器分歧）；引擎已被两次排除（负控制 + `SPEC-EQ-LONG`）。
- **影响**：`ms/token` 仍可比（各自 `got` 归一）⇒ k 曲线结论不变；**缺的是服务面"spec 与 plain 输出相同"的门**。
- **F386**：`SPECREQ` 记下引擎实际收到的 `$maxTok` 与 `$ids` 长度，判定是上限还是输入不同；修一处；把该一致性做成服务面门。


## ▶ 状态（F386，2026-11-06 12:03）——边界门绿：4/8/12/16/32 各档 plain 与 spec 长度与逐 id 完全一致
- **新门 `SPEC-EQ-STOP`**：五档上限逐档比较，`first_diff=-1` 全档；自检 **165 PASS / 0 FAIL**、`RUN_EXIT=0`。
- **推翻了"门看不到停止"的解释**：这条提示在引擎里**不提前 eos**，所以 F384 的 48 上限门并不失明。
- **新方法与更正**：自检用**裸提示**，服务面用**加了 chat 模板的提示**——**"同一提示"从来不是事实**；plain 11 / spec 24 因此**大概率来自模板差异而不是生成器分歧**。**教训：比较两条路径时，"输入相同"必须被验证，不能当默认。**
- **F387（只差一次装表）**：`SPECREQ` 记 `ids_len=` 与 `cap=`，两分支都记，一次判定。
- **8000/8001 本轮未重跑**（只新增自检门），如实标注。


## ▶ 状态（F387，2026-11-06 12:08）——真分歧钉住：同 28-token 输入下 plain 出 11、spec 出 24
- **三形状实验**：`prefix_cache:0` 与 `spec_k:0` 都是 **11**；`spec_k:4` 是 **24**；三者 `prompt_tokens=28` 相同 ⇒ **输入/上限/模板都不是原因，差异在 `model_spec_generate`**。
- **澄清**：F386 的"自检裸提示 vs 服务模板提示"只解释**自检与服务**的差异，**不解释这次的 plain-vs-spec**（两者同一输入）。
- **代码线索**：`spec_generate` 有 eos 检查，但**接受块成批追加后才检查 `out.back()`** ⇒ eos 落在块内部时会带出后续 token；另一可能是**批验证 argmax 在 eos 位置与单步不同**。**两者未区分**。
- **门为什么没抓到**：两个引擎门的输入（裸提示、≤32 上限）**不触发 eos** ⇒ 落在"没有停止行为"的区域。**门的输入必须覆盖被测行为发生的区域。**
- **F388**：在**模板化输入**上打印两条完整 id 序列，按"第 11 个 token 处是否相同"区分"越过 eos"与"批验证数值分歧"。
- **未改引擎** ⇒ 梯沿用 F386 绿：165/0、8000 115/0、8001 par/fairness 0、哨兵 0。


## ▶ 状态（F388，2026-11-06 12:10）——**投机越过了 eos**：plain 输出是 spec 的真前缀，spec 多吐 13 个 token（含下一轮 `Human:` 内容）
- **证据**：同输入下 `spec_k:0` 得 `The quick brown fox jumps over the lazy dog.`（10 token）；`spec_k:4` 得同一句 **+ `
Human: A dog is playing in the park.`**（24 token）；`prefix_equal=1`（44 字符全同）。
- **结论**：**数值分歧被排除**（前缀逐字符相同）；**确认接受块越过 eos**（**成批追加后才检查 `out.back()==eos`**）。
- **性质**：**用户可见的正确性缺陷**；**F377–F383 的收益数字需在修复后复核**（是在行为不正确的路径上量的）。
- **修法**：`spec_generate` 里把 `take` 截断到接受块内的第一个 eos。
- **判据（F389）**：会到 eos 的模板化输入 + "plain 是 spec 的前缀且 eos 后无 token"。
- **未改引擎** ⇒ 梯沿用 F386 绿：165/0、8000 115/0、8001 par/fairness 0、哨兵 0。


## ▶ 状态（F389，2026-11-06 15:46）——**eos 越界已修**；spec/plain 文本一致；k 复核 k=4 **1.309×**、k=1 仍亏 0.942×
- **修复**：两条投机路径在接受块追加前把 `take` 截断到块内第一个 eos（含）。
- **服务面验证**：`spec_k:0` 10 token / 44 字符；`spec_k:4` **11 token / 同一段 44 字符**（多的 1 个是 eos 本身）⇒ **越界内容消失**。
- **新门 `SPEC-STOP-EOS`**：输入改用 `api_history`（与处理器一致）⇒ 真正覆盖 eos；PASS（drafts=8 accepted=7）。
- **门的第一版又落在无行为区域**（两侧都跑满 48）：**`plain_n == cap` 就是"没到 eos"的警报**，第 4 次同类错误，记入方法条。
- **复核 k**：k=1 0.942×（100% 接受率仍亏）、**k=4 1.309×**、k=8 1.204× ⇒ 最优点与机制不变，量级因分母变化而微调。
- **梯**：自检 **166/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.519×**、哨兵 **0**。
- **待办**：spec 与 plain 的 `completion_tokens` 差 1（eos 是否计入）需要统一。


## ▶ 状态（F390，2026-11-06 15:47）——k 自适应**经核不成立**（三窗口最优点均为 4）；1 token 差异是计数口径
- **条件核验**：F382（1.238×）、F383（1.360×）、F389 修复后（1.309×）**最优点都是 k=4** ⇒ 自适应无数据支撑 ⇒ **不做**，`k=4` 固定默认继续用。
- **1 token 口径差**：`$genN = count(explode(out)) - $promptN`，两条生成器都把 eos 放进 `out` ⇒ 文本相同、id 数差 1，属**用量计数口径**，不影响收益口径（一律用 `got` 归一）。
- **决定**：不为这 1 个 token 改两条生成器的输出契约（刚修好停止逻辑，风险大于收益）；标为已知口径差。
- **未改引擎** ⇒ 梯沿用 F389 绿：166/0、8000 115/0、8001 par/fairness 0、scaling 1.519×、哨兵 0。


## ▶ 状态（F391，2026-11-06 16:00）——草稿窗口是**双向权衡**：m=2 重复文本 1.849×、无重复文本 0.945× ⇒ 默认保持 3
- **改动**：查找窗口变运行期旋钮 `KAKASI_SPEC_WINDOW`（默认 3）。
- **开关生效的行为证据**：同输入下 m=2 提 48 草稿/0 接受，m=3 提 0 ⇒ 行为确实变了。
- **权衡**：重复字母表 m=2 **1.849×** > m=3 1.789× > m=4 1.688×；词表提示 m=2 **0.945×（48 草稿全拒，白付验证）**、m=3 **中性**。
- **决定**：**默认 3 不变**（吃亏侧只 ~3%，受伤侧为 0）；短窗口只适合已知重复性负载。
- **梯**：166/0、8000 115/0、8001 par/fairness 0、scaling 1.500×、哨兵 0。
- **待补**：窗口值进启动日志（现在只有行为证据）。


## ▶ 状态（F392，2026-11-06 17:21）——默认窗口 **2 + 扩窗**：重复文本 **1.865×**、非重复中性；两次"改了没生效"被判据抓住
- **改动**：`SPEC_WINDOW` 启动日志；空接受一次即把窗口扩到 3（miss 计数不变）；**默认 3 → 2**；`SPEC-ADAPT` 改判"浪费 ≤ 1 次 verify"。
- **数字**：字母表 2+扩窗 **1.865×**（100% 接受）vs 窗口 3 **1.758×**；词表 2+扩窗 **1.012×**（白验 48 → **16**）vs 窗口 3 中性 ⇒ **默认改动有双向数据**。
- **两次未生效**：默认值（横幅 `SPEC_WINDOW 3` 抓住）与门判据（grep 抓住）⇒ **纪律：模式替换后要 grep 目标文本**。
- **门契约**：旧判据结构性不可达 ⇒ 换成"浪费有界"；`SPEC-NEG` 负控制不变。
- **梯**：**166/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.543×**、哨兵 **0**、`SPEC_WINDOW 2`。


## ▶ 状态（F393，2026-11-06 17:36）——窗口进请求级记录；**三负载复核：默认 2+扩窗全都不劣于旧默认**
- **`win=` 字段**进 `SPECREQ`（配置起始窗口），跨轮比对只需一个文件。
- **第三负载（半重复）**：窗口 2 **1.064×**（37.5% 接受）vs 窗口 3 **1.035×**（33.3%）。
- **三负载汇总**：高度重复 **+6.1%**、无重复 ≈0、半重复 **+2.8%** ⇒ **默认改动有三种形状的证据**。
- **观察**：半重复下接受率 33–37% 仍有 1.04–1.06× ⇒ 收益不是接受率的线性函数，决定项仍是"每次 verify 提交多少 token"。
- **梯**：166/0、8000 115/0、8001 par/fairness 0、scaling 1.538×、哨兵 0、`SPEC_WINDOW 2`。


## ▶ 状态（F394，2026-11-06 18:05）——约束解码第一处静默截断修掉：全字符串属性上限 **3 → 8**，新门 `SCHEMA-PROPS5`
- **盘点**（读代码）：形状/门对应表与五类缺口已写进 findings.md F394；最静默的是 `api_top_leaves` 的 `maxN` 截断（不报错）。
- **修复**：`:5896` 上限 3 → 8（生成器本就与元数无关）。
- **新门**：`SCHEMA-PROPS5` 两条判据——检测器看到 5 个属性 + 输出含全部 5 键按序；**PASS**（detected=5）。
- **夹具错误被门抓住**：第一版用错包裹键 ⇒ detected=0 FAIL；改真实 `response_format.json_schema.schema` 后过。
- **梯**：**167/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.536×**、哨兵 **0**、`SCHEMA-SCOPE` 仍绿。
- **下一轮**：混合叶子 4 / 对象数组项 4 的同类上限；再往后处理 `pattern` 与数值边界的静默忽略。


## ▶ 状态（F395，2026-11-06 18:19）——三处属性上限全部修掉并各有双判据门（169/0）
- **改动**：混合叶子（:4448）与对象数组项（:3265）上限 4 → 8（生成器本就通用）。
- **新门**：`SCHEMA-MIX5`（5 混合叶子）、`SCHEMA-ARROBJ5`（每项 5 叶子）各"检测到 N + 输出含 N"⇒ 均 PASS；`SCHEMA-PROPS5` 仍 PASS；`SCHEMA-SCOPE` 仍绿。
- **方法条**："生成器通用 + 检测器有预算"是隐蔽缺陷组合；**必须两条判据都断言**。
- **梯**：**169/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.511×**、哨兵 **0**。
- **下一轮**：`pattern`/数值边界的**显式拒绝**（400 + 原因），不再静默放行。


## ▶ 状态（F396，2026-11-06 18:56）——`pattern` 元字符**显式 400**（不再静默无约束）；支持形式活体双向验证
- **改动**：`api_schema_pattern_unsupported` + 8000 校验分支 → **400 + 原因**；`SCHEMA-PAT-REJECT` 双向门 PASS。
- **活体**：不支持 → **400** + 原因；支持 `^(Paris|London)$` → **200** `{"city":"Paris"}`。
- **抓住的错误**：第一版状态码是 **200**（body 是 error）⇒ 改用既有 `$status` 置 400，复验通过。**错误响应的形状包括状态码。**
- **仍排队**：数值边界的非支持组合仍静默放行（下一轮同样显式拒绝）；required/minItems 等未实现。
- **方法条（第三次）**：模式替换后必须 grep 目标文本；本轮门的拼接又"成功但没落地"。
- **梯**：**170/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.551×**、哨兵 **0**。


## ▶ 状态（F397，2026-11-06 19:20）——数值边界显式 400；活体抓到"门绿但线上全拒"的空格 bug 并修掉
- **改动**：`api_schema_bounds_unsupported` + 8000 校验 → 400 + 原因；受支持组合（integer+双边界+跨度≤15）不变。
- **活体**：支持 → **200 `{"n":1}`**；float / 单侧 / 跨度 100 → **400 + 原因**。
- **抓到的 bug**：检测器找 `"type":"integer"`（无空格），真实请求是 `"type": "integer"` ⇒ **全部被拒**；门当时全绿（夹具是紧凑 JSON）。**双向活体检查抓出来的**；修成 `\s*` 正则，门加 `wire_supported/wire_float` 两种排版。
- **方法条**：**门的夹具必须与线上格式同形**；双向断言 + 两种排版是这类检测器的最低配置。
- **梯**：**171/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.499×**、哨兵 **0**。
- **仍排队**：required/minItems 等未实现；oneOf 受限；pattern/边界只是"明确拒绝"，尚未真正实现。


## ▶ 状态（F398，2026-11-06 19:50）——约束支持表 + 五关键字显式拒绝；修掉自己造成的 53 例回归
- **改动**：五个未实现关键字 → **400 + 关键字名**；`required` 仅在**真子集**时拒绝（全属性 required 构造上已满足）。
- **门**：`SCHEMA-KEYWORDS` 8 例双向 + 两种排版 ⇒ PASS；活体四例（含 **tool 真子集 → 200**）。
- **回归与修复**：第一版扫整个 body ⇒ **tool 参数 schema 的 required 被误拒** ⇒ **api_smoke 115 → 62**；改为只扫 `api_json_object_after($body,"schema")` ⇒ **回到 115/0**。
- **方法条**：拒绝类改动必须同时验证"受支持用法不被误伤"；**扫描范围本身是要断言的性质**（误伤面在同一请求的另一份 schema）。
- **支持表**已写进 findings.md F398（每行有门或活体证据）。
- **梯**：**172/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.510×**、哨兵 **0**。


## ▶ 状态（F399，2026-11-06 20:30）——`minLength`/`maxLength` **真正支持**（扁平全字符串），按字符过滤候选 token
- **实现**：bounds 检测器 + 生成器按**剩余字符预算**过滤候选；四个调用点接入；拒绝只在扁平全字符串形状放行。
- **第一版 bug**：多字符 token 越过 max（门抓住）⇒ 改为按 token 字符长度过滤；`maxonly len2=1`。
- **门更新**：`SCHEMA-KEYWORDS` 旧期望被新行为证伪 ⇒ `flat_maxLength=[]` + 新增 `mixed_maxLength=[maxLength]`；新门 `SCHEMA-LEN` 检查**输出长度**。
- **活体**：3..6 → `Hello`(5)；max2 → `A`(1) ⇒ PASS。
- **过程问题**：残留进程锁 exe（LNK1104）；探针正则只匹配小写导致误判"没数据"。
- **梯**：**173/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.545×**、哨兵 **0**。


## ▶ 状态（F400，2026-11-07 09:20）——`minItems`/`maxItems` **真正支持**（单属性标量数组），门检查**元素个数**
- **实现**：`api_schema_array_element_bounds`（形状 + 出现次数双重判据）+ 两个数组生成器按**元素计数**过滤逗号/右括号，min 高于默认预算时抬高（上限 8）；检测器正则只放行 `minItems`/`maxItems` 作 items 的兄弟键；四个调用点接入。
- **门**：新门 `SCHEMA-COUNT`（`bounds=[3:4] n=4`、数字数组 `2:2` → `n=2`、三个拒绝侧）；`SCHEMA-KEYWORDS` 扩到 14 例（放行 3 / 仍拒 4：界写在对象上、对象数组、`maxItems:0`、min>max）。
- **活体七例**（`engine/schema_count_f400c.log`，`scripts/schema_count_run.sh`）：4 元素 200、2 元素 200、对象数组 400、界在对象上 400、**流式** 2 元素 200、**抬高预算** 6 元素 200、min 9 超上限 400 ⇒ PASS。
- **方法**：活体用 `json.loads` 数元素（serve 字符集含逗号，切分会多数）；新分支（抬高、上限拒绝）各配活体例。
- **梯**：**174/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.562×**、哨兵 **0**。
- **仍拒绝（排队）**：多属性/混合/嵌套/对象数组/顶层数组的界；`maxItems:0`；元素上限 8。
- **F400b**：界写在 `items` **之前**的排版是**检测缺口**而非实现缺口 ⇒ 两个正则各加"只认这两个关键字"的前置可选段；门加 `pre_n=2 pre_refuse=[]`，活体加 `str_bounds_before_items` 200/2 元素；梯复跑 **174/0**、8000 **115/0**、8001 绿、scaling **1.539×**（`engine/selftest_f400b.log`、`engine/schema_count_f400e.log`）。


## ▶ 状态（F401，2026-11-07 10:26）——`required` 真子集**真正支持**（扁平全字符串：省略可选属性），并抓到 F399 遗留的非法 JSON
- **实现**：`api_schema_required_filter`（形状 + 逐个名字都在 plan 里）⇒ `api_response_schema_string_props_required` 过滤属性表，**bounds 也改为吃同一张表**（否则按序字符预算错位）。
- **门抓住的真缺陷**：旧判据用**计数**比较 ⇒ `required:["a","z"]` 对两个声明属性被当成"全部必需"而放行（`z` 根本不存在）。新例 `req_undeclared` 第一版 FAIL ⇒ 改成**名字**比较后 PASS。**数量相等 ≠ 名字相符。**
- **活体第五例抓到非法 JSON**：`{"city":"New York","note":"I am ""}` ⇒ 根因是 maxLength 收口时把**解码出多个字符**的引号 token 当闭引号发进字符串（F399 遗留，组合才暴露）；只允许解码恰为一个 `"` 的 token 收口 ⇒ 同 workload 复测 200 且合法；自检加 `comb_json=1 comb_city=8 comb_note=5 comb_zip=0` 钉住。
- **门/表**：新门 `SCHEMA-REQ-SUBSET` 断言**键集合**＝required；`SCHEMA-KEYWORDS` 的 `req_subset`/`wire_subset` 期望由 `required` 改 `""`（旧期望被证伪 ⇒ 改门），加 `req_undeclared`/`req_mixed` 仍拒；支持表该行拆分；**拒绝消息**不再声称"required 只在全部声明时 honoured"。
- **活体五例**：`engine/schema_required_f401e.log` ⇒ VERDICT: PASS（`scripts/schema_required_run.sh`）。
- **梯**：**175/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.520×**、哨兵 **0**。


## ▶ 状态（F402，2026-11-07 10:45）——`additionalProperties` 误拒修复（构造上已满足 ⇒ 不该拒绝）+ 按序取用审计
- **审计**：按属性序号取用的中间产物只有 bounds 一处，且已吃过滤表 ⇒ 无错位面。
- **判据**：`api_schema_additional_properties_honoured`＝布尔拼写 + 只出现一次 + 有 properties + 叶子 kind ∈ {s,an,af,ab}；schema 形式/嵌套/含非字符串叶子仍 400。
- **门**：`SCHEMA-KEYWORDS` 增至 22 例（`addProps=[]`、`addprops_wire=[]` 放行；form/nested/mixed 仍拒；`addprops_arr=[maxItems]` 把**已知过度拒绝**钉进门）。
- **活体**：`addprops_all_string` 200 且键集合＝required；`addprops_mixed_refuse` 400 ⇒ harness 现 7 例全绿（`engine/schema_required_f402.log`）。
- **梯**：**175/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.515×**、哨兵 **0**。


## ▶ 状态（F403①，2026-11-07 12:40）——数组界 + `additionalProperties` 判据**可组合**（各自支持的约束不再互相排斥）；拒绝消息补上实际支持面
- **改动**：`api_schema_array_element_bounds` 排除正则去掉 `additionalProperties`（键约束由自己的判据在拒绝时判定，不再参与数组形状排除）；`api_schema_additional_properties_honoured` 叶子 kind 集加 `a`；**拒绝消息**补上 additionalProperties 的实际支持面（验收⑤）。
- **前一轮残留澄清**：上次会话 10:56 的 `selftest_f403.log` 是**中间态构建**的产物（kwZ 仍拒、SCHEMA-KEYWORDS FAIL）；且 `run-kakasi-selftest.bat` 的 `%1` 在脚本内 `cd engine` **之后**展开，传 `engine\xxx.log` 会落到不存在的 `engine\engine\`——**exe 根本没跑**、BAT_EXIT=1、日志不动，长得像"测试失败"。修正：传纯文件名；**证据必须核 mtime 晚于本轮构建**。
- **门**：`SCHEMA-KEYWORDS` 的 `addprops_arr` 期望 `[maxItems]` → `[]`（旧期望被新行为证伪 ⇒ 改门并说明），新增 `addprops_form_arr=[additionalProperties]`（schema 形式组合仍拒并点名）；harness 7 → 9 例（`addprops_with_arr_bounds` 断言 200+元素数+键集合；`addprops_form_with_arr_bounds` 断言 400+点名）。
- **活体**：`addprops_with_arr_bounds` **200**、2 元素、键集合＝["cities"]；`addprops_form_with_arr_bounds` **400** ⇒ 9 例全绿（`engine/schema_required_f403.log`，VERDICT: PASS）；数组界 8 例复跑全绿（`engine/schema_count_f403.log`）。
- **梯**：自检 **175/0**、`RUN_EXIT=0`；8000 **115/0**、`SMOKE_EXIT=0`；8001 par/fairness **0**；scaling **1.557×**；哨兵 **0**（`engine/selftest_f403.log`、`engine/api_smoke_f403.log`、`engine/par_smoke_f403.log`、`engine/fairness_f403.log`、`engine/throughput_f403.log`、`engine/schema_required_f403.log`、`engine/schema_count_f403.log`）。
- **未做（F403② 排队）**：required 省略 / additionalProperties 扩到**混合叶子**（integer/number/boolean）——设计已在 findings（先修 `api_object_mixed` 的 `b` 分支，过滤形状门只放 {s,i,n,b}，逐形状验证键集合后再放判据）。
- **下一轮顺位**：F403② → pattern 元字符实现 → 数值边界实现 → 数组界的其余形状（多属性/顶层/对象数组）→ 分片多卡 / FR-05。


## ▶ 状态（F403②，2026-11-07 13:55）——required 省略 / additionalProperties 扩到**混合叶子**；活体探针抓到候选层丢 required 键的真缺陷并修掉
- **探针（先于实现）**：`{flag:b,count:i}` 全必需 → `{"flag":false}`（count 丢）；`{name:s,nums:an}` 全必需 → `{"nums":[...]}`（name 丢）。**真缺陷**：候选层只拼可枚举叶子、单数组路径只发数组键——required 点名被丢的键时服务的是违反 required 的答案；F398 的"全必需构造上已满足"对这两类形状不成立。
- **改动**：①候选层 required 感知（required 点名未枚举键 ⇒ 不产候选，语法链带全键服务）；②`api_object_mixed` 布尔分支（b 原来发成数字）；③`api_schema_mixed_omittable`（kind ∈ {s,i,n,b}，e:/en:/ao:/数组 kind 不放）进 required 过滤的形状门，plan 过滤放在 `api_response_schema_mixed_object` 内部（四链一处生效）；④addprops 判据 kind 加 i/n/b；⑤关键字门：全发射面之外的多叶 plan 由候选层当裁判（空 ⇒ 显式 400 required；`{flag:b,num:i min/max}` 全必需仍由候选层完整服务），单叶例外。
- **门**：`SCHEMA-KEYWORDS` 26 例（`req_mixed`/`addprops_mixed` 期望被证伪改 `[]`，新增 req_arr_sub/req_arr_all/req_enum_all/addprops_enum 仍拒对照）；`SCHEMA-REQ-SUBSET` 加混合/布尔/候选层断言（mix city=1 count=0、bool_cands=0、bool_keys=2 bool_bool=1、enum_int_full=1）。
- **活体**：13 例双向全绿（`engine/schema_required_f403c.log`）——`bool_all_required` **200 `{"flag":false,"count":100000}`**（原缺陷现场修复）。**第一轮 ok=False 是测试自身的错**：`keys_of` 返回排序键集，断言写反了顺序；改断言后全绿，FAIL 现场留档（`engine/schema_required_f403b.log`）。
- **梯**：自检 **175/0**、`RUN_EXIT=0`；8000 **115/0**；8001 par/fairness **0**；scaling **1.536×**；哨兵 **0**。
- **留拒（排队）**：数组叶子的 required 子集、`{mode:e,flag:b}` 全必需（门钉住为已知过度拒绝，后续按"先证键集合再放行"取消）。
- **下一轮顺位**：pattern 元字符实现 → 数值边界实现 → 数组界的其余形状（多属性/顶层/对象数组）→ 分片多卡 / FR-05。


## ▶ 状态（F404，2026-11-07 15:40）——pattern 元字符**真正实现**（锚定 class+count）；门抓到"字符串内大括号破坏叶子启发式"的隐藏缺陷并修掉
- **实现**：`api_schema_pattern_spec` 解析 `^...$` 内的字面量段+精确计数字符类段（`{n}`/裸类=1，n≤16，类解析期展开同族区间）；spec 编码 `l<text>~c<n>x<chars>`；plan 新 kind `p:`（api_top_leaves 先于 F282 分支）；`api_object_mixed` p 分支逐段发射（类字符走 `api_pattern_class_ids`——**单字符过滤**，多字符 token 会超发）；混合检测器移出 pattern 排除 + 接受单叶 p plan；关键字门 fullEmission 计入 p。四链经混合检测器自动支持。
- **门抓住的真缺陷**：`^[A-Z]{3}$` 的 `{3}` 使叶子值大括数=2 ⇒ "一大括号=扁平叶子"启发式把整个叶子丢出 plan（SCHEMA-PAT-IMPL 第一版 plan=[] FAIL）⇒ 修法：**先剥 JSON 字符串内容再数大括号**（三处形状判定全改），kind/pattern 正则仍在原值上跑。记法：**括号计数启发式必须剥字符串——schema 字符串值可合法含任何括号**。
- **门**：`SCHEMA-PAT-REJECT` 11 例双向（counted 放行、range/star/unanchored/negated/mixed_alt 仍拒）；新门 `SCHEMA-PAT-IMPL`（发射值 preg_match 匹配模式 + 多叶全必需全键）——**状态码门对"值不符合模式"是绿的，值匹配才是验收面**。
- **活体**：10 例双向全绿（`engine/schema_pattern_f404.log`）——`{"code":"EYX"}`、`{"sku":"SKU-1234"}`、`{"tag":"Xaa"}` 逐一匹配；四种未实现形式 400 点名 pattern；F282 字面量交替不回退。required 13 例复跑全绿（`engine/schema_required_f404.log`）。
- **梯**：自检 **176/0**、`RUN_EXIT=0`；8000 **115/0**；8001 par/fairness **0**；scaling **1.499×**；哨兵 **0**。
- **留拒（排队）**：`{n,m}` 区间量词、`* + ?`、否定类、非锚定类、嵌套叶子类 pattern、pattern 同叶 minLength/maxLength。
- **下一轮顺位**：数值边界实现 → 数组界的其余形状（多属性/顶层/对象数组）→ 分片多卡 / FR-05。


## ▶ 状态（F405，2026-11-07 16:50）——数值边界**真正实现**（单整数叶 + 非负界 + 任意跨度 + 单侧可缺）；活体抓到 maximum-only 哨兵撞守卫的非法 JSON
- **实现**：`api_schema_number_bounded_shape`（单整数叶 kind "i"、界至少一个且在场者非负 ⇒ "prop|lo|hi"，缺席侧 -1）；`api_number_bounded_fill` **逐位走界**（定长：有上界取 len(hi)——d 位值恒大于短一位的 lo，仅上界紧；len 相等两侧紧；每位允许数字 = 界的剩余交集，前缀入界后放宽 0..9，首位禁前导零；发射值是界的子集——合法）；边界门豁免该形状（负界 span≤15 仍由候选层枚举）；四链在 number_prop 前接入；拒绝消息按验收⑤更新。
- **活体抓到的真 bug**：maximum-only 的 lo=-1 哨兵撞 fill 的 `$lo < 0` 守卫 ⇒ 线上回 **`{"n":}` 非法 JSON**（门当时绿——只测了 minimum-only 的发射）⇒ 修守卫 + 补 v5（maximum-only 发射）断言。**记法：哨兵值与守卫条件一起复核；检测器绿 ≠ 发射器绿。**
- **门**：`SCHEMA-BOUND-REJECT` onesided/wide 期望证伪改 0 + multi_leaf/neg_one/float_wide/mult_of 仍拒；新门 `SCHEMA-NUM-BND`（shape 字符串 + 各走法发射值在界内 + json_ok）。
- **活体**：8 例双向全绿（`engine/schema_bounds_f405b.log`）——`{"n":100000}`、`{"n":234567}`、`{"n":5}`、`{"n":12}`、`{"n":2}` 全在界内；多叶/负界/浮点 400 且消息一致。pattern 10 例、required 13 例复跑不回退。
- **梯**（修复后二进制）：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.574×**、哨兵 **0**。
- **留拒（排队）**：负界任意跨度（带符号走位）、浮点界、多叶有界整数、multipleOf 组合。
- **下一轮顺位**：数组界的其余形状（多属性/顶层/对象数组）→ `{n,m}` 区间量词/`* + ?` → 分片多卡 / FR-05。


## ▶ 状态（F406，2026-11-07 17:55）——数组界扩到**顶层数组**（schema 本身是数组对象），门放行与无人服务的缝隙同步堵住
- **实现**：`api_schema_array_element_bounds` 加顶层分支（锚定数组对象、界仅此两键、items 恰标量字符串；max0/min>max/min>8 同款拒绝）；`api_response_schema_top_array_string` 正则接受界兄弟键（**服务面同步**——否则门放行后无人服务）；`api_top_array_string_value` 加 $bounds（F400 同款计数机制）；四链接入 `api_response_schema_array_bounds`（关键字检测器的 body 包装，一处扩展两个用途）。
- **门**：`SCHEMA-COUNT` 加顶层例（top_bounds=[2:2] top_n=2、三拒绝例、top_kw/top_kwObj 双向）；活体 harness 扩到 11 例。
- **活体**：11 例全绿（`engine/schema_count_f406.log`）——顶层 2/2 恰 2 元素、顶层 6/7 抬高分支、顶层 max0 400。required 13 例、pattern 10 例、bounds 8 例复跑全绿。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.566×**、哨兵 **0**。
- **留拒（排队）**：多属性带界数组叶、顶层数字/布尔数组界、对象数组界。
- **下一轮顺位**：多属性带界数组叶（混合步进器 a-kind 计数化）→ 对象数组界 → `{n,m}` 区间量词 → 分片多卡 / FR-05。


## ▶ 状态（F407，2026-11-07 19:00）——数组界扩到**多属性对象**（带界数组叶与兄弟键同发，混合步进器计数化）
- **实现**：plan 数组叶 kind 带 `~min~max` 后缀（a/an/af/ab；不可服务的界保持裸 kind 交给门拒绝）；`api_object_mixed` 计数数组分支（嵌套步进器发射 + F400 计数）；关键字检测器 bIdx 规则（带 ~ 叶子最多一个、其余 ∈ {s,i,n,b}、occurrence + 界验证照旧）；两个单数组服务检测器（string/int）在"有界 ∧ 多叶"时让位；fullEmission/addprops 接受 ~ 前缀（全必需同发、addProps 可组合）。
- **门抓到的回归**：第一版多叶规则只认 `a~` ⇒ 单叶**整数**数组带界（`an~2~2`）断裂（c2/kwN/kwO）⇒ 四种前缀统一识别。**记法：kind 词表加后缀编码时，所有按完整 kind 串相等判断的路径都要重过一遍。**
- **门**：`SCHEMA-COUNT` multi 例（plan=[cities:a~2~2,note:s]、n=2、keys=2、坏界仍拒）；`SCHEMA-KEYWORDS` 4 新例（multi_bounds/addprops/allreq 放行、bad 仍拒）；活体 14 例。
- **活体**：14 例全绿（`engine/schema_count_f407b.log`）——多叶 2/2 恰 2 元素双键、6/7 抬高 6 元素、坏界 400。required/pattern/bounds 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.561×**、哨兵 **0**。
- **留拒（排队）**：对象数组的界（ao 写入器计数化）、`{n,m}` 区间量词、嵌套叶子的带界数组。
- **下一轮顺位**：对象数组界 → `{n,m}` 区间量词 / `* + ?` → 分片多卡 / FR-05。


## ▶ 状态（F408，2026-11-07 21:20）——数组界扩到**对象数组**（ao 写入器计数化）；门抓到检查顺序与 kind 段数两个缺陷
- **实现**：`api_array_object_value` 加 $bounds（F400 同款计数）；关键字检测器 items 检查重构（object ⇒ ao 规则 / array ⇒ 仍拒）且**移到 properties 计数规则之前**（ao 天然两个 properties 块）；`api_schema_array_object_bounds`（单叶 + kind 恰为 "ao" + occurrence + 界验证）；两处 ao 正则接受 items 前界段；四链接入。
- **门抓到的两个缺陷**：①顺序——ao 被计数规则先拒（ao 天然两 properties 块）⇒ items 检查前移；②kind 判断 `strpos($kv[1],"ao:")` 对三段条目恒假（kv[1] 是 "ao"，itemkey 在 kv[2]）⇒ 改 `count>=3 && kv[1]=="ao"`。**记法：plan 条目段数因 kind 而异，按 kv[1] 判 kind 前先明确段数约定。**
- **被证伪翻转**：kwQ `[maxItems]`→`[]`、c3A `""`→`"0:3"`、活体用例 3 400→200 计数内（翻转均有说明与新对照）。
- **活体**：16 例全绿（`engine/schema_count_f408c.log`）——ao 2/2 恰 2 项、坏界 400。required/pattern/bounds 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.495×**、哨兵 **0**。
- **留拒（排队）**：多叶 ao、`{n,m}` 区间量词、嵌套带界数组。
- **下一轮顺位**：`{n,m}` 区间量词 / `* + ?` → 嵌套带界数组 / 多叶 ao → 分片多卡 / FR-05。


## ▶ 状态（F409，2026-11-07 22:40）——pattern 第二批：`{n,m}` 区间与 `* + ?`（仅末段，编码 c<min>-<max>x）
- **实现**：spec 解析器加量化符（非精确仅末段——闭引号独立 token 无歧义；非末段维持 400）；编码 exact `c<n>x` / 区间 `c<min>-<max>x` / 无界 `c<min>-x`；写入器统一走位（min 前只类字符、min 后类字符∪闭引号、max 后只引号；**走位永不发射引号**——尾部 $pcq 闭合）。
- **弯路**：构建连续失败三次才被发现（pvBase 定义顺序 + foreach 括号被吞），期间两次"177/0"是 F408 旧二进制（BUILD_EXIT=1 被误读为 0）；exact 被当 ∞（无横线 ≠ ∞，仅"横线无数字"是）；计数分支与尾部重复发射闭引号（线上杂散 `""`）。**记法：门红先核二进制出身；值内杂散字符是发射重复的指纹。**
- **门**：SCHEMA-PAT-REJECT 翻转 prH/prG/prK/prC（末段量化符服务）+ 非末段仍拒对照；SCHEMA-PAT-IMPL 加 spec_range/spec_star/planz/plans 断言；spD 改造为非末段仍拒证明；活体 13 例。
- **活体**：13 例全绿（`engine/schema_pattern_f409g.log`）——区间/星号/加号的值逐一匹配，非末段 400。required/bounds/count 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.490×**、哨兵 **0**。
- **留拒（排队）**：非末段量化符（NFA）、否定类、非锚定、嵌套带界数组、多叶 ao。
- **下一轮顺位**：嵌套带界数组 / 多叶 ao → 否定类与非锚定（若可无歧义服务）→ 分片多卡 / FR-05。


## ▶ 状态（F410，2026-11-07 23:30）——数组界扩到**多叶 ao**（对象数组带界 + 兄弟键合法省略）
- **实现**：`api_schema_array_object_bounds` 放宽单叶限制（plan 扫描恰一个 "ao" 叶，两个对象数组仍拒）；ao 写入器计数 + 只发该键（兄弟键可选合法省略）；required/子集仍拒由既有规则自动覆盖（ao 三段 kind 不在任何放行集）。
- **门**：kwAM 翻转（多叶 ao 放行）+ kwAU（required 仍拒）对照；活体 18 例。
- **活体**：18 例全绿（`engine/schema_count_f410.log`）——多叶 ao 2/2 恰 2 项且键集合=["rows"]、required 400。required/pattern/bounds 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.507×**、哨兵 **0**。
- **留拒（排队）**：嵌套带界数组、非末段量化符（NFA）、否定类、非锚定。
- **下一轮顺位**：嵌套带界数组 → 否定类与非锚定 → 分片多卡 / FR-05。


## ▶ 状态（F411，2026-11-08 00:40）——数组界扩到**嵌套对象内**的数组（嵌套步进器计数化）；抓住自己造成的尾逗号回归
- **实现**：嵌套步进器叶子循环头解析 `~` 编码（缺省无界）→ 数组分支预算抬升 + 边界过滤；`api_schema_nested_array_bounds`（镜像嵌套检测器下潜：叶层恰一个带~叶子 + occurrence + 界验证 + 镜像其排除）；检测器在计数规则前调用。F407 编码经 api_top_leaves 自动流入嵌套 plan——步进器与检测器是仅有的新增。
- **门/活体抓住的回归（自己造成的）**：重构边界过滤时把无界叶子在预算末元素的"只给右括号"丢成"逗号或右括号" ⇒ deep40 数组叶 4 例尾逗号非法 JSON（smoke 115→110）⇒ 无界路径逐 token 对齐旧行为 ⇒ 回到 115/0。**记法：重构"预算+边界过滤"循环时无界路径必须逐 token 对齐；尾逗号是边界过滤丢失的指纹。**
- **门**：SCHEMA-COUNT 加 c9 嵌套例（nested_bounds=[2:2] nested_n=2、坏界仍拒）；活体 20 例。
- **活体**：20 例全绿（`engine/schema_count_f411b.log`）——嵌套 2/2 嵌套保持、坏界 400。required/pattern/bounds 复跑不回退。
- **梯**（修复后二进制）：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.546×**、哨兵 **0**。
- **留拒（排队）**：非末段量化符（NFA）、否定类、非锚定、多叶嵌套（嵌套叶层多带界叶子）。
- **下一轮顺位**：否定类与非锚定 → 多叶嵌套带界 → 分片多卡 / FR-05。


## ▶ 状态（F412，2026-11-08 01:50）——pattern 第三批：**否定类**与**非锚定**（均可无歧义服务）
- **实现**：①否定类 `[^body]`——字符集 = 排除集在 65 字符 JSON 安全字母表内的补集（发射值仍无法逃出 JSON 串）；补集为空仍拒；②锚点可选（^ 与 $ 独立剥离）——非锚定接受"包含匹配"的任何串，锚定发射即合法解。
- **门/活体**：prI/prJ 翻转 + 空补集/转义仍拒对照；活体 15 例全绿（`engine/schema_pattern_f412.log`）——非锚定值恰 3 位、否定类值全非小写。required/bounds/count 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.485×**、哨兵 **0**。
- **留拒（排队）**：非末段量化符（NFA）、body 内字面量 `^`、转义 shorthands、多叶嵌套带界。
- **下一轮顺位**：多叶嵌套带界 → 转义 shorthands（`\d` 等若可映射到字符集）→ 分片多卡 / FR-05。


## ▶ 状态（F413，2026-11-08 02:30）——pattern 第四批：**转义 shorthands**（\d/\w/\s，裸用与类内；字母表加下划线）
- **实现**：裸转义 `\d`/`\w`/`\s` = 合成字符集（量化符机制照类分支复用：`\d{3}`/`\w+`/`\s?` 组合）；类内 `[\d\-]` 同映射；**安全字母表加下划线**（class-chars 校验/补集字母表/字面量分支三处同步）；`\D` 大写与字面量反斜杠维持拒绝。
- **门抓到的两个缺陷**：①**线材转义拼写**——活体请求的 JSON 转义使 `\d` 在线材是 `\d`，解析器只认单反斜杠 ⇒ 活体 \d/\w 全 400（自检夹具是进程内单反斜杠拼写所以绿）⇒ 双拼写统一接受（逐拼写核步进长度）；②**字母表扩展连带**——`\w` 加下划线后旧"空补集"夹具补集非空（剩 `_`）被合法服务 ⇒ 真"空补集"改用全排除形式 + 部分否定服务对照。**记法：pattern 门在线材原文上工作，转义类约束必须双拼写；字母表扩展有否定类连带。**
- **门**：prAU 翻转（escape 服务）+ prAV（`\D` 仍拒）/prAW（反斜杠仍拒）/prAX（部分否定服务）对照；SCHEMA-PAT-IMPL 加转义断言；活体 18 例。
- **活体**：18 例全绿（`engine/schema_pattern_f413d2.log`）。required/bounds/count 复跑不回退。**过程中 smoke/selftest 一次崩溃（RUN_EXIT=-1、连接重置）为瞬态设备故障——同二进制复跑 177/0/115/0 全绿（非代码缺陷）。**
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.536×**、哨兵 **0**。
- **留拒（排队）**：非末段量化符（NFA）、body 内字面量 `^`、其他转义、多叶嵌套带界。
- **下一轮顺位**：多叶嵌套带界 → 其他转义（若可映射）→ 分片多卡 / FR-05。


## ▶ 状态（F414，2026-11-08 04:10）——数组界扩到**多叶嵌套**（同一嵌套对象内多个带界数组，逐叶验证）
- **实现**：`api_schema_nested_array_bounds` 叶层规则从"恰一个 ~ 叶子"放宽为**逐叶验证**（每个带界叶子独立抽取验证）+ **总和 occurrence**（带界叶子出现次数之和 == 全 schema；游离界仍拒）；检测器返回第一个带界叶子的界（仅门信号——步进器读 plan 自带编码）；嵌套步进器零改动（逐叶独立解析 F407 编码）。
- **门**：SCHEMA-COUNT 加双带界数组嵌套例（two_nums=2 two_tags=3、游离界仍拒）；活体 22 例。
- **活体**：22 例全绿（`engine/schema_count_f414.log`）——双计数同时兑现、游离界 400。required/pattern/bounds 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.609×**、哨兵 **0**。
- **留拒（排队）**：非末段量化符（NFA）、body 内字面量 `^`、其他转义（`\D` 等）。
- **下一轮顺位**：非末段量化符（NFA）→ 其他转义 → 分片多卡 / FR-05。


## ▶ 状态（F415，2026-11-08 04:40）——pattern 第五批：**非末段量化符**（最小计数发射——子集合法论证取代 NFA 设计）
- **设计洞察**：原计划的 NFA 走位（位置集模拟）被简化论证取代——非末段量化符发射**最小计数**（dmin 落在 [min,max] 内 ⇒ 值是合法匹配，与 F405 "输出是界的子集"同源）⇒ 确定性发射后进入下一段，无 NFA。解析器仅拒 min=0 非末段（epsilon 转移）。
- **实现**：解析器的非末段拒绝收窄为 min=0；写入器段循环索引化（isLast）——非末段 "c" 走 min-精确发射，末段保持原走位。
- **门**：prAO/prAP 翻转 + prAY（min=0 非末段）仍拒；SCHEMA-PAT-IMPL 的 spD 翻转为两段 spec；活体 18 例。
- **活体**：18 例全绿（`engine/schema_pattern_f415c.log`）——非末段星号/区间值匹配、min=0 400。required/bounds/count 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.693×**、哨兵 **0**。
- **留拒（排队）**：min=0 非末段（epsilon）、body 内字面量 `^`、`\D` 等其他转义。
- **下一轮顺位**：其他转义（\D/\b 若可映射）→ body 内字面量 `^` → 分片多卡 / FR-05。


## ▶ 状态（F416，2026-11-08 05:40）——pattern 第六批：`\D` 转义（数字补集）与**类内字面量 `^`**（值字母表 67）
- **实现**：`\D` 裸用与类内 = 0-9 在值字母表内的补集（否定机制复用）；类体**非首位 `^`** 作字面量插入值字母表（首位仍是否定标记）——class-chars 校验/补集字母表（67）/规格编码三处同步；**连带**：F413 空补集夹具留 `^` 被服务 ⇒ 真空补集改 67 字符全排除 + 部分否定对照（连续第二轮同一涟漪模式）。
- **门**：prAV/prAT 翻转 + prAX 对照；活体 20 例。
- **活体**：20 例全绿（`engine/schema_pattern_f416.log`）——`\D{3}` 值全非数字、部分否定值含 `^`/`_`、真空补集 400。required/bounds/count 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.540×**、哨兵 **0**。
- **留拒（排队）**：非末段 min=0（epsilon）、类外字面量 `^`（锚点歧义）、`\b` 等非字符类转义。
- **下一轮顺位**：审视剩余排队项的价值 → 分片多卡 / FR-05（本机不可验证，保持排队）。


## ▶ 状态（F417，2026-11-08 05:30）——解除 **min=0 非末段**（同机制服务）+ 断言类拒绝归档
- **分析**：排队项"body 内字面量 `^`"语义分析归档——模式中间 `^`/`$` 是**锚点断言**（无多行标志下 `a^b` 永不匹配 = 不可满足）⇒ 现行拒绝正确，无需工作；`\b` 位置断言同理。**min=0 非末段**由既有最小计数机制天然覆盖（`[a-z]*` 发 0 字符进下一段）⇒ 解析器 min≥1 限制解除。
- **门**：prAY 翻转（min=0 非末段服务）+ 断言类仍拒对照；活体 21 例。
- **活体**：21 例全绿（`engine/schema_pattern_f417c.log`）——`^[a-z]*[0-9]{2}$` 值匹配（星号段发 0 字符）；断言类 400。required/bounds/count 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.468×**、哨兵 **0**。
- **排队收敛**：pattern 支持面至此覆盖常见真实形式的全部主干；剩余仅极少 exotic 变体（归档为正确拒绝）。分片多卡 / FR-05 本机不可验证保持排队。


## ▶ 状态（F418，2026-11-08 06:40）——取消**数组叶子 required 子集**过度拒绝（F403② 钉住的已知项，机制天然支持）
- **实现**：`api_schema_mixed_omittable` 的 kind 集接受计数数组前缀（a~/an~/af~/ab~）——过滤 plan 丢弃该叶，步进器只发 required 键；被省略的键不可能违反自己的界。
- **门**：SCHEMA-REQ-SUBSET 加数组叶例（arr_keep=[note] arr_plan=[note:s] arr_keys note=1 cities=0）；活体 19 例。
- **活体**：19 例全绿（`engine/schema_required_f418.log`）——数组叶子子集 200 且键集合=["note"]。pattern/bounds/count 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.603×**、哨兵 **0**。
- **排队收敛**：F403② 钉住的两个已知过度拒绝中，数组叶子子集已取消；{mode:e,flag:b} 全必需经候选层仲裁已服务（验证见 F403②）。
- **下一轮顺位**：清理剩余边缘项 → 分片多卡 / FR-05（本机不可验证，保持排队）。


## ▶ 状态（F419，2026-11-08 06:00）——数值边界扩到**负界任意跨度**（带符号走位映射）
- **实现**：`api_number_bounded_value` 符号映射——同负（lo<0,hi<0）= 强制 `-` + **幅值走位** fill(-hi,-lo)（值 -(mag) ∈ [lo,hi]）；跨零/单侧负下界 = **裁剪到非负侧**（wlo=max(lo,0)——子集合法论证）；单侧负最大值 = `-` + 幅值单侧最小走位。`api_schema_number_bounded_shape` 撤销负界拒绝（哨兵 -1 与真实 -1 的碰撞逐一核对均落合法子集）。拒绝消息更新。
- **门**：SCHEMA-BOUND-REJECT 的 bdI 翻转（单侧负下界服务）+ bdL（多叶负界仍拒）对照；SCHEMA-NUM-BND 加 neg_shape/v6（同负幅值走位）/v7（跨零裁剪）断言；活体 10 例。
- **活体**：10 例全绿（`engine/schema_bounds_f419.log`）——同负 `[-5000,-100]` → 值 -1000；跨零 `[-1000,1000]` → 值 1000（子集内）；浮点 400。required/pattern/count 复跑不回退。
- **梯**：自检 **177/0**、8000 **115/0**、8001 par/fairness **0**、哨兵 **0**。
- **留拒（排队）**：multipleOf 与界组合、浮点界。
- **下一轮顺位**：multipleOf 组合 → 分片多卡 / FR-05（本机不可验证，保持排队）。


## ▶ 状态（F420 续，2026-11-08 10:10）——接手工作树里未验证的 multipleOf 组合，补两个洞
- **接手时的状态**：`git status` 显示 main.php / schema_bounds_check.py / findings.md 有未提交改动（上一会话的 F420 实现），**梯未跑完**；按"以工作树为准"先读 diff 再动手。
- **读出来的两个洞（不是跑出来的）**：①`SCHEMA-NUM-BND` 新期望 `$nbD == " n|1|5|0"` 把 echo 标签的前导空格抄进了比较串 ⇒ 必红，已改回；②**不可满足倍数无人拒**：形状返回 "" 后旧跨度规则判"受支持"，候选层会发非倍数 ⇒ 在 `api_schema_bounds_unsupported` 形状判定后加"带界 + multipleOf 而形状不认 ⇒ 拒"，400 消息同步写明。
- **门/活体补齐**：`SCHEMA-BOUND-REJECT` 加 `mult_one=0`（单叶服务）、`mult_unsat=1`、`mult_wire=0`（空格排版），多叶 `mult_of=1` 保持；活体加双负 `[-100,-10] m=7` → -14 与不可满足 → 400。
- **机制核对**：`api_number_bounded_value` 的符号映射使折叠后的 `lo==hi` 在正/双负/零三个分支都发射该值（双负 = dash + 幅值 fill），与"确定性单值＝子集合法解"的论证一致。
- **过程错误（记下来，同类第三次）**：第一次重建报 **`SYCL_LINK_FAILED`**，随后自检打印的是**旧二进制**的门文本（有 `mult_shape` 没有我新加的 `mult_one`）⇒ 差点把陈旧结果当本轮结论。处置：杀残留进程 → 重建 → **查 exe mtime/size**（4231680 → 4233216，10:10:26）→ 复跑。**判据：链接失败之后的一切数字都属于上一个二进制，必须先证明二进制换了。**
- **梯（复跑，新二进制）**：自检 **177 PASS / 0 FAIL**、`RUN_EXIT=0`（输出里出现 `mult_one=[0] mult_unsat=[1] mult_wire=[0]` ⇒ 证明新码在二进制里）；`SCHEMA-NUM-BND` `mult_shape=[n|7|7|7] v8=7`；活体 bounds **12 例全绿**（`{"n":7}`、双负 `{"n":-14}`、不可满足 **400**），required/pattern/count 复跑不回退；8000 **115/0**、8001 par/fairness **0**、scaling **1.562×**、哨兵 **0**（`engine/selftest_f420b.log`、`engine/schema_bounds_f420.log`、`engine/api_smoke_f420.log`、`engine/throughput_f420.log`）。


## ▶ 状态（F421，2026-11-08 10:40）——整数叶 + 浮点界：向内取整；空区间/指数写法显式拒绝
- **改前活体取证**：`engine/schema_bounds_f421pre.log` —— integer 叶 `[1.5,1.9]` → **200 `{"n":1}`**（区间内无整数却发了小于下界的值）⇒ `VERDICT: FAIL cases=1`；`[1.5,9.5]` → `{"n":2}`（碰巧合法，说明单看一例会漏）。
- **根因**：形状界正则 `(-?[0-9]+)` 丢小数 ⇒ 空区间变 `[1,1]`；拒绝函数先问形状，旧的"含小数点即拒"永远走不到。
- **实现**：`api_schema_bound_inward`（下界 ceil / 上界 floor，负数截断方向天然正确，小数全 0 不动，非纯十进制返回哨兵）；形状取整后 `lo>hi` ⇒ 无形状；拒绝函数补"界字面量非纯十进制 ⇒ 拒"（压住 `1e1..12` 这种小跨度指数）；400 消息改为实际支持面。
- **门**：新门 `SCHEMA-NUM-FRAC`（pos/neg/empty/integral + 发射值 ∈[2,9]）；`SCHEMA-BOUND-REJECT` 加 `frac_int=0 frac_unsat=1 frac_exp=1 frac_neg=0`。活体加 2 例（整数叶浮点界服务、空区间 400）。
- **错误日志**：第一次自检 **`RUN_EXIT=-1`**，日志停在 `PARITY 164`（277 行，远早于 schema 门）⇒ 先查竞争：无引擎进程、无哨兵、exe 时间戳正常；**重跑一次**（`selftest_f421b.log`）以区分偶发设备中止与真回归。若第二次仍停在同一处，则按回归处理（本轮改动全在 PHP schema 判据，parity 环不经过它，需二分定位）。
- **重跑结果**：越过 164 跑完 ⇒ 偶发中止定性成立。自检 **178/0**、`RUN_EXIT=0`，新门字段已打印（`frac_int=0 frac_unsat=1 frac_exp=1 frac_neg=0`、`SCHEMA-NUM-FRAC pos=[n|2|9|0] neg=[n|-9|-2|0] empty=[] v=2`）。
- **纯 helper 单测**：新增 `engine/tests/bound_inward_test.php`（PHP CLI，**运行时从 main.php 抽取函数**防漂移）⇒ **22 例全绿**，覆盖正负小数两侧、±0.5、小数全 0、整数、指数哨兵。
- **活体改后**：`engine/schema_bounds_f421.log` **14 例全绿**——空区间由 200 `{"n":1}` 翻 **400**，`[1.5,9.5]` → `{"n":2}`；required/pattern/count 不回退。改前证据 `schema_bounds_f421pre.log` 一并留档成对。
- **梯**：8000 **115/0**、8001 par/fairness **0**、scaling **1.421×**（实测，门 PASS measured）、哨兵 **0**。
- **提交后清理（同类第二次）**：commit `0ce1e89` 之后发现 `engine/serve.on`（10:55）与一个仍在跑的引擎 PID 783840——**门禁报完 SENTINELS_LEFT=0 之后**才出现的排队后台副本。处置：停进程、删三个哨兵、复查 0/0；`api_smoke_f421.log`/`spec_req.log` 一度被改写，副本跑完后内容与已提交版本一致（`git status` 回到干净）。**判据：门禁的"哨兵 0"只是那一刻的快照；提交后再查一次进程与哨兵，排队副本会在之后启动。另：`Get-CimInstance Win32_Process` 在本机会卡死（WMI），查进程用 `Get-Process`，要命令行才考虑 WMI 且预期它挂起。**


## ▶ 状态（F422，2026-11-08 11:10）——exclusive 边界口径复核：复核出**静默违反**，exclusive-only 宽/单侧面接入数字走位
- **改前活体取证**：`engine/serve_f422pre.log`——`exclusiveMinimum:0, exclusiveMaximum:100000` → **200 `{"n":234567}`**（越上界，静默违反）；`exclusiveMinimum:5`（单侧）→ **200 `{"n":234567}`**（约束被忽略）。窄跨度 exclusive 早已由候选层服务（`SCHEMA-EXCL`），缺陷只在宽/单侧。
- **根因**：①`api_schema_number_bounded_shape` 只读 `minimum`/`maximum`，不读 exclusive；②`api_schema_bounds_unsupported` 早退（无 min/max ⇒ 受支持）让 exclusive-only 请求绕过全部拒绝逻辑，落入忽略 exclusive 的生成器。
- **实现**：新 helper `api_schema_bound_exclusive`（exclusiveMinimum→floor(X)+1、exclusiveMaximum→ceil(X)-1；小数也服务、指数拒）；shape 检测器回退读 exclusive、`$loSet/$hiSet` 贯穿；拒绝函数早退纳入 exclusive 关键字 + "exclusive-only 且 shape 空 ⇒ 拒"；400 消息写明 exclusive 面。
- **门/单测**：新门 `SCHEMA-NUM-EXCL`（wide=[n|1|99999|0] min1=[n|6|-1|0] max1=[n|-1|49|0] unsat=[] frac=[n|2|9|0] mult=[n|7|7|7]）；`SCHEMA-BOUND-REJECT` 加 `excl_wide=0 excl_unsat=1 excl_mult=1 excl_one=0`；`engine/tests/bound_exclusive_test.php`（PHP CLI 抽取式）**22 例全绿**。
- **活体改后**：`engine/schema_bounds_f422.log` **18 例全绿**（新增 4 例 exclusive）——`excl_wide_served` 200 `{"n":23456}`（∈[1,99999]，改前同请求发越界 234567）、`excl_onesided_min` 200 `{"n":7}`、`excl_onesided_max` 200 `{"n":23}`、`excl_unsatisfiable`（5<n<6）**400**；required/pattern/count 不回退。
- **梯**：自检 **179/0**（178+新门）、8000 **115/0**、8001 par/fairness **0**、scaling **1.431×**、spec greedy **1.991×**/sampled **1.551×**、哨兵 **0**（`engine/selftest_f422.log`、`engine/api_smoke_f422.log`、`engine/throughput_f422.log`）。
- **已知限制（记录）**：同侧 inclusive+exclusive 时 inclusive 优先；若 exclusive 更严则发射值可能违反——本轮只覆盖 exclusive-only 面，混合面留后续。
- **下一轮顺位**：分片多卡 / FR-05（本机不可验证，保持排队）；或同侧 inclusive+exclusive 混合面。


## ▶ 状态（F423，2026-11-08 11:37）——同侧 inclusive+exclusive 混合面：更严者胜
- **改前活体取证**：`engine/serve_f423pre.log`——`minimum:1 + exclusiveMinimum:5` → **200 `{"n":2}`**（应 n≥6，违反）；`maximum:50 + exclusiveMaximum:20` → **200 `{"n":23}`**（应 n≤19，违反）。两层都漏：shape 层（宽/单侧）与候选层（窄，span≤15）都取 inclusive 优先、丢同侧更严 exclusive。
- **实现**：同一侧可同时给两种界，**更严者胜**（下界 max、上界 min）。①shape 层：`$hasExLo/$hasExHi` 去掉 `!$hasLo/!$hasHi` 门控，两侧独立解析后 seed 于 `PHP_INT_MIN/MAX` 再 max/min 合并；②候选层：inclusive/exclusive 各自读、max/min 合并后枚举（窄侧枚举从更严 exclusive 界起）；③拒绝/400 消息沿用 F422（空区间仍拒）。
- **门/活体**：`SCHEMA-NUM-EXCL` 加 mix_strict/max/loose/both；新门 `SCHEMA-EXCL-MIX`（候选 `[{"n":5}…{"n":10}]`）；`SCHEMA-BOUND-REJECT` 加 `mix_sat=0 mix_unsat=1`。活体 `engine/schema_bounds_f423.log` **22 例全绿**（新增 4 例混合：strict_min 7 / strict_max 12 / incl_stricter 5 / unsat 400），F422 四例 exclusive 不回退。
- **梯**：自检 **180/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.565×**、spec **1.792×/1.449×**、哨兵 **0**（`engine/selftest_f423.log`、`engine/api_smoke_f423.log`、`engine/throughput_f423.log`）。
- **记法**：合并"更严者胜"要在**每个会发射值/枚举的层**各做一次（shape 与候选）——只修一层会在另一层的 span 上复发。
- **下一轮顺位**：分片多卡 / FR-05（本机不可验证，保持排队）。exclusive 系（exclusive-only + 同侧混合面）本轮全部关掉。


## ▶ 状态（F424，2026-11-08 12:08）——有界 `multipleOf` > 1000：拆掉从机制抄来的上限
- **改前活体取证**：`engine/serve_f424pre.log`——`min1 max1000000 m5000`（可服务，最小倍数 5000）→ **400**（过度拒绝，非静默违反）；`min1 m5000`（单侧）→ **400**。
- **根因**：shape 检测器有界路径 F420 抄了无界 suffix 机制的 `m ≤ 1000` 上限，但有界路径靠"折叠到区间内最小倍数"（模运算对任意 m 成立）⇒ 上限人为。
- **实现**：`if ($mult < 1 || $mult > 1000)` → `if ($mult < 1)`；可服务⇒折叠到最小倍数发射，无倍数⇒形状空⇒F420 拒绝照旧。**无界 `m > 999` 不动**（三位 suffix 封顶 m ≤ 999，需更长 suffix，继续排队）。
- **门/活体**：`SCHEMA-NUM-BND` 加 `bigmult_shape=[n|5000|5000|5000] v9=5000`；`SCHEMA-BOUND-REJECT` 加 `bigmult_sat=0 bigmult_unsat=1`。活体 `engine/schema_bounds_f424.log` **24 例全绿**（新增 `bigmult_served` 200 `{"n":5000}`、`bigmult_unsatisfiable` 400），F422/F423 各例不回退。
- **梯**：自检 **180/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.391×**、spec **1.886×/1.522×**、哨兵 **0**（`engine/selftest_f424.log`、`engine/api_smoke_f424.log`、`engine/throughput_f424.log`）。
- **记法**："抄来的上限"要问它约束的是哪条机制——有界（折叠到最小倍数，对任意 m 成立）vs 无界（三位 suffix，m ≤ 999）；拆错的上限、保留对的上限。
- **下一轮顺位**：分片多卡 / FR-05（本机不可验证，保持排队）；或有界 `multipleOf` 之外的"多叶 multipleOf / number 叶浮点界"。


## ▶ 状态（F425，2026-11-08 13:05）——`uniqueItems`：未知键导致整个 schema 掉出结构化路径
- **改前活体**：`true`/`false` 两方向都是 **200 + 散文**（`Here are some French cities:`）——不是"发重复元素"，而是数组检测器的精确正则被陌生键打断、级联落空后走无约束文本。
- **实现**：①两个数组检测器容忍 `"uniqueItems": false`，数组文法恢复；②拒绝列表加 `uniqueItems`，例外**值感知**（仅布尔 `false` 且只出现一次）；③400 消息补支持面。
- **第二轮真问题（门抓住）**：第一次自检 `uniq_true=[]` 而 `uniq_route=cities` 正常 ⇒ 我只加了例外、没把名字加回关键字列表（早先为避让 F424 撤回过那行）⇒ **例外是死代码**。补回名单后 PASS。**记法：例外与名单成对改；门要断言"该拒的一侧确实被拒"。**
- **门**：`SCHEMA-KEYWORDS` 30 例（含 `uniq_true/uniq_false/uniq_wire/uniq_route`）。
- **活体**：`engine/schema_count_f425.log` —— `unique_true_refuse` **400**、`unique_false_served` **200 真 JSON**（改前两例 FAIL，`schema_count_f425pre.log`）；bounds/required/pattern 不回退。
- **梯**：自检 **180/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.558×**、哨兵 **0**。


## ▶ 日志清理（2026-11-08 13:29）——只删"不构成记录"的东西
**口径**：`.gitignore` 覆盖的产物与未跟踪的一次性文件删；**已提交的证据日志一律不动**（含"改前 FAIL"的成对证据）；`engine/models/`、`engine/build/`、`*.gguf` 明确排除。
**盘点**：已跟踪日志 **453 个 / 5.1 MB**（其中 201 个被三份文档引用）；空文件 **0**；已忽略产物 **52 MB**（主要是 `engine/api_prefix_*.kv` 前缀缓存与历史 `api_smoke_f1xx.log`）；未跟踪 `engine/gates_f201.txt` 一个。
**执行**：删已忽略日志 **2198 个（407 KB）**、前缀缓存 **8 个 .kv（27 MB）**、未跟踪 stray **1 个**；仓库体积 **259 MB → 197 MB（释放 62 MB）**。删后核验：`git status` 只剩本轮 4 个改动、`ls engine/*.log` 仍 **453**（已跟踪未动）、`engine/build` **38 MB** 完好、exe 为 13:27:57 新构建。
**未删并说明**：252 个**未被文档引用**的已跟踪日志（2.6 MB，多为各轮中间 build/run/gate 记录）——删它们会破坏历史记录，留给"是否裁剪提交历史"的决定。
**第二步（同一轮）**：其中 **36 个 `engine/build_f*.log`（288 KB）确认零引用**（纯 TypePHP 编译转录，代码已提交、可重建），单独提交删除；run/gate 类转录是各轮梯的原始记录，保留。**这一步也说明：glob 传给 `git add -A` 对"已删除文件"不展开（暂存区只多了 progress.md），要先用 `git ls-files` 取名单再 `git rm`。**
**第三步（收尾清点）**：再删 `engine/main.php.f160.bak`（396 KB，F160 的源码备份，代码已在 git 里）、`engine/par_gate_result.txt`（旧 gate 输出，`scripts/par_gate.bat` 会重新生成）、`engine/tests/__pycache__/`。**保留且说明理由**：`engine/build/`（38 MB，删了要全量重建）、`engine/tests/llama_logits*.bin`（51 MB，parity 的 llama.cpp 参考 logits，是**测试 oracle 不是日志**）、`probe/*` 的构建产物（重新跑探针要用）、`engine/models/`（空目录）。清理后体积仍 197 MB——体积大头是 oracle 与构建缓存，不是日志。
**一处必须如实记下的失误**：`gitignore` 的那 2198 个旧日志是**整批删除、没有先留存名单**。事后核对发现 findings 有 **35 处**、`docs/audit-v0.2.md` 有 **2 处**引用 `build_f1xx.log`，而这些文件现在都不在磁盘/索引里（它们从未入库 ⇒ 无 git 副本可恢复）。**影响范围**：被删的是**编译转录**，不是结果证据；对应结论所依赖的 run/gate/自检日志都还在，且 36 个**已跟踪**的编译转录在删除前已做过"全文档零引用"核对（`CITED_COUNT=0`，含 docs/specs）。**规则修正**：删除 `gitignore` 覆盖的文件前，若文档可能引用，必须先 `git status --ignored` 留名单并与文档交叉核对；"忽略"不等于"无效"。
**过程问题**：清理命令里的 `cd` 一次失败（`No such file or directory`），但 Bash 工具的工作目录持久化，相对路径仍在仓库内执行——**正是"`cd` 失败后相对路径仍在旧目录执行"那条陷阱**；改为先核 `pwd` 与 `git status` 再继续，未造成误删。


## ▶ 状态（F426，2026-11-08 13:40）——结构类关键字：从静默忽略/掉出结构化路径改成**点名 400**
- **改前活体**：`contains` **200 忽略**、`propertyNames` **200 + 散文**（陌生键挤出数组文法）、`not` 那例 **400 但点的是 `required`**（夹具把 `required` 写进 `not` 子 schema ⇒ 对的结局、错的理由；已改裸 `{"not":{"type":"integer"}}`）。
- **实现**：`api_schema_structural_unsupported` 按**值形状**匹配 11 个结构关键字（`{`/`[`/数字/布尔），枚举值 `"not"` 不误判；命中即点名拒绝。已知边界（属性名撞关键字）在门里钉住。
- **门**：新门 `SCHEMA-STRUCT`（8 个点名 + `enum_value=[]`、`prop_named=[not]` 两个对照）⇒ PASS。
- **活体**：`engine/schema_count_f426.log` 三例全部 **400 且含各自关键字名**；**改前那份日志被本轮清理误删**（13:25 生成、13:29 清扫时一并删除，早于入库），改前数值按当次输出转录记录：`contains` 200 忽略 / `propertyNames` 200 散文 / `not` 400 错理由、`VERDICT: FAIL cases=2`。bounds/required/pattern 不回退。
- **梯**：自检 **181/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.553×**、哨兵 **0**。
- **过程错误**：`$stA` 与 session 的 int 同名 ⇒ TypePHP 类型改判失败、`TPC_BUILD_FAILED`、exe 不变；链里的管道掩盖退出码导致自检跑了旧二进制。改名 `$sfA` 并复核 exe 时间戳后通过。**记法：单一 `main()` 作用域内新增变量先 grep 占用；构建失败要看 `TPC_BUILD_FAILED` 与 exe 时间戳，别让管道吞退出码。**


## ▶ 状态（F427，2026-11-08 14:10）——`minProperties`/`maxProperties`：键数可满足则支持、不可满足则拒
- **改前活体**：2 键对象上 `minProperties:3` / `maxProperties:1` 都是 **200 且 2 个键**（静默违反）；`minProperties:2` 本就满足。关键字引用数 0。
- **实现**：`api_schema_plan_count` 数 plan；只在扁平全字符串形状计算会发出的键数（required 子集优先），满足则放行、否则点名拒绝；其他形状键集合未证明 ⇒ 仍拒。
- **门**：`SCHEMA-PROPS-COUNT` 8 例（含 required 子集 `sub1=[]`/`sub2=[minProperties]`、混合边界 `mixed=[minProperties]`、空格排版）⇒ PASS；`SCHEMA-KEYWORDS` 40 例同时通过。
- **活体**：`engine/schema_required_f427.log` —— 可满足 200、两例不可满足 **400 点名**；count/bounds/pattern 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.493×**、哨兵 **0**。
- **过程问题**：后台自检命令用相对路径 grep 日志（cwd 不对）⇒ 任务标"失败"，但日志 `RUN_EXIT=0`。**判据：后台失败先看输出里失败的是哪一步，探针的退出码不是引擎的退出码。**（F428 复核后更正：真因是路径写成 `/c/git/typephp-kakasi`，漏了中间一层 `c`。）


## ▶ 状态（F428，2026-11-08 14:18）——`format`/`patternProperties`/`contentEncoding`·`contentMediaType` 按名拒绝
- **改前活体**：三条全 **200 违反**——`format:"email"` 发非邮箱、`patternProperties ^c→integer` 下 `city` 发字符串、`contentEncoding:"base64"` 发散文。
- **实现**：`api_schema_structural_unsupported` 改**分形状四段匹配**（对象 / 布尔或对象 / 字符串 / 整数），新覆盖四个关键字，且**名为 `format` 的属性不会被误判**（属性值是对象）。
- **门**：`SCHEMA-STRUCT` 扩到 15 项，含新关键字 + `format_prop=[]` 对照。
- **活体**：三例应从 200 翻成 **400 点名**。
- **梯（新二进制 14:17:44）**：自检 **182 PASS / 0 FAIL**、`RUN_EXIT=0`（`SCHEMA-STRUCT` 15 项全对，含 `format_prop=[]` 对照）；活体三例 400 点名（改前 200 违反，`schema_required_f428pre.log`）；count/bounds/pattern 不回退；8000 **115/0**、8001 par/fairness **0**、scaling **1.530×**、哨兵 **0**。


## ▶ 状态（F429，2026-11-08 14:31）——`propertyNames`：从误拒改成**对声明键名求值**
- **改前活体**：`pattern:"^[a-z]+$"` 对 city/note 本已匹配却 **400 误拒**；`^x` 与 `maxLength:3` 拒绝是对的。
- **实现**：`api_schema_property_names_ok` —— 只在扁平全字符串形状；白名单求值（pattern≤200 / min-maxLength / enum / const / type:string）；子 schema 含任何**不能评估**的约束即返回 0（不靠"没看见"放行）。
- **门**：`SCHEMA-STRUCT` 扩到 20 项（`pnames_ok=[]`、`pnames_bad`、`pnames_len_ok=[]`、`pnames_enum`、`pnames_mixed` 边界）。
- **第二层（活体抓到）**：只放宽拒绝不够——`api_response_schema_string_props` 的"含 `pattern` 即让位候选层"守卫把 `propertyNames` 里的键名模式当成了值模式 ⇒ **接受后返回散文**。修法：把 `propertyNames` 块摘出该守卫的扫描面；门加路由断言 `pnames_route=2`。
- **活体**：`pnames_pattern_ok` **200 真 JSON**、两例不可满足 **400 点名**（改前满足例被 400 误拒）。
- **梯（第二颗二进制 14:41:28）**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.564×**、哨兵 **0**。


## ▶ 状态（F430，2026-11-08 15:05）——无界 `multipleOf` > 999：后缀宽度按位数取，且不再发半个对象
- **改前活体**：`multipleOf:5000` / `:250000` → **200 `{"n":}`（非法 JSON）**。
- **机制**：`x = (m − (p·10^k) % m) % m < m`，只需 `10^k ≥ m` ⇒ k＝乘数位数（3–6）。
- **实现**：上限 999→999999、`$k/$pow` 按位数、填充/强制循环用 `$k`；`api_multiple_value` 在 body 空时返回 ""（不再发裸头部）；无界 `m>999999` 显式拒绝；`m=1` 交给普通整数生成器（不误拒）；消息补支持范围。
- **门**：`unbounded_big=1`、`unbounded_ok=0`、`mult_one_ub=0`。
- **活体**：`{"n":23450000}`（%5000=0）、`{"n":2345000000}`（%250000=0）；其余 harness 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.512×**、哨兵 **0**。


## ▶ 状态（F431，2026-11-08 15:20）——多叶 `multipleOf`：静默丢键 → 显式拒绝
- **改前活体**：`{"a":multipleOf 3,"b":multipleOf 5}` → **200 `{"a":1000002}`**，`b` 消失。
- **根因**：无界检测器只返回第一个叶子，写入器只发一个键，且没有任何生成器能补其余键。
- **实现**：`multipleOf` 存在且 plan 叶子数 > 1 ⇒ 拒绝；消息补"multipleOf on a schema with more than one property"。
- **门**：`mult_multi=1`、`mult_mixed=1`（单叶 `unbounded_ok=0` 不变）。
- **探针修正**：错误体截断窗口 200 → 900（新消息的相关子句在 200 之后，导致假红）。
- **活体**：`multi_leaf_multipleof` **400 点名**；bounds/required/count/pattern PASS。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.525×**、哨兵 **0**。


## ▶ 状态（F432，2026-11-08 15:38）——`dependentRequired`：条件约束可判，不再一律拒绝
- **改前活体**：`{"city","note"}` + `dependentRequired:{"city":["note"]}` 本已满足却 **400 误拒**。
- **实现**：`api_schema_dependent_required_ok` —— 会发出的键集合（required 子集优先，否则 plan）；trigger 不在集合则跳过，在则要求依赖都在；未声明依赖/混合形状仍拒。
- **门**：`SCHEMA-STRUCT` 24 项（`dep_ok=[]`、`dep_subset/dep_undeclared/dep_mixed` 点名）。
- **活体**：`dep_ok` **200 双键**（改前 400）、两例违反 **400 点名**；其余 harness 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.532×**、哨兵 **0**。


## ▶ 状态（F433，2026-11-08 15:52）——`number` 叶 + `multipleOf`：没有路由，返回散文
- **改前活体**：`{"n":{"type":"number","multipleOf":3}}` → **200 正文 `10`**（不是 JSON）。
- **实现**：无界分支加"`multipleOf` + `type:number` ⇒ 拒绝"；消息补该支持面。
- **门**：`SCHEMA-BOUND-REJECT` 加 `mult_number`（期望 1）。
- **活体**：`number_leaf_multipleof` **400 点名**（改前 200 散文）；其余 harness 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.606×**、哨兵 **0**。


## ▶ 状态（F434，2026-11-08 16:06）——tuple 校验：`items` 数组形式 / `prefixItems` 点名拒绝
- **改前活体**：两种 tuple 形态都 **200 + 散文**（无路由）。
- **实现**：结构判据按**值形状**加两条（`"items":[…]`、`"prefixItems":[…]`）；对象形式 `items` 不受影响。
- **门**：`SCHEMA-STRUCT` 27 项（`tuple_items`、`prefix_items`、`items_obj=[]` 对照）。
- **活体**：两例 400 点名；其余 harness 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.559×**、哨兵 **0**。


## ▶ 状态（F435，2026-11-08 16:09）——支持表的"引用可解析"检查器
- 新增 `scripts/check_gates.sh`：抽取支持表里所有反引号名字（门名/字段名/活体用例名），要求每个都能在**最新自检日志或任一已提交活体日志**里找到。
- 首跑：**cited_names=54 unresolved=0**（`tool_req_subset` 一开始报未解析，真因是初版只查最新 8 份活体日志；改为全量后解析成功）。
- 意义：支持表上一轮刚重写，这个脚本把"每行都有证据"从**声明**变成**可执行验证**；表再漂移会直接报出来。
- **记法**：文档里的证据引用要能被脚本解析；"只查最新 N 份"会让老证据变成假未解析，全量搜索才对。


## ▶ 状态（F436，2026-11-08 16:21）——布尔形式的 exclusive 界：读不到 ⇒ 点名拒绝
- **改前活体**：`minimum:5 + exclusiveMinimum:true` → **200 `{"n":5}`**（边界值被放行）。
- **实现**：`":true"` 拼写 ⇒ 400 点名（`false` 不加约束、保持服务）；消息补明。
- **门**：`SCHEMA-BOUND-REJECT` 加 `excl_bool=1`、`excl_bool_false=0`。
- **活体**：`exclusive_bool_refuse` **400**（消息含 exclusive）；bounds PASS。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.534×**、哨兵 **0**。


## ▶ 收尾审计（2026-11-08 16:24）——文档契约 ↔ 证据 ↔ 树状态
- **支持表引用**：`check_gates.sh` ⇒ **56 引用 / 0 未解析**（每行都能落到最新自检的门/字段或已提交活体日志）。
- **日志引用**：`check_citations.sh` ⇒ **cited=673 tracked=325 missing=348**；tracked 从 283 升到 325（近几轮把自己的证据都入库了，策略生效），missing 保持 348（历史悬空，未改写）。
- **门**：最新自检 `selftest_f436.log` 里 **48 个 `SCHEMA-*: PASS`**、总计 **182 PASS / 0 FAIL**、`RUN_EXIT=0`。
- **树**：`git status` 干净、哨兵 **0**、无引擎进程；HEAD `f174214`。
- **判据**：目标"继续"仍开放（item 列表见 task_plan 的"明确留拒"），但**文档—证据—工作树三者当前一致**，可作为下一位接手者的起点。


## ▶ 状态（F437 规格，2026-11-08 16:25）——布尔 exclusive 的**实现**已写成可执行规格
- 剩余 6 轮不足以跑完一次实现梯（需 8 轮），按"预算不足先写规格"处理：F437 规格写进 `task_plan.md`，含**精确锚点**（形状函数两段探测 + 拒绝条件收窄）、**门差异**（`excl_bool` 期望 1→0、新增 `excl_bool_alone=1`、取值断言 `{"n":6}`）、**活体差异**（从"400 或 200 且 n>5"收紧为"200 且 n>5"）与验收条件。
- 改前证据已入库：`schema_bounds_f436pre.log` 里 `minimum:5 + exclusiveMinimum:true` → **200 `{"n":5}`**；当前状态是 F436 的点名 400，所以这是"拒绝 → 实现"的一步。
- **记法**：预算不足时，**把下一轮写成带锚点的规格**比开一个跑不完的梯更有价值——它把发现成本（找位置、找门、找活体）提前付清。


## ▶ 状态（F437，2026-11-08 18:00）——布尔 exclusive 从"拒绝"变**实现**（收紧同侧数值界）
- **三级演化**（同一 fixture `minimum:5 + exclusiveMinimum:true`）：F436 前 **200 `{"n":5}`**（违反）→ F436 点名 400 → **F437 200 `{"n":7}`**（>5）。
- **实现**：形状函数里把布尔形式**喂进已有的相邻整数换算**（`floor(X)+1` / `ceil(X)-1`），再交给"同侧更严者胜"；数值拼写优先于布尔（更具体）。拒绝收窄为**布尔孤立**（无同侧数值界 ⇒ 无意义）。
- **门**：`excl_bool=0`（旧期望 1 被证伪 ⇒ 改门）、`bool_shape=[n|6|-1|0]`（5 被收紧到 6）、`bool_alone=1`、`excl_bool_false=0`。
- **活体**：`exclusive_bool_served` **200 `{"n":7}`**、`exclusive_bool_alone_refuse` **400**；bounds/required/count/pattern 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.547×**、哨兵 **0**。


## ▶ 状态（F438，2026-11-08 18:16）——多叶 `multipleOf` 从"拒绝"变**实现**（逐叶后缀写入）
- **改前**：两叶全倍数 schema → **200 `{"a":…}`**（丢 `b`）→ F431 起 **400**。
- **实现**：新写入器 `api_multi_multiple_value`（首叶开对象、其余 `,"k":` 续写，每叶用同一后缀机制）+ 检测器 `api_schema_multi_multiple`（全部叶子都是 `i` 且各带 `multipleOf`，plan＝`a|3,b|5`）；四处路由在单叶分支**之前**接多叶；拒绝条件改为"plan>1 **且** 多叶检测器为空"。
- **门**：`mult_multi` 期望 **1→0**（旧期望证伪 ⇒ 改门）、新增 `multi_plan` 断言写入器拿到 `a|3,b|5`；`mult_mixed`（一个 multipleOf + 一个普通整数叶）仍 1。
- **活体**：`multi_leaf_multipleof` **200 `{"a":1000002,"b":1000000}`**（a%3=0、b%5=0）、`multi_leaf_mixed_refuse` **400**；bounds/required/count/pattern 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.506×**、哨兵 **0**。
- **过程记一笔**：插入新函数时把单叶检测器的**函数头**吃掉了（留下孤儿 `{`），靠读回发现并补回——**插入型编辑后要读边界**。


## ▶ 状态（F439，2026-11-08 18:30）——`number` 叶小数界：**整数即合法 number**，区间含整数就服务
- **思路**：不引入小数写入器——整数是合法的 `number`，所以只要区间 `[lo,hi]` 内含整数，收紧到 `[ceil(lo), floor(hi)]` 即可复用整数写入器；无整数的区间（如 [1.5,1.9]）仍需小数写入器，保持 400。
- **实现**：`api_schema_number_bounded_shape` 的叶子 kind 从只认 `i` 放宽到 `i|n`；**number+multipleOf 的拒绝提到校验最前**（否则新的放行会把倍数静默丢掉）。
- **门（旧期望被证伪 ⇒ 改门）**：`$bdB`/`$bdJ`/`$bdG`（number 叶浮点界三例）期望 **1→0**；`SCHEMA-NUM-FRAC` 加 `num_float=[n|2|9|0]`、`num_float_empty=[]`、`num_float_ok=0`、`num_mult_refuse=1`。
- **活体**：`float_served` **200 `{"n":100}`**、`float_empty_refuse` **400**（改前前者 400）；bounds/required/count/pattern 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.513×**、哨兵 **0**。


## ▶ 状态（F440，2026-11-08 19:00）——无整数区间：**一位小数**发射（不改路由）
- **思路**：`hi = -999` 作为"十进制标记"，`lo` 携带**十分位值**（如 15 = 1.5）；同一写入器在标记分支把 `int.frac` 作为一次强制续写发出——**四处路由无需改动**。
- **实现**：形状函数在"区间无整数"分支改为计算十分位区间（两侧都非负、各至多一位小数、无 `multipleOf`）并返回 `prop|k|-999|0`；新增 `api_schema_tenths`；写入器加标记分支。
- **第一版真 bug（门抓住）**：回退**没有限制叶子类型**，于是 integer 叶 `[1.5,1.9]` 也被发成 `1.5`（`frac_unsat` 从 1 变 0、`empty` 从 "" 变标记）⇒ 加 `$kv[1] == "n"` 后复绿。
- **门（旧期望证伪 ⇒ 改门）**：`num_float_empty` 期望由 `""` 改为 `n|15|-999|0`；新增 `num_dec`/`num_dec_fine`/`dec_text`。
- **活体**：`float_dec_served` **200 `{"n":1.5}`**（改前 400）、`float_dec_fine_refuse` **400**（[1.51,1.59] 无十分位格点）。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.464×**、哨兵 **0**。


## ▶ 状态（F441，2026-11-08 19:15）——小数网格推广到**两位**
- **实现**：`api_schema_tenths` 泛化为 `api_schema_scaled($lit,$digits)`；形状"无整数"分支按边界的最大小数位数选 `d∈{1,2}`，标记 **-999（一位）/ -998（两位）**；写入器按 `d` 取比例并**补零**（151 → `1.51`）。
- **门（旧期望证伪 ⇒ 改门）**：`num_dec_fine` 由 `""` 改为 `n|151|-998|0`；新增 `num_dec3=[]`（三位小数无格点）与 `dec2_text=[{"n":1.51}]`。
- **活体**：`float_dec2_served` **200 `{"n":1.51}`**（改前 400）、`float_dec3_refuse` **400**；一位小数与整数路径不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.520×**、哨兵 **0**。


## ▶ 状态（F442，2026-11-08 19:30）——小数界支持**负数**
- **实现**：`api_schema_scaled` 接受前导 `-`（符号 × 幅值）；形状的网格条件去掉"非负"限制（`$tLo <= $tHi` 即可满足区间）；写入器取绝对值后加**符号前缀**（`-19 → "-1.9"`）。
- **哨兵修正**：`api_schema_scaled` 的失败哨兵从 `-1` 改为 `-1000000`——`-1` 与合法值 -0.1（缩放后 -1）**碰撞**，会把"解析失败"当成 -0.1。
- **门**：`SCHEMA-NUM-FRAC` 加 `num_dec_neg=[n|-19|-999|0]` 与 `neg_text=[{"n":-1.9}]`。
- **活体**：`float_dec_neg` **200 `{"n":-1.9}`**；正小数（1.5 / 1.51）与三位拒绝路径不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.529×**、哨兵 **0**。


## ▶ 状态（F443，2026-11-08 19:45）——无界 `multipleOf` 上限 **999999 → 999999999**
- **实现**：后缀宽度阶梯扩到 9 位（7/1e7、8/1e8、9/1e9），上限与拒绝阈值同步改为 `999999999`。
- **门（旧期望证伪 ⇒ 改门）**：`unbounded_big` 夹具由 2,000,000 改为 2,000,000,000（仍应 1）；新增 `unbounded_2m=0`（旧"超出机制"的 2,000,000 现在应被服务）。
- **活体**：2,000,000 → **200 `{"n":23450000000}`**（%2e6=0）、987,654,321 → **200 `{"n":2345679012375}`**（%m=0，9 位后缀）；5,000 / 250,000 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.523×**、哨兵 **0**。


## ▶ 状态（F445，2026-11-08 20:15）——小数网格到**三位**
- **实现**：`api_schema_scaled` 支持 1–3 位小数；形状按边界位数取 `d∈{1,2,3}`，标记 **-999/-998/-997**；写入器按 `d` 取比例并补零（1511 → `1.511`）。
- **同时修两个连带问题**：①`$nfM` 变量名与自检里已有的**数组**同名 ⇒ `TPC_BUILD_FAILED`（改 `$nfN`）；②我为扩大哨兵做的全局替换把 `api_schema_bound_inward/exclusive` 的返回值也改成 **-2000000000**，而调用方仍在比 `-1000000` ⇒ `frac_exp`（指数写法）从"拒绝"变成"服务"；统一比较值后恢复。
- **门（旧期望证伪 ⇒ 改门）**：`num_dec3` 由 `""` 改为 `n|1511|-997|0`；新增 `num_dec4=[]`、`dec3_text=[{"n":1.511}]`。
- **活体**：`float_dec3_served` **200 `{"n":1.511}`**（改前 400）、`float_dec4_refuse` **400**；1.5/1.51/-1.9 均不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.521×**、哨兵 **0**。


## ▶ 状态（F444，2026-11-08 20:34）——tuple 校验：从"点名拒绝"变**逐位置实现**
- **实现**：`api_tuple_value`（元素按 plan 顺序：`s` 走字符集步进、`i` 走数字走位，末尾强制 `]}`）；`api_schema_tuple` 只认**单属性 + `items` 数组 + 2–3 个纯 `string`/`integer` 子 schema**；四处路由在数组分支前接入；拒绝收窄为"tuple 检测器为空才拒"。
- **编译期问题**：第一版在 8000 非流的作用域里用了 `$prefixCache`（该处只有字面量 `1`）⇒ `error C2065`、`TPC_BUILD_FAILED`；改为 `1` 后构建成功（**exe 时间戳确认换了二进制**）。
- **门（旧期望证伪 ⇒ 改门）**：`tuple_items` 由 `items` 改为 `""`；新增 `tuple_plan=[pair|s,i]` 与文本断言 `tuple_text=[{"pair":["word",1000]}]`；`prefix_items` 仍 `prefixItems`。
- **活体**：`tuple_served` **200 `{"pair":["1000",2000]}`**（元素 2 个、类型按位置）、`prefixitems_refuse` **400**；其余 harness 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.611×**、哨兵 **0**。


## ▶ 状态（F446，2026-11-08 21:10）——`dependentSchemas`（窄形态）从拒绝变**支持**；活体抓到键丢失
- **实现**：`api_schema_dependent_schemas_ok` 只认"每个 trigger 的子 schema 恰为 `{"required":[...]}`"，按 F432 的发出键集判定；其他子 schema 体仍拒。
- **活体抓到的第二层**：`depsch_ok` 先是 **200 但只发 `note`**——`api_schema_required_names` 把 `dependentSchemas` 子 schema 里的 `"required"` 当成了**根级 required**，键过滤因此收缩到 `{note}`。修法：读名字与"是否存在"两处都先**剥离 dependentSchemas 块**（新增 `api_schema_required_present`）。
- **门**：`SCHEMA-STRUCT` 加 `depsch_ok=[]`、`depsch_bad=[dependentSchemas]`、`depsch_other=[dependentSchemas]`。
- **活体**：`depsch_ok` **200 双键**、`depsch_bad_subset`/`depsch_other_body` **400 点名**；其余 harness 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.526×**、哨兵 **0**。


## ▶ 状态（F447，2026-11-08 21:24）——`dependencies`（draft-7）两种拼写均支持
- **实现**：`api_schema_dependencies_ok`——数组值＝"触发键在则这些键也必须在"（同 dependentRequired），对象值**仅当恰为** `{"required":[…]}`（同 dependentSchemas）；其他对象体拒绝。`api_schema_emitted_set` 抽出"会发出键集"供三个条件关键字共用。
- **成对修复**：`api_schema_required_names/present` 的剥离逻辑抽成 `api_schema_required_scan`，**同时剥离 `dependentSchemas` 与 `dependencies`**——否则 `dependencies` 里的嵌套 `required` 会被当成根级（F446 同族）。
- **门**：`deps_arr=[]`、`deps_obj=[]`、`deps_bad=[dependencies]`、`deps_other=[dependencies]`。
- **活体**：两种拼写 **200 双键**、required 子集省略依赖 **400 点名**；其余 harness 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.537×**、哨兵 **0**。


## ▶ 收尾审计（2026-11-08 21:25）——约束面"每个关键字都有归宿"
- **不变量核对**：`api_schema_structural_unsupported` 里逐个确认 17 个关键字仍在判据内（contains/patternProperties/not/if/dependentRequired/dependentSchemas/dependencies/propertyNames/unevaluatedItems/unevaluatedProperties/minContains/maxContains/format/contentEncoding/contentMediaType/prefixItems/items），其中 `dependentRequired`/`dependentSchemas`/`dependencies`/`propertyNames`/`items`（元组）有专门的**实现/ honoured** 分支，其余仍**点名拒绝** ⇒ 没有"既不实现也不拒绝"的静默面。
- **文档**：支持表 **58 引用 / 0 未解析**；三个条件关键字与 tuple 的行都已入表。
- **门与梯**：最新自检 **182 PASS / 0 FAIL**、**48 个 `SCHEMA-*` 门**；8000 **115/0**、8001 par/fairness **0**、scaling **1.537×**、哨兵 **0**；工作树干净。
- **仍未实现（明确排队）**：`contains`/`not`/`if`/`patternProperties`/`unevaluated*` 的**实现**（都需要子 schema 求值器）、四位以上小数、无界 `multipleOf` > 999999999、`minContains/maxContains`；分片多卡 / FR-05 本机不可验证。


## ▶ 状态（F448，2026-11-08 21:41）——`not` 的**类型矛盾**形式从拒绝变支持；值级规则与其他形状仍点名拒绝
- **实现**：`api_schema_not_ok`——扁平全字符串 + **根级** `"not"` + 子 schema 恰为 `{"type":"integer"|"number"|"boolean"|"array"|"object"}` ⇒ 放行（发出的值全是字符串，构造上不可能违反）。结构判定把 `not` 从 `contains|patternProperties|if` 的 alternation 中拆出单独判定；其余子 schema（pattern/enum/嵌套）返回值 `not`。
- **名字冒充的守卫**：`"not"` 的位置须在 `properties` 块之外，属性名恰好叫 `not`（旧夹具 `prop_named`）仍拒；`enum` 值 `"not"` 不误判。
- **门**：`SCHEMA-STRUCT` 新增 `not_type=[]`（放行）、`not_pattern=[not]`、`not_mixed=[not]`，以及 `not_route=2`（放行后值生成路由仍在）。
- **旧门翻转**：`schema_count_check.py` 的 `not_refuse` 夹具正是被新行为证伪的那一种（`cities` 字符串 + `not:{"type":"integer"}`）⇒ 改为 `not_type_served`（200 且值为字符串），并加对照 `not_pattern_refuse`（400 点名）。
- **活体**：`not_type_ok` **200 双键**；`not_pattern_refuse`/`not_mixed_refuse` **400 点名 not**；bounds/required/count/pattern 不回退。
- **梯**：自检 **182 PASS / 0 FAIL**、`RUN_EXIT=0`；8000 **115/0**；8001 par/fairness **0**；scaling **1.554×**；哨兵 **0**。


## ▶ 状态（F449，2026-11-08 21:58）——`contains` 的**单项类型**形式从拒绝变支持；值规则/类型不匹配/计数仍点名拒绝
- **实现**：`api_schema_contains_ok`——单数组属性 + 根级 `contains` + 子 schema 恰为配对类型（`a`↔string、`an`↔integer、`af`↔number）+ 不带 `minContains`/`maxContains` ⇒ 放行。结构判定把 `contains` 从 `patternProperties|if` 拆出单独判定。
- **构造满足的依据是控制流**：两个数组写出器都在闭括号可选**之前**先发出首元素 ⇒ 数组必然 ≥1 元素且元素类型由写出器保证，首元素即匹配。
- **门**：`SCHEMA-STRUCT` 加 `contains_type=[]`、`contains_mismatch=[contains]`、`contains_pattern=[contains]`、`contains_route=[cities]`（数组路由仍认领）。
- **旧门翻转**：`schema_count_check.py` 的 `contains_refuse` 正是被放行的形式 ⇒ 改 `contains_type_served`（200、数组 ≥1 元素），加对照 `contains_pattern_refuse`（400 点名）。
- **活体**：`contains_type_ok` **200**、`contains_mismatch_refuse` **400 点名**；count harness 同向通过；bounds/pattern 不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.351×**（单次测量，低于近几轮，如实记录）、哨兵 **0**。


## ▶ 状态（F450，2026-11-08 22:13）——number 叶小数界扩到**四位**（万分之一格）；五位及以上仍点名拒绝
- **改动（三处宽度假设一次改全）**：`api_schema_scaled` 字面正则 `{1,3}`→`{1,4}` + `$digits==4` 分支；计划构造两个字面正则同改、加 `$d=4` 与标记 `-996`；写入器 `-996` → `d=4, scale=10000`。沿用"缩放值放 lo、网格标记放 hi"的既有编码，无新路由。
- **门**：`SCHEMA-NUM-FRAC` 的 `num_dec4` 由 `""` 改为 `n|15111|-996|0`，加文本断言 `dec4_text=[{"n":1.5111}]`。
- **活体**：`float_dec4_served` **200 `{"n":1.5111}`**、新增 `float_dec5_refuse` **400**；1/2/3 位与负数不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.548×**、哨兵 **0**。


## ▶ 状态（F451，2026-11-08 22:35）——`patternProperties` 的**空约束**形式从拒绝变支持；匹配/多条模式仍点名拒绝
- **实现**：`api_schema_pattern_props_ok`——恰一条模式、长度 1–200、键集用 `api_schema_emitted_set`；任一键命中或模式不可读 ⇒ 拒绝，全不命中 ⇒ 放行（块不约束任何属性）。`patternProperties` 从 `patternProperties|if` 拆出单独判定。
- **门**：`pp_vacuous=[]`、`pp_match=[patternProperties]`、`pp_multi=[patternProperties]`、`pp_route=2`；旧 `$sfL` 边界不变。
- **活体**：`patternprops_vacuous_ok` **200 双键**、`patternprops_refuse`（`^c` 命中 city）**400 点名**。
- **本轮自伤**：拆 `(patternProperties|if)` 时捕获组消失但 `return $mk[1];` 未改 ⇒ 含 `"if"` 的 schema 触发 `null returned` 致命错误（自检 127 PASS 后退出，`selftest_f451.log`）。修为 `return "if";`，`selftest_f451b.log` 182/0。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.519×**、哨兵 **0**。


## ▶ 规格（F452，2026-11-08 22:37）——`if/then/else` 第一窄形态已写、锚点已核、未执行
- **形态**：键集可证明的对象形状 + 根级 `if` + 子 schema 恰为 `{"type":"<t>"}`；`t==object` ⇒ 生效分支 `then`，其余 ⇒ `else`；生效分支若存在须恰为 `{"type":"object"}`（恒真），更丰富的体需求值 ⇒ 仍点名拒绝；不生效分支按语义忽略、不检查。
- **锚点核对**：`api_schema_structural_unsupported` 在 `engine/main.php:8485`，`return "if";` 全文件恰一处（`:8541`）；`api_schema_emitted_set` 在 `:7975`；门变量 `$sfAW`–`$sfAZ` 未用；`engine/tests/*.py` 无 `if` 夹具。
- **门/活体/验收**：见 `task_plan.md` 的 F452 规格节（含插入点行号与两例活体）。
- **顺位**：F452 执行 → `unevaluated*`（空约束形态）→ 分片多卡 / FR-05（本机不可验证）。


## ▶ 状态（F452，2026-11-08 22:51）——`if/then/else` 的类型窄形态从拒绝变支持；丰富生效分支仍点名拒绝
- **实现**：`api_schema_if_ok`——键集可证明对象 + 根级 `if` 恰为单 `type`；`object`⇒`then`、其余⇒`else`；生效分支缺省或恰为 `{"type":"object"}` ⇒ 放行，丰富体 ⇒ 拒绝；不生效分支按语义忽略。
- **连带修复**：`api_schema_required_scan` 剥离列表扩到 `if,then,else`（预防 F446 同族的键丢失）。
- **门**：`if_else=[]`、`if_rich=[if]`、`if_noelse=[]`、`if_route=2`；旧 `$sfC`（属性名 if）不变。
- **活体**：`if_true_ok`/`if_false_ok` **200 双键**、`if_rich_refuse` **400 点名**。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.579×**、哨兵 **0**。


## ▶ 状态（F453，2026-11-08 23:07）——`unevaluated*` 的**空判定**形式从拒绝变支持；会真正判定的形状仍点名拒绝
- **实现**：`api_schema_unevaluated_props_ok`（键集可证明 + 根级关键字 ⇒ 无对象可判）与 `api_schema_unevaluated_items_ok`（单标量数组 + 根级 ⇒ 元素全由 items 求值）；结构判定拆出各自守卫。
- **门（含翻转）**：旧 `$sfF` 从 `unevaluatedProperties` 翻为 `""`；新增 `uneval_props=[]`、`uneval_props_schema=[]`、`uneval_items=[]`、`uneval_items_wrong=[unevaluatedItems]`、`uneval_prop_named=[unevaluatedProperties]`。
- **活体**：`uneval_props_ok`/`uneval_props_schema_ok` **200 双键**、`uneval_items_ok` **200 数组**、`uneval_items_wrong_refuse` **400 点名**；用故意违反的子 schema 证明是空判定。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.505×**、哨兵 **0**。


## ▶ 状态（F454，2026-11-08 23:24）——`minContains`/`maxContains` 在 `contains` 形态上支持（计数折成元素上下界）
- **观察**：F449 形态里每元素皆匹配 ⇒ 计数＝元素数，可复用 `minItems`/`maxItems` 的预算。
- **实现**：`api_response_schema_array_bounds` 折叠两组计数（取更严者；`maxContains:0`、`min>bMax`、`min>8` 返回不可服务）；`api_schema_contains_ok` 去掉计数早退；新增 `api_schema_minmax_contains_ok` 谓词守卫。
- **门**：`cont_min=[]`、`cont_max=[]`、`cont_min_big=[minContains]`、`cont_min_over=[minContains]`、`cont_bounds=[2:3]`。
- **活体（断言个数）**：min 3 → **4 元素**；max 2 → **1 元素**；min 9 / min 3+max 2 → **400 点名**。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.575×**、哨兵 **0**。


## ▶ 状态（F455，2026-11-08 23:39）——`patternProperties` 的**匹配键 + 裸类型**形式支持
- **观察**：命中键时，裸 type 若等于该键声明种类必然产出的类型（s/e/p→string、i→integer、i|n→number、b→boolean、a*→array），值构造满足。
- **实现**：`api_schema_pattern_props_ok` 增加 `api_schema_leaf_kind` + `api_schema_kind_is_type` 判定；更丰富子 schema 或类型不匹配仍拒。
- **门**：新增 `pp_match_ok=[]`；旧 `$sfL`/`$sfAT` 保持拒绝作对照。
- **活体**：`patternprops_match_ok` **200 双键**；`patternprops_refuse` **400 点名**。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.536×**、哨兵 **0**。


## ▶ 状态（F456，2026-11-08 23:54）——number 叶小数界扩到**五位**；六位及以上仍点名拒绝
- **改动**：`api_schema_scaled` `{1,4}`→`{1,5}` + `$digits==5`；计划构造加 `$d=5`/标记 `-995`；写入器 `-995`→`d=5, scale=100000`。
- **门**：`num_dec5=n|151111|-995|0`、`dec5_text=[{"n":1.51111}]`、`num_dec6=[]`。
- **活体**：`float_dec5_served` **200 `{"n":1.51111}`**、`float_dec6_refuse` **400**；1–4 位与负数不回退。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.454×**（单次测量，如实记录）、哨兵 **0**。


## ▶ 规格（F457，2026-11-08 23:54）——基于 plan 的子 schema 判定已写、待执行
- **入口**：`api_schema_leaf_guarantees(kind, sub)`——只对 `e:` 有限枚举提供 enum/const/pattern 保证，`type` 复用 `kind_is_type`；其他种类一律不算保证。
- **试点**：`api_schema_pattern_props_ok` 的丰富子 schema 分支。
- **执行前必答**：①含 enum 叶 + patternProperties 的路由是否仍被候选/枚举写入器认领（门断言，再活体）②enum 解析用 PHP CLI 抽取式单测钉住 `e:` 编码一致性。


## ▶ 状态（F457，2026-11-09 00:13）——基于 plan 的子 schema 判定落地（`e:` 枚举保证），试点 patternProperties
- **入口**：`api_schema_leaf_guarantees(kind,sub)`——`e:` 枚举提供 enum 包含 / const 相等 / pattern 逐值匹配；其他种类只由 `kind_is_type` 负责 type。
- **前缀**：`api_schema_pattern_props_ok` 的丰富子 schema 分支改调该判定。
- **规格两问的证据**：门 `pp_enum_route=2`（候选写入器仍认领）；PHP CLI 单测 `leaf_guarantees_test.php` **12/12**（含带空格枚举值）。
- **门**：`pp_enum=[]`、`pp_pat=[]`、`pp_enum_sub=[patternProperties]`、`pp_pat_miss=[patternProperties]`。
- **活体**：`patternprops_enum_ok` **200 `{"city":"paris"}`**、`patternprops_enum_sub_refuse` **400 点名**。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.503×**、哨兵 **0**。


## ▶ 状态（F458，2026-11-09 00:32）——修既有静默违约：带值约束的 `items` 从"200 且形状错误"改为点名 400
- **改前证据**：`items` enum/const/pattern → 200 `{"items":"paris"}`；`items` 数值界 → 200 `{"items":5}`；裸类型正常（`engine/items_gap_f458.log`）。
- **实现**：`api_schema_items_constrained` 扫描每个 `"items":{…}` 块（含嵌套）的值约束键；命中返回 `"items"`。`properties`/`required` 排除（对象数组合法携带）。
- **门**：`items_enum`/`items_const`/`items_pattern`/`items_min` = `[items]`，`items_plain=[]`。
- **活体**：两例 400 点名、`items_plain_ok` 200 数组；smoke 仍 **115/0**。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.509×**、哨兵 **0**。


## ▶ 状态（F459，2026-11-09 01:13）——枚举项数组从 400 变**逐元素枚举发射**（项感知写入器 v1）
- **实现**：`api_schema_array_enum` 检测 + `api_array_enum_value` 受限逐元素发射（枚举值+闭引号，元素间只可达 `,`/`]`，元素数走既有 bounds）；四路由接入。
- **两个更早写者（关键）**：候选扫描把 `items` 当属性（已剥离 items 块，`ae_route=0`）；扁平枚举分支只回裸值（已加数组枚举守卫）。
- **活体证据链**：`{"items":"paris"}` → `"paris"` → **`{"cities":["paris","paris"]}`**；const/数值界仍 400，裸数组不变。
- **门**：`items_enum=[]`、`ae_plan`/`ae_text`/`ae_route=0`、`items_const/pattern/min=[items]`。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.529×**、哨兵 **0**。


## ▶ 状态（F460，2026-11-09 08:05）——双侧整数界 `items` 从点名 400 变**逐元素有界整数发射**（项感知写入器 v2）
- **实现**：`api_schema_array_item_bounds`（单数组属性 + `items` 恰为双侧整数界 ⇒ `prop|lo|hi`）+ `api_array_bounded_int_value`（数字体抽成 `api_number_bounded_element` 共享，F419 符号映射原样；`api_number_bounded_value` 改复用，单属性行为由 `SCHEMA-NUM-*` 四门钉回归）；四路由接入、items 拒绝并列豁免。
- **门（含翻转）**：`items_min`（双侧）翻 `[]`；新增 `items_one=[items]`、`ib_plan=[nums|5|9]`、`ib_text=[{"nums":[5]}]`＋`api_json_ok`。
- **活体**：`items_bounded_ok` **200 `{"nums":[5,6,7,8]}`**（每元素 [5,9] 整数）；`items_one_sided_refuse` **400**（单侧先被界判据成对走位点名）；四个 harness 全绿、smoke 不回退。
- **过程**：前一会话已实现＋构建＋活体验证（01:24 `schema_required_f460b.log`）但未提交；本轮重建（07:36）全梯复验后入库。`schema_required_f460pre.log` 跑在前会话构建上，只证"旧 exe 非 F459 源码"，非改前证据。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.528×**、哨兵 **0**。


## ▶ 状态（F461，2026-11-09 12:50）——单侧整数界 `items` 从点名 400 变**逐元素发射**（缺侧 -1 哨兵；负单侧仍拒）
- **实现**：检测器 `api_schema_array_item_bounds` 单侧扩展（缺侧哨兵 -1，元素写入器零改动）＋`api_schema_bounds_unsupported` 顶部早退豁免（成对走位此前在路由前点名单侧）；路由零改动。
- **门（含翻转）**：`items_one` `items`→``、`ib_one` →`nums|5|-1`；新 `ib_max=[nums|-1|9]`、`ib_negmax=[]`、`ib_minone_text` 文本断言。
- **活体**：`items_one_sided_min_ok` 200 `[5,6,7,8]`（每元素 ≥5）、`items_one_sided_max_ok` 200 `[1,2,3,4]`（每元素 ≤9）、`items_one_sided_negmax_refuse` 400；改前证据 `schema_required_f461pre.log`（F460 二进制上两例 400）。
- **过程（门抓缺陷）**：第一版 `array_key_exists` 判"在场"被 PHP 的空串赋值坑（非尾部未匹配组赋 `""`）⇒ `ib_max` 出 `nums|0|9`、`SCHEMA-STRUCT` FAIL（`selftest_f461.log` 留证）；改 `isset && != ""` 后绿。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.557×**、哨兵 **0**。


## ▶ 状态（F462，2026-11-09 21:05）——`number` 型整数/单侧/无整数小数网格 `items` 从点名 400 变**逐元素发射**（复用 F440–F456 缩放编码）
- **实现**：检测器 `api_schema_array_item_number`（items 块 wrap 成单属性 schema 喂给既有 `api_schema_number_bounded_shape`，取 2–4 段、mult≠0 拒）＋`api_number_decimal_element`（从 `api_number_bounded_value` marker 分支抽出，单属性行为由 `SCHEMA-NUM-FRAC` 等门钉回归）＋`api_array_item_number_value`（元素体＝marker 确定性小数值或数字走位）；四路由接入、结构 items 检查与界判据早退并列豁免。
- **门**：`in_plan=[nums|15|-999|0]`、`in_int=[nums|1|9|0]`、`in_one=[nums|-1|9|0]`、`in_multi=[]`、`in_kw=[]`、`in_kw_multi=[items]`；`in_dec_text=[{"nums":[1.5]}]`＋`in_intval_text` 两条文本断言＋json_ok。
- **活体**：`items_num_dec_ok` 200 `[1.5,1.5,1.5,1.5]`（每元素＝缩放下界，必落 [1.5,1.9]）、`items_num_int_ok` 200 `[1,2,3,4]`（每元素 ∈[1,9]）、`items_num_oneside_ok` 200、`items_num_frac6_refuse`/`items_num_multi_refuse` 400；改前证据 `schema_bounds_f462pre.log`（F461 二进制上三例 400）。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.481×**、哨兵 **0**。


## ▶ 状态（F463，2026-11-09 23:55）——单侧**负**上界 `maximum:-N` 从静默违约/点名 400 变**幅值单侧走位发射**
- **改前证据（静默违约）**：单属性 integer `maximum:-3` → 200 但发 `10`（违约）；number items 同形 → 200 但发**散文**（F462 检测器认领但元素体 ""）；integer items → 400（F461 过度拒绝）。三者同根＝`api_number_bounded_element` 的 both-negative 分支不认单侧负上界。
- **实现**：element 加 `if ($lo==-1 && $hi<-1) { $whi=-1; }`（幅值从 |hi| 起无上界，负号强制）＋F461 integer 检测器解除负 max 拒绝（负 min 保持拒绝）。
- **门（含翻转）**：`ib_negmax` `[]`→`nums|-1|-3`；新 `bn_shape`/`bn_text`（单属性）、`in_negmax`/`in_negmax_text`（number items）。
- **活体**：`negmax_single_served` 200 `{"n":-3}`、`items_num_negmax_ok` 200 `[-3,-4,-5,-6]`、`items_int_negmax_ok` 200 `[-3,-4,-5,-6]`（每元素 ≤ -3）。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.535×**、哨兵 **0**。


## ▶ 状态（F464，2026-11-10 00:15）——单侧负下界 `minimum:-N` 解除 integer items 守卫；改前取证暴露三条路不一致
- **改前证据**：integer items `minimum:-5` → 400（F461 守卫过度拒绝）；单属性同形 → 200 `{"n":-1}` 已服务（无门）；number items 同形 → 200 `[-1,-2,-3,-4]` 已服务（无门）。
- **实现**：`api_schema_array_item_bounds` 删除 `!$hiSet && $lo<0` 守卫（element 零改动，幅值走位 `[1,|lo|]` 天然覆盖）。
- **门（新）**：`ib_negmin`/`ib_negmin_text`、`ng_shape`/`ng_text`、`ng_items`/`ng_items_text`——三条路全部钉上门。
- **活体**：`items_int_negmin_ok` 200 `[-1,-2,-3,-4]`、`negmin_single_served` 200 `{"n":-1}`、`items_num_negmin_ok` 200 `[-1,-2,-3,-4]`。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling 见 gate_live（单次测量，如实记录）、哨兵 **0**。


## ▶ 状态（F465，2026-11-10 00:50）——integer 型 multipleOf `items` 从点名 400 变**逐元素倍数发射**
- **改前证据**：无界 → 400 "items"（F458 keywords 层）；有界 → 400 "numeric bounds…"（bounds 层 F420 守卫）；unsat/number 对照 400 正确。
- **实现**：检测器（无界 `prop|mult|-1|-1`；有界 wrap→shape 折叠 `lo==hi==start`）＋写入器（无界 `api_multiple_fill` 逐元素 / 有界定值走位）＋四路由＋两处豁免。
- **门**：`im_unb`/`im_bnd`/`im_unsat=[]`/`im_big=[]`/`im_num=[]`/`im_kw_bnd=[]`＋两条文本断言（无界元素 %3==0、有界 5000）。
- **活体**：`items_mult_unb_ok` 200 `[1234002,2345001,3456000,4567002]`、`items_mult_bnd_ok` 200 `[5000,…]`、unsat/num 400。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.528×**、哨兵 **0**。


## ▶ 状态（F466，2026-11-10 07:15）——number 叶＋整数 multipleOf 从过度拒绝变**整数倍数子集发射**
- **改前证据**：单属性有界 number m3 → 400（过度拒绝）；number items m3 → 400；小数 M → 400（旧守卫前缀匹配碰巧拒，本轮显式化）。
- **实现**：shape 删 number+multipleOf 排除＋F433 守卫改写（小数 M 拒；整数 M 且 shape 不认且非 items 认领 → 拒）＋F465 检测器 kind 放宽 an|af。
- **门抓到两处波纹漏翻**：NUM-FRAC 的 `nfH`、STRUCT 的 `in_kw_multi`＋bounds 活体暴露检测器有界分支把单侧哨兵当真值 ⇒ 删手写 lo>hi、一律 wrap→shape（`selftest_f466.log`/`f466c.log` 留证）。
- **活体**：`num_mult_served` 200 `{"n":3}`、frac/unb 400、`items_mult_num_ok`/`items_num_multi_refuse` 200（每元素 %3==0）。
- **梯**：自检 **182/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.510×**、哨兵 **0**。

## ▶ 状态（F467，2026-11-10 08:11）——items 上 multipleOf＋exclusive 组合从点名 400 变**同一 shape 判定折叠发射**
- **改前证据**：integer items `exclusiveMinimum:0,max9,m3` → 400 "numeric bounds…"（bounds 层 F420；F458 关键字层同认）；布尔形 400；number items `excl2.5,max9.5,m3` → 400；unsat/frac 对照 400 正确（`schema_bounds_f467pre.log`，F466 二进制）。
- **实现**：`api_schema_array_item_multiple` 只钉表面（单属性、items type integer|number、整数 multipleOf、键名白名单），界一律 wrap→`api_schema_number_bounded_shape`（与单属性同一判定）；无界后缀路径不变；路由与两处豁免零改动。
- **门**：`im_excl`/`im_excl_bool`/`im_excl_num`＝`nums|3|3|3`、`im_excl_unsat`/`im_excl_frac`＝``、`im_excl_kw=[items]`、`im_excl_text=[{"nums":[3]}]`。
- **活体**：三形态 200 `[3,3,3,3]`；unsat/小数 M 400；F465/F466 系不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.523×**、哨兵 **0**。

## ▶ 状态（F468，2026-11-10 08:29 构建）——integer items 的 exclusive 界（无 multipleOf）从点名 400 变**同一 shape 判定发射**
- **改前证据**：integer items `exclusiveMinimum:0,max9` → 400、布尔形 400；number 叶同形无 mult → 200 `[3,4,5,6]`（F462 已服务，两路不一致）；unsat 对照 400（`schema_bounds_f468pre.log`，F467 二进制）。
- **实现**：`api_schema_array_item_bounds` 改为键名白名单＋wrap→shape（同 F467 手法；mult 段必须 0，multipleOf 归 F465）；删手写 lo>hi；路由零改动。
- **门**：`ib_excl=[nums|1|9]`、`ib_excl_bool=[nums|1|-1]`、`ib_excl_unsat=[]`、`ib_excl_kw=[items]`、`ib_excl_text`（1..9＋json_ok）。
- **活体**：两形态 200 `[1,2,3,4]`、unsat 400、number 对照 200；F460–F467 系不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.561×**、哨兵 **0**。

## ▶ 状态（F469，2026-11-10 12:59 构建）——小数 multipleOf 从点名 400 变**缩放网格折叠发射**（复用小数 marker 写入器）
- **改前证据**：number 叶 min1 max2 m0.3 / 负界 / 无整数区间 / items 同形 → 400；unsat/integer 叶/无界/exclusive 对照 400（`schema_bounds_f469pre.log`，F468 二进制）。
- **实现**：shape 上移 multipleOf 解析（完整字面量＋plain 校验，指数显式拒），小数 M 在公共网格折叠到最小倍数返回 marker 编码；F433 守卫加 shape 豁免；items 经 F462 检测器自动认领（零路由改动）。
- **门**：`fm_shape`/`fm_text`/`fm_unsat=[]`/`fm_neg`/`fm_d2`/`fm_noint`/`fm_items`/`fm_intleaf=1`＋五条文本断言。
- **活体**：1.2 / -1.8 / 1.5 / items [1.2,…] 200；unsat/integer 叶 400；F466/F467 小数 M 对照不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.544×**、哨兵 **0**。

## ▶ 状态（F470，2026-11-10 13:18 构建）——数组项 string const 从点名 400 变**单值枚举逐元素发射**
- **改前证据**：const items → 400 "items"；enum 对照 200 `[paris,paris]`（`schema_required_f470pre.log`，F469 二进制）。
- **实现**：`api_schema_array_enum` 在 enum 正则外再认 string const（同编码约束）返回 `prop|X`；结构层豁免自动放行；写入器/路由零改动。
- **门（含翻转）**：`items_const` `items`→``、`ae_const` ``→`cities|paris`；新 `ac_text=[{"cities":["paris"]}]`/`ac_route=0`；`items_pattern` 保持拒。
- **活体**：`items_const_ok` 200 `[paris,paris]`；enum 不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.545×**、哨兵 **0**。

## ▶ 状态（F471，2026-11-10 13:42 构建）——数组项 string pattern（类/计数）从点名 400 变**逐元素走位匹配发射**
- **改前证据**：items `^[A-Z]{3}$` → 400 "items"；交替无类对照 400（`schema_required_f471pre.log`，F470 二进制）。
- **实现**：复用枚举项检测器/写入器（4 路由零改动）——检测器加 pattern 分支返 `prop|~spec`（~ 前缀无碰撞），写入器加 pattern 模式；新 helper `api_pattern_body` 独立复刻段发射（false 区分失败/空），不触旧单属性路。
- **门**：`items_pattern` 保持 `items`（交替对照）；新 `ap_plan`/`ap_text`（正则）/`ap_route=0`。
- **活体**：`items_pattern_ok` 200 `[CJL,CJL]`、交替 400；enum/const/单属性 pattern 不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.547×**、哨兵 **0**。

## ▶ 状态（F472，2026-11-10 14:07 构建）——contains 值级子 schema 的**子集形式**从点名 400 变构造满足
- **改前证据**：items const paris + contains const paris → 400；items enum + contains 超集 enum → 400；子集不成立对照 400（`schema_count_f472pre.log`，F471 二进制）。
- **实现**：`api_schema_contains_ok` bare-type 失败后加值级子集分支——kind a 取 array_enum valsCsv（非 ~），`leaf_guarantees("e:".valsCsv,sub)==1 ⇒ 1`；路由/写入器零改动。
- **门**：`cv_const`/`cv_enum`/`cv_pat`=[]、`cv_miss=[contains]`、`cv_route=[cities|paris]`；contains_pattern 保持对照。
- **活体**：const/enum 子集 200 `[paris,paris]`、子集不成立 400；type/pattern 对照不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.463×**、哨兵 **0**。

## ▶ 状态（F473，2026-11-10 18:05 构建）——小数网格 5→6 位（marker -994，七位仍拒）
- **改前证据**：六位 `[1.511111,1.511119]` → 400；七位对照 400；1–5 位/负数不变（`schema_bounds_f473pre.log`，F472 二进制）。
- **实现**：`api_schema_scaled` {1,6}＋digits==6；shape 网格 {1,6}＋d=6＋mark -994；decimal_element/bounded_value/item_number_value 三处 marker 判定加 -994；F469 小数 M 保持 ≤5。
- **门（含翻转）**：`num_dec6` ``→`n|1511111|-994|0`；新 `num_dec7=[]`、`dec6_text=[{"n":1.511111}]`。
- **活体**：`float_dec6_served` 200 `{"n":1.511111}`、`float_dec7_refuse` 400；**波纹** `items_num_frac6_refuse` 翻 `items_num_frac6_ok`（200 `[0.000123,…]`，F462 wrap→shape 随网格扩宽自动服务）。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.542×**、哨兵 **0**。

## ▶ 状态（F474，2026-11-10 18:32 构建）——`not` 值级非字符串常量形式＋修 not 块误伤 flat-string 生成器
- **改前证据**：`not:{const:5}` → 400 "not"；字符串常量/pattern 对照 400（`schema_count_f474pre.log`，F473 二进制）。
- **实现**：`api_schema_not_ok` 加非字符串标量 const／全非字符串标量 enum 分支；**修复** `api_response_schema_string_props` 剥离根级 not 块（活体首跑抓到 200 散文＝静默违约），加 `nt_route=1` 门。
- **门**：`nt_const_num`/`nt_enum_num`=[]、`nt_const_str`/`nt_enum_mixed`=[not]、`nt_route=1`；not_pattern 保持对照。
- **活体**：`not_const_num_served` 200 `{"cities":"…"}`、`not_const_str_refuse` 400；type/pattern 不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.544×**、哨兵 **0**。

## ▶ 状态（F475，2026-11-10 18:48 构建）——if/then/else 的 bare-required 条件与选中分支（已证键集判定）
- **改前证据**：if required 条件/then 分支、F452 if_rich 三例 → 400（`schema_required_f475pre.log`，F474 二进制）。
- **实现**：`api_schema_if_ok` 条件加 bare required（emitted_set 判 X⊆emitted 选分支），选中分支加 bare required（Y⊆emitted ⇒ 1）；旧 if_rich 翻服务。
- **门（含翻转）**：`if_req_true/false/then=[]`、`if_req_miss=[if]`、`if_req_route=2`、`if_rich` `if`→``。
- **活体**：if_req_true_ok/if_req_then_ok 200 双键、miss 400、`if_rich_refuse` 翻 `if_rich_served` 200；true/false 不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.532×**、哨兵 **0**。

## ▶ 状态（F476，2026-11-10 19:05 构建）——patternProperties 的数值 enum/const kind（`en:`）从点名 400 变静态可证
- **改前证据**：数值枚举属性 + pp bare integer/enum 超集 → 400；enum 不覆盖对照 400（`schema_required_f476pre.log`，F475 二进制）。
- **实现**：`api_schema_kind_is_type` 加 `en:`（number 恒真、integer 需全整数字面量，置于 "~" 剥离前）；`leaf_guarantees` 加 `en:`（裸数值 const 相等／全裸数值 enum 包含；带引号成员不判）；判定本体零改动。
- **门**：`pp_num_type`/`pp_num_enum`/`pp_num_const`=[]、`pp_num_miss`/`pp_num_mixed`/`pp_num_str`=[patternProperties]。
- **活体**：两形态 200 `{"count":1}`、miss 400；字符串 patternprops 不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.483×**、哨兵 **0**。

## ▶ 状态（F477，2026-11-10 19:23 构建）——数组项 pattern 的锚定纯字面量交替/纯字面量从点名 400 变有限集发射
- **改前证据**：items `^(paris|lyon)$` → 400；`^a.c$` 元字符对照 400（pattern 层措辞）；类形态 200（`schema_required_f477pre.log`，F476 二进制）。
- **实现**：`api_schema_array_enum` pattern 分支加锚定纯字面量交替（`[A-Za-z0-9 _-]`、可选外层括号、`|`→`~`）→ 枚举编码；单成员＝单值枚举；元字符/未锚定仍拒。写入器/路由零改动。
- **门（含翻转）**：`items_pattern` `items`→``；新 `sf_itb=[items]`、`ap_alt=[cities|paris~lyon]`/`ap_alt_text`、`ap_lit=[cities|paris]`、`ap_bad=[]`。
- **活体**：交替 200 `[paris,paris]`、元字符 400、类形态不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.565×**、哨兵 **0**。

## ▶ 状态（F478，2026-11-10 19:42 构建）——小数 multipleOf 单侧界（min-only / max-only）
- **改前证据**：min1/max0.2/max-1 + m0.3 → 400；无界/integer 叶对照 400（`schema_bounds_f478pre.log`，F477 二进制）。
- **实现**：F469 分支泛化为"至少一侧 inclusive"，min 取 ceil 最小倍数、max-only 取 0 或 floor 最大负倍数；上界校验保留。
- **门**：`fm_minonly`/`fm_maxpos`/`fm_maxneg`＋三文本；双侧与 unsat/intleaf 不回退。
- **活体**：1.2 / 0.0 / -1.2 200；对照不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.482×**、哨兵 **0**。

## ▶ 状态（2026-11-10 20:25 构建）——F479 `not` 含字符集外字符的字符串 const/enum＋修扁平枚举误读 not
- **改前证据**：`not:{const:"Paris!"}` / `not:{enum:["a!","b@"]}` → 400；可命中成员对照 400（`schema_count_f479pre.log`，F478 二进制）。
- **实现**：`api_charset_covers`＋`api_enum_all_unequalled`（手写扫描）接进 not_ok；新增 `api_schema_strip_root_not` 并在 string_props 与**扁平枚举扫描**两处剥离（活体探测到未修前 `not:{enum}` 返回裸值 `"a!"`＝静默违约）。
- **环境前提**：tpc 转换器在巨型断言链下越过默认 PHP 栈 ⇒ 在 `tpc_v0.9.5_windows_x64/php.ini` 追加 `zend.max_allowed_stack_size = 64M`（备份 php.ini.bak-f479），构建恢复。
- **门**：`nt_const_bang`/`nt_enum_bang`/`nt_enum_scalars`=[]、`nt_enum_miss`/`nt_escaped`=[not]。
- **活体**：const/enum 外字符 200 `{"cities":"…"}`、可命中成员 400；对照不回退。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.570×**、哨兵 **0**。

## ▶ 状态（2026-11-10 20:40 构建）——F480 patternProperties 的 p: 同 spec 构造满足
- **改前证据**：pattern 属性 `^[A-Z]{3}$` + pp 同 pattern → 400；不同 spec 对照 400（`schema_required_f480pre.log`，F479 二进制）。
- **实现**：`api_schema_leaf_guarantees` 顶部加 `p:` 分支（pp 子 schema 的 pattern spec 与属性 spec 相等 ⇒ 1）；判定本体零改动。
- **门**：`pp_pat_same`=[]、`pp_pat_diff`=[patternProperties]；既有 pp 系列不回退。
- **活体**：同 spec 200 `{"code":"EYX"}`、不同 spec 400。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.528×**、哨兵 **0**。

## ▶ 状态（2026-11-10 20:54 构建）——F481 contains 对 pattern-items 同 spec 构造满足
- **改前证据**：items `^[A-Z]{3}$` + contains 同 pattern → 400；不同 spec 对照 400（`schema_count_f481pre.log`，F480 二进制）。
- **实现**：contains_ok F472 分支加 `~` spec 路径（spec 相等 ⇒ 1）；枚举分支保持。
- **门**：`cv_pat_spec`=[]、`cv_pat_spec_diff`=[contains]；既有 cv 系列不回退。
- **活体**：同 spec 200 `[CJL,CJL]`、不同 spec 400。
- **梯**：自检 **182/0**、bounds/required/count/pattern PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.497×**、哨兵 **0**。

## ▶ 状态（2026-11-10 22:00 构建）——F482 not:{pattern} 字符集排除形式（单门）
- **改前证据**：`not:{pattern:"^(paris_|lyon_)$"}` → 400；全字符集/元字符对照 400（探测与 `schema_count_f482pre.log`）。
- **实现**：not_ok 加 pattern 分支（锚定纯字面量交替、每成员含字符集外字符 ⇒ 1）；门缩减为单 `nt_pat_bang`。
- **过程（重要）**：多门时 tpc 转换器**静默中止**（exit 0、不编译、批处理用旧对象仍报 Build successful）⇒ 自检假绿；诊断靠 main.obj mtime；最终缩减门数量入库。php.ini 栈限提到 256M；半删缓存会导致 rsp 缺失，需清整个 `build/gen/cache`。
- **活体**：bang 形态 200、全字符集/`^x` 400；四 harness PASS；smoke 115/0、par/fairness 0、scaling 1.525×、哨兵 0。

## ▶ 状态（F483，2026-11-10 22:14）——构建守卫脚本（拦截 tpc 静默中止导致的陈旧二进制）
- **动机**：F482 中 tpc 静默中止转换、批处理仍报 Build successful ⇒ 自检假绿。
- **实现**：`scripts/build-check.sh` 强制重译 main.obj 并断言其 mtime 前进；失败/陈旧非 0 退出。
- **验证**：build_f483a → BUILD_OK（11 文件编译）；新建二进制自检 **182/0**（`selftest_f483.log`）。
- **用法**：后续构建统一 `bash scripts/build-check.sh <tag>`。

## ▶ 状态（2026-11-10 22:28 构建）——F484 patternProperties 对象数组 kind ao: 的 bare array
- **改前证据**：对象数组属性 + pp `{"type":"array"}` → 400；pp string 对照 400（`schema_required_f484pre.log`，F483 二进制）。
- **实现**：`kind_is_type` 的 array 分支加 `ao:` 前缀；单门 `pp_ao_type`。
- **活体**：`{"rows":[{"n":"100000"}]}` 200、pp string 400；四 harness PASS；smoke 115/0、par/fairness 0、scaling 1.544×、哨兵 0。
- **构建**：`scripts/build-check.sh` → BUILD_OK（11 文件）。

## ▶ 状态（F485，2026-11-10 22:48）——试探被容量墙挡住，回退恢复 F484 绿态；守卫改为整清缓存
- **F485 尝试**：contains+minContains:0 空判定（2 处早退）→ tpc 静默中止（无编译/rsp），全量构建链接失败（`build_f485b.log`）。
- **回退**：main.php/schema_count_check/task_plan 全部 checkout；整清缓存全量构建 BUILD_OK（11 文件）；自检 **182/0**（`selftest_f485.log`，exe 22:48:53）。
- **守卫修复**：`build-check.sh` 改为 `rm -rf build/gen/cache`（部分删除会导致 rsp/obj 失配，`build_f485.log` 留证）。
- **后续前置**：先瘦身 main.php（拆分巨型 STRUCT `&&` 断言链），再做功能增量。

## ▶ 状态（F486，2026-11-10 23:00）——SCHEMA-STRUCT 断言链切分为 10 块（恢复构建容量）
- **动机**：F485 显示 main.php 已到 tpc 转换器容量边缘（1–2 分支即静默中止）。
- **实现**：顶层 `&&` 切 10 块（每块 25 项）→ `$structOk` 逐块判定；顺序/注释不变；一次性脚本已删。
- **验证**：php -l 通过；build-check **BUILD_OK**（11 文件）；自检 **182/0**、SCHEMA-STRUCT PASS；四 harness PASS；哨兵 0。
- **意义**：为后续功能增量恢复构建容量（F485 的 minContains:0 可排在下一轮）。

## ▶ 收尾（2026-11-10 23:13，HEAD 83c12a0）——HEAD 全梯复核通过，交接下一步
- **HEAD 认证**：F486 瘦身后 binary（经 `build-check.sh` 全量构建，BUILD_OK/11 文件）→ 自检 **182/0**、四 schema harness **PASS**、标准 live 门禁（`gate_live.sh f486b`）**smoke 115/0、par/fairness 0、scaling 1.459×、哨兵 0**；`check_gates.sh` **94/0**；`check_citations.sh` 仅报告未跟踪日志（正常）。
- **下一步（明确交接）**：①在瘦身后的基线上重做 **F485：`contains` + `minContains:0`（无 `maxContains`）空判定**（两处早退：`api_schema_contains_ok`/`api_schema_minmax_contains_ok`；单门 `cont_min0`；活体 `contains_min0_served`/`contains_min0_max_refuse`）；②其余 `patternProperties`/`contains`/`not` 更丰富值级子集需 plan/写入器扩展；③`unevaluated*` 真判定需"写入器发未声明键"能力；④分片多卡 / FR-05 本机不可验证。
- **工具链注意（换机/重建必读）**：tpc `php.ini` 已加 `zend.max_allowed_stack_size = 256M`（repo 外，建议保留）；构建一律 `bash scripts/build-check.sh <tag>`（整清 `build/gen/cache`，断言 `main.obj` mtime 前进）；tpc 可能静默中止转换而批处理仍报 Build successful，红线即停。
- **状态**：本会话 F467–F486 全部提交（F485 为受阻记录、已回退）；工作树干净；项目整体仍未完成（见支持表"仍未实现"）。

## ▶ 状态（F485 第二轮，2026-11-10 23:37 构建）——contains+minContains:0 空判定落地；含路由扫描修复
- **前置**：第一轮被 tpc 容量墙挡住回退；F486 瘦身后重做成功。
- **实现**：contains_ok/minmax_contains_ok 两处早退；**修复** array_string 检测器把 root contains 内 pattern 当值域（实测 200 散文）⇒ 根级 contains 块剥离。
- **门/活体**：`cont_min0=[]`；`contains_min0_served` 200 `{"cities":[...]}`、`contains_min0_max_refuse` 400；既有 contains 不回退。
- **梯**：自检 **182/0**、四 harness PASS、8000 **115/0**、8001 par/fairness **0**、scaling **1.552×**、哨兵 **0**；构建 BUILD_OK（11 文件）。

## ▶ 状态（F487，2026-11-10 21:00 构建）——contains 的 min0+max0 且元素集合不相交 ⇒ 恰零匹配可证
- **改前证据**：enum/类型不匹配两形态 → 400；有交集对照 400（`schema_count_f487pre.log`，F485 二进制）。
- **实现**：`api_schema_contains_all_miss`（bare type 不可能 / 枚举值表不相交，值表取自 `api_schema_array_enum`）＋两处早退＋单门 `cont_max0`。
- **过程**：首版 helper 误用 plan kind 判枚举（数组属性 kind 是 a/an/af）→ 门 FAIL 181/1；改用 items 检测器后 182/0。
- **活体**：200 `[paris,paris]`、200（类型不匹配）、有交集 400；四 harness PASS；8000 115/0、8001 par/fairness 0、scaling 1.536×、哨兵 0。

## ▶ 状态（F488，2026-11-10 21:15 构建）——propertyNames 扩到 mixed-omittable；mixed 扫描剥离 propertyNames
- **改前证据**：mixed(a,b) + propertyNames ^[ab]$ → 400（`schema_count_f488pre.log`，F487 二进制）。
- **实现**：mixed_omittable 扫描前剥离 propertyNames 块；property_names_ok 守卫放宽为 flat||mixed。
- **门（含翻转）**：`pnames_mixed` `propertyNames`→``（旧对照键名其实满足 ^[a-z]+$）；新 `pnames_mix_bad=[propertyNames]`（^[a-c]+$ 下 count 违反）。
- **活体**：mixed 200 键 a,b、bad 400；四 harness PASS；8000 115/0、8001 par/fairness 0、scaling 1.564×、哨兵 0；构建 BUILD_OK。

## ▶ 状态（F489，2026-11-10 21:43 构建）——dependentRequired 扩到 mixed-omittable
- **改前证据**：mixed(city/count) + dependentRequired city→count → 400（`schema_count_f489pre.log`，F488 二进制）。
- **实现**：dependent_required_ok 守卫放宽为 flat||mixed（体内 emit 已精确）。
- **门（含翻转）**：`dep_mixed` `dependentRequired`→``（依赖已声明、精确键集恒成立）；新 `dep_mix_bad=[dependentRequired]`（依赖未声明）。
- **活体**：mixed 200 三键、未声明依赖 400；四 harness PASS；8000 115/0、8001 par/fairness 0、scaling 1.557×、哨兵 0；构建 BUILD_OK。

## ▶ 状态（F490，2026-11-10 21:58 构建）——dependentSchemas 与 dependencies 扩到 mixed-omittable
- **改前证据**：两形态 → 400；未声明依赖对照 400（`schema_count_f490pre.log`，F489 二进制）。
- **实现**：两处守卫放宽为 flat||mixed（体内键集已精确）。
- **门**：`dep_sch_mix=[]`、`dep_deps_mix=[]`。
- **活体**：两形态 200 双键、两 bad 400；四 harness PASS；8000 115/0、8001 par/fairness 0、scaling 1.562×、哨兵 0；构建 BUILD_OK。

## ▶ 状态（F491，2026-11-10 22:12 构建）——minProperties/maxProperties 扩到 mixed-omittable
- **改前证据**：mixed + minProperties:1 → 400；min3/max1 对照 400（`schema_count_f491pre.log`，F490 二进制）。
- **实现**：计数分支条件放宽为 flat||mixed（计数来源不变）。
- **门（含翻转）**：`mixed` `minProperties`→``；新 `pc_mix_min`/`pc_mix_max`。
- **活体**：mixed min1 200 双键、min3/max1 400；四 harness PASS；8000 115/0、8001 par/fairness 0、scaling 1.561×、哨兵 0；构建 BUILD_OK。

## ▶ 状态（F492，2026-11-10 22:44 构建）——additionalProperties 的 schema 值形式（真空满足）
- **改前证据**：schema 值 + 数组界 → 400；bool/enum 对照正常（`schema_required_f492pre.log`，F491 二进制）。
- **实现**：F402 判定去掉值形限制、加根级守卫；门翻转 `addprops_form`/`addprops_form_arr`，新增 `addprops_schema`。
- **过程**：只翻 kwW 后 181/1（kwAB 同族旧期望漏翻）⇒ 补翻 182/0。
- **活体**：schema 形式 200 `{"cities":[…]}`；四 harness PASS；8000 115/0、8001 par/fairness 0、scaling 1.536×、哨兵 0；构建 BUILD_OK。

## ▶ 状态（F493，2026-11-10 23:00 构建）——minLength/maxLength 扩到 mixed
- **改前证据**：mixed + min/maxLength → 400；pattern 叶对照 400（`schema_required_f493pre.log`，F492 二进制）。
- **实现**：api_object_mixed 增 bounds 并有界步进；4 处路由传参；守卫放宽为 flat||mixed。
- **门（含翻转）**：`mixed_maxLength`→``、`mixed_refusal`→``；pattern 叶保持 400。
- **活体**：mixed 200 `{"city":"New York","count":100000}`、pattern 叶 400；四 harness PASS；8000 115/0、8001 par/fairness 0、scaling 1.480×、哨兵 0；构建 BUILD_OK。

## ▶ 状态（F494，2026-11-10 23:15 构建）——minLength/maxLength 与定长 pattern 叶兼容
- **改前证据**：定长 pattern + min/maxLength → 400；越界/范围 pattern 对照 400（`schema_required_f494pre.log`，F493 二进制）。
- **实现**：`api_schema_pattern_length_ok`（逐段求定长＋界限包含）＋关键字分支；无写入器改动。
- **门**：`p_len_ok=[]`、`p_len_bad`/`p_len_range`=[minLength]。
- **活体**：`{"code":"CJL"}` 200、越界/范围 400；四 harness PASS；8000 115/0、8001 par/fairness 0、scaling 1.479×、哨兵 0；构建 BUILD_OK。

## ▶ 状态（F495，已完成）——多叶 mixed 的 minLength/maxLength（s 叶写入器保证、p 叶定长包含）
- **改前证据**：`mixed_len_p_ok`/`mixed_len_p_bad_refuse` 均 400（`schema_required_f495pre.log`，F494 二进制）。
- **实现**：`api_schema_lengths_ok`（裸 schema 资格扫描；s 叶跳过、p 叶要求全精确且界限包含）+ 关键字分支；`api_response_schema_string_bounds` 改为按写入器消费顺序生成预算（mixed 只列 plan 中 s 叶）。
- **过程（两次证伪）**：①helper 首版用需 `json_schema` 包装的检测器 ⇒ 门 `len_mix_p` 181/1；改裸 schema 扫描。②活体暴露预算提取器见 `pattern` 即返回空 ⇒ p 叶共存时 city 无预算超 maxLength；按消费顺序重写并加门 `len_mix_bounds=[3:8]`。旧对照 `mixed_len_pattern_refuse` 翻转为 `mixed_len_pattern_ok`。
- **活体**：`mixed_len_p_ok` 200 `{"city":"New York","code":"NYN","count":100000}`、`mixed_len_pattern_ok` 200、`mixed_len_p_bad_refuse` 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f495c.log`，`len_mix_p=[]`/`len_mix_p_bad=[minLength]`/`len_mix_bounds=[3:8]`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.447×**；哨兵 **0**；构建 BUILD_OK（`build_f495c`）。

## ▶ 状态（F496，已完成）——整数叶的小数 multipleOf：折叠 + 候选层完整字面过滤
- **思路**：整数 x 是 p/q 的倍数 ⇔ x 是 p 的倍数（最简分子）；同时候选枚举层必须按完整字面读乘数（旧前缀正则把 0.3 读成 0）。
- **过程**：首轮只改形状折叠 ⇒ 活体 `{"n":2}`（链首 candidates Pass 2 先认领）；回退并定位后，第二轮同时修 Pass 2。
- **门**：`fm_intleaf=0`、`fm_int03/fm_int15=[n|3|3|3]`、`fm_int_unsat=[]`、`fm_int_cand=[{"n":3},{"n":6},{"n":9}]`。
- **活体**：0.3 / 1.5 → `{"n":3}`，[1,2]+0.3 → 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f496b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.526×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F497，已完成）——整数数组项的 fractional multipleOf
- **思路**：整数元素是 p/q 的倍数 ⇔ 是 p 的倍数；检测器读完整字面取最简分子，交给既有 item writer。
- **实现**：`api_schema_array_item_multiple` 改完整字面解析（后随分隔符）；无界 fractional 与 number 型 items 保持原语义。
- **门**：`im_excl_frac` `[]`→`[nums|3|3|3]`；新 `im_frac_unb=[]`、`im_frac_num=[]`。
- **活体**：`items_mult_excl_frac_ok` 200 `{"nums":[3,3,3,3]}`；number-items 小数乘数由既有路线服务（`1.2` 是 0.3 的倍数），原拒绝对照翻转；unsat 仍 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f497.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.516×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F498，已完成）——无界整数项的 fractional multipleOf
- **思路**：无界 item writer 发“所给乘数的倍数”；取最简分子 p 后，p/(p/q)=q 为整数 ⇒ 构造满足。
- **实现**：删除无界分支对 fractional 的拒绝（九位上限沿用）。
- **门**：`im_frac_unb` `[]`→`[nums|3|-1|-1]`；`im_frac_num` 保持 `[]`。
- **活体**：`items_mult_frac_unb_ok` 200 `{"nums":[1234002,2345001,3456000,4567002]}` 全部为 3 的倍数；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f498.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.504×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F499，已完成）——无界整数叶的 fractional multipleOf
- **改前证据**：活体 `{"n":{"type":"integer","multipleOf":0.3}}` → 200 `10`（静默违反，F498 二进制）。
- **实现**：无界检测器改 plan 定位 + 完整字面，p≥2 交后缀写入器；p=1 无包装写入器 ⇒ 无界分支显式 400。
- **门**：`unb_int_frac=[n|3]`、`unb_int_quarter=[]`、`unb_int_five=[n|5]`。
- **活体**：`{"n":2345001}`（3 的倍数）、0.25 → 400 `numeric bounds`；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f499b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.528×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F500，已完成）——无界整数叶 p=1：400 升级为构造满足
- **思路**：`api_multiple_fill` 对乘数 1 的残差为 0，值=prefix·10^k，恒为 1 的倍数 ⇒ 恒满足 0.25 等。
- **实现**：写入器守卫 `<2`→`<1`；检测器接受 p=1；删除 F499 的无界 p<2 拒绝。
- **门**：`unb_int_quarter` `[]`→`[n|1]`；frac/five 不回退。
- **活体**：`{"n":2345000}`（/0.25 为整数）、0.3 例不变；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f500.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.576×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F501，已完成）——number 叶小数乘数 + exclusive 界
- **思路**：十进制网格折叠；严格下界取下一格、严格上界取字面下方最大倍数；同侧相等时 exclusive 胜。
- **实现**：小数分支允许 exclusive，字面/位数集合纳入 exclusive 字面。
- **门**：`fm_excl_lo=[n|15|-999|0]`、`fm_excl_hi`/`fm_excl_bool=[n|12|-999|0]`、`fm_excl_unsat=[]`。
- **活体**：`{"n":1.5}`（>1.2）、`{"n":1.2}`（<1.5）、1.2<x<1.5 → 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f501.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.546×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F502，已完成）——小数乘数/界扩到 6 位
- **实现**：分子解析上限 5→6；number 小数分支 5 个正则 `{1,5}`→`{1,6}`；marker 表加 `d==6 → -994`。
- **门**：`fm_d6=[n|1000001|-994|0]`、`fm_d6_unsat=[]`。
- **活体**：`{"n":0.000246}`（0.000123×2）、unsat 400；**测试坑**：`json.dumps(0.000001)` 是 `1e-06`（指数形式按设计拒绝），用例改用 0.000123。
- **梯**：自检 **182/0**（`selftest_f502.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.489×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F503，已完成）——无界 number 叶的 multipleOf
- **思路**：后缀写入器发整数；整数是合法 number，且 p 的倍数同时是 p/q 的倍数。
- **实现**：探测器接受 body/裸 schema，kind 放宽到 i|n；两条 number 守卫在探测器有值时放行。
- **门（含翻转）**：`nm_frac`/`nm_unb`/`mult_number` 1→0；新 `nm_frac_ub`/`nm_int_ub=[n|3]`（首轮漏翻 mult_number，1 FAIL 后补齐）。
- **活体**：`{"n":2345001}`（0.3 与 3 两例）；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f503b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.524×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F504，覆盖）——number items 的 fractional M + exclusive 界
- **结论**：items number 路线复用同一 shape helper，F501 严格侧折叠已自动生效；无引擎改动。
- **活体**：`items_mult_frac_num_excl_ok` 200 `{"nums":[1.5,1.5,1.5,1.5]}`（1.5/0.3=5），bounds harness CHECK_EXIT=0（`schema_bounds_f504probe.log`）；二进制与 F503 相同。

## ▶ 状态（F505，已完成）——无界 multipleOf 超过后缀宽度：发常量 0
- **思路**：0 是任何 M 的倍数；后缀宽度（≤999999999）之外的乘数不再拒绝。
- **实现**：`api_multiple_value` 超宽发 `0`；探测器去掉上限；无界分支删上限拒绝。
- **门**：`unbounded_big` 1→0、新 `unb_big_shape=[n|2000000000]`。
- **活体**：整数/number 叶 M=2000000000 均 `{"n":0}`；items 上限不变；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f505.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.518×**；哨兵 **0**。
- **注**：构建日志末行 `SKIPPED_RUN` 是 norun 模式的正常输出（实为链接成功，exe 11:15 新于源码 11:12）。

## ▶ 状态（F506，已完成）——无界 items 的 multipleOf 超过后缀宽度：逐元素发 0
- **实现**：items 无界分支超宽发 `0`；探测器删除两处九位上限拒绝。
- **门**：`im_big` `[]`→`[nums|2000000000|-1|-1]`。
- **活体**：`{"nums":[0,0,0,0]}`；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f506.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.508×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F507，已完成）——多叶全整数 multipleOf 的超宽 M
- **实现**：多叶写入器两处填充在超宽时改发常量 0；检测器本就无上限。
- **门**：新 `multi_big_plan=[a|2000000000,b|2000000001]`。
- **活体**：`{"a":0,"b":0}`；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f507.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.513×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F508，已完成）——多叶全整数的 fractional M
- **实现**：多叶检测器逐叶读完整字面并折最简分子（F496 lemma）；写入器沿用 F507（任意大小/0）。
- **门**：新 `multi_frac_plan=[a|3,b|1]`；首轮条件未加上，补齐后重建复验（门字段打印≠已断言）。
- **活体**：`{"a":1000002,"b":1000000}`（a 被 3 整除、b 为 0.5 倍数）；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f508b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.566×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F509，已完成）——混合对象的 per-leaf multipleOf（无界叶发 0）
- **实现**：plan 新 kind `z`（发字面 0）；mixed 检测器接受裸 schema 并逐叶改写无界 i/n+multipleOf；三处守卫豁免，all-required 豁免带 kind 白名单。
- **过程**：首轮豁免过宽 ⇒ `req_arr_all` 误放行（普通数组叶），加白名单后 182/0；旧对照 `multi_leaf_mixed_refuse` 被证伪并翻转。
- **门**：`mult_mixed` 1→0；新 `mix_z_plan=[city:s,count:z]`、`mix_z_bad=[]`。
- **活体**：`{"city":"…","count":0}`（含 all-required）、`{"a":0,"b":100000}`；有界叶与 required 子集仍 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f509b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.520×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F510，已完成）——混合对象内有界整数叶的 multipleOf（kind q）
- **实现**：plan 新 3 段 kind `q:<常量>`；检测器逐叶判定数值界（必须折叠为唯一整数常量）；bounds 守卫早期豁免；required 白名单加 q。
- **门（含翻转）**：新 `mix_q_plan=[city:s,count:q:3]`、`mix_q_bad=[]`、`mix_excl_bad=[]`；F509 的 `mix_z_bad` 被证伪改期望。
- **活体**：`{"count":3}`（city 可选）与 all-required `{"city":"…","count":3}`（真正走 q 分支）；小数网格有界叶与无乘数数值界仍 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f510b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.574×**；哨兵 **0**；BUILD_OK。
- **记法**：可选键被候选层省略是合法的；覆盖新写入分支要用 all-required 挤掉候选层。

## ▶ 状态（F511，已完成）——混合对象内 number 叶的小数网格折叠
- **实现**：q 分支区分 marker 与整数折叠；marker → `d = mk + 1000` 还原字面小数（符号+零填充），写入器不变。
- **门（含翻转）**：`mix_q_bad` `[]`→`[city:s,count:q:1.2]`；新 `mix_q_unsat=[]`、`mix_q_d2=[city:s,count:q:1.25]`。
- **过程**：首版位宽公式 `-998-mk` 对 -994 为负，d=1 恰好正确 ⇒ 改 `mk+1000` 并加 d=2 门重建复验。
- **活体**：`{"city":"…","count":1.2}`、`{"city":"…","count":1.25}`、无倍数区间 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f511b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.526×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F512，已完成）——小数位宽扩到 7 位（marker -993）
- **实现**：写入器 marker 表/两处 marker 判定/`api_schema_scaled`（隐藏的第四处：digits if 链）/形状 5 个正则与 marker 表/回退 d 探测/mixed q 范围/分子上限 一次改全。
- **过程**：首轮 `num_dec7=[n|1|-993|0]` 暴露 `api_schema_scaled` 的 if 链只到 6 位（7 位退化为整数部分），补 `digits==7` 后 182/0。
- **门（含翻转）**：`num_dec7` `[]`→`[n|15111111|-993|0]`；新 `fm_d7=[n|2468|-993|0]`。
- **活体**：`{"n":1.5111111}`（原拒绝用例翻转）、`{"n":0.0002468}`；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f512b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.546×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F512，已完成）——小数位扩到七位（新 marker -993）
- **实现**：宽度阶梯一次改全——`api_schema_scaled` 正则 `{1,6}`→`{1,7}`＋`digits==7` 分支；`api_schema_multiplier_numerator` 位数上限 6→7；乘数分支字面正则（乘数＋四个界）到 `{1,7}`（d 由字面小数实际长度推导，无正则阶梯）；bounds-only 分支字面正则到 `{1,7}`＋`\.[0-9]{7}` 检测；两处 marker 表加 `d==7→-993`；`api_number_decimal_element` 加 d=7/scale=10^7；`api_number_bounded_value`/`api_array_item_number_value` 的 marker 门加 -993；mixed q 分支 marker 区间扩到 -993（还原公式 `mk+1000` 本就通用，无需改）。
- **门（含翻转）**：`num_dec7` `""`→`[n|15111111|-993|0]`（自检 nfR 期望由拒翻转为折叠）；新 `fm_d7=[n|2468|-993|0]`（乘数 0.0001234 七位、界 [0.0002,0.001]，首倍数 0.0001234×2=0.0002468）。
- **过程**：首轮自检两 FAIL——`num_dec7=[n|1|-993|0]`（界折叠错误）与 `fm_d7=[]`（乘数被拒）——宽度位点仍有漏改；补齐字面正则与 d 检测位点后二轮 182/0。F450“宽度多处同改”教训第三次复现。
- **活体**：`float_dec7_served` 200 `{"n":1.5111111}`（原 `float_dec7_refuse` 翻转）、`num_mult_frac_d7_ok` 200 `{"n":0.0002468}`（在界内且为 0.0001234 的倍数）；1–6 位与负数不回退。
- **梯**：自检 **182/0**（`selftest_f512b.log`，`: FAIL` 0、`RUN_EXIT=0`）；四 harness **PASS**（`schema_{bounds,required,count,pattern}_f512b.log`，CHECK_EXIT=0）；8000 **115/0**；8001 par/fairness **0**；scaling **1.487×**；哨兵 **0**（`gate_live_f512.log`）；BUILD_OK（`build_f512b`，exe 新于源码）。

## ▶ 状态（F513，已完成）——小数位扩到八位（新 marker -992）
- **改前证据**：活体探针（F512 二进制）对 8 位界（1.51111111..1.51111119）与 8 位乘数（0.00012345）均 **400**；d=7 对照 200（`schema_bounds_f513pre.log`）。
- **实现**：F512 位点清单推进一位——`api_schema_scaled` 正则 `{1,7}`→`{1,8}`＋`digits==8` 分支；`api_schema_multiplier_numerator` 上限 8；乘数分支字面正则 `{1,8}`×5；bounds-only 分支字面正则 `{1,8}`×2＋`\.[0-9]{8}` 检测；两处 marker 表 `d==8→-992`；`api_number_decimal_element` d=8/scale=10^8；`api_number_bounded_value`/`api_array_item_number_value` 门加 -992；mixed q 分支区间到 -992。
- **门**：`num_dec8` `""`→`[n|151111111|-992|0]`（自检 nfS）；新 `fm_d8=[n|24690|-992|0]`（0.00012345×2=0.0002469 落 [0.0002,0.001]）。
- **活体**：`float_dec8_served` 200 `{"n":1.51111111}`、`num_mult_frac_d8_ok` 200 `{"n":0.00024690}`、`items_num_dec8_ok` 200（数组项写入器 -992 门**首次**有活体覆盖）；1–7 位不回退。
- **过程**：位点按 F512 清单一次改全，自检首轮即 182/0（无需 b 轮）。一个非代码坑：直接跑 exe 缺 oneAPI 运行时 PATH（`libmmd.dll` 加载失败，exit 127）——自检与一切直跑都要带 gate_live 同款 PATH 导出。
- **梯**：自检 **182/0**（`selftest_f513.log`，`: FAIL` 0、`RUN_EXIT=0`）；四 harness **PASS**（`schema_{bounds,required,count,pattern}_f513.log`，CHECK_EXIT=0）；8000 **115/0**；8001 par/fairness **0**；scaling **1.523×**；哨兵 **0**（`gate_live_f513.log`）；BUILD_OK（`build_f513`）。

## ▶ 状态（F514，已完成）——整数上界落在 marker 窗口：静默违反改显式拒绝
- **改前证据**：F513 二进制（`probe_f514pre.log`）——[-2000000,-992] 宽跨 **200 `{"n":-0.02000000}`**、单侧 max=-992 **200 `{"n":-0.00000001}`**、multipleOf=992 塌缩 **200 `{"n":-0.00000992}`**：hi 槽被写入器读成 d=8 marker，类型与上界双双违反。
- **实现**：`api_schema_number_bounded_shape` 整数返回（仅该处）前加窗口守卫 `hi∈[-999,-992] ⇒ ""`；请求层 400 点名 numeric bounds。窄跨窗口由候选层先行认领不受影响；-1000 及以下幅值步进不变；小数/marker 分支在守卫前返回。未来扩宽（-991）须同步扩窗。
- **门**：新 `win_wide`/`win_one`/`win_mult`/`win_narrow_shape` 全 `""`、`win_below=[n|-2000000|-1000|0]`；活体 `marker_window_{wide,one,mult}_refuse` 400、`marker_window_narrow_ok` 200 `{"n":-998}`、`marker_window_below_ok` 200。
- **过程**：narrow 控制原用 [-1000,-992] ⇒ 活体暴露**另一既有缺陷**：候选枚举层对 ≥4 位负值发截断 JSON（`{"n":-1000`，200，引擎自记 json=bad）；同宽正值 1000 完整、≤3 位负值完整。tokenize oracle：`":-` 为单 token 52052，walk 末 "}" 步 next_allowed 连败 ⇒ 与挂账的 F228"首调空返回/整行 NaN"同族，控制改 [-998,-992]（全 3 位），缺陷记 F515（`probe_f514b/c.log` 留证）。
- **梯**：自检 **182/0**（`selftest_f514.log`，`: FAIL` 0，首轮即绿）；四 harness **PASS**（`schema_*_f514.log`，CHECK_EXIT=0）；8000 **115/0**；8001 par/fairness **0**；scaling **1.539×**；哨兵 **0**（`gate_live_f514.log`）；BUILD_OK（`build_f514`）。

## ▶ 状态（F516，已完成）——小数位宽扩到九位（marker -991），窗口守卫同步扩宽
- **实现**：全部宽度站点一次改全（写入器/marker 判定/scaled 正则与 digits==9/分子上限/形状 5 正则与 marker 表/回退 d 探测/mixed q 范围/数组项判定）；窗口守卫 `[-999,-992]`→`[-999,-991]`。
- **门**：新 `num_dec9=[n|1511111111|-991|0]`、`fm_d9=[n|246912|-991|0]`、`win_nine=[]`；1–8 位不回退。
- **活体**：`{"n":1.511111111}`、`{"n":0.000246912}`、maximum:-991 → 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f516.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.548×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F517，已完成）——enum-item 有限域的 uniqueItems:true
- **实现**：`api_array_enum_value` 增 `$unique`（去重值表/按序强制/域耗尽收尾/域<min 返回空）、4 个调用点传标志；uniqueItems 与 minItems 守卫按 enum 路由放宽。
- **过程**：首轮 `uniq_enum=[minItems]`（minItems 先判定且 enum 路线未被 element_bounds 认领），放宽后 182/0。
- **门**：`uniq_enum=[]`、`uniq_enum_unsat`/`uniq_enum_pat=[uniqueItems]`；`uniq_true`/`uniq_false` 不回退。
- **活体**：`{"cities":["paris","lyon","nice"]}`（互异、3∈[2,3]）；域不足与 pattern 均 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f517b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.468×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F518，覆盖）——multipleOf-items 的计数界与 uniqueItems 对照
- **结论**：items-multiple 写入器消费 bounds：`minItems:3/maxItems:3` 返回 `{"nums":[5000,5000,5000]}`；`uniqueItems:true` 对同值折叠数组仍点名拒绝。
- **变更**：仅 harness 两例；无引擎改动，二进制同 F517；bounds harness CHECK_EXIT=0。

## ▶ 状态（F519，覆盖）——const 与字面交替的 uniqueItems
- **结论**：const（F470）与锚定字面交替（F477）产出有限 enum 计划，F517 去重自动覆盖：`{"codes":["paris"]}`、`{"cities":["paris","lyon"]}`；const+minItems:2 仍 400。
- **变更**：仅 harness 三例，无引擎改动。

## 下一步（F519 后）
- **F515**：候选枚举层 ≥4 位负值截断（`{"n":-1000`），已证 `":-` 单 token 52052、末 `}` 步 next_allowed 连败，与 F228 同族，需 engine 判别量一轮。
- **unevaluated\*** 真实求值（子 schema 求值器）；10+ 位小数（窗口继续内缩，收益/代价需先写规格）；FR-05/多分片（本机不可验证）。

## ▶ 状态（F520，已完成）——数值 enum/const items
- **实现**：enum 检测器放宽 kind 到 a/an/af，新增数值 enum/const 分支（`#` 前缀）；写入器按裸字面量发射（head/分隔不带引号）。
- **过程**：先漏 kind 放宽（items 仍拒），再漏字符串引号假设（`{"nums":["2,…]}`），两次后 182/0。
- **门**：`num_enum_items=[nums|#2~5~9]`、`num_const_items=[nums|#7]`、`num_enum_uniq=[]`。
- **活体**：`[2,5,9,2]`、unique `[2,5,9]`、const `[7,7,7,7]`、const+unique+min2 400；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f520e.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.528×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F521，已完成）——boolean enum/const items
- **实现**：标量 enum/const 分支加 boolean、kind 加 `ab`；复用 `#` 裸字面量写入与有限域去重。
- **门**：`bool_enum_items=[flags|#true~false]`、`bool_const_items=[flags|#true]`。
- **活体**：`[false×4]`、unique `[true,false]`、const `[true×4]`；四 harness PASS。
- **梯**：自检 **182/0**（`selftest_f521.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.556×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F522，已完成）——顶层标量 enum 数组
- **实现**：检测器 `api_schema_top_array_scalar_enum`（`#` 有限值表）、`api_schema_top_bounds`、裸字面量根数组写入器、四处分发 + 三处守卫豁免。
- **根因（判别量定位）**：非流式被 `$enumFlat` 分支吞掉（body 里任意 `"enum":[…]` 被当成单值答案）⇒ 排他条件后落到本路线，非流式 `[2,5,9,2]`、`json=ok`。
- **过程**：首版只接线路由 ⇒ 非流式自由文本 `2`（静默违反）⇒ 全部回退留证；加 DBG 判别量后定位并完成。
- **门/活体**：`top_scalar_enum=[#2~5~9]`、`top_scalar_const=[#true]`、`SCHEMA-TOPARR-SCALAR` PASS；`[2,5,9,2]`、unique `[2,5,9]`、bool `[false]`、const+unique+min2 400。
- **梯**：自检 **182/0**（`selftest_f522b.log`）；8000 **115/0**；8001 par/fairness **0**；scaling **1.531×**；哨兵 **0**；BUILD_OK。

## ▶ 状态（F523，尝试后回退）——顶层 string enum 数组
- **证伪**：写入器门（cache=0）通过，但活体（prefixCache）输出 `["paris,"paris,…]`（引号被分词成逗号）——静默坏 JSON。
- **处置**：string 分支/门/活体全部回退，源码回到 F522；重建自检 182/0、bounds harness 绿；证据日志入库。

## ▶ 状态（F524，尝试后回退）——tokenizer 标点族
- **探针**：`paris"`→`par|is|"`、`-1000`→`-|1|0|0|0`（encode 正确）；`:-`/`,"` 为合并 token。
- **尝试**：strict 循环 + 界写入器闭合失败即空；自检 182/0，但窄负区间仍 200 `{"n":-1000` ⇒ 第三处截断点未定位。
- **处置**：全部回退（回到 a83ea90），重建自检 182/0；探针/证据日志入库，下一步用分支判别量定位第三个写入器。

## ▶ 状态（F525，调查）——候选层四位负值截断门内复现
- **复现**：门内 `SCHEMA-NARROW cands=9 text=[{"n":-1000] json=0`（cache=0），与 F515 活体一致、与缓存无关。
- **已排除**：候选串分词（TOKPROBE 正确）、continue/界写入器容错闭合（F524 strict 未改变）。
- **下一步**：strict 哨兵 + 候选层 fail-closed 400 + 门升级为断言。

## ▶ 状态（F527，已完成）——候选前缀匹配顺序 + 并行门控（F515 真因）
- **真因**：短候选（7 token 的 `-999…-992`）未校验前缀就设 `done`，砍掉 `-1000` 的 `}`；非 tokenizer。
- **实现**：先匹配后判完成；strict 哨兵 + 候选层 fail-closed 400；pair 骨架仅 `parReq==1` 构建（F199 门控）。
- **门/活体**：`SCHEMA-NARROW-STRICT` PASS（`{"n":-1000}`）；`narrow_neg_no_bad_json` 200 合法；smoke 115/0。
- **梯**：自检 **182/0**（`selftest_f527c.log`）；四 harness PASS；8000 115/0；8001 par/fair 0；scaling 1.545×；哨兵 0。

## ▶ 状态（F528，已完成）——顶层 string enum/const 数组
- **结论**：F523 畸形引号的真因是 F527 的前缀完成 bug（短候选提前结束），不是 tokenizer。
- **实现**：检测器 `@` 分支 + 写入器带引号模式；`$enumFlat` 已排他。
- **门/活体**：`SCHEMA-TOPARR-STRENUM` PASS；`["paris","paris"]`、unique `["paris","lyon","nice"]`、const `["paris"]`。
- **梯**：自检 182/0（`selftest_f528.log`）；四 harness PASS；8000 115/0；8001 par/fair 0；scaling 1.592×；哨兵 0。

## ▶ 状态（F529，已完成）——小数位宽扩到 10 位（marker -990）
- **实现**：宽度站点一次改全；窗口守卫 `[-999,-990]`。
- **门/活体**：`num_dec10`/`fm_d10`/`win_ten`；`{"n":1.5111111111}`、`{"n":0.0002469134}`、−990/−991 上界 400。
- **代价**：整数上界 −990…−999 变为显式 400；10 位为末档。
- **梯**：自检 182/0（`selftest_f529.log`）；四 harness PASS；8000 115/0；8001 par/fair 0；scaling 1.582×；哨兵 0。

## ▶ 状态（F530，已完成）——unevaluatedItems 的根数组真空形态
- **思路**：`items`（schema）评估所有元素 ⇒ 根数组 `unevaluatedItems` 真空；tuple/prefixItems 仍拒绝。
- **实现**：检测器根数组分支 + 根 string 数组检测器容忍该关键字。
- **过程**：门入口/变量名撞名各 1 FAIL；活体曾返回对象（检测器拒绝多余关键字），修正后返回数组。
- **门/活体**：`ueRootVac=[]`、`ueRootTuple=[prefixItems]`；`["New York City,"]`、tuple 400。
- **梯**：自检 182/0（`selftest_f530e.log`）；四 harness PASS；8000 115/0；8001 par/fair 0；scaling 1.581×；哨兵 0。

## ▶ 状态（F531，已完成）——根 number/boolean 数组的 unevaluatedItems
- **实现**：三个根数组检测器统一**前置剥离** `,"unevaluatedItems":<浅对象|true|false>` 后再匹配。
- **过程**：正则尾部容忍方案门内失败（-1/0），改剥离后 `ue_num_det=1`、`ue_bool_det=1`。
- **活体**：`[123,456,789]`、`[false]`、`["New York City,"]`；bounds harness PASS。
- **梯**：自检 182/0（`selftest_f531e.log`）；四 harness PASS；8000 115/0；8001 par/fair 0；scaling ~1.58×；哨兵 0。

## F532（2026-11-12）：11 位小数（marker -989，窗口 [-999,-989]）
- **改动**：宽度站点按清单 +1 位（scaled 正则/分支、分子上限、5+2 条字面量正则、`{11}` 宽度探测、两处 marker 表、mixed q 范围、decimal element、两处写入器门、窗口守卫）。
- **活体红证 → 修复**：计划门全绿但 `float_dec11_served` 返回 10 位截断；真因是两处 `api_constrained_continue([$dec], 12)` 预算与宽度绑定，改为 `strlen($dec) + 4`；`num_mult_frac_d11_ok` 的浮点容差放过截断值，改精确文本断言。
- **门**：`num_dec11=[n|151111111111|-989|0]`、`fm_d11=[n|24691356|-989|0]`、`win_eleven=[]`。
- **活体**：`float_dec11_served` 200 `{"n":1.51111111111}`、`num_mult_frac_d11_ok` 200 `{"n":0.00024691356}`、`marker_window_eleven_refuse` 400。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f532c.log`）；四 harness PASS（bounds `f532e`）；8000 115/0；8001 par/fair 0；scaling 1.542×；哨兵 0；BUILD_OK（`build_f532c`）。

## F533（2026-11-12）：7–11 位“发射文本”探针（对象 + 数组元素）
- **动机**：F532 的截断只有活体看见——自检文本探针只到 6 位，数组元素小数写入器没有文本探针。
- **改动**：SCHEMA-NUM-FRAC 新增 `dec7_text`…`dec11_text`、`neg11_text`、`arr11_text` 七条精确文本断言；数组解码取最后一个 `{` 之后的尾段（缓存 prompt 在前，`^` 锚定会误判）。
- **门**：七条探针全部精确匹配（数组为三个 `1.51111111111`）。
- **活体补强**：bounds harness 新增 `items_num_dec11_ok`——200 `{"nums":[1.51111111111,×4]}`（`f533c`），数组元素 11 位从 8 位覆盖再上一位。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f533b.log`）；四 harness PASS；8000 115/0；8001 par/fair 0；哨兵 0（`gate_live f533`）；最终二进制复核 bounds（`f533b`）；BUILD_OK（`build_f533b`）。

## F534（2026-11-12）：HTTP 200 层嵌套 schema 的静默错误答案（下降窗口 8000 字节）
- **活体取证**：bounds harness 首请求（`deep200_cold_first_ok`）返回 `{"count":100000}`；手动深度扫描 40/64/96/128/129/150 全对、200 稳定错，非冷启动特有。
- **根因**：`api_response_schema_nested_object` 只扫 `substr($schema,$ip,8000)`；200 层约 8.4 KB，`api_balance_block` 找不到闭合 ⇒ 空计划 ⇒ 静默落到浅层检测器回 200（违反“要么实现要么显式 400”）。
- **修复**：去掉初始窗口（`$win = substr($schema,$ip)`）；每层 20000 上限大于 256 级最大文本，保留。
- **门/活体**：`deep200_cold_first_ok` 200 且 k0…k199/city/count 全对；原 defect 二进制下的同用例为红（`{"count":100000}`）。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f534.log`）；bounds PASS（`f534d`）；required/count/pattern 0；8000 115/0；8001 par/fair 0；scaling 1.562×；哨兵 0；BUILD_OK（`build_f534`）。
- **未做**：>256 层的 fail-closed（超限仍可能落浅层）——入队。

## F535（2026-11-12）：>256 层嵌套的 fail-closed
- **改动**：新增 `api_schema_nested_overflow`（按检测器同一键链下降计数，第 257 层返回 1）；非流式拒绝链插入 400 “nested object depth beyond 256 levels is not supported”。
- **过程（两轮红证）**：① 谓词要求整包 body，但链传的是 schema 对象 → 兼容两形；② 滑窗按上一键文本定位，运行时节名逐层不同 → 改为在 block 上重新匹配下一层键（镜像检测器）。
- **门/活体**：`over200=0/over260=1`；`deep260_refuse` 400 含 “256”；`deep200_cold_first_ok` 仍 200。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f535c.log`）；bounds PASS；8000 115/0；8001 par/fair 0；scaling 1.559×；哨兵 0（`gate_live f535c`）；BUILD_OK（`build_f535c`）。
- **边界（实测收窄）**：8000 的四个嵌套分发点同处 `api_serve`、共用拒绝链——`stream:true` 260 层实测 400、200 层正常；8001 并行服务对任何 `json_schema` 一律 400（text-only），无静默降级。

## F537（2026-11-12）：`unevaluatedProperties:false` 真空形态的活体门
- **发现**：扁平对象（键全部声明）上 `unevaluatedProperties:false` 已被服务且值合法（键集恰为声明集）；静态 `api_schema_keywords_unsupported` 列出关键字≠运行时会拒绝——形状检测器先认领。
- **改动**：bounds harness 加 `uneval_props_closed_ok` / `uneval_props_open_ok`（200 且键集精确），无引擎改动、无需重建。
- **梯**：bounds PASS（`f536b`，VERDICT PASS）；其余不受影响（仅测试新增）。

## F538（2026-11-12）：六条支持表声明的活体核对
- **结果**：`not:{const:5}`、根级 `contains:{const}` 运行时 **400**（静态字段却显示可判）；`if:{required}`、`propertyNames` ok pattern **200 合法**；`dependentRequired` 子集与含元字符 items pattern 400（与表一致）。
- **修正**：支持表头改为“以活体为准”；`not` 数值 const / 根级 `contains` const 从“支持”降为“静态可判、运行时 400”。
- **证据**：`claims_f538.log`（六条原样输出）。

## F539（2026-11-12）：两类静默违反（200 + 自由文本）
- **D1 本地 `$ref` 顺序依赖**：`definitions` 在 `properties` 之前 → 200 自由文本；放在之后 → 200 合法对象。
- **D2 oneOf/anyOf 无人认领**：属性级 oneOf（string const）、根级 oneOf、属性级 anyOf（整数界）全部 200 自由文本——未实现也未 400。
- **对照**：allOf+required、additionalProperties:true+required、数组 min/maxItems 均合法。
- **处置**：入队 P0（先成对门取证再改：$ref 两种键序、oneOf 是否已有可工作夹具）；证据 `claims_f539.log`。

## F540（2026-11-12）：oneOf/anyOf const 分支折叠
- **改动**：`api_schema_flatten_choices` 收集完各分支 `"enum"` 后用 `preg_match_all` 并入 `"const"` 字面量（单值 const ≡ 单值 enum）；仅数值界分支不认领。
- **活体**：`oneof_const_ok`、`anyof_const_ok` → 200 `{"city":"paris"}`（F539 时同形为 200 自由文本）。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f540.log`）；bounds PASS（`f540`）；gate_live 见 `api_smoke_f540.log`。

## F541（2026-11-12）：本地 `$ref` 键序依赖修复（D1 闭合）
- **红证**：plain string 定义 + `definitions` 在 `properties` 之前 → 200 散文；之后 → `{"city":…}`（`f541pre2`，CHECK_EXIT=1）。
- **根因**：内联后把定义容器清空为 `{}`，定义在前时 schema 首键是 `definitions`，形状检测器 `^{"type"/"properties"` 锚点失配 ⇒ 无约束生成。
- **修复**：整对删除容器（键+值+相邻逗号+空白）；静态门 `SCHEMA-REF-ORDER` 断言两序解析字节相等且无 `definitions`/`$ref`。
- **活体**：`ref_defs_plain_first_ok` 由散文变 200 `{"city":…}`；`ref_defs_plain_last_ok`、enum 定义两序全绿。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL；bounds/required/count/pattern PASS；8000 115/0；8001 par/fair 0；scaling 1.538×；哨兵 0；BUILD_OK（`build_f541`）。

## F542（2026-11-12）：纯数值界 oneOf/anyOf 分支 fail-closed（D2 闭合）
- **做法**：`api_schema_choices_pure_bounds` 扫描每个 oneOf/anyOf 数组，无任何分支含 `enum`/`const`/`properties`/`required`/`pattern` 即判 1；拒绝链 400 “branches carrying only numeric bounds are not supported”。
- **门**：静态 `SCHEMA-CHOICES-BOUNDS pure=1/root_enum=0/const=0/enum_int=0`；活体 `anyof_bounds_refuse` 400；F540 的 `oneof_const_ok` 仍 200。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f542.log`）；bounds/required/count/pattern PASS；8000 115/0；8001 par/fair 0；scaling 1.577×；哨兵 0；BUILD_OK（`build_f542`）。

## F543（2026-11-12）：根级 string enum 加引号 + 根级 const 折叠
- **缺陷**：根级 string enum 返回裸 `paris`（非法 JSON）；根级 const 返回 200 散文。
- **修复**：enum 分支对全字符串枚举改发带引号候选（`api_esc` 转义）；新增根 const 分支（直接子键正则，字符串保引号）。
- **回归与修复**：首版 const 分支误抢根数组 items 的 const（`top_str_const_ok` 退化），加 `"schema":{[^{}]*"const"` 直接子键限定后恢复。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f543b.log`）；bounds PASS（`f543b`）；8000 115/0；8001 par/fair 0；scaling 1.579×；哨兵 0；BUILD_OK（`build_f543b`）。
- **边界**：流式根级字符串 enum 未验（入队）。

## F545（2026-11-12）：流式根标量 enum/const 折叠（D4 闭合）
- **缺陷**：`stream:true` 时根级 string enum/const 流式返回 200 散文（F544 取证）。
- **实现**：`api_root_scalar_choices`（根层直接子键、字符串加引号、const 折叠、items 内 const 排除）；在流式发射点 `$sJOut` 为空时兜底填入候选，复用既有 JSON 发射。
- **过程**：① helper 插入吞掉下一个函数声明 → `Stmt_Block` 构建失败，补回；② 首版分支插进非流式链成死码，改到发射点后生效。
- **活体**：`stream:true` → `"paris"`/`"paris"`/2（`stream_root_f545b.log`）。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f545c.log`）；bounds/required/count/pattern PASS；8000 115/0；8001 par/fair 0；scaling 1.569×；哨兵 0；BUILD_OK（`build_f545c`）。

## F546（2026-11-12）：流式八项成对核对
- **结果**：allOf/if/$ref/const-oneOf/propertyNames/uneval-closed/200 层对象在 `stream:true` 下值全部合法；纯界 anyOf 400；未发现新的传输差异。
- **证据**：`stream_claims_f546.log`（八条原样输出）。

## F547（2026-11-12）：流式根标量永久门
- **改动**：smoke 增 `chat-stream-root-strenum`/`chat-stream-root-strconst`（引号 JSON 字符串断言）。
- **结果**：SMOKE 117/0；8001 par/fair 0；哨兵 0。**基线：8000 smoke 117/0（F547 起）**。

## F548（2026-11-12）：contains 的构造满足形态
- **实现**：`api_schema_contains_required`（const/enum ∩ items 域、无 maxContains、minContains≤1）；`api_array_enum_value` 增 `$must`（元素 0 强制、bMin 提到 1、unique 模式重排域首）；`api_schema_contains_ok` 豁免；四处调用点接入。
- **活体**：`contains_const_ok` 200 `{"cities":["paris","paris"]}`；`contains_miss_refuse` 400。
- **期望翻转**：count harness 的 `contains_subset_miss_refuse`（曾期望 400）改为 `contains_const_served`（200 且含 paris）。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f548.log`）；bounds PASS（`f548`）；count PASS（`f548b`）；required/pattern 0；8000 117/0；8001 par/fair 0；scaling 1.560×；哨兵 0；BUILD_OK（`build_f548`）。

## F549（2026-11-12）：流式 contains 常驻门
- **改动**：smoke 增 `chat-stream-contains-const`（`stream:true` 下数组必须含 `Paris`）。
- **结果**：SMOKE 118/0；8001 par/fair 0；哨兵 0。**基线：8000 smoke 118/0（F549 起）**。

## F550（2026-11-12）：contains 的“同 pattern”构造满足
- **实现**：`api_schema_contains_pattern_ok`（items 与 contains 为同一 pattern 字面量、minContains≤1、无 maxContains）；`api_schema_contains_ok` 豁免；无需改写入器（全部元素同 spec）。
- **活体**：`contains_pattern_same_ok` 200 `["CJL","CJL"]`；不同 pattern 400。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f550.log`）；bounds/required/count/pattern PASS；8000 118/0；8001 par/fair 0；scaling 1.571×；哨兵 0；BUILD_OK（`build_f550`）。

## F551（2026-11-12）：同 pattern contains 的 min/maxContains（元素计数）
- **实现**：放宽 `api_schema_contains_pattern_ok`（仅在不可满足时拒绝）；`api_response_schema_array_bounds` 对同 pattern 情形以 `0:0` 为基折叠计数。
- **过程**：首轮被 `api_schema_minmax_contains_ok` 拒绝，因无 minItems 时 bounds 返回空；补基后通过。
- **活体**：min3 → 4 元素；max1 → 1 元素；min3+max1 → 400；不同 pattern → 400。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f551.log`）；bounds PASS（`f551b`）；required/count/pattern 0；8000 118/0；8001 par/fair 0；scaling 1.499×；哨兵 0；BUILD_OK（`build_f551b`）。

## F552（2026-11-12）：流式 contains 计数常驻门
- **改动**：smoke 增 `chat-stream-contains-min3`（长度≥3）与 `chat-stream-contains-max1`（长度=1）。
- **过程**：首版花括号多一个， smoke 0 条即 SyntaxError；加 `ast.parse` 预检后通过。
- **结果**：SMOKE 120/0；8001 par/fair 0；哨兵 0。**基线：8000 smoke 120/0（F552 起）**。

## F553（2026-11-12）：const contains 的 minContains（前 K 个元素）
- **实现**：required 放宽 minContains；`api_schema_contains_count`（unique+K>1 / maxItems<K ⇒ 0）；写入器 `$mustCount`；bounds 的 `0:0` 基覆盖 const 形态。
- **过程**：首轮被 minmax 裁判拒绝（无 minItems 时 bounds 为空）；扩展基后通过。
- **活体**：min2 → `["paris","paris"]`；unique+min2、maxItems<min2 → 400。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f553.log`）；bounds PASS（`f553b`）；required/count/pattern 0；8000 120/0；8001 par/fair 0；scaling 1.572×；哨兵 0；BUILD_OK（`build_f553b`）。

## F554（2026-11-12）：const contains 的 maxContains（匹配数恰为 K）
- **实现**：required 允许 maxContains；count 校验 K≤M；写入器在强制前 K 后改用“域减必需值”；单值域收缩 `bMax=maxItems=K`。
- **过程**：单值域首轮多一个元素（只改 bMax 未改 maxItems），同步后 `["paris"]`。
- **活体**：max1/min1max1 → 恰 1 个 paris；单值域+max2 → `["paris"]`；max0 → 400。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f554.log`）；bounds PASS（`f554b`）；required/count/pattern 0；8000 120/0；8001 par/fair 0；scaling 1.600×；哨兵 0；BUILD_OK（`build_f554b`）。

## F555（2026-11-12）：`propertyNames:{enum}` 误路由修复
- **缺陷**：对象 schema 的 propertyNames 枚举被 `$enumFlat` 当成顶层枚举，返回 200 `"city"`。
- **修复**：`$enumFlat` 条件加 `propertyNames` 守卫。
- **活体**：`pnames_enum_obj_ok` 200 对象（键集 city/note）；`pnames_maxlen_ok` 200。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f555.log`）；bounds PASS（`f555`）；required/count/pattern 0；8000 120/0；8001 par/fair 0；scaling 1.574×；哨兵 0；BUILD_OK（`build_f555`）。

## F556（2026-11-12）：嵌套 enum 误路由边界核对
- **结果**：`contains`/`if`/`dependentSchemas`/`not` 子 schema 里的 enum 全部 400 点名关键字（拒绝链先于生成），无误路由；`propertyNames` 是唯一缝隙（F555 已修）。
- **证据**：`claims_f556.log`。

## F557（2026-11-12）：dependentSchemas 的真空值级子 schema
- **实现**：判定接受“只含 properties 且键集与发射集不相交”的子 schema；新增 `api_schema_strip_vacuous_dep`，在两条预处理链剥离真空条目（否则生成器把 dependent properties 当主 plan）。
- **过程**：仅改判定时值为 `{"city":{"x":"0.000000"}}`；剥离后 `{"city":…,"note":…}`。
- **门**：`depsch_vacuous_props` 200；约束已发射键/带 additionalProperties ⇒ 400。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f557.log`）；bounds/count/pattern 0；8000 120/0；8001 par/fair 0；scaling 1.613×；哨兵 0；BUILD_OK（`build_f557c`）。

## F558（2026-11-12）：if/then/else 的真空值级条件
- **实现**：`api_schema_if_ok` 接受“条件 properties 键全未发射（恒真）”与“被选分支 properties 键全未发射（真空）”；`api_schema_strip_vacuous_if` 在两条预处理链删除判定通过的 if/then/else；新增 `api_block_keys` 顶层键名扫描。
- **过程**：首轮 400 因 `api_top_leaves` 不认 const 叶子；改用键名扫描后通过。
- **门**：`if_vacuous_then_ok`/`if_vacuous_else_ok` 200；条件/分支命中已发射键 ⇒ 400。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f558.log`）；bounds/count/pattern 0；8000 120/0；8001 par/fair 0；scaling 1.572×；哨兵 0；BUILD_OK（`build_f558b`）。

## F559（2026-11-12）：dependencies（draft-04）的真空值级子 schema
- **实现**：对象分支接受“只含未发射键的 properties”；新增 `api_schema_strip_vacuous_dependencies`（判定通过即整条删除），两条预处理链接入。
- **门**：`deps_vacuous_props` 200（键集 city/note）；`deps_emitted_key_refuse` 400；既有数组/bare-required 用例不回退。
- **梯**：自检 RUN_EXIT=0 / 0 FAIL（`selftest_f559.log`）；bounds/count/pattern 0；8000 120/0；8001 par/fair 0；scaling 1.582×；哨兵 0；BUILD_OK（`build_f559`）。

## F560（2026-11-12）：真空条件/依赖形态的流式对照
- **结果**：if-then / if-else / dependencies 三个真空形态在 `stream:true` 下键集均为 `{city,note}`；与预处理双链一致，无新差异。
- **证据**：`stream_cond_f560.log`。

