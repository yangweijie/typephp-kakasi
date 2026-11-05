# 完成度审计 — 对照 `specs/typephp-inference-framework/requirements.md` v0.2

> 日期：2026-11-02　·　审计对象：`engine/build/kakasi_engine_sycl.exe` 与仓库内文档/脚本
> **最后一次全量自检（F205 后，`engine/run_f205.log`）**：0 FAIL、`RUN_EXIT=0`、**136 处 PASS 标记**（F206 134 → F207 136，新增外部引用两条）（嵌套 2/3/4/6/8/12/20/40/80 层（上限 128）、**2–7 维数组（7+ 由通用 N 维构造器接管，字符串与整数）**、对象数组元素内字符串数组（含标量兄弟、整数数组、二维数组、嵌套链）、oneOf 分支含 grammar（含根级）、**转义字符串（`\"`/偶数反斜杠片段）**、无界 `multipleOf`、顶层四类数组（含内嵌对象元素）、数字/浮点/科学计数法/布尔 const·enum、`multipleOf`、排他边界、候选与根类型作用域、本地 `$ref`、`allOf`、属性级/根级 `oneOf` 均覆盖）；**对 oracle 复测**：window-0 f32 mean 0.00161 / max 0.02247，f16 mean 0.00161 / max 0.02283（alignment 126/126）→ PASS；decode 59–61 tok/s、prefill(512) ≈1220–1240 ms（同机波动）；`INT8-REPRO requant_b0=0 requant_b1=0 cross=0.0163`。二进制晚于所有源文件（含 `gguf_charset_ids`、`api_string_value*`、`api_number_fill/value/nested`、`api_object_mixed`、`api_array_string/number/object/array2_value`、`api_array3/4/5/6_string_value`、`api_array_object_array_value`、`api_oneof_grammar_value`/`api_root_oneof_grammar_value`、路径驱动 `api_nested_object_value`（下钻上限 12，已构建验证）、`api_balance_block`/`api_top_leaves`、8000 SSE 语法回退、Responses schema 守卫放宽）。活服务 `api_smoke.py` **92/92**（`engine/api_smoke_f202.log`）**→ 93/93**（`engine/api_smoke_f203.log`，新增 Anthropic 并行扇出形状，64 s 跑完）于当前同源二进制复验（F206 95/95；**F207 当前同源 96/96**，`engine/api_smoke_f207.log` `SMOKE_EXIT=0`），日志记`SMOKE_EXIT=0`、0 FAIL；新增三出口 2-D 元素数组、20 层路径、**Anthropic `output_format`（非流式 + 分块流式回放 + `$ref` + `const` + `allOf`）**、**Responses 流式（文本 + schema + 工具 + stop）**、**整数 7 维数组**、**`n=2` 非流式 / 流式显式拒绝**、**`max_completion_tokens` 别名**、**流式 `timeout_ms` 预算**、**客户端断连存活**、**seed 可复现**与 **`tool_choice` none/auto 流式**形状；8000 服务以显式 PATH + `serve.on` 哨兵直起，见 F150 运行附注）；`par_smoke.py` **7/7** 于 2026-11-03 00:24 复验通过（`engine/par_smoke_deep12.log`），但监听器在 DEEP12 重建前启动且 PID 不可解析（`netstat` 报 0），故该 7/7 只证明服务端行为，**不构成 F151 二进制的 8001 回归证据**（待重启后 `par_gate.bat`）、`load_test.py` 8 并发全对（ratio 1.27×）、`fairness_test.py` PASS（0.28 s vs 3.99 s）。**par accept 空转告警已修（F167）**：`stream_select` 门控后空闲 8 s 内 0 行（原 30 s 443 行），同二进制 `par_smoke` 7/7 + `fairness` PASS（0.28 s vs 4.91 s）。**单槽 8000 同类告警已修（F183）**：空闲 10 s 内 0 行（原 9 分钟 57 行），smoke 80/80。**F169 二进制复测**：`par_smoke_f169.log` 7/7、`fairness_f169.log` PASS（0.31 s vs 4.98 s）、`Accept failed` 0 行。**F170 二进制复测**：`par_smoke_f170.log` 7/7、`fairness_f170.log` PASS（0.28 s vs 4.90 s）、`Accept failed` 0 行。**F176 二进制复测**：`par_smoke_f176.log` 7/7、`fairness_f176.log` PASS（0.35 s vs 5.50 s）、`Accept failed` 0 行。**F178 二进制复测**：`par_smoke_f178.log` 7/7、`fairness_f178.log` PASS（0.34 s vs 5.48 s）、`Accept failed` 0 行。**F179 二进制复测**：`par_smoke_f179.log` 7/7、`fairness_f179.log` PASS（0.30 s vs 5.01 s）、`Accept failed` 0 行。**F180 二进制复测**：`par_smoke_f180.log` 7/7、`fairness_f180.log` PASS（0.29 s vs 5.05 s）、`Accept failed` 0 行。**F181 二进制复测**：`par_smoke_f181.log` 7/7、`fairness_f181.log` PASS（0.30 s vs 4.97 s）、`Accept failed` 0 行。**F183 二进制复测**：`par_smoke_f183.log` 7/7、`fairness_f183.log` PASS（0.31 s vs 5.15 s）、`Accept failed` 0 行。**F188 二进制复测**：`par_smoke_f188.log` 7/7、`fairness_f188.log` PASS（0.41 s vs 6.92 s）、`Accept failed` 0 行。**F190 二进制复测**：`par_smoke_f190.log` 7/7、`fairness_f190.log` PASS（0.29 s vs 4.87 s）、`Accept failed` 0 行；8001 服务用 `serve.par.on` 哨兵显式 PATH 直起，门禁后已停止并确认无残留标志文件。**F193 二进制复测**：`par_smoke_f193.log` **8/8**（新增 `oai-json-object-keeps-stream`，同文件 `oai-stop` 仍裁成 "P"，证明 stop 门作用域正确）、`fairness_f193.log` PASS（短流 0.27 s vs 长流 4.75 s）、同二进制 `run_f193b.log` **132 PASS/0 FAIL** 与 `api_smoke_f193c.log` **88/88**、`Accept failed` 0 行。**F196–F200 二进制复测**：`run_f196b.log` / `run_f199.log` / `run_f200.log` 均 **134 PASS / 0 FAIL**；活服务 `api_smoke_f196.log` 89/89 → `api_smoke_f199.log` 90/90 → `api_smoke_f200.log` **91/91**；8001 侧 `par_smoke_f196/f199/f200.log` **8/8** 配 `fairness_f196/f199/f200.log` PASS（短流 0.27–0.47 s vs 长流 4.78–7.8 s，同机波动，判据只看相对关系）；`Accept failed` 0 行，门禁后 `serve*.on` 均已清除。
> **F160 状态（已验证）**：根级 oneOf 分支含 grammar 已实现并构建成功（`engine/build_f160.log`），三出口接入；`engine/run_f160.log`：**122 PASS / 0 FAIL / `RUN_EXIT=0`**，`SCHEMA-ROOT-ONEOF-GRAMMAR: PASS`（book 支、title 空串，与 F159 同口径）；同一二进制活服务 `engine/api_smoke_f160.log`：**61/61 PASS**。
> 原则：每条只认**仓库里可复现的证据**（命令、日志、断言名）。没有证据的写"未做"，不用"已设计"顶替。
> **F156–F159 状态**：元素内混合叶子（ARR2）、检测器回归修复（F157）、元素内嵌套链（F158）、oneOf 分支含 grammar（F159）均已落地；证据为 `engine/build_f158.log`（120 PASS/0 FAIL）、`engine/build_f159.log`（121 PASS/0 FAIL、无 FAIL 行）、`engine/api_smoke_f158.log` / `api_smoke_f159.log`（各 61 项 PASS）。F159 诚实注记：该次模型选 book 分支且 title 为空串（JSON 合法、形状断言覆盖）。**复核注记**：后续会话误把 F157 修好的断言改回三段，发现后已恢复为 `items|tags` 并重建（`engine/build_f157.log` 构建成功）；同源复核 `engine/run_f157.log` 已通过：**121 PASS / 0 FAIL / `RUN_EXIT=0`**（ARR、ARR2、ONEOF-GRAMMAR 均 PASS）；同一二进制活服务复测 `engine/api_smoke_f157.log`：**61/61 PASS、`SMOKE_EXIT=0`**。
> 回归入口：`scripts\build-kakasi.bat`（构建 + 全量自检）、`engine\tests\logp_parity.py`（对 oracle 判据）、`engine\tests\api_smoke.py`（对活服务一次性验完 61 种 API 形状）、`engine\tests\load_test.py`（并发正确性）、`engine\tests\par_smoke.py`（并行服务 8001 的形状与拒绝契约）、`engine\tests\fairness_test.py`（长流+短流公平性）。Windows 上从 Git Bash 派生 `cmd` 跑门禁时，脚本里的 `timeout` 必须写全路径 `C:\Windows\System32\timeout.exe`（否则解析到 GNU coreutils，睡眠失效，见 findings F148）。

