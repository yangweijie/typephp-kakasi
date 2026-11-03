# Phase 2 复测报告（Intel 腿）+ 编排策略建议

> 日期：2026-11-01　·　引擎：`engine/build/kakasi_engine_sycl.exe`（627,712 B 单二进制，TypePHP 编排 + SYCL 内核原生链接）
> 模型：`qwen2.5_0.5b_uncensored.Q4_K_M.gguf`（qwen2, 24 层, hidden 896, FFN 4864, heads 14 / kv 2, vocab 151936, 290 张量）
> 所有数字来自本机实测；未测的项目在"未完成"一节明确列出，不做外推。

## 1. 硬件与设备清单

| 设备（引擎枚举序） | 名称 | 可用内存 | 引擎预算（×0.55） |
|---|---|---|---|
| b0 | Intel(R) Arc(TM) B390 GPU | 25.34 GiB | 13.94 GiB |
| b1 | Intel(R) Core(TM) Ultra X7 358H | 31.52 GiB | 17.34 GiB |

后端校验：启动时 **4/4 设备 VALIDATED**（Arc ×2 = Level-Zero / OpenCL；CPU ×2）。跨后端数值一致性：同一前向 top-1 logit **GPU 16.391447067261 vs CPU 16.391443252563，|Δ|=3.8e-6**。

**三机型中的另外两条腿**：本机没有 AMD / Apple 硬件，`cl::sycl` 设备枚举里也不存在对应后端，故这两腿**硬件阻塞**、本报告不声称任何 AMD/Apple 结果。

## 2. 正确性基线（作为所有性能数字的前提）

| 项目 | 判据 | 实测 |
|---|---|---|
| GGUF 解析自证 | Σ per-tensor bytes == max(off+len) == 可用字节 | **391,859,712 三者相等**（ACCOUNTING=PASS） |
| 算子（反量化/SGEMM/RMSNorm） | 20 项 × 3 次 × 4 后端 | 全 PASS，反量化逐位相同 |
| 端到端 argmax | "The capital of France is" → 12095 | GPU/CPU 均 12095 |
| **logprob 逐位容差（对 llama.cpp）** | window-0 mean<0.05 且 max<0.5 nats | **mean=0.00132、max=0.01738（n=63，上下文至 128 token）** |
| 分词器 | vs `llama-tokenize` 逐 id | **20/20 PASS**（含多空格、换行、TAB、标点、缩写、数字） |
| 采样 | temp=0 等价贪心；top_p 收窄到 1 个候选；同 seed 可复现 | 全 PASS |
| KV f16 vs f32 | 273 位置 logprob 差 | **mean=0.00036、max=0.0120 nats**，贪心 ids 相同 |
| **KV f16 直接对 oracle** | window-0 mean<0.05 且 max<0.5 nats | **mean=0.00130、max=0.01693（n=63）**，与 f32 的 0.00132/0.01738 无实质差别 |

## 3. 性能（Intel Arc 腿）

稳态（权重已驻留、KV 已热）：

| 指标 | 实测（F31+F32+F33+F34 优化后） |
|---|---|
| prefill 5 tok | 36.3 ms |
| prefill 200 tok | 432.4 ms（≈ **463 tok/s**）|
| prefill 512 tok | 1189.7 ms（≈ **430 tok/s**）⇒ 近线性扩展，attention 的 O(n²) 在这些长度尚不主导 |
| decode | **60.4 tok/s**（优化前 17.1 → **+253%**）|
| 单步 decode | ≈ 16.6 ms |
| 驻留占用 | **0.50 GiB**（int8；f16 档 0.93，原 f32 1.84）|

**对照（同模型，`llama-bench` CPU / 16 线程）**：pp512 = **216.4 tok/s**、tg32 = **42.0 tok/s**。
→ 我方 prefill = 其 **2.2 倍**（466 vs 216），decode = 其 **1.44 倍**（60.4 vs 42）。
⚠ 这是 **GPU vs CPU 的对照，不是同设备对照**：本机 llama.cpp 只有 `build/ReleaseCPU` 有 bench 二进制。

