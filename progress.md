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

- **F188（`tool_choice:"none"` 误判修复）已验证**：`api_tool_choice` 在全 body 找 `name`，字符串 none 时误命中 tools 数组；改为字符串直接返回空、对象用平衡块限定。`engine/build_f188.log` 成功、`engine/run_f188.log` **130 PASS / 0 FAIL**、`api_smoke_f188.log` **85/85 PASS**（新增 `chat-tool-none-stream`、`responses-stream-stop`）。
- **F187（seed 可复现 smoke）已验证**：同 seed 两次 Chat 文本一致；`api_smoke_f187.log` **83/83 PASS**（零改码）。
- **F186（客户端中途断开 smoke）已验证**：原始 socket 读首批字节后关闭，随后普通请求仍返回 “Paris”；`api_smoke_f186.log` **82/82 PASS**（零改码）。
- **F185（流式超时 smoke）已验证**：`timeout_ms=1` → 最后 chunk `finish_reason=="length"` 且 <5 s；`api_smoke_f185.log` **81/81 PASS**（零改码）。
- **临时文件清理（2026-11-03 04:54）**：删除 findings 标注可删的旧日志 `serve_par13.log`（91 MB）、`serve_par_fix.log`（185 MB）、`build_f148.log`、`par_gate_console*.txt`、`par_gate_health.txt`、`par_health.txt`；`engine/` 体积 379 MB → **111 MB**。保留所有当前证据日志（`build_f*`、`run_f*`、`api_smoke_f*`、`par_smoke_f*`、`fairness_f*`）。
- **F184（已知缺口，未修）**：`/v1/responses` 的 `text.format.type=json_object` 未接入校验重试（实测返回围栏 JSON）；细节与建议见 findings F184。
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

## 续作入口（若恢复）

1. **per-session KV 真并行**：现状是单线程 accept + 全局 `g_kv`/`g_sess_*`。**第一步已完成（F86：KV 快照/恢复 + SESSION-SNAP 交接逐位一致）**；下一步：把 save/restore 用一个显式 session 句柄封装（或直接两槽），在 accept 循环里对进行中的流式连接做时间片推进，先验证"长流不饿死短请求"，再谈墙钟收益与线程。
2. **grammar-constrained 解码**：已覆盖（F109–F147、F152–F159）：字符串 charset/有符号整数/小数/科学计数法/布尔/嵌套 1–12 层/同层混合叶子/2–6 维数组/三类数组/对象数组/顶层数组/元素内字符串数组+标量混合叶子（F157）/元素内嵌套链 ≤12 层（F158）/**oneOf 分支含 grammar（F159：const 选支 + 分支自有语法）**/**根级 oneOf 分支含 grammar（F160）**/有界·无界 multipleOf/排他边界/本地 `$ref`/`allOf`/属性级·根级 `oneOf`。**剩余缺口**：外部 `$ref`、转义字符串；>12 层与 >6 维收益递减暂缓。离线复刻检测器到 Python 的方法已工具化（F158/F159 两轮使用），下个增量先跑防误认领矩阵再重建。
3. 其余：多分片 GGUF 合并、多样草稿器、并行多卡、FR-05 插件、UNIKERN 决策。

复现：`scripts\build-kakasi-nosetvars.bat norun`（构建，WMI/`setvars` 挂起时亦可）→ `scripts\run-kakasi-selftest.bat run.log`（全量自检，判据 121 PASS / 0 FAIL / `RUN_EXIT=0`，当前证据 `engine/run_f157.log`）→ 起 8000 服务后 `python engine\tests\api_smoke.py 8000`（**66/66**，`engine/api_smoke_f168e.log`）；8001：`scripts\serve-par.bat` 后 `python engine\tests\par_smoke.py 8001`（7/7）、`python engine\tests\fairness_test.py 8001`（替代路径证据见 F157）。环境正常时仍可用 `scripts\build-kakasi.bat` 一次完成构建+自检。

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