| 需求 | 状态 | 证据 |
|---|---|---|
| **FR-01** TypePHP AOT 单一二进制、原生链接禁 FFI | ✅ | 单二进制同时含 PHP 编排与 SYCL 内核；`scripts/build-kakasi.bat` 四步（icx 编 obj → tpc → icx 终链含 `sycl-post-link` → 运行）；原生调用 0.65 ns/op（`probe/CONCLUSION.md`） |
| **FR-02** 统一后端抽象（buffer_type + backend + 调度器） | ⚠ 部分 | 有 `sycl_backend`（设备发现/校验/常驻 queue）+ 算子层（gemv/gemm/rmsnorm/attention…）+ `plan()` 编排；**缺 ggml 式计算图调度器**（按张量位置切图、边界拷贝）——现有的是"层区间切分"，不是图调度 |
| **FR-03** 可移植计算基座（SYCL，单二进制 offload） | ✅ 有替代 | 用 **oneAPI DPC++ 2026.1**（`acpp` 未安装，决策已记录）；单二进制运行时探测 4 设备、回退 CPU、报告可用性 |
| **FR-04** 多后端矩阵 + doctor | ⚠ 部分 | `scripts\doctor.bat` 输出设备/驱动/内存/校验/量化构成 + `doctor: READY (validated 4/4)`；**矩阵只有 CPU + Intel 可验**，NVIDIA/AMD/Apple 本机无设备。观察：b2/b3 与 b0/b1 同名不同驱动（Level-Zero vs OpenCL），属同一物理设备的两条路径 |
| **FR-05** 厂商调优内核插件 | ⛔ 未做 | 无插件注册/动态加载 |
| **FR-06** KFlash 树状投机解码 | ⚠ 部分（机制齐了；树的收益被草稿器卡住） | **链**：prompt-lookup + 一次批量验证 + 贪心接受，**逐位一致**（`SPEC`），自适应（`SPEC-NEG`），k 按实测选（k=8 ≈ 2.5×）；**树**：attention **树掩码**支持**一次 pass 验证多分支**、取最长通过分支，**逐位一致**（`SPEC-TREE`）；但 `SPEC-TREE-SWEEP`（684/918/969 ms）与 `SPEC-AMB`（chain 与 tree **接受数相同 5/5**）都表明：**prompt-lookup 下树无收益，瓶颈在草稿器**。**缺** DFlash/DFlash2/DSpark/LiLiCorr 等多样草稿器；**2-gram 兜底实验无收益且破坏 SPEC-ADAPT，已回退（F83）** |
| **FR-07** 会话与执行引擎（单流 + KV 量化） | ⚠ 部分（已收敛） | 单流 prefill/decode + KV cache（f32/f16 可切，`KVQ` 通过）；**流式请求支持 `timeout_ms` 与客户端断开检测**，每请求一条终态日志（`tokens=18 reason=timeout` / `tokens=41 reason=client-gone`），取消后服务仍正常响应（FR-07.2 GWT 实测）。**非流式仍是原子的**（不可抢占，仅受 `max_tokens` 限制）；**会话 KV 快照/恢复与逐 token 交替逐位一致（F86/F94）**；**并行服务 8001 双流公平性 PASS**（短流 0.25–0.27 s vs 串行 3.20 s，F95；支持一个 stop，F96；仅流式文本、两槽、非吞吐提速）；批处理属 V1 |
| **FR-08** 分词器 + 聊天模板 | ⚠ 部分（已收敛） | 分词 20/20 vs `llama-tokenize`、ChatML（`TOKENIZER`/`CHAT`）；**`json_object`** = 校验+重试；**`json_schema`** = 有限 schema 下真约束（F70 非流式 + F71 流式）；**`stop`/`stop_sequences`** 非流式截断 + 流式保留回看，Anthropic 报 `stop_sequence`（F72）；**`seed` 透传**（F74 非流式 + F75 流式，同 seed 可复现且两路径输出一致）；**enum/const = 真约束（含空格/逗号值，F98）**（`SPEC-CONSTRAIN` 证明禁答不可达，F51）；**工具调用**：`tool_choice` 走**约束骨架**（保证合法；`none` 禁止、`required`/`any` 强制、点名精确，F91；流式 required/any 亦支持，F92；Anthropic 流式 any：F93）、只给 `tools` 走**解析+白名单**（未声明名被拒），`finish_reason:"tool_calls"` 实测（F52）；**Anthropic `tool_use`** content block + `stop_reason:"tool_use"` 实测（F54）；**Anthropic `output_format` 结构化输出**（`output_format.schema` 翻译为 json_schema 记号，复用全部 grammar，F169，smoke `anth-json-schema-array-obj-arr2d` 实测；**流式**由 F171 以整段回放实现，smoke `anth-json-schema-string-stream` / `anth-json-schema-array-obj-arr2d-stream` 实测）；**OpenAI 流式 tool_calls**（约束解码照跑 + 三段 delta + `[DONE]`）实测（F56）；**schema→参数**：enum/const 属性做笛卡尔积后用约束解码选参（F57，三出口共用，London/fahrenheit 实测）；**Anthropic 流式 `tool_use`** 六事件序列 + `input_json_delta` 实测（F58）；**工具轮回放**：assistant `tool_calls` 与 `role:"tool"` 按模型自身模板渲染成 `<tool_call>`/`<tool_response>`（F60，ids 级相等 + 活服务往返）；**`# Tools` 目录注入首个 system 轮**（F61，prompt_tokens 15→163 实测；`<tool_call>` 回包也可解析）；**Anthropic 数组式历史**（`tool_use`/`tool_result`/`text` 块 → 同一线格式，F62，input_tokens 76 实测）；**并行结果合并同一 user 轮**（F63，ids 级相等 + 88 prompt tokens）；**同消息多 `tool_result` 块**（Anthropic 并行结果，F64，input_tokens 90）；**并行助手调用**（一条 assistant 消息多个 `tool_calls`/`tool_use` 合成一个回合，F65，113 prompt tokens）；**Anthropic `system` 文本块数组**（F67，27 vs 13 input tokens）；**`tool_result.content` 文本块数组**（F68，ids 级相等）；**布尔/有界整数参数**（F69，原生类型、7/true 实测）；**`json_schema` 结构化输出**（F70，有限扁平 schema 走约束解码）；**自由字符串值**：`gguf_charset_ids` 词表白名单 + 逐 token 允许集 + 强制闭合（F109，`SCHEMA-STR` 得 `{"city":"paris"}`；单/多属性字符串 schema 主服务非流式与 8001 流式均接，F110）；**整数/小数**：数字文法 + 首位单字符（防前导零）+ 可选前导 `-` + `.` 状态（点后至少一位，F111/F112/F113，`SCHEMA-NUM`/`SCHEMA-FRAC` 实测，smoke `integer`/`number` 用例）；**一层嵌套字符串**：嵌套检测先于平坦检测，强制 `{"outer":{"inner":"…"}}` 骨架（F114，`SCHEMA-NEST` 实测）；**8000 SSE 已对齐**同样的四级回退（F115，`chat-json-schema-string-stream` 实测）；**Responses 出口已接**（守卫从 `"json_schema":{` 放宽到记号级，F116，`responses-json-schema-string` 实测）；**一层嵌套数字**（`api_number_fill` 抽取 + 双层骨架，F117，`SCHEMA-NESTNUM` 实测）；**同层混合叶子**（计划串驱动的 `api_object_mixed`，F118，`SCHEMA-MIX` 实测）；**字符串数组**（`,`/`]` 二选一的收尾文法，F119，`SCHEMA-ARR` 实测）；**数字数组**（同一收尾规则 + 数字文法，F120；首跑抓到"上限后仍强制 `]` 留下尾逗号"的真 bug 并修复，`SCHEMA-ARR-NUM` 实测）；**对象数组**（items.properties 的扁平叶子计划 + 元素强制 `}`，检测先于嵌套字符串，F121，`SCHEMA-ARR-OBJ` 实测）；**检测器作用域**（`api_balance_block` + depth-1 `api_top_leaves`，兄弟/嵌套叶子不再泄漏进计划串，F122，`SCHEMA-SCOPE` 实测）；**嵌套对象多叶子**（前瞻定位 + 计划驱动组装，F123，`SCHEMA-NEST-OBJ` 实测）；**两层嵌套**（路径驱动前缀 + 逐层下钻检测，F124，`SCHEMA-DEEP` 实测）；**3/4 层嵌套**（下钻上限 4，`SCHEMA-DEEP3`/`SCHEMA-DEEP4` 实测；活服务 4 层实测）；**6 层嵌套**（上限提到 6，`SCHEMA-DEEP6` 程序化 schema 实测，F127）；**数组套数组**（两级 `,`/`]` 收尾文法，F126，`SCHEMA-ARR2` 实测）；**有界整数 `multipleOf`**（枚举时按倍数过滤，F130，`SCHEMA-MULT` 实测）；**三维数组**（三层收尾文法与逐层补 `]`，F131，`SCHEMA-ARR3` 实测）；**整数排他边界**（F132，`SCHEMA-EXCL` 实测）；**四维数组**（F133，`SCHEMA-ARR4` 实测）；**五维数组**（F152，`SCHEMA-ARR5` 实测）；**顶层数组 schema**（无对象包裹，F134，`SCHEMA-TOPARR` 实测）；**顶层数字数组**（F135，`SCHEMA-TOPARR-NUM` 实测）；**数字 const/enum**（无引号常量与数字枚举的引号修正，F136，`SCHEMA-CONST-NUM` 实测）；**浮点 const/enum**（F137，`SCHEMA-CONST-FLOAT` 实测）；**顶层布尔数组**（14 种有限候选，F138，`SCHEMA-TOPARR-BOOL` 实测）；**候选作用域**（`api_schema_candidates` 只在 `properties` 平衡块内扫描，顶层数组的 `items` 不再被当属性，F138）；**顶层对象数组**（计划驱动元素组装，F139，`SCHEMA-TOPARR-OBJ` 实测）；**根类型守卫**（根为数组时属性型检测器一律跳过，F139）；**本地 `$ref`**（`#/definitions|$defs` 内联后清空 definitions，F140，`SCHEMA-REF` 实测）；**`allOf` 合并**（成员 `properties` 合并，F141，`SCHEMA-ALLOF` 实测）；**属性级 `oneOf/anyOf`**（枚举分支并集，F142，`SCHEMA-ONEOF` 实测）；**根级 `oneOf/anyOf`**（分支候选并集，F143，`SCHEMA-ROOT-ONEOF` 实测）；**科学计数法字面量**（F144，`SCHEMA-SCI` 实测）；**无界 `multipleOf`**（F153，`SCHEMA-MULT-UNBOUND` 实测：`{"n":7000000}` 可被 7 整除）；**嵌套上限 8 层**（F145，`SCHEMA-DEEP8` 实测）；**12 层嵌套**（上限提到 12，F151，`SCHEMA-DEEP12` 实测）；**布尔 const/enum**（类型修正，F146，`SCHEMA-BOOL` 实测）；**顶层对象数组内嵌结构**（元素内一层对象，F147，`SCHEMA-TOPARR-OBJ2` 实测）；**六维字符串数组**（`api_array6_string_value` + 检测器，三条 schema 路径均接入，F154，`SCHEMA-ARR6` 实测）；**对象数组元素内字符串数组**（`{"items":[{"tags":["red"]}]}`，专用检测器先于平坦叶子检测，F155，`SCHEMA-ARR-OBJ-ARR` 实测）；**元素内混合叶子**（数组 + 标量兄弟，F156/F157，`SCHEMA-ARR-OBJ-ARR2` 实测）；**元素内嵌套链**（`items|meta/inner|city:s,count:i`，最深 12 层，F158，`SCHEMA-ARR-OBJ-NEST` 实测）；**oneOf 分支含 grammar**（首属性 const 选支 + 分支自有语法填充，F159，`SCHEMA-ONEOF-GRAMMAR` 实测）；**根级 oneOf 分支含 grammar**（F160，`SCHEMA-ROOT-ONEOF-GRAMMAR` 实测）；**对象数组元素内整数数组**（F161，`SCHEMA-ARR-OBJ-NUM` 实测，活服务 `api_smoke_f161.log` 61/61，8001 `par_smoke_f161.log` 7/7 + `fairness_f161.log` PASS）；**对象数组元素内二维字符串数组**（F163，`SCHEMA-ARR-OBJ-ARR2D` 实测，活服务 `api_smoke_f163.log` 61/61）；**转义字符串**（`api_json_escape_safe_ids` 片段白名单：`\"`/偶数反斜杠，F164，`SCHEMA-ESCAPE` 实测，活服务 `api_smoke_f164.log` 61/61）；**嵌套至 20 层**（下钻上限 32；闭合串 token 预算按深度放大，F165，`SCHEMA-DEEP20` 实测）；**嵌套至 40 层**（上限 64；检测窗口与强制前缀预算随深度放大，F170，`SCHEMA-DEEP40` 实测）；**嵌套至 80 层**（上限 128；每层下钻窗口 2000→20000，F179，`SCHEMA-DEEP80` 实测）；**通用 N 维字符串数组（rank ≥ 7）**（运行时拼前缀/子链，F166，`SCHEMA-ARR7` 实测，活服务 `api_smoke_f166.log` 61/61）；**通用整数 N 维数组（rank ≥ 7）**（同构构造器 + 四处接线，F178，`SCHEMA-ARR7-INT` 实测）。**缺** 129 层以上嵌套的**可装配**证据（F208/F209：200 层已能装配，检测与装配都过；剩下的是**冷启动首次长约束解码返回空**的缺陷，见 findings F208/F209）、（外部 `$ref` 已由 F207 以调用方注册表方式支持，URL/文件解析有意不做）、Responses 并行工具流式（单工具已由 F176 支持）。**并行工具调用已实测为缺口（F194）**：两工具 +"一次调用两者"的 8000 请求在 `tools` 自由路径得 `tool_calls=0`、`/v1/responses` 无 `function_call`，`tool_choice:"required"` 强制路径得 `tool_calls=1` ⇒ 单次响应最多一个调用（`api_tool_call_json` 只返回单个调用，三出口均按单调用序列化，Responses 流式更是硬编码 `fc_1`/`output_index:0`）。**并行调用已按 `parallel_tool_calls` 打通（F199：非流式 + `tool_choice:"required"`；开关置真时只放"两块串联"候选，因为贪心约束解码在任一候选完整时就停、单块候选在场则并行永不出现；骨架兜底不再顶替带标签文本；活服务成对实测 2 个调用 vs 1 个，守卫 `chat-tool-parallel-calls`，`api_smoke_f199.log` **90/90**；点名 `tool_choice` 与流式 `index`/`output_index` 递增仍未接）**。**解析半已由 F195 闭合**：新增 `api_tool_calls_json()` 把一轮里的多个调用块全部解析为多项 `tool_calls`（每块仍过同一白名单、id 重编号），chat 非流式自由 `tools` 路径接入，自检 `TOOL-CALLS-PARALLEL` 三条同判；**生成侧仍受限**——约束解码一次只产一条骨架，故 `required`/点名路径不可能并行，Anthropic 与 Responses 非流式也只取第一项。；同批实测还发现 **`required` 路径丢 arguments**（返回 `'get_weather'` + `'{}'`，`$qArgs==""` 兜底未取模型真正选中的调用）——**已由 F194a 修复**：`required` 分支逐工具取参数候选骨架、`$toolJson` 门控并上 `$reqCands`，实测得 `{"city":"Paris"}`，守卫 `chat-tool-required-args`，`api_smoke_f194b.log` **89/89**。 |
| **FR-09** 服务器与 API | ⚠ 部分（已大幅收敛） | **四种形状并存、两种流式、默认端口 8000、loopback**（Responses 的 `function_call` 与 `instructions`：F89/F90）：`/v1/chat/completions`（**OpenAI SSE**）、`/v1/completions`（旧版文本补全，非流式，F107）、`/v1/messages`（Anthropic，**含流式事件序列**）、`/v1/responses`（字符串/消息数组 input、instructions 字符串/数组与工具目录，F81/F90/F97）、`n` 多候选（F84）、`max_tokens` 上限 512（F85）、`stream_options.include_usage`（F103）、截断报 `length`/`max_tokens`（F88）、Responses `function_call`（F89）、**`GET /v1/models`（含模型库索引，F59）**、`GET /health` 探针、`GET /` chat page —— 均已 curl 实测；**工具调用 / enum 约束解码已接（F51/F52/F54/F56，含 Anthropic `tool_use` 与 OpenAI 流式 tool_calls）**；**并发连接正确**：修掉"未读完请求体就关连接 → RST"后冷/热 4 并发 `4/4`、8 并发 `8/8` 内容全对（F53）；**流式并发 4/4、8/8 且全部收到 `[DONE]`（F66）**；主服务仍是**单线程 FIFO**（wall≈N×单请求）；**并行服务 8001 两槽协作轮转保证短流不被饿死（F95），并支持 stop（F96）与有限 json_schema（F100）**；**`parallel_tool_calls` 已在非流式生效（F199/F200）**：开关置真时 required 与点名两条分支只给"两块串联"候选（贪心约束解码任一候选完整即停，单块在场则并行永不出现），活服务成对实测两项调用且参数各归各位 —— **流式仍是一调用**（`index`/`output_index` 未递增，见 F202 预研清单）；**点名分支我一度误判为"打断连接"，F203 已推翻**：那是同时跑了两台引擎抢同一设备造成的假阴性（同一时刻 8001 公平性也被污染成长流 41 s 的假 FAIL），隔离重测 `engine/api_smoke_f202.log` **92/92** 通过 ⇒ 硬规则：**任何时刻只允许一个引擎进程**，起服务前先用 `netstat` 确认 8000/8001 空闲 |
| **FR-10** 模型格式与目录管理 | ⚠ 部分（已收敛） | GGUF v3 加载（290 张量、字节账目自证）；**`scripts\models.bat import <path|dir> / pull <url> / list / rm <name>`**（导入与拉取都**复制/下载后真加载**再入索引，坏文件被拒且不留残件；`pull` 用 `copy()` 流式落盘）；**目录式模型文件夹已支持（F55）**：C++ 桥扫描目录、必须恰好一个 `.gguf`，真实 LM Studio 目录导入+列出+删除实测；**模型库已接入 `GET /v1/models`（F59，id 去 `.gguf`、去重）**，并带出 `bytes/architecture/layers/hidden`（F73）。**多分片**：名称识别 + 明确拒绝（F80），**合并未做** |
| **FR-11** MoE 专家流式 | ⛔ 不适用 | 本模型是 dense，**没有可验证对象**；未写代码 |
| **FR-12** 多级存储与显式流式调度 | ⚠ 部分 | 驻留预算 + 流式回退（`ORCH` 曲线：三档 token 相同，0.05 GiB 档 41× 慢）+ **SSD 前缀缓存**（离线 3.3×；非流式 1.01/1.05 s、流式首 token 0.242 s，采样与约束解码路径同样覆盖（F76–F79），按 64 桶隔离会话且防短命中覆盖（F82），`prefix_cache:0` 全路径生效，且修复了部分命中读错层的 bug；**跨进程重启复用已验证（F108，cache=213）**）；缺显式 I/O 与计算重叠 |
| **FR-13** 多设备/多机 split | ⛔ 部分雏形 | 只有 CPU+GPU **层区间** offload（`orch[split]` 逐 id 相同，但驻留被关闭、非提速）；无并行多卡/多机 |
| **FR-14** SSD 前缀缓存 | ✅ | `cache_store`/`cache_prefill`：命中 19/19、**67.1 → 20.5 ms（3.3×）**、续写逐 id 相同（`PREFIXCACHE`） |
| **FR-15** UNIKERN 集成（V2，条件性） | ⛔ 未触发 | 决策点依赖 V1 性能是否"巨大提升"，尚未评估 |
| **FR-16** 可观测/基准/一致性 gate | ✅ | 对 oracle 的 logprob 判据（**当前二进制复测**：window-0 f32 mean 0.00161 / max 0.02247；f16 mean 0.00161 / max 0.02283；alignment 126/126）、跨后端 drift 3.8e-6~0.0163、`PERF`、`ORCH` 曲线、`SPEC`/`STREAM`/`API`/`PREFIXCACHE` 断言、`ACCOUNTING` 字节自证 |
| **FR-17** 构建与可移植分发 | ⚠ 部分 | 一条命令联合构建 + 单二进制；**未做安装包/版本化产物** |
| **FR-18** 推理客户端编排层 | ⚠ 部分 | 设备选择 + 内存预算（`plan()` 实测与推导互证、策略按"每字节热度"）+ KV 量化 + CPU offload；**缺 I/O 重叠与专家流式** |