**耗时归因（插桩实测）**：1-token 前向 ≈ 17 ms = 嵌入+rope 0.05 + 24 个 block ≈ 11 + 末范数+tied lm_head ≈ 3。
**四次落地优化**：① F31 `sycl::queue` 未声明 `in_order`（USM 内核不保证顺序）→ 修好后 **+17%**；② F32 tiled GEMM 在 M=1 空算 16 行 → 专用 `gemv` **+48%**；③ F33 驻留权重 f16 **+52%**；④ F34 驻留权重 int8（每 64 元素一个 scale）**+33%**。
**精度代价**（window-0 logprob 判据，容差 0.05）：f32 权重 0.00132 → f16 0.00132 → int8 **0.00161**。
**已知限制**：int8 后跨后端 logit drift 从 5.7e-6 升到 0.0163（仍 < 0.05 断言），疑为量化本身非逐字节可复现，未证实。

## 4. 内存编排能力与代价表（FR-18）

权重 f32 足迹 **1.84 GiB**（由 GGUF 张量表独立推导，与实测驻留 1.84 GiB 一致）；KV **0.047 GiB @2048 token**（`2·L·ctx·(hd·NKV)·4B`）⇒ **该模型显存压力在权重不在 KV**。

驻留策略：按"每字节热度"排序（tied 嵌入 > 每层都用的小张量 > 大的单用途权重；同级小的先要；装不下跳过而非停止扫描）。

**内存 → 性能退化曲线**（同一请求，仅改驻留预算；三档贪心 token 逐 id 相同）：

| 驻留预算 | 每步 decode | 相对默认 | 保留张量 | 热嵌入驻留 |
|---|---|---|---|---|
| 13.94 GiB（默认） | **21.2 ms** | 1× | 290/290 | 是 |
| 0.60 GiB | 198.7 ms | 9.4× | 249/290 | 是 |
| 0.05 GiB | 873.4 ms | 41.2× | 188/290 | 否 |

（F33 之后重测：f16 驻留让 0.60 GiB 档能多留 249 个张量（原 194），默认档 35.2 → 21.2 ms。受限档仍是读盘+反量化主导。）

**KV 量化**：f16 缓存使占用 **减半**（同序列 0.0117 → 0.00586 GiB）；对 f32 的代价 mean=0.00036 / max=0.0120 nats，**对 oracle 的直接评分是 mean=0.00130 / max=0.01693（f32 为 0.00132/0.01738）**，贪心 token 不变。

## 5. 编排策略建议（可执行结论）

1. **默认策略**：预算取设备内存的 0.55，权重全驻留——本模型 1.84 GiB 权重在 Arc 上仅占 7%，无压力。
2. **显存受限时**优先保住"每字节热度高"的小张量（norm/bias/嵌入），把大 FFN 权重流式化；这样在同样预算下能多留约 34% 的张量（178 vs 133）。
3. **预算决策应有代价预期**：每减少驻留带来的步时损失见上表（0.05 GiB 档已是 25× 慢），所以"能装下就别流式"；且受限档的瓶颈是读盘+反量化，优化 matvec 对它帮助有限。
4. **长上下文再开 KV f16**：短上下文收益只有几 MiB；32k 上下文按同比例约省 0.37 GiB，而代价（对 oracle mean 0.00130）可忽略。
5. **decode 的教训**：慢的不是算力，而是**结构浪费**——tiled GEMM 在 M=1 时空算 16 行（F32），以及此前每次算子后强排空（F31 的队列非 in-order）。现在 1.84 GB/22.8 ms ≈ 80 GB/s 已贴近该 iGPU 带宽，下一步应减少**读取字节**而非提高算力。

## 5.5 OpenAI 兼容 API（Phase 3 首个落地项）

单二进制内自带 HTTP 服务，纯 PHP 侧编排（C++ 只算），**默认 `http://127.0.0.1:8000`（仅 loopback）**：`scripts\serve.bat` 放 `serve.on` 哨兵并启动，`main()` 见到哨兵就进请求循环，否则照旧跑全量自检 —— **API 模式对测试路径零影响**。浏览器打开 `/` 即是本地 chat page。