## 结论

- **完全达成**：FR-01（核心命题）, FR-14, FR-16；FR-03 以 DPC++ 替代达成其目标。
- **部分达成**：FR-02/04/06/07/08/09/10/12/13/17/18 —— 每条的缺口已在上表逐项写明。
- **未做/不适用**：FR-05（插件）、FR-11（MoE，无对象）、FR-15（UNIKERN，条件未触发）。
- **因此本目标整体仍未完成**：已补齐树状投机**机制**（收益被草稿器卡住）、结构化输出/工具调用（含两种 API 形状与并行调用）、`/v1/responses` + chat page、导入/管理 CLI（含目录与 `/v1/models`）、**schema 语法约束（字符串/有符号整数/小数/嵌套 1–6 层/混合叶子/字符串·数字·对象数组/数组套数组，四出口）**；仍缺 **指数/`multipleOf`/3 维数组/`$ref`、per-session KV 真并行、多分片 GGUF、多样草稿器、并行多卡/多机、FR-05 插件、条件性 UNIKERN**。

## 如何接手（构建 / 自检 / 使用 / 护栏）

**构建与自检（一条命令，约 5 分钟）**
```bat
scripts\build-kakasi.bat          rem icx 编 3 个 SYCL obj → tpc 编 PHP+bridge → icx 终链(含 sycl-post-link) → 跑全量自检
scripts\build-kakasi.bat norun    rem 只构建不跑
scripts\run-kakasi.bat            rem 只跑（需二进制已构建）
```

**工具**
| 命令 | 作用 |
|---|---|
| `scripts\doctor.bat` | 设备/驱动/内存/校验 + 模型形状 + 量化构成 + 逐设备 plan |
| `scripts\models.bat import <path|dir>` / `pull <url>` / `list` / `rm <name>` / `cache [clear]` | 本地模型管理（导入时真加载副本再入索引；目录须恰好一个 .gguf；`/v1/models` 会列出库内模型；`cache` 查看/清理前缀缓存桶） |
| `scripts\serve.bat` | OpenAI/Anthropic API，`http://127.0.0.1:8000/`（`/` 是 chat page） |
| `python engine\tests\logp_parity.py engine_parity.txt llama_logits_c128.bin [PARITY\|PARITY16]` | 对 llama.cpp 的 logprob 判据 |
| `scripts\build-sock.bat` | TypePHP 能力探针（socket / 文件 / JSON 原语，改 PHP 侧前先在这里试） |

**每次改动必须保持的护栏（当前值）**
1. 全量自检全 PASS（`OPS/FORWARD/GENERATE/SAMPLING/LONGCTX/TOKENIZER/CHAT/PIPELINE/PERF/ORCH/KVQ/WPREC/API-UNIT/STREAM-UNIT/SPEC*/PREFIXCACHE`）。
2. 跨后端 top-1 logit 一致（当前 \|Δ\|≈1e-5，int8 驻留时 0.016，断言阈值 0.05）。
3. `logp_parity` window-0：mean < 0.05 且 max < 0.5 nats（F128 复测：f32 0.00161/0.0225、f16 0.00161/0.0228）。
4. **所有设备队列必须 `property::queue::in_order`**（F31：这是曾经三次"撤排空就出错"的真因）。
5. **释放设备内存前必须排空**；arena 只在层边界 `wait` 之后重置。
6. **prompt 构造只有一处**（F35/F37 两次同类 bug：助手表头漏加）。
7. 任何"更快"的改动都要用 ②③ 验收，**argmax 不够**（F24/F26/F31 都证明了这一点）。
8. 改 API/模板后必须起服务跑 `python engine\tests\api_smoke.py 8000`（61 种形状），改模型库后跑 `models.bat list`。

**已知限制（按优先级）**
1. **FR-06 树机制已通、收益未证**（树掩码一次验证多分支、逐位一致；但 prompt-lookup 下接受数与单链相同，**瓶颈是草稿器**）。
2. **grammar-constrained 解码只到单自由字符串**（`json_object` 仍是校验+一次重试；enum/const、布尔/有界整数、有限 `json_schema` 已是真约束 F69/F70；单自由字符串属性已可用 charset 白名单填充 F109；**无界/小数数字、嵌套对象、转义仍不能用模型内容填充**）。
3. 非流式请求不可抢占（仅 `max_tokens` 上界）。
4. 并行多卡/多机 split、I/O 与计算重叠、MoE（本模型 dense）、厂商内核插件（FR-05）、UNIKERN（条件未触发）均未做。
5. 模型存储只支持**单文件** GGUF（目录式文件夹已支持，但**多分片不合并**；`config.json` 等伴随文件不复制）。
6. **连接处理是单线程 FIFO**：N 并发结果全对，但 wall≈N×单请求（无真并行）；真并行需 per-session KV 槽位（现为全局单例）。

## 收尾顺序（①–③ 本轮已完成；④ 起为剩余）

1. **FR-09 补齐**：`/v1/responses` + 默认端口 8000 + 本地 chat page —— 纯 PHP，可 curl 验证。✅ 已完成
2. **FR-10 CLI**：`import/list/rm`（文件管理 + 清单）—— 纯 PHP。✅ 已完成（F42）
3. **FR-07 取消/超时**：服务端 read 超时 + 会话中止标志。✅ 已完成（F43，流式路径；非流式仍原子）
4. **FR-06 树**：attention 树掩码 + 多分支一次验证，逐位一致。✅ 已完成机制（F50）；**收益未证**（草稿器瓶颈）
5. **FR-09 并发连接**：修掉未读请求体的 RST；4/8 并发结果全对。✅ 已完成正确性（F53）；**无真并行**（单线程 FIFO）
6. FR-05/11/13/15 属 V1+/V2 或本机不可验，保持"未做"标注即可。⛔
7. 剩余可做：grammar-constrained 的指数/`multipleOf`、3 维数组、`$ref/allOf`；多分片 GGUF 合并、多样草稿器（DFlash/DFlash2/DSpark/LiLiCorr）、per-session KV 真并行、条件性 UNIKERN 决策。