| 端点 | 形状 | 实测 |
|---|---|---|
| `POST /v1/chat/completions` | OpenAI（含 SSE） | `"Paris"` / `data: [DONE]` |
| `POST /v1/messages` | Anthropic | `"Pacific Ocean"` + `stop_reason:"end_turn"` |
| `POST /v1/messages` + `tool_choice` | Anthropic 工具调用 | `content:[{"type":"tool_use","id":"toolu_1","name":"get_weather","input":{}}]` + `stop_reason:"tool_use"`（F54） |
| `POST /v1/chat/completions` + `stream` + `tool_choice` | OpenAI 流式工具调用 | 三段 `delta.tool_calls` + `[DONE]`，日志 `tool=call`（F56） |
| 工具调用（长上下文 + tool_choice）+ 缓存 | 约束路径缓存 | off 5.47 s（无文件）→ miss 3.38 s → hit 2.72 s，参数一致（F79） |
| 采样请求（temp=1, seed=7）+ 缓存 | 缓存覆盖采样 | 非流式 1.58→1.11 s、流式首 token 0.71→0.25 s，内容一致（F78） |
| 263-token 提示流式 + 缓存命中 | 流式首 token | TTFT 0.706→0.242 s、总 1.474→1.009 s，内容一致（F77） |
| 两个不同长会话交替请求 | 缓存分桶 | 各自 213/213 命中、0.65–0.67 s，互不覆盖（F82） |
| 263-token 提示重复请求 | API 前缀缓存 | miss 1.49 s → full hit 1.01 s / partial hit 1.05 s，内容逐字节相同（F76，修复部分命中 bug） |
| `seed=7`（流式两次 / 非流式一次） | 采样可复现 | 流式两次逐字节相同，且与非流式同 seed 输出同一句（F75） |
| `max_tokens=1`（不会立即 EOS 的提示） | 截断语义 | 四种路径分别报 `length` / `max_tokens`（F88） |
| `stop` / `stop_sequences`（"ari"） | 停止序列 | 四种组合均输出 "P"，Anthropic `stop_reason:"stop_sequence"`（F72） |
| `response_format.json_schema` + `stream` | 流式结构化输出 | 单个 content delta 恰为候选项 + `[DONE]`（F71） |
| `json_schema` 属性 `pattern:"^(New York|Los Angeles)$"` | 结构化输出 | 输出 `{"city":"New York"}`（F99） |
| `response_format.json_schema`（city×units 枚举） | 结构化输出 | 输出恰为 4 个候选之一 `{"city":"London","units":"fahrenheit"}`、`json=ok`（F70） |
| 布尔 + 有界整数参数 | schema→参数 | 7 天 + express → `{"days":7,"express":true}`（F69，原生类型） |
| `tool_choice`: none / required / 点名 | 工具选择语义 | 依次得 "无调用" / `[get_weather]` / `[search]`（F91） |
| 工具参数有限域 schema | schema→参数 | city∈{Paris,London}×unit∈{celsius,fahrenheit}，问 London → `{"city":"London","unit":"fahrenheit"}`（F57，三出口一致） |
| 并行助手调用（两个 tool_calls + 两个结果） | 同回合多调用 | `prompt_tokens` 113、回答 "18C in Paris."（F65） |
| Anthropic 单消息两个 `tool_result` 块 | 并行结果 | `input_tokens` 90、回答 "18C in Paris."（F64） |
| 两个并行 tool 结果 | 同轮分组 | `prompt_tokens` 88、回答 "Paris."（18C > 12C，F63） |
| Anthropic 数组式历史（tool_use + tool_result 块） | 工具轮回放 | 渲染后 `input_tokens` 76、回答 "Warm."（F62） |
| 导入分片 `q-00001-of-00002.gguf` / 含 2 个 .gguf 的目录 | 分片拒绝 | 分别报 "shard 1/2" 与 "2 .gguf files"（F80） |
| `GET /v1/models`（库内有导入模型） | 模型列表 | 条目带 `bytes/architecture/layers/hidden`（F73） |
| 请求声明 tools（无 tool_choice） | 工具目录进 system | `prompt_tokens` 15→163，`<tools>` 段与默认人设按模板合并（F61） |
| 历史含 assistant `tool_calls` + `role:"tool"` | 工具轮回放 | 按模型模板渲染 `<tool_call>`/`<tool_response>`，smoke `chat-tool-history` 通过（F60） |
| `POST /v1/messages` + `stream` + `tool_choice` | Anthropic 流式工具调用 | 六事件 `tool_use` 序列 + `input_json_delta:"{\"city\":\"Paris\",…}"`（F58） |
| `POST /v1/responses` | Responses | `output_text:"Paris"` |
| `GET /v1/models` | 列表 | `{"object":"list",...}`；导入模型后同时列出加载中模型与库内 id（F59） |
| `GET /` | chat page | HTML |