## 状态更新（2026-11-04，覆盖 F190–F228；只列有仓库内证据的项）

- **收尾顺序第 7 条（grammar-constrained 余项）已闭合**：外部 `$ref` 由调用方提供的 `schema_registry` 解析（不碰网络/文件系统，故无路径穿越）⇒ 见 findings F207；嵌套上限 128→**256**（F208，检测侧 200 层实测 `plan=ok`）。
- **工具面**：`tool_choice:"none"/"auto"/"required"` 与作用域（F188/F189/F190/F194a）、**并行工具调用**生成侧 + chat/Anthropic/Responses 三出口与**流式 fan-out**（F195–F206，含点名 `tool_choice`+`parallel_tool_calls`）；`json_object` 在 Chat/Responses 与流式路径的 stop 交互（F191–F193）。
- **冷启动功能缺陷已定位并功能修复**（audit 原文未覆盖，属新增发现）：会话内第一条长强制解码返回空。根因链 F224（前缀在第 502 步被截断，热态走满 893）→ F225（`g_err=no logits computed`，拒绝时会话仅 516 ⇒ **不是容量**）→ F226（`api_constrain_loop` 用"已消费 ids 重新 prefill"恢复；`SCHEMA-DEEP200-RETRY first_len` 0→3909，`engine/run_f226.log` 137 PASS / 0 FAIL / `RUN_EXIT=0`）→ **判据收紧为首调计分**（`engine/run_f227.log` 790 行 `RUN_EXIT=0`、137 PASS / 0 FAIL、`SCHEMA-DEEP200: PASS (… first attempt)`）。
- **仍未闭合（诚实标注，不得当作已完成）**：① native 根因未证——F228 代码分析把 `no logits computed` 收缩到 `rows==0` 或**整行 NaN** 两条，探针已写但未构建未测；② `session_next_allowed` 里 `g_sess_len += 1` 早于 `r.empty()` 判定（`src/model_sycl.cc:1496`）未修；③ F227 的"重启只在单 id 强制步"收窄**尚未跑过门禁**；④ **8000 `api_smoke.py` 与 8001 `par_smoke.py`/`fairness_test.py` 未在修复后的二进制上复跑**——audit 的活服务门禁因此对 F226/F227 属未验证；⑤ FR-05/11/13/15 仍按原标注"未做"，FR-06 树收益的多样草稿器、per-session KV 并行吞吐、SSD 前缀分桶、多卡 split、GGUF 分片合并均未开始。

## 状态更新增量（F238–F253，2026-11-04 10:33）
- **F238（新缺陷，未修）**：嵌套对象 schema 的**叶子带 `enum`** 时，服务侧退化为扁平对象（实测 `{"city":"Paris"}`，16 字符，`finish_reason=stop`，`closes=1`），40 层结构全丢。根因两处：`api_schema_candidates` 的 pass-1 枚举正则**跨层**匹配到深层叶子（`main.php:5277`），以及 `api_response_schema_nested_object` 对含 `enum|const|pattern|minimum|maximum|multipleOf` 的 schema **一律拒绝**（`main.php:2629`）。修复规格见 findings **F244**（plan 语法 `e:`、planner、builder、收窄 2629 四步），判据是活服务门 `chat-json-schema-deep40-enum-leaf`。
- **F226/F227（冷启动）**：功能闭合且过完整梯子；同一二进制**四遍**自检 137 PASS / 0 FAIL、`first_len` 0→3909（**首调**判据）、8000 `API SMOKE: PASS`、8001 `par_smoke` 9/9 + `fairness` PASS；首个秒表数 **673 s**（无未收窄对照 ⇒ 只报绝对值）。
- **F245/F250/F252（测量基础设施缺陷，已定因）**：两轮重建 `SYCL_LINK_FAILED`，起初误判为"句柄释放时序"，真因是**孤儿 `kakasi_engine_sycl` 进程**占着 exe（`mv` 成功会误导：链接器要**替换**而非改名）。规程：重建前 `Get-Process` 过滤 + `Stop-Process -Force`，重建后核 **exe mtime 变新** 与 **`SYCL_LINK_FAILED` 计数 0**；再用 **池序号 → `main.cc` 引用**（F253：`[1844]`）证明改动确实进了二进制。**`*_f240` 系列全部作废。**
- **F235（FR-06 的真实瓶颈）**：草稿器只在**第一个**最长后缀匹配处 `break`（`model_sycl.cc:1600-1640`）⇒ 树的分支同源，多分支结构性无收益；`task_plan.md` 末段给出可执行方案与"以吞吐而非逐位一致"的判据。
- **F242/F234（`g_sess_len`）**：自增早于 `r.empty()` 判定的缺陷会使失败步占一格空行，并**随 KV 快照固化**；修复已提交 `235bc45`，**尚未验证**。
- **仍未开始**：per-session KV 并行吞吐、SSD 前缀分桶、多卡 split、GGUF 分片合并、FR-05/11/13/15（维持"未做/本机不可验"标注）。

## F238 状态更新（2026-11-04 11:31）
**已修复（8000 门禁验证）**：`3e7cfd8` 让枚举叶进入嵌套 plan（`e:` 类型）。三个耦合缺陷：①plan 的枚举值分隔符 `|` 与 `path|plan` 分隔符冲突，导致 plan 被截断（改用 `~`）；②`api_object_head` 只给 `s` 叶开引号，`e` 叶输出 `"city":Paris"`；③全 body 枚举分支抢在嵌套装配之前（加 "`"properties"` 计数 <2" 前置）。实测 `engine/api_smoke_f249.log`：`API SMOKE: PASS`、`SMOKE_EXIT=0`、**99 个 PASS 行**，40 层结构完整且 `city` 取值 ∈ {Paris, London}。
**仍未完成的验证**：全套自检（137 项）与 8001 `par_smoke`/`fairness` 在修复版二进制上的复跑（作业 `b0i2o29tr`），以及带 `SESSION-POS` 守卫的重建轮（`b5bbqj9x7`）。**在这些数字到齐之前，本条只写"8000 门禁已验证"，不写"梯子全绿"。**
**覆盖缺口（诚实标注）**：`g_sess_len` 顺序修复没有判别性测试——`SESSION-POS` 命中的拒绝出口在自增之前，改前改后都会过（F257）；要判别需构造能令 `prefill_top1` 返回空的近容量会话，尚未做。

## 审计增补（F259–F262，2026-11-04 12:15）
- **F238 已过完整梯子（可收口）**：修复版二进制上 8000 `API SMOKE: PASS`（99 PASS 行、`SMOKE_EXIT=0`）+ 自检 **137 PASS / 0 FAIL**（`SUITE_SECS=698`、`first_len=3909`、`SCHEMA-DEEP200` **首调**判据通过）+ 8001 `par_smoke` **9/9** + `fairness` **PASS**（`b0i2o29tr`）。
- **HEAD 自检 138 PASS / 0 FAIL**（`b5bbqj9x7`，多出 `SESSION-POS`）；但该轮**未跑** 8000/8001 ⇒ 若要在审计里写"HEAD 过完整梯子"，还需一次带门禁的复跑（进行中：`b46va91uc`，二进制与 HEAD 同源：exe 更新于 `5cec283`，其后只有文档提交）。
- **覆盖更正（F261）**：`g_sess_len` 顺序修复**不可由 PHP 判别**——`prefill_top1` 的所有空返回都是基础设施故障，容量不足会被 `ensure_kv` 按需扩容 ⇒ 状态写为"代码推理正确 + 无回归"，不留"待补测试"的假待办。
- **分析更正（F262）**：树草稿器**已实现多命中**（`spec_tree_generate` 收 B 条），F157"树无收益"的正确解释是**重复文本的同 trigram 后继相同**（分支同源）；FR-06.2 因此改为"取**不同**后继"（n-gram 频次 top-k / 次优 token），并要求测试语料本身能表达多样性。

## 审计增补（F265–F269，2026-11-04 13:15）——三处缺陷在根因层闭合
- **冷启动首调长约束返回空（F208 起）→ 根因闭合**：真因是 KV 扩容时 `free_all()` 先丢旧行、新缓冲未初始化 ⇒ 跨过 `KV_MIN_CAP=512` 后注意力读到垃圾、**整行 logits NaN**（探针实测 `nonfinite=allsize=151936`，`sesslen=515`）。修法：扩容时 `memcpy` 保留 `keep_rows=pos` 行（`0796977`）。**验证**：干净构建（无探针）**138 PASS / 0 FAIL**、`first_len=3887 second_len=3887`（首次与后续**完全一致**，修复前 3909/3887）、8000 **99 PASS**、8001 par 9/9 + fairness PASS（`b3ne5mjjj`）。F226 的 re-prefill 兜底保留但已不触发。
- **F238 枚举叶丢嵌套 → 已修并过全梯**（字符串枚举 `e:`，`3e7cfd8`）。
- **F258 数字枚举只保结构 → 已修并过全梯**（数字枚举 `en:`，`81e5ff6`）：8000 **100 PASS**（含新门 `chat-json-schema-deep40-enum-num`）、自检 138 PASS、8001 全绿（`b2t03yrlx`）。
- **覆盖与边界的诚实标注**：`g_sess_len` 顺序修复不可由 PHP 判别（F261，容量按需扩容、空返回皆为基础设施故障）；混合类型枚举回退无约束；`const/pattern/min|max|multipleOf` 仍走扁平路径；**split 路径 `g_kv2` 扩容同样"先 free 再分配"（本机不可验，多卡实验路径）**。
- **未开始**：FR-06.2 多样草稿器（F262 已更正方案）、per-session KV 并行吞吐、SSD 前缀分桶、多卡 split、GGUF 分片、FR-05。

## 审计增补（F279–F282，2026-11-04 15:00）
- **判别性回归守卫（F279）**：新增 `SESSION-GROW`——从 3 token 起点连做 530 次受限步，跨过 `KV_MIN_CAP=512`；旧实现在此返回 `-1 (no logits computed)`，故该用例**能对着旧代码失败**。自检总数 **139**。
- **嵌套 plan 的取值约束扩面**：字符串枚举（`e:`）、数字枚举（`en:`）、`const`（两种）、**纯字面量 `pattern` 交替式**均受约束并通过完整梯子；新增门 `deep40-enum-num` / `deep40-const-leaf` / `deep40-pattern-leaf`，8000 门禁达 **102 个 PASS 行**。
- **当前基线**：139 项自检 / 102 形状 8000 / 8001 `par_smoke` 9/9 + `fairness` PASS，全部在同一 HEAD（`8a4cc86` 之前一个源码提交 `152a648`）上取得；`first_len == second_len == 3887`。
- **仍回退旧路径**：含元字符的 `pattern`、`minimum/maximum/multipleOf`、混合类型枚举；**未开始**：采样验证（F273 的 FR-06 前置）、per-session KV 并行吞吐、SSD 前缀分桶、多卡 split（需第二块设备）、GGUF 分片、FR-05。

## 审计增补（F285–F289，2026-11-04 16:49）——"形状缺陷"一族已收口
**问题类别**：装配出的 JSON **缺键**（不是取值错），因为 planner 只认识部分叶子形状。
- **F285 布尔叶**：`api_top_leaves` 只认 string/integer/number ⇒ `{"type":"boolean"}` 的键被静默丢弃。修：`b:` 叶（`true`/`false` 两字面量）。
- **F288 叶子上限**：嵌套检测器 `api_top_leaves($block,3)` ⇒ 四属性对象只装配三个。修：上限 6；**实测未扰动既有用例**（139 项自检全绿）。
- **F289 字符串数组叶**：数组值对象有 **两个** `{`（自身 + items），连叶子判断都进不去。修：识别 `items.type=string` → `a:` 叶，按"逐项字符串 + 逗号/`]` 终止"续解。
- **新增门**：`deep40-bool-leaf`、`deep40-mixed-leaves`（三种类型同层，逐键断言）、`deep40-four-leaves`、`deep40-array-leaf` ⇒ 8000 门禁 **106 个 PASS 行**。
**当前基线**：自检 **139 PASS / 0 FAIL**、8000 **106 PASS**、8001 `par_smoke` 9/9 + `fairness` PASS（同一构建）。
**仍在的缺口**：数字数组叶 / 对象数组叶（嵌套内无对应 plan 类型）、混合类型枚举、含元字符的 `pattern`、`minimum/maximum/multipleOf`；以及大项：**采样验证**（F273/F284，FR-06 的前置）、per-session KV 并行吞吐、SSD 前缀分桶、多卡 split（本机不可执行）、GGUF 分片、FR-05。

## 审计增补（F290–F294，2026-11-04 18:35）——叶子形状覆盖继续扩面
- **F290 数字数组叶**（`an:` 整数 / `af:` 小数）：`api_number_fill` 逐项续解，终止符（`,`/`]`）由同一步选出；8000 107 PASS。
- **F291 布尔数组叶**（`ab:`）：`true|false` 逐项；8000 108 PASS ⇒ **字符串/整数/小数/布尔数组**均可作为嵌套叶。
- **F292 可空联合叶**（`["string","null"]` 等）：此前整叶被丢弃（只匹配纯 `"type":"string"`）；8000 109 PASS。
- **F293 联合类型叶**（任意含标量成员的 `type` 数组）：输出任一成员即合法，取捕获到的第一个标量；8000 110 PASS。
- **F294 数组项的联合类型**（`items.type` 为数组）：与纯形式同处理（进行中，判据 `deep40-union-array-leaf`）。
**基线（F293 完成时）**：自检 **139 PASS / 0 FAIL**、8000 **110 PASS**、8001 `par_smoke` 9/9 + `fairness` PASS。
**仍未覆盖**：对象数组叶（嵌套内）、`pattern` 含元字符、`minimum/maximum/multipleOf`、以及无标量成员的联合（如 `["object","array"]`）。

## 审计增补（F295–F297，2026-11-04 20:20）
- **F295**：HEAD 从零重建（清 `build/gen` + `build/obj`）后完整梯子全绿：自检 139 / 8000 111 / 8001 9/9 + fairness。
- **F296/F297 对象数组叶**：先证路由、再动装配。路由靠"`"properties"` 出现 >2 次则根级数组-对象检测器让位"；装配靠 `ao:` 叶，**关键判别量是 4 个花括号**（值 + items + properties + 属性 type 对象），首版误用 3 导致静默漏项。最终 8000 **112 PASS**、自检 139、8001 全绿。
- **当前基线（HEAD 源码）**：**139 项自检 / 112 形状 8000 / 8001 9/9 + fairness**，均在 `73fa748` 的构建上取得。
- **仍未支持**：多属性对象项、数组项内的嵌套对象、无标量成员的联合、含元字符 `pattern`、`minimum/maximum/multipleOf`。
- **大项未动**：采样验证（F284，FR-06 前置）、per-session KV 并行吞吐、SSD 前缀分桶、多卡 split（需第二设备）、GGUF 分片、FR-05。

## 审计增补（F296–F300，2026-11-04 21:08）
- **对象数组叶（单字符串属性）已支持并过完整梯子**（F296/F297）：先证路由（根级数组-对象检测器在 `"properties"` 出现 >2 次时让位），再做装配（`ao:` 叶，**判别量是 4 个花括号**，首版误用 3 静默漏项）。8000 达 **112 形状**。
- **多属性对象项：两次尝试均未落地，已回退**（F298/F299/F300）。第一次改 plan 语法与装配，`entries` 键消失；第二次在服务器侧加探针（候选赋值之后、再之后）**两次都没打印** ⇒ 该请求在更上游的 dispatch 分支就被消费。分支归属仍未定位。
- **离线复刻探针未生效**：插入的 `DET-PROBE` 代码最终不在源码里（`src=0`），自检跑的是基线（139 PASS / 0 FAIL）——这一轮没有产生新信息，已如实记为**未执行**而非"探针沉默"。
- **当前基线（HEAD `dacbe58`，源码 = `73fa748`）**：自检 **139 PASS / 0 FAIL**、8000 **112 形状**、8001 `par_smoke` 9/9 + `fairness` PASS。
- **下一步建议**：多属性对象项暂缓——需要先给整条 dispatch 分段打点或做真正的离线复刻；**更高价值的是采样验证（F284）**，它是 FR-06 的前置（F273 已证贪心验证下多分支收益恒为 0）。

## 审计增补（F301–F306，2026-11-04 23:31）——FR-06 的前置条件已被**实测解除**
- **四件采样原语落地**（F301/F302/F304，均过完整梯子）：`model_token_prob(id,temp)`、`model_rand01()`、`model_sample_excluding(id,temp)`（残差）、`model_peek_argmax()`（**不推进会话**）。
- **采样验证循环**（F305）：`SPEC-SAMPLE: PASS (acc=9 rej=15 diff=15 reproducible)` ⇒ 得到**非贪心且可复现**的解码路径；F273 的"贪心验证下多分支收益恒为 0"前提不再成立。
- **经济性实测**（F306）：24 步 wall 对比 **greedy 444.87 ms / sampling 452.61 ms，ratio 1.017** ⇒ 接受判定+残差采样只贵 **1.7%/token**，任何被接受的草稿都是净收益。
- **当前基线**：自检 **144 PASS / 0 FAIL**、8000 **112 形状**、8001 `par_smoke` 9/9 + `fairness` PASS（同一构建）。
- **仍缺口**：把采样循环接进 C++ `spec_*`（多草稿 + 一次批量验证）并测 **同温度 tree-on/off wall**；多属性对象项（阻塞于上游 dispatch 归属未定位）；per-session KV 并行吞吐；SSD 前缀分桶；GGUF 分片；多卡 split（需第二设备）；FR-05。