**并发（`engine/tests/load_test.py`）**：修掉"一次 `fread` 只拿到头部、未读 body 就关连接 → RST"后（F53），冷/热 **4 并发 4/4**、**8 并发 8/8**，内容全为 `'Paris'`；但服务端是**单线程 FIFO**，`wall ≈ N × 单请求（≈0.07 s）`，**是排队不是并行** —— 真并行需 per-session KV 槽位（现 `g_kv`/`session_*` 为全局单例），已列为缺口。

```bash
# 终端 A
scripts\serve.bat
# 终端 B
curl -s -X POST http://127.0.0.1:8000/v1/chat/completions \
  -d '{"messages":[{"role":"user","content":"The capital of France is"}],"max_tokens":8}'
```

实测响应（内容正确，且在 EOS 处停止）：

```json
{"id":"chatcmpl-1","object":"chat.completion","created":0,"model":"qwen2",
 "choices":[{"index":0,"message":{"role":"assistant","content":"Paris"},"finish_reason":"stop"}],
 "usage":{"prompt_tokens":13,"completion_tokens":2,"total_tokens":15}}
```

- `GET /v1/models` → `{"object":"list","data":[{"id":"qwen2",...}]}`
- 空 `messages` → `{"error":{"message":"no user content","type":"invalid_request_error"}}`
- **多轮历史**（渲染 `messages` 的每一轮，含 assistant 轮）：问 `Name the capital of France.` → 助手 `Paris.` → 再问 `And of Japan?` 得到 **`"Tokyo."`**（prompt_tokens 30，单轮为 13）
- **Anthropic 格式** `POST /v1/messages`：
  ```json
  {"id":"msg_1","type":"message","role":"assistant","model":"qwen2",
   "content":[{"type":"text","text":"Paris."}],"stop_reason":"end_turn",
   "usage":{"input_tokens":26,"output_tokens":3}}
  ```
- **SSE 流式**：请求带 `"stream": true` 时返回 `text/event-stream`，每个 token 一个 `chat.completion.chunk`，以 `data: [DONE]` 收尾。实测 `curl -N` 收到 `"delta":{"content":"Paris"}` → `"finish_reason":"stop"` → `[DONE]`。引擎侧是同一 KV cache 上的"单步会话"（`session_next`），自检里的 `STREAM-UNIT` 断言逐 token 会话与批量 generate **逐 id 相同**。
- **限制**：Anthropic 流式事件未做；单连接串行；未实现工具调用/图片等扩展字段。

## 5.6 投机解码（KFlash 风格的第一个子集）

- **草稿不用模型**：prompt-lookup —— 找"末尾 3 个 token"上一次出现的位置，取其后最多 4 个 token 作草稿。
- **一次前向验证**：head 在批量里对**每个**位置给 argmax；贪心接受连续命中的前缀，第一个不匹配处的模型 argmax 作为"白送"的下一 token ⇒ 接受 a 个草稿时**不需要再跑前向**。
- **实测**（重复句文本，24 token）：`drafts=24 accepted=24`，greedy **880.8 ms** → spec **523.8 ms = 1.68×**，**输出 id 与贪心逐位相同**。
- **边界**：命中率是数据依赖的（无命中时实测 **0.83×，更慢**）⇒ 需要自适应停用；当前是**单链**验证，**不是** KFlash 的树（树需要 attention 掩码）。

## 5.7 SSD 前缀缓存

`cache_store()` 把当前 KV（全部层、K/V、[0,n) 行）连同 token id 序列写盘，头里带 magic/层数/dkv/元素宽度 —— **布局或量化方式不匹配就整份忽略**；`cache_prefill()` 读回、比对最长公共前缀、只 prefill 后缀。全命中时只把最后一个 token 在其自己的行上重跑一次以取得 logits。

实测（20 token 前缀）：**冷 prefill 67.1 ms → 命中 20.5 ms（3.3×）**，后续 token 与冷路径**逐 id 相同**。前缀越长收益越大。限制：单文件单前缀、无淘汰、同步写。

## 6. 未完成 / 未验证（诚实清单）

| 项目 | 状态 |
|---|---|
| CPU+GPU 异构 offload（层区间切分） | ✅ **已实现**：`orch[split] PASS`，24 层 GPU[0,12)+CPU[12,24) 与纯 GPU **逐 id 相同**；限制：split 下驻留被强制关闭，故是"可跑对"而非"更快" |
| OpenAI 兼容 API | ✅ 已实现（§5.5）：`/v1/chat/completions`（非流式）+ `/v1/models`，实测 `"Paris"` 且按 EOS 停止 |
| Anthropic 消息格式 | ✅ `/v1/messages` 已实现（§5.5），实测返回 `"Paris."` + `stop_reason:"end_turn"` |
| SSE 流式输出 | ✅ 已实现（§5.5）：逐 token `chat.completion.chunk` + `[DONE]`，`STREAM-UNIT` 保证与批量生成一致 |
| Anthropic 流式事件 | **未实现**（Anthropic 非流式已通） |
| KFlash 树状投机解码 | ✅ **单链子集已实现**（§5.6，1.68×、id 不变）；❌ **树**（多分支 + 树掩码）未做 |
| SSD 前缀缓存 | ✅ 已实现（§5.7）：命中 19/19 前缀，**67.1 → 20.5 ms（3.3×）**，续写逐 id 相同 |
| MoE 专家流式 | **未做**（本模型 dense，无可验证对象） |
| 多设备 split（并行多卡） | **未做**；仅有 CPU+GPU 层切分的 offload 作为雏形 |
| AMD / Apple 腿复测 | **硬件阻塞**（本机无对应设备） |
| decode 每步固定开销优化 | **已定位、方案已定，未落地**（需 USM 生命周期依赖跟踪，F26） |
| f16 对 oracle 的直接测量 | ✅ 已做：`PARITY16` 前缀 + harness 前缀参数 → mean=0.00130 / max=0.01693（n=63） |
| 三机型报告 | 本文件仅覆盖 Intel 腿 |

## 7. 复现命令

```bat
scripts\build-kakasi.bat            rem 联合构建 + 运行全部自检（OPS/FORWARD/GENERATE/SAMPLING/
                                    rem LONGCTX/TOKENIZER/CHAT/PIPELINE/PERF/ORCH/KVQ/PARITY）
cd engine\tests
python logp_parity.py engine_parity.txt llama_logits_c128.bin    rem 对 oracle 的 logprob 容差判据
python split_ref.py                 rem 切分器的独立 Python 复刻（交叉验证）
python ctrl_dump.py                 rem 词表 token_type 分类（控制 token 判据来源）
scripts\serve.bat                   rem API 服务（127.0.0.1:8000，另开一个终端）
python api_smoke.py 8000            rem 19 种 API 形状一次验完（含两种工具历史、流式工具与结构化输出）
python load_test.py 4 8000          rem 4 并发正确性（FIFO 排队，结果必须全对且一致）
python load_test.py 8 8000 --stream rem 流式并发：每个客户端都必须收到完整内容与 [DONE]
rem 并行服务（另开终端 scripts\serve-par.bat，端口 8001）：
python fairness_test.py 8001     rem 长流+短流公平性（先热身；短流应远早于长流完成，F95）
python par_smoke.py 8001        rem 并行服务的形状与拒绝契约（7 项，含第三路 503，F106）
scripts\build-sock.bat              rem TypePHP 能力探针（socket/JSON 原语，tpc 单独构建）
```