## 审计增补（F307–F312，2026-11-05 03:28）——FR-06 第一次拿到**可复现的真实收益**，同时把"多分支"这件事证伪到草稿器
- **FR-06 采样验证落地（F307）**：接受判据从"逐位等于 argmax"改成 `rand01() < p(draft)`，拒绝时从残差 `(p−δ)⁺` 重采（归一用 `mass − p(want)`，第一版按 `mass` 归一是**数学错误**，会让重采样偏向词表头部）。`temp=0` 退化回贪心树，写成回归门而非推理。
- **收益三次独立复现（F312）**：`SPEC-TREE-WALL`（同 temp=0.9、同 prompt、`off_len==on_len==95`）在三个不同二进制上给出 **1.3406× / 1.3513× / 1.3567×**，`accepted/verifies` 恒为 **48/12 ⇒ 每次验证 4.0 token**。服务侧 `spec_bins`/`spec_depth` 已在活服务验证（8000 **113/113**，含新门 `chat-spec-tree-sampled`；8001 `par`+`fairness` 全 0）。
- **负结果同样入账（F310/F312）**：`alt_branch`（提交非零分支的次数）在**所有**树门禁里为 0；再叠加"分支倒序＋并列取最大下标"的覆盖控制仍为 0 ⇒ **当前 prompt-lookup 草稿器产生不了真正竞争的分支**（重复三元组的续写彼此相同，去重后只剩一条）。因此：①"多分支收益"**无证据**，已证的只是"一次验证更宽"；②F308 的修法**行为上未被触达**，只能说"已实现且无回归"。解开两者的同一个动作＝FR-06.2 步 1。
- **F308 缺陷与修法（审计自己新代码时从内核倒推）**：RoPE 行位置原按摊平序号 `pos+b*D+i` 给，且接受非零分支后不压实 KV 行 ⇒ 只有分支 0 精确。已改为①rope 位置按**逻辑深度**（`g_row_pos`，`prefill_tops_tree` 每条出口都清）②`kv_compact()` 把接受分支的 K/V 行搬到 `[pos,pos+run)`（行距 `dkv*elem()`，f16/f32 自适应）③`spec_stats()` 常驻 `alt_branch=` 覆盖门。
- **测量方法更正（F312，三轮样本）**：`SPEC-TREE-SWEEP` 的绝对计时三轮互相矛盾（(2,2)=865/1934/1221 而 `drafts/accepted/verifies` 恒定）⇒ **sweep 计时不可用于形状判断**；形状与收益一律以同函数、同温度、只变 `bins` 的 `SPEC-TREE-WALL` 隔离测量为准。
- **门禁工装缺陷（F309）**：起服务的 Bash 链没 `cd` 进 `engine` ⇒ exe 按 cwd 相对路径读不到 `serve.on` ⇒ **跑的是全量自测**，端口不监听，产出 `SMOKE_EXIT=1` 与 `FAIRNESS FAIL` 两个**看似引擎缺陷的红数**。修法是固化工装 `scripts/gate_live.sh`：从 `engine` 起进程、健康探测不通就中止整条链、按日志特征确认进程身份。
- **基线（f311 构建）**：自检 **146 PASS / 1 FAIL**（唯一 FAIL＝`SPEC-TREE-ALT` 覆盖门，非行为缺陷）、8000 **113 形状**、8001 `par_smoke`+`fairness` 全 0。日志 `run_f311.log` / `api_smoke_f311.log` / `par_smoke_f311.log` / `fairness_f311.log`。f312 轮（竞争分支提示词）结果见本文件末尾补记。
- **f314 补记（F314）**：`NOTCOVERED` 的原因是我的测量位置（正序运行的 stats 从未被读）＋`max_branches=` 解析偏移错。补正后 `engine/run_f314.log`：**147 PASS / 0 FAIL**，`SPEC-TREE-ALT: PASS`（两种分支顺序均与贪心逐 id 相同）＋`SPEC-TREE-ALT-COVERAGE: COVERED alt_branch=nat6 max_branches=2` ⇒ **F308 的深度位置＋KV 压实路径首次被实际走到且结果正确**；`SPEC-TREE-WALL` 第 5 个样本 **1.3377×**；8000/8001 门禁同轮全绿。**仍缺**：关掉修复的对照（将用 `model_spec_fix(0)` 在同一构建内复现缺陷）。
- **F316 补记（证据链闭合）**：`model_spec_fix(0/1)` 在同一构建内做双向对照——关掉修复 `SPEC-TREE-CTRL: PASS defect reproduced (alt_branch=2 diffs=4/84)`，打开修复逐 id 等于贪心 ⇒ **F308 的缺陷是行为级事实、修法是必要的**。梯子：自检 **148 PASS / 0 FAIL**、8000 **113/0**、8001 `par`+`fairness` 全 0；`SPEC-TREE-WALL` 六个独立构建 1.3377×–1.3629×。FR-06 的"验证形状"这一半至此收口，剩余上限在草稿器的分支竞争度（FR-06.2 步 1）。
- **F318（FR-06.2 步 1＝中性，已入账）**：频次排序分支在同构建 A/B 下与新近序**同样**的 `accepted/verifies`（18/5），wall 差 1.4%（机器抖动内），但 `alt_branch` 0→1 证明路径生效。上限诊断：候选集只有 `max_branches=2`（重复文本为 1）⇒ 下一步换草稿**来源**（top-k 不同续写／模型自举分支）而不是换排序。梯子：149 PASS/0 FAIL、8000 113/0、8001 全 0；`SPEC-TREE-WALL` 第七个样本 1.3597×。
- **F320（FR-06.2 步 2＝中性偏负，已入账）**：窗口加宽在竞争提示词上 `NOTWIDENED branches=2->2`、`drafts/accepted/verifies` 与窄窗口完全相同（36/40/11），而在 `SPEC-TREE-WALL` 上多算 3 个节点（36→39）却不多收一个 token；`temp=0` 仍逐 id 等于贪心、`alt_branch` 仍 COVERED。梯子全绿：**150 PASS/0 FAIL**、8000 113/0、8001 全 0，收益第 8 个样本 1.3346×。结论：排序与窗口两条"策略型"改法都被否证，**上限在草稿源**；`g_spec_multiwin` 默认应翻 0（待下一轮梯子）。
- **F321（F320 待办①已闭合）**：`g_spec_multiwin` 默认翻 0 并走完整梯子——`drafts` 39→36 而 `accepted/verifies` 不变，白算节点被消除，`SPEC-TREE-WALL` 第 9 个样本 **1.3679×**（区间 1.3346–1.3679）。梯子全绿：150 PASS/0 FAIL、8000 113/0、8001 全 0；F308 证据链不受影响（`COVERAGE: COVERED`、`CTRL: defect reproduced`）。FR-06.2 下一步＝模型自举草稿源（`max_branches` 2→4+）。
- **F322（FR-06.4 对树收口）**：`SPEC-TREE-SHAPES` 在同一次运行内共享一条 off 基线测四个形状——`bins=1/2/4`（depth=3）**逐字相同**（36/48/12；1.3710/1.3748/1.3733×），`bins=2 depth=4` 退化到 1.1119× ⇒ **收益源自验证深度，分支数在当前草稿器下不进计算**（`max_branches=1`）。梯子全绿：151 PASS/0 FAIL、8000 113/0、8001 全 0。替代了按 F312 作废的 sweep 计时。
- **F325（FR-06.2 步 3 有数了）**：模型自举分支在 `temp=0.9` 同构建 A/B 下把 `max_branches` 1→4、`accepted` 45→48（3.75→4.0 token/验证），但 `drafts` 36→120 使 wall 退到 **0.9225×** ⇒ 机制可行、形态不划算，默认关闭；`temp=0` 仍逐 id 等于贪心。梯子全绿 **152 PASS/0 FAIL**、8000 113/0、8001 全 0。同时记录一次我自己的实现错误及其判别法（自举块曾插在树摊平之后，`max_branches`/`drafts` 两个计数不变 ⇒ 一眼看穿"没进计算"）。**FR-06 多分支这一半的门槛由此改写为：变深度树。**
- **F327（FR-06.2 步 3 由亏转正，仍待重复采样）**：树改为**变深度**（`nodeOff/nodeLen` 取代 `b*D`），自举分支只占 1 个节点。同构建 A/B：`drafts` 120→62、`accepted` 45→48、wall 比 **0.9225×→1.0564×**。深度均匀主路径逐字不变（`SPEC-TREE-WALL` 1.3921×＝十样本最高；`temp=0` 仍等于贪心、F308 对照不受影响）。梯子全绿 152 PASS/0 FAIL、8000 113/0、8001 全 0。`g_spec_selfseed` 默认保持 0，直到 ≥3 对交错 A/B 的中位 ratio 仍 >1.0。
- **F329（自我更正）**：变深度自举的收益从 F327 单次采样的 `1.0564` **更正为三对交错 A/B 的中位 `1.0105`（+1.05%，三对分别 1.0159/1.0105/1.0023）**；代价是验证宽度 +72%（36→62 节点）换 +3 接受 ⇒ 方向正、幅度小，`g_spec_selfseed` 默认保持 0。FR-06.2 的三条策略路（排序/窗口/来源）至此全部有数：**单二进制约束下分支多样性只值个位数百分比**，再高需要独立草稿模型。梯子全绿 152 PASS/0 FAIL、8000 113/0、8001 全 0。新增判据纪律：wall 类测量必须 ≥3 对交错取中位数。
- **F330（贪心结构选择重测，链胜）**：`SPEC-CHAIN-VS-TREE` 中位＝链 `k=8` **397.19 ms** vs 树 `bins=2 depth=3` **698.07 ms**（均与贪心逐 id 相同），机理是链的单次验证提交长度不受 depth 截断（8 token/验证 vs 3）。推论：采样侧遗漏了 `bins=1` 的长深度形状，且服务侧 `spec_depth<=4` 是过紧硬夹，应按 `bins*depth<=32` 的掩码预算给。梯子 **153 PASS/0 FAIL**、8000 113/0、8001 全 0。
- **F332（FR-06 收益上界更正为 2.08×）**：形状扫描补测长深度 ⇒ `bins=1 depth=6` **2.0825×**（6.86 token/验证），而此前推荐的 depth=3 只有 1.35×、depth=8 反而 0.9271×（接受 6/24 崩塌）。错误根因是**扫描上界 4 属于未经检验的假设**；现 `SPEC-TREE-SHAPES` 常驻打印整条曲线，服务侧 `spec_depth` 改按 `bins*depth<=32` 放宽并经 `spec_bins=2,spec_depth=6` 实请求过门禁。梯全绿 **153 PASS/0 FAIL**、8000 113/0、8001 全 0。自举分支第 4 次中位 +0.74%，维持默认关闭。
- **F334（FR-06 数字定稿）**：把两条扫描的边界推开后——采样树峰值 **2.1457×**（`bins=1 depth=6`，复现 F332 的 2.08×；depth 8 塌到 0.93×、10/12 回升到 1.09/1.21×，故最优深度取决于该文本可信链条长）；贪心链峰值 **3.0213×**（`k=12`；此前长期引用的 `k=8`=2.576× 是被未复核的旧边界）。贪心下链仍完胜树（689–696 ms）。多分支第 5 次中位 +0.93% ⇒ 默认关闭维持。梯子 **153 PASS/0 FAIL**、8000 113/0、8001 全 0。方法论入账：**扫描边界＝未经检验的假设**，最优结论必须附"边界外一格"的探测。
- **F338（并发吞吐入账，"共享"一词有了数值）**：新增 `throughput_test.py`（含预热流）并挂入 `gate_live.sh`。两路并发聚合吞吐 **中位 1.149×**（四次 1.112/1.187/1.152/1.146；并发侧速率稳定 23.6–24.0 tok/s），⇒ 两槽只靠重叠 host 间隙获益，算力仍串行；**第三路连接失败**（两槽天花板）。第一版数据因未预热把基线污染成 4.06 s、假出 1.653×，已用预热流修正并写入方法论。真并行需 continuous batching（一次前向跨两段 KV），未做。
- **F339（更正计划自身的过期论断）**：核查现状后，"SSD 前缀缓存无多前缀索引/淘汰"**不成立**——`model_cache_key($ids,64)` 已把缓存索引成 64 个槽（`api_prefix_b<0..63>.kv`），磁盘实测仅 1 个文件/3.8 MB，也不存在无界增长。真正的缺口被重新表述为：①淘汰只看哈希不看新近度（热条目可被撞槽无条件覆盖 ⇒ 命中率悬崖）；②槽驻留状态不可观测。顺序约束：**先做可观测性门禁，再动 LRU**，否则改完无法证伪。未写代码。
- **F341（前缀缓存可观测性落地）**：新增常驻门 `CACHE-EVICT`——运行时找出撞进同一哈希槽的两个不同长提示，存 A→测 A 复用→存 B→再测 A 复用，得 **49 → 22 token** 的静默 displacement；40 次找不到碰撞即 FAIL（顺带守住 64 槽前提）。至此"recency-blind 驱逐"有了可证伪的判据，LRU 改造的验收写法＝该数字显著回升且 `PREFIXCACHE`/`PREFIXPART` 不变差。梯子全绿 **154 PASS/0 FAIL**、8000 113/0、8001 全 0，吞吐门第 5 个样本 1.105×（与 1.149 中位一致）。
- **F342（前缀缓存：2 路组相联落地，非 LRU）**：`api_cache_pick` 给每个前缀两个候选槽 + append-only owner 索引，主槽被他人占用即走备用；同一碰撞对（bucket 32）的复用从 F341 的 **49→22** 变为 **49→49**，`PREFIXCACHE` 19/19、`PREFIXPART` 逐 id 一致均不变差。梯全绿 **154 PASS/0 FAIL**、8000 113/0、8001 全 0、吞吐 1.093×（第 6 样本）。**两槽皆被占时仍无条件覆盖** ⇒ 真正的 recency 驱逐依旧是未做项，但判据已就位（`hit_after < hit_own` 即红）。
- **F344（前缀缓存驱逐补全为 2 路 LRU）**：owner 索引加入使用时戳，`api_cache_pick` 在两槽皆被他人占据时改选"最近最少使用"者为受害者。实测三层证据：单元门 `CACHE-LRU: PASS policy victim=earlier_used equal=primary`（与哈希运气无关）；端到端 `CACHE-LRU-INTEGRATION: COVERED hot entry survived the triple (own=48 after=48, victim=49->21)`（150 提示中搜到共享候选对的 A/B/C，被"用过"的 A 存活，更冷的 B 被打掉）；F342 的成对保护数字不变。梯全绿 **155 PASS/0 FAIL**、8000 113/0、8001 全 0、吞吐 1.125×。仍限 2 路/128 槽，真并行仍需 continuous batching。
- **F348（服务面 liveness 定性完成）**：同 seed 只变 `spec_bins/depth` 的差分证明服务端采样树**确实在执行**（off≠on，2/2 试验），固化为 `chat-spec-tree-affects-output`；8000 升至 **114/114**、8001 全 0、吞吐第 8 样本 1.084×。同时记录两处事实：非流式请求只能打 8000（8001 只流式），以及"在跑 ≠ 有收益"——服务负载上 spec/plain 差 ≤3%，接受率仍需把 `model_spec_stats()` 暴露进响应才能钉死。引擎源码本轮未改。
- **F350（服务面从"在跑"进到"在接受"）**：新增 `GET /spec_stats` 诊断路由（不碰任何响应形状）+ 门 `chat-spec-tree-accepts`（要求 `verifies>0 && accepted>0`，串行读进程级计数）。梯全绿：自检 **155 PASS/0 FAIL**、8000 **115/115**（三条 spec 门全绿）、8001 全 0、吞吐第 9 样本 1.059×。FR-06 服务面结论定型：**路径执行 + 有接受，但端到端不提速**；瓶颈移到工作负载结构与单设备串行算力。
- **F352（服务面接受计数，首次）**：门禁固定报告 spec off/on 并发速率 + `GET /spec_stats`。首轮：**21.07 vs 20.75 tok/s（−1.5%）**，计数 `drafts=36 accepted=13 verifies=6` ⇒ 每次验证 ~2.2 token，**在接受但换不到时间**（验证宽度从 1 涨到 6，自洽地抵消）。尾部 `drafting=off` 表明这是末次调用快照，不能当平均接受率引用；按请求归因仍是缺口。三项均为**报告**而非硬门，理由：硬门会把机器漂移或本负载的固有零和性误当成回归。
- **F356（FR-06 服务面定档）**：同探针跑 `sampled` 模式——tree(2,6) 满长度 128/128、`accepted=41 verifies=11` 却只有 **1.006×** 且 `drafting=off`（自适应止损生效）⇒ F352 的中性结论**不是短样本假象**；tree(1,6) 表面 1.564× 但只生成 52 token，被探针有效性判据拒绝上报。与 F355 合并：**贪心链 3.31× 有效、采样树 ≈1.0× 有效**，收益取决于负载重复度与温度 ⇒ 文档口径改为"按温度选路"。
