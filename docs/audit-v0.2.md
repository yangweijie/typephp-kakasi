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
- **F358（更正 F356 的外推）**：两套探针进入 `gate_live.sh` 后首次满长度测量显示采样树服务面 **2.346×(1,6) / 2.112×(2,6)**、贪心链 **3.26×**，全部有效性判据 PASS ⇒ F356 的"采样树中性 1.006×"降级为特定词表提示的局部观测（那次尾部 `drafting=off`）。再次确认深度>分支（同 accepted=124，drafts 108→216 无收益），并把服务口径改为"由调用方按负载重复度选择，默认不开"。梯：8000 115/115、8001 全 0、吞吐 1.054×。

## FR-06 证据索引（F358 收点；每条都可 grep 复核）
| 论断 | 数值 | 证据文件（本轮实测行） |
|---|---|---|
| 采样树自测峰值（深度 6） | 2.1457× | `engine/run_f333.log`：`best=1,6 speedup=2.145661670111x` |
| 贪心链自测峰值（k=12） | 3.0213× | `engine/run_f333.log`：`chain12_med_ms=330.89995384216 (x3.0213121309609` |
| 树位置/KV 压实缺陷必要 | 关修复 4/84 位不同 | `engine/run_f333.log`：`SPEC-TREE-CTRL: PASS defect reproduced ... (alt_branch=2 diffs=4/84)` |
| 非零分支确被提交 | alt_branch=nat6 | `engine/run_f324.log`：`SPEC-TREE-ALT-COVERAGE: COVERED alt_branch=nat6/rev0/smp0 max_branches=4`（f324 起为 nat6） |
| 前缀缓存成对保护 | 49→49 | `engine/run_f343.log`：`CACHE-EVICT: PASS 2-way protected ... hit_own=49 hit_after=49` |
| LRU 三方争抢 | 热存活/冷被逐 | `engine/run_f343.log`：`CACHE-LRU-INTEGRATION: COVERED ... own=48 after=48, victim=49->21` |
| 服务面贪心投机 | 3.263× | `engine/spec_greedy_f357.log`：`k=8 ... 3.263x`（192 token 满长度，PASS measured） |
| 服务面采样投机 | 2.346× / 2.112× | `engine/spec_sampled_f357.log`：`tree(1,6) ... 2.346x`、`tree(2,6) ... 2.112x` |
| 采样在接受（服务面） | verifies>0 accepted>0 | `engine/api_smoke_f357.log`：`PASS chat-spec-tree-accepts`；`engine/spec_stats_f357.log` |
| 并发吞吐上限 | 1.05–1.15×，第三路失败 | `engine/throughput_f357.log`（scaling），`engine/throughput_f337_3s.log`（errs=1） |
| 负结果（不外推） | 词表提示 1.006×/0.71× | `engine/run_f340.log`+F354/F356 条目（早停/自适应停用所致） |
**基线**：`engine/run_f349.log` 155 PASS / 0 FAIL；`engine/api_smoke_f357.log` 115 PASS / 0 FAIL；`engine/par_smoke_f357.log`+`fairness_f357.log` 退出码 0；HEAD 二进制 == 该自测构建。

## 审计增补（F359–F360，2026-11-05 15:00）——continuous batching 落地，收益从 1.06× 抬到 1.45×
- **F359（机制落地）**：批 API（`model_batch_add/stop/next`，逐会话温度/种子）、每行计划（slot/lo/hi/abspos）、逐行 KV 落槽（`kv_store_slots`）、8001 两会话**共用一次前向**（删掉 `model_session_save/restore` 轮转）；批布局 = 暂存区 `[0,8192)` + 两个 4096 行区域。**代价**：8001 不再用前缀缓存；提示+max_tokens 超槽容量按 400 拒绝。**坑**：主机指针传进 device kernel 会让 kernel fault 被 `catch` 吞掉（`BATCH-EQ rows=0` + 队列错误态导致下一个无关调用崩溃）⇒ 必须用 device 副本（`plan_upload/plan_free`）。**第一版吞吐没达标（1.060×）**：M=2 落进分块 GEMM，16 行 tile 里只有 2 行有用 ⇒ 8 倍白算 MAC ⇒ **F353"不是内核重写"对机制成立、对性能不成立**。
- **F360（性能）**：`gemv2`——权重只读一次、点积进两个累加器；逐行累加顺序与 `gemv` 相同 ⇒ 批里的行与单独解码**逐位相同**。引擎内比值 **1.77×**（单流 18.69ms/步 vs 批 21.07ms/步，第二个会话只多 13%）；服务面 8001 scaling 4 样本 **1.457/1.448/1.496/1.418**（中位 **1.45×**），**目标 1.6–2× 未达成**。
- **瓶颈已经换人**：服务面每 token 60.3ms 里引擎只占 18.7ms ⇒ **~41.6ms/token 在 PHP 侧**（token→文本、socket 写、HTTP 帧），两个流各付一次 ⇒ 把 1.77× 稀释成 1.45×。**下一轮杠杆是 PHP 每 token 热路径，不是 kernel。**

### F359/F360 证据索引（每条都可 grep 复核）
| 论断 | 数值 | 证据文件（本轮实测行） |
|---|---|---|
| 批跑 == 单跑（逐 id） | PASS tokens=10 rows=2 | `engine/run_f360.log`：`BATCH-EQ: PASS tokens=10 rows=2 batch==single for both conversations` |
| 引擎内批处理比值 | 1.77×（53.49 → 94.93 tok/s） | `engine/run_f360.log`：`BATCH-THRU: PASS single_tps=53.49 batch_tps=94.93 ratio=1.77 single_ms=224.31 batch_ms=252.79` |
| 服务面 8001 并发 scaling | 中位 1.45× | `engine/throughput_f360.log`（1.457×）+ `engine/thru3_f360.log`（1.448/1.496/1.418） |
| F359 第一版（无 gemv2） | 1.060× | `engine/throughput_f359.log`：串行 3.22s/18.95 tok/s、并发 6.07s/20.08 tok/s |
| 8000 单槽（对照，未批处理） | 1.004× | `engine/thru_plain_f360.log` |
| 投机门未受 M=2 改动影响 | greedy 3.324× / sampled 2.358× | `engine/spec_greedy_f360.log`、`engine/spec_sampled_f360.log` |
| 8000 形状 / 8001 par+fairness | 115 PASS 0 FAIL / 退出码 0 / 0 | `engine/api_smoke_f360.log`、`engine/par_smoke_f360.log`、`engine/fairness_f360.log` |
| 自检基线 | **157 PASS / 0 FAIL** | `engine/run_f360.log`（相对 155 基线多 `BATCH-EQ` + `BATCH-THRU`） |
**基线（本次 HEAD）**：`engine/run_f360.log` 157 PASS / 0 FAIL；`engine/api_smoke_f360.log` 115 PASS / 0 FAIL；8001 `par`+`fairness` 退出码 0；哨兵 0；HEAD 二进制 == 该自测构建（exe 3377152 bytes，22:31）。

## 审计增补（F361，2026-11-05 15:04）——F359 的吞吐目标在 temp 0 下达成，缺口归因更正
- **实测**（同一构建、同一提示、60 token/流）：temp 0.0 下 8001 并发 scaling **1.714× / 1.789×**（中位 **1.75×**，落在 F359 的 1.6–2× 目标内）；temp 0.9 下仍 1.45×（4 样本 1.418–1.496）。证据：`engine/thru_t0_f360.log`。
- **归因更正**：F360 把每 token 的非引擎开销（约 41.6ms）写成"PHP 侧"，**实际构成是采样器 ~15ms/流/token（引擎内 C++：全词表 exp + 151936 元素排序 + 核截断）+ 其余 ~26ms/token（PHP 与 Python 客户端）**。两个流各付一次采样 ⇒ 引擎内 1.77× 被摊成服务面 1.45×。**下一轮该动采样器**；F360 那句"下一轮动 PHP"作废。
- **边界**：temp 0 与 temp 0.9 是两个不同的分布设置，两个数字都对、不能互相替代；`gemv2` 只覆盖 M=2，M≥3 未测。

| 论断 | 数值 | 证据文件 |
|---|---|---|
| temp 0 下批处理达标 | 1.714× / 1.789× | `engine/thru_t0_f360.log`（串行 2.71s/22.52 tok/s，并发 3.16s/38.61 tok/s） |
| temp 0.9 下摊薄 | 中位 1.45× | `engine/throughput_f360.log` + `engine/thru3_f360.log` |
| 采样每流每 token 的代价 | ~14.9ms | 同上两组的串行速率之差（59.3 vs 44.4 ms/token） |
| 引擎内批处理比值 | 1.77× | `engine/run_f360.log`：`BATCH-THRU: PASS ... ratio=1.77` |

## 审计增补（F362，2026-11-05 15:07）——采样器的账算清：15.48ms/次，去排序可到 5.05ms
- **探针**（`probe/sampler/sampler_bench.cpp`，纯 C++，`probe/sampler/build.bat` 跑）：引擎现状 **15.484 ms/次**（与服务面独立测到的 14.9ms/token/流 相符 ⇒ F361 的归因获第三方确认）；"打包 (p,id) 排序" **16.586 ms（更慢，否决）**；**quickselect 完全不排序 5.045 ms（3.07×）**。
- **含义**：开销主要来自 O(n log n) 的比较次数本身，不是访存 ⇒ 下一轮把 `sample_from` 的 `top_p ≥ 1.0` 分支换成 quickselect（**分布不变**，同 seed 只在 tie 上可能不同），`top_p < 1` 保留排序（核截断要有序前缀）。
- **边界**：探针的分布校验只覆盖头部 token（600 次抽样只落到 6 个不同 token）⇒ 尾部分布必须**在引擎内再证一次**，不能引用探针的 0.00%。

| 论断 | 数值 | 证据文件 |
|---|---|---|
| 采样器现状成本 | 15.484 ms/次 | `probe/sampler/sampler_bench.log`（600 次抽样，n=151936，temp 0.9，top_p 1.0） |
| 打包排序更慢 | 16.586 ms（0.93×） | 同上 |
| 去排序可达 | 5.045 ms（3.07×） | 同上 |
| 与服务面测值互证 | 15.48 vs 14.9 ms/token | `probe/sampler/sampler_bench.log` + `engine/thru_t0_f360.log` / `engine/throughput_f360.log` |

## 审计增补（F363，2026-11-06 00:40）——采样器去排序化：每流每 token 的采样开销 14.5ms → 4.7ms，8001 scaling 1.45× → 1.640×
- **改动**：`engine/src/model_sycl.cc` 的 `sample_from` 拆成两条路——`0 < top_p < 1` **原样保留**（核截断需要有序前缀）；`top_p ≥ 1.0`（**服务默认**）换成 `smp_select`（带上方概率质量累加的 quickselect，**不排序**），并复用一块 `static` 缓冲（引擎内无线程）。
- **分布证明在引擎内**：新增原生 `model_sampler_check`（`model_sycl.cc` + `model.cc` 包装 + `model.stub.php` 声明）与自检门 `SAMPLER-DIST`，把已发布的采样器与"先排序再走累积"的参考实现逐 token id 对照。
- **边界**：①**tie 顺序变了**（新比较器 `p 降序、id 升序`）⇒ 同 seed 的具体 token 可能变，**分布不变**，仓库无 golden 输出文件；②`top_p < 1` 未动；③采样开销**不是零**（还剩 4.7ms/流/token）；④**F362 探针的 `select_sample` 用 `hi = n - 1` 而 `nth_element` 的 `hi` 是开区间上界 ⇒ 最后一个词表项永远抽不到**（`probe/sampler/sampler_bench.cpp:164`），已发布的 `smp_select` 用 `hi = n` ⇒ **F362 的 5.045ms 是探针数字，不是已发布代码的数字**。

| 论断 | 数值 | 证据文件 |
|---|---|---|
| 分布不变（引擎内对照） | `mism=0 amb=0 grid=0 exact=0`（200 抽样 / 128 点扫描 / 12 精确点） | `engine/run_f363b.log`：`sampler[dist] PASS draws=200 ... ids=44` |
| 自检门禁不回退 | 159 PASS / 0 FAIL（基线 157，只多 `SAMPLER-DIST`） | `engine/run_f363b.log` |
| 8000 形状门 | 115 PASS / 0 FAIL | `engine/api_smoke_f363.log` |
| 8001 门 | `PAR_EXIT=0`、`FAIR_EXIT=0` | `engine/par_smoke_f363.log`、`engine/fairness_f363.log` |
| temp 0.9 服务面（4 样本中位） | 串行 23.36 tok/s、并发 38.93 tok/s、scaling **1.640×** | `engine/thru_s1_f363.log` … `thru_s4_f363.log`、`engine/throughput_f363.log` |
| 同构建 temp 0 对照（4 样本中位） | 串行 26.24 tok/s、并发 45.17 tok/s、scaling **1.736×** | `engine/thru_t0_s1_f363.log` … `thru_t0_s4_f363.log` |
| 采样开销（抗漂移口径：同构建 temp0 − temp0.9） | **14.5 → 4.7 ms/流/token** | 上两行的倒数之差 |
| 投机门未被扰动 | greedy 3.335× / sampled 2.368×（基线 3.324×/2.358×） | `engine/spec_greedy_f363.log`、`engine/spec_sampled_f363.log` |
| 第一版门禁写错的留档 | `mism=150`（参照实现多推进了一次 RNG） | `engine/run_f363.log` |

## 审计增补（F364，2026-11-06 00:53）——每 token 的钱：**客户端/传输被排除**，剩下的大头**随上下文长度增长**
- **方法（不改引擎、不重建）**：服务端 `engine/par_trace.log` 本来就给每个 token 打时间戳（`STEP A n=k`，写在 socket 之后，`main.php:6857`/`6933`）⇒ 与客户端到达时刻（同一台机器的同一个 Unix epoch）直接相减。新增 `engine/tests/chunk_gap_probe.py`（客户端到达）、`engine/tests/chunk_gap_analyze.py`（配对与拆分）、`engine/tests/chunk_gap_ab.py`（双流：用 trace 相邻行拆迭代）、`scripts/chunk_gap_run.sh`（起服务/跑探针/收尾）。
- **否掉的推断**：F363 写"差额里必然有客户端的 wall 与 socket 写阻塞" ⇒ **被测量否掉**：transport lag 中位 |−0.13 ms|/token（60 token 合计 −8.6…−13.4 ms），双流下 **B 的写只占 0.4 ms**（服务端 `fwrite` 没在等读者）⇒ **那 ≈23ms 全在服务端**。
- **新的账**：每 token 成本 = **常数 + 长度相关**两项。60 个 token 内每 token 成本涨 **14.6…+21.4 ms**（≈0.25–0.36 ms/上下文 token，temp 0.9 与 temp 0.0 一样涨）⇒ 剩下的不是固定开销，而是**随上下文变长而变贵的每步工作**（机制待证）。
- **边界**：客户端探针只读单流（双流侧是服务端 trace 自拆）；**长度增长的机制未证**（下一步查 `model_batch_next` 每步搬了什么）；本轮**未改引擎与门禁** ⇒ 基线不动。

| 论断 | 数值 | 证据文件 |
|---|---|---|
| transport lag ≈ 0（单流） | 中位 −0.132 / −0.132 / −0.138 / −0.135 ms（60 token 合计 −8.6 / −13.4 / −9.3 / −10.5 ms） | `engine/chunk_gap_split_f364e_r1_t0_9.json` … `engine/chunk_gap_split_f364e_r4_t0_0.json` |
| 服务端 cadence（temp 0.9） | 中位 47.8 / 47.4 ms | 同上 |
| 服务端 cadence（temp 0.0） | 中位 43.6 / 43.6 ms | 同上 |
| 服务面采样开销（抗漂移：同批次 temp0.9 − temp0.0） | **4.0 ms/token**（F363 在会话路径上量到 4.7 ms） | 上两行之差 |
| 客户端没在等（双流） | B 的写中位 **0.4 ms**（max 6.7）；迭代 cadence 中位 57.8 ms（min 36.0 / max 79.7） | `engine/chunk_gap_ab_f364e.json` |
| 双流 scaling 同批复测 | **1.679×**（F363 记录 1.640×） | `engine/chunk_gap_two_f364e.log` |
| 每 token 成本随长度增长 | q1→q4 中位 34.6→51.3 / 39.4→52.0 / 36.7→52.7 / 38.9→55.2 ms；前10→后10 +21.4 / +14.6 / +18.9 / +21.3 ms | `engine/chunk_gap_f364e_r1_t0_9.json` … `r4_t0_0.json`（四分位由到达时刻现算） |
| 对齐三坑的留档 | 第一次跑 lag 中位 969289 ms、server gap 最小 −971602 ms | 数字留在 `findings.md` F364「对齐的三个坑」里（那次产物在清理临时文件时一并删掉，**没有留在仓库里**） |
| 增长在**服务端**（不是客户端读得慢） | 服务端自己的迭代 cadence 同样在涨：q1 41.8 → q4 61.5 ms（首 10 次 42.0 → 末 10 次 66.8） | `engine/chunk_gap_trend_f364e.json`（由 `engine/par_trace.log` 最后 120 行 `STEP A` 现算） |

## 审计增补（F365，2026-11-06 01:36）——长度相关的那笔钱**定位并改掉**：注意力三遍重算 → 算一次缓存复用
- **机制（F364 的"机制待证"到此为止）**：自检里新增的 `PERF stage-sweep`（L=8/32/64/128/256 各起会话量一步的 stage 计时）在旧二进制上显示**只有 s1（24 个 block）随长度涨**（s0≈0.1 ms、s2≈2.2 ms 恒定）⇒ 长度相关的钱在 block 里；block 里随长度变贵的是 `attention()`，它对可见 KV 范围走三遍（max / softmax 求和 / 加权 V）且**每遍重算同一个点积**。
- **改法**：每个 `(token, head)` 的分数**只算一次**写进设备端 scratch（`T × NH × scoreRows`），三遍复用；**值与求和顺序不变 ⇒ 逐位相同**。分层切到第二块设备时**另配一块 scratch**（第一版跨设备共用缓冲 ⇒ 自检 split 段崩 `0xC0000005`）。
- **测法**：两条路径**都在同一个二进制里**（`model_set_attn_mode(0|1)`；环境 `KAKASI_ATTN_MODE=1`），sweep **按 L 配对**（每个 L 先 m0 再 m1，各两遍）⇒ 相邻测量抵消机器漂移（单独一趟 sweep 曾出现 L=32 的 s1 高于 L=64）。**跨轮比较不成立**：旧构建同一算法 fit 0.39 ms/token vs 本轮 0.365 ⇒ 机器漂 ≈13%。
- **正确性**：新增自检门 `ATTN-MODE`——**逐 L 比对两条路径采样出的 token id**（五个 L 全同）⇒ 这是"逐位相同"的直接证据；自检 **160 PASS / 0 FAIL**（`engine/run_f365c.log`，相对 159 基线只多这一项）。

| 论断 | 数值 | 证据文件 |
|---|---|---|
| 长度相关的钱在 s1（block）里 | 旧构建 s1 随 L：21.75/23.82/37.03/65.31/**117.60** ms（s0、s2 恒定） | `engine/run_f364b.log`（`PERF stage-sweep`） |
| 同构建配对 A/B：s1（缓存 vs 三遍重算） | L=8 10.18/9.83 vs 11.20/12.27；L=32 16.18/14.94 vs 19.77/19.48；L=64 21.45/21.22 vs 32.82/32.06；L=128 36.76/36.75 vs 56.72/57.04；L=256 **62.43/62.19 vs 101.96/101.37** ms | `engine/run_f365c.log`（`PERF sweep L=…`） |
| 拟合：长度相关项降 42%、常数项不动 | m0 **s1 ≈ 8.54+0.212·L**；m1 **s1 ≈ 8.86+0.365·L**（5 点最小二乘） | 同上 |
| 每步整体收益（随长度增长） | L=8 1.15×；L=32 1.23×；L=64 1.48×；L=128 1.50×；L=256 **1.60×** | 同上（`step=` 两模式之比） |
| 两条路径逐位相同 | 五个 L 的 token id 全同（3283/279/6722/315/374） | `engine/run_f365c.log`（`ATTN-MODE: PASS`） |
| 自检 | **160 PASS / 0 FAIL** | `engine/run_f365c.log` |
| 跨设备共用缓冲会崩（真缺陷，已修） | 自检 split 段 `0xC0000005`（第一版 scratch 在主设备） | `engine/run_f365.log`（崩掉的那一趟）与 `engine/run_f365b.log`（修后 159/0） |

### F365 服务面端到端（同构建 A/B）与门禁
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 服务面每 token cadence（temp 0.9） | m0 **31.1** ms / m1 **44.6** ms（各 3 次的中位）⇒ **1.43×** | `engine/chunk_gap_split_f365m0_r*_t0_9.json` / `engine/chunk_gap_split_f365m1_r*_t0_9.json` |
| 服务面每 token cadence（temp 0.0） | m0 30.9 / m1 34.3 ms（m1 三次 34.3/39.4/33.9，噪声大） | `engine/chunk_gap_split_f365m{0,1}_r*_t0_0.json` |
| 双流一次迭代 cadence | m0 **40.4** / m1 **47.6** ms（trace 自拆，B 的写两模式都是 0.3 ms） | `engine/chunk_gap_ab_f365m0.json` / `engine/chunk_gap_ab_f365m1.json` |
| 8001 双流 scaling | m0 **1.637×** / m1 1.582× | `engine/chunk_gap_two_f365m0.log` / `engine/chunk_gap_two_f365m1.log` |
| 端到端"逐位相同" | 两模式输出文本逐字节相同（148 / 150 字符，探针已带 `seed=12345`） | `engine/chunk_gap_f365m{0,1}_r1_t0_9.json` / `…_t0_0.json` |
| 活门禁 | 8000 **115 PASS / 0 FAIL**；8001 `par` **0**、`fairness` **0**、scaling **1.625×**；投机 greedy **2.841×** / sampled **2.071×**；哨兵 0 | `engine/gate_f365.log` |

## 审计增补（F366，2026-11-06 07:35）——**负结果**：split-KV 并行度 ×16 = 收益 0%，注意力的长度成本不是工作项数
- **假设**：F365 后注意力每 `(token, head)` 只有 1 个工作项（解码 T=1 时 NH=14 个），整条可见 KV 范围是一项之内串行 ⇒ 猜占用率/延迟受限。
- **实现（默认关）**：模式 2 `attention_split`——范围切 `P=min(16, scoreRows/16)` 块，每块 online softmax 出 `(max, sum, 未归一化加权 V)`，第二个小 kernel 合并；只在 `T*NH<224` 时启用；partial 缓冲 `T*NH*P*(HD+2)`，**第二块设备另配一份**（F365 的教训）。开关：`model_set_attn_mode(2)` / `KAKASI_ATTN_MODE=2`。
- **判据设计**：模式 2 **换了求和分组**，所以不断言逐位相同，而是新增 `ATTN-SPLIT`（五个 L 的 greedy token 与模式 0 一致）；收益用同一二进制内**按 L 配对**的 m0/m2 计时。
- **结论**：**假设被否**——五个 L 的 s1/step 差都在 ±3% 内且方向不一致（L=64 慢 7%）⇒ **并行度乘 16 收益为 0**。与 F365（动字节 ⇒ 斜率 −42%）合起来 ⇒ 剩下的是**每位置 K+V 读路径本身**。
- **状态**：默认仍是模式 0 ⇒ **发布的数值行为与 F365 一致**；模式 2 留在树里作测量工装。自检 **161 PASS / 0 FAIL**（比 F365 多的那一项是 `ATTN-SPLIT`）、`RUN_EXIT=0`。

| 论断 | 数值 | 证据文件 |
|---|---|---|
| split-KV 无收益（s1 中位） | L=8 10.30 vs 10.39；L=32 15.96 vs 15.57；L=64 22.20 vs 23.82（**更慢**）；L=128 39.45 vs 38.94；L=256 64.23 vs 62.57 ms | `engine/run_f366.log`（`PERF sweep L=…` m0/m2 两列） |
| 每步整体同样没变 | L=8 12.67/12.82；L=32 18.44/18.00；L=64 24.64/26.34；L=128 44.76/42.24；L=256 67.52/65.84 ms | 同上（`step=`） |
| 并行度确实上去了 | `T*NH=14 → 14×P`，L=256 时 P=16 ⇒ **224 个工作项/层**（规则见 `splitP` 计算） | `engine/src/model_sycl.cc`（`splitP` / `attention_split`） |
| 分组不同但 argmax 未翻 | 五个 L 的 greedy token 与模式 0 全同 | `engine/run_f366.log`（`ATTN-SPLIT: PASS`） |
| 基线不动 | 自检 **161 PASS / 0 FAIL**、`RUN_EXIT=0`；默认模式 0 | `engine/run_f366.log` |

## 审计增补（F367，2026-11-06 08:03）——**自我更正**：F366 的负结果出自一个没生效的开关；split-KV 真值 **3.9×**
- **缺陷**：`set_attn_mode` 写作 `g_attn_mode = (m == 1) ? 1 : 0`（提交 `081f244`）⇒ `model_set_attn_mode(2)` 静默变 0 ⇒ **F366 的"模式 2"＝模式 0**，"16× 并行度收益 0%"是**测量仪器未接入**的假象。
- **为什么当时没看出来**：计时与 token 两种对照在"两侧同路"下**必然**给出"无差异 / 一致"，即负结果与开关失效**不可区分**；F366 把它读成了结论。
- **现在怎么防**：新增门 **`ATTN-ENGAGE`**——每次 `model_set_attn_mode(m)` 的返回值（引擎真实模式）与请求值**逐一断言**（8 次：0/1/2 与 0/4/5）。这是仓库里既有的"先证探针在二进制里"纪律，**在本轮的新开关上被再次需要**。
- **更正后的结论**：split-KV 在 L=256 把 s1 从 63.66 降到 **16.35 ms（3.89×）**、每步 66.83 → 18.72 ms（3.57×）、长度斜率 0.216 → **0.0236 ms/上下文 token（−90%）**；**L=8 慢 3%**（P=1 仍付两次 launch）。F366 的**字节**结论保留（f16 使 KV 字节减半而斜率 0.2210→0.2189，独立对照）。
- **新做的计时消融（模式 4/5，输出无效、已核开关生效）**：L=256 的 s1 m0 63.96 / m4 **40.35（V 的 64 次全局读-改-写 → 1 次，−37%）** / m5 **38.34（去掉 64 长相关点积链，−40%）** ⇒ 两笔各占约四成；**split 的 −74% 超过任一侧** ⇒ 收益机制是**缩短每线程串行依赖链**，不是减少总工作量。

| 论断 | 数值 | 证据文件 |
|---|---|---|
| 开关夹取缺陷 | `(m == 1) ? 1 : 0`（本轮改为 `0..5` 才生效） | `git show 081f244:engine/src/model_sycl.cc` 与 `engine/src/model_sycl.cc`（现值） |
| F366 的 m2 列其实是 m0 | 五个 L 上 m2 与 m0 差 ±3% 且方向不一致 | `engine/run_f366.log`（`PERF sweep L=…`） |
| split-KV 真收益 | L=32 1.31×、L=64 1.73×、L=128 2.33×、L=256 **3.89×**（s1）；每步 L=256 **3.57×**；L=8 **0.96×（慢 3%）** | `engine/run_f367.log`（`PERF sweep L=…`） |
| 斜率更正 | 0.216 → **0.0236 ms/上下文 token（−90%）** | 同上（五点拟合） |
| 两笔成本各占四成 | m4 −37%（V 读-改-写）、m5 −40%（点积链），单位：L=256 的 s1 | `engine/run_f367.log`（`PERF ablate m4/m5`） |
| 开关确实生效（新门） | `ATTN-ENGAGE: PASS`（8 次切换逐一断言） | `engine/run_f367.log` / `engine/run_f367m2.log` |
| 模式 2 下的全梯预验证 | 见下（`run_f367m2.log`、`gate_f367m2.log`） | 同左 |

### F367 模式 2 下的预验证（提默认之前先跑一遍全梯）
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 模式 2 下的自检 | **162 PASS / 0 FAIL**、`RUN_EXIT=0`（含 `ATTN-ENGAGE: PASS`） | `engine/run_f367m2.log` |
| 模式 2 下的活门禁 | 8000 **115 PASS / 0 FAIL**；8001 `par` **0**、`fairness` **0**；哨兵 0 | `engine/gate_f367m2.log` |
| 模式 2 下的 8001 双流 scaling | **1.822×**（模式 0 的同一轮基线是 1.625×） | `engine/gate_f367m2.log`、`engine/gate_f365.log` |
| 模式 2 下的服务面投机比（只报告，不是门） | greedy **1.634×** / sampled **1.408×**（模式 0 是 2.841×/2.071×）⇒ **基线每步变便宜，drafting 的相对优势随之缩小** | 同上 |
| 边界 | 自检里 `switch` 之后的段落会把模式恢复成 0（sweep 收尾设 0），所以模式 2 下的自检只覆盖 sweep 之前的 OPS/FORWARD/GENERATE/SAMPLING/SAMPLER-DIST；**服务端从不调用该开关 ⇒ 活门禁整个跑在模式 2 上**，这部分才是提默认的依据 | `engine/main.php`（sweep 的 `model_set_attn_mode(0)`） |

### F368（2026-11-06 00:35）——split-KV 提为默认，全梯绿，服务面单流仍有 1.03× 的未解释缺口
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 默认已是 split-KV，且服务端自证 | `ATTN_MODE 2` / `ATTN_MODE 0` 两个服务日志各自一行 | `engine/serve_f368b2.log`、`engine/serve_f368b0.log` |
| 引擎每步（会话路径，两遍取优者） | L=8 13.5/13.0 无倒退；L=64 **24.7 → 16.0**；L=256 **66.5 → 18.2** ms | `engine/run_f368.log`（`PERF sweep L=…`） |
| 自检（默认=2，sweep 之后的段也在模式 2 下跑） | **162 PASS / 0 FAIL**、`RUN_EXIT=0`、`ATTN-ENGAGE` PASS | `engine/run_f368.log` |
| 活门禁 | 8000 **115/0**；8001 par **0** fairness **0**；scaling **1.837×**；哨兵 0 | `engine/gate_f368.log` |
| 双流 scaling 同构建 A/B | 模式 0 **1.577×** → 模式 2 **1.920×**（另一批 1.617 → 1.850） | `engine/chunk_gap_f368b0.log`、`engine/chunk_gap_f368b2.log` |
| **未解释缺口** | 单流每 token cadence 31.9 → **30.9**（1.03×），同期引擎自量 24.7 → 16.0（1.54×） | `engine/chunk_gap_split_f368b{0,2}_r*.json` 与 `engine/run_f368.log` |
| 投机服务面比（只报告） | greedy **1.660×** / sampled **1.424×**（模式 0 时代 2.841/2.071）⇒ 基线每步变便宜，drafting 相对优势缩小 | `engine/gate_f368.log` |

### F369（2026-11-06 00:47）——服务面 residual 第一次被量出来：**比引擎设备段还大，并且会吸收设备侧的节省**
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 服务面每 token 拆分（模式 2，发布默认） | cadence **30.8**（30.8/30.6/31.1）＝ `s1` **13.98** ＋ residual **16.95** ms | `engine/chunk_gap_split_f369_r*_t0_9.json` |
| 同口径模式 0 | cadence **31.3**（31.0/31.3/31.3）＝ `s1` **18.29** ＋ residual **12.94** ms | `engine/chunk_gap_split_f369m0_r*_t0_9.json` |
| ⇒ split 在服务路径上确实省了设备时间 | `s1` **18.29 → 13.98（−4.3 ms，1.31×）** | 上两行 |
| ⇒ 但省下的时间被宿主侧吸收 | residual **+4.0 ms**，cadence 只动 1.02× | 上两行 |
| 服务侧的分块数 | `sp = 2,3,4,5`（`g_split_p` 无条件记录"算出的分块数"，故模式 0 也非空；证明走没走要看 `ATTN_MODE`） | `engine/serve_f369.log`、`engine/chunk_gap_split_f369*_r1_t0_9.json` |
| temp 0.9 与 temp 0.0 的 cadence 差在模式 2 下几乎为 0 | 30.8 vs 30.8（F364 同口径是 47.6 vs 43.6＝4.0 ms）⇒ **采样不再是服务面大头** | `engine/chunk_gap_split_f369_r{1,4}_t0_9.json`、`…_r4_t0_0.json` |
| 自检（装表后） | **162 PASS / 0 FAIL**、`RUN_EXIT=0`、三项 ATTN 门 PASS | `engine/run_f369.log` |
| 双流 scaling（发布默认） | **1.861×** | `engine/chunk_gap_f369.log` |

### F370（2026-11-06 01:03）——服务每 token 拆成 pre 7–8 ms / eng 20.2 ms / post **0.09 ms**，覆盖率 92%
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 输出侧不是对象 | `post` **0.087 / 0.088 / 0.089** ms（3 次） | `engine/chunk_gap_split_f370_r{1,2,3}_t0_9.json` |
| 引擎调用内、stage 标记外 | `eng` 20.2 − `s1` 14.0 = **6.2 ms**（含 s2≈2.2 ⇒ 未命名 ≈4 ms：logits 回拷 / 采样 / 批路径簿记） | 同上（`eng_ms`、`engine_s1_ms`） |
| **最大的意外** | **`pre` = 7.26 / 8.05 / 8.06 ms**——迭代起点到引擎调用之间，**与模型无关，且比采样与 PHP 都大** | 同上（`pre_ms`） |
| 装表完整性判据（非恒等式） | `coverage_pct` = **92.3–92.6%**（三项来自三个独立戳） | 同上 |
| 缺的 8% 的结构解释 | A 的 `post` 在 A 的 trace 处结束，B 槽处理发生在其后 ⇒ **待用 B 行的 `post` 核**，不作结论 | `engine/par_trace.log`（同批行） |
| 装表不改变行为 | 服务文本与 F369 **逐字节相同**（148 字符，同 seed） | `engine/chunk_gap_f369_r1_t0_9.json` vs `engine/chunk_gap_f370_r1_t0_9.json` |
| 梯（带戳构建） | 自检 **162/0**、`RUN_EXIT=0`；8000 **115/0**；8001 par **0** fairness **0**；scaling **1.780×**；哨兵 **0** | `engine/run_f370.log`、`engine/gate_f370.log` |

### F372（2026-11-06 01:39）——去掉每 token 的 select 睡眠：**服务面 32.6 → 25.8 ms/token（1.26×）**，机制字段自证
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 睡眠确实被去掉 | `sel` **6.75/5.67/6.15 → 0.032/0.031/0.030** ms；`pre` 6.99 → **0.034** | `engine/chunk_gap_split_f371_r*_t0_9.json` vs `engine/chunk_gap_split_f372_r*_t0_9.json` |
| 收益来自去等待而非漂移 | cadence **32.6/34.4/32.7 → 25.3/26.4/25.8**（降 6.5–7.9 ms ≈ `sel` 降幅），同期 `eng`/`s1` 未变 | 同上 |
| 采样成本重新可见 | temp 0.0：cadence **21.6/22.2**、`eng` 18.4/18.9 ⇒ 采样 **3.6–4.8 ms**（F369 的"temp 差≈0"是被睡眠抖动淹掉） | `engine/chunk_gap_split_f372_r{4,5}_t0_0.json` |
| 每 token 的新账 | sel 0.03 ＋ eng **22.9**（s1 15.4＋s2≈2.2＋采样≈4＋**回拷/簿记≈1.3**）＋ post 0.11 ＋ B 槽≈2.4 ＝ **25.8 ms** | 同上（`coverage_pct` 88–91%） |
| 空闲不忙等 | 超时只在"无槽位要推进"时为 1 ms（`$tmo` 条件式） | `engine/main.php`（`api_serve_par` 的 select 处） |
| 梯 | 自检 **162/0**、`RUN_EXIT=0`；8000 **115/0**；8001 par **0** fairness **0**；哨兵 **0**；投机 greedy **1.843×**/sampled **1.462×** | `engine/run_f372.log`、`engine/gate_f372.log` |
| scaling 数值下行不是退化 | **1.583×/1.658×**（睡眠时代 1.667–1.780）——分母（串行侧）变快 ⇒ 比值缩 | `engine/gate_f372.log`、`engine/chunk_gap_f372.log` |

### F373（2026-11-06 02:07）——第三/第四行终于可测：**每 token 17.08 → 5.78 ms（2.96×）**，M=4 未见拐点
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 一步 1/2/3/4 行的成本 | step_ms **17.08 / 19.90 / 21.35 / 23.13**；per_tok_ms **17.08 / 9.95 / 7.11 / 5.78**（第一遍 19.48/18.83/19.81/22.38） | `engine/run_f373b.log`、`engine/run_f373.log`（`BATCH-M3-COST`） |
| 三行批处理与单独逐 id 相同 | `BATCH-M3: PASS tokens=8 rows=3` | `engine/run_f373b.log` |
| **一步确实带 3 行**（不是分批） | 判据 `rows == 3` 独立于相等性断言 | 同上 |
| 门的形状判据救了一次 | 第一版 `FAIL eq_a=1 eq_b=1 eq_c=1 rows=3 n=1`：分隔符抄错（换行≠逗号），**两侧同错所以相等仍真** | `engine/run_f373.log` |
| 批处理内核仍逐位等价 | `gemvM<MM>` 不改 k 划分与树形归约；`BATCH-EQ` 仍 PASS、`ATTN-MODE/SPLIT/ENGAGE` 全 PASS | `engine/run_f373b.log` |
| 服务面零改动 | `batch_start` 旧入口仍固定 2 区域；`model_batch_start_n` 只被新门使用 | `engine/src/model_sycl.cc`、`engine/tests/../main.php`（BATCH-M3 段） |
| 自检 | **163 PASS / 0 FAIL**、`RUN_EXIT=0` | `engine/run_f373b.log` |
| 活梯（F373，服务面路径未变） | 8000 **115/0**；8001 par **0** fairness **0**；scaling **1.511×**；哨兵 **0**；服务文本与 F372 **逐字节相同**；每 token cadence **25.8 / 26.2 ms**（F372：25.3–26.4） | `engine/gate_f373.log`、`engine/chunk_gap_split_f373_r*_t0_9.json` |

### F374（2026-11-06 02:34）——两处纯等待去掉：首 token **1167→144 ms**、每 token **25.8→22.9 ms**、coverage **87–90%→97.8–99%**
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 首请求贵的是一次性进程内开销（改前） | TTFT **1214.2 / 1167.4 / 924.7 / 1618.3 ms** vs 后续 **75–105 ms** | `engine/chunk_gap_f372_r1_t0_9.json`、`…_f373_r1…`、`…_f369_r1…`、`…_f364e_r1…` |
| 暖机确实执行并被记录（机制字段） | `WARM ms=1122.085 / 1087.305`，`err=` 空 | `engine/serve_par_f374.log`、`engine/serve_f374.log` |
| 首请求 TTFT 改善 | **144.3 ms**（与后续 89–100 ms 同档） | `engine/chunk_gap_f374_r1_t0_9.json` |
| 每 token 成本改善 | cadence **22.5 / 23.0 / 23.1 ms**（改前 25.3–26.4） | `engine/chunk_gap_split_f374_r*_t0_9.json` |
| **归因判据（可推翻）命中** | coverage **97.79 / 97.79 / 98.95%**（改前 87.0–90.6%）⇒ 缺的 2.4 ms 确为循环尾 `usleep(2000)` | 同上 vs `engine/chunk_gap_split_f37{2,3}_r*_t0_9.json` |
| 引擎步未被本轮触动（对照） | `BATCH-M3-COST` 每 token **5.61 ms**（F373 为 5.78） | `engine/run_f374.log` |
| 累计（同一探针口径） | 每 token **47.6 → 25.8 → 22.9 ms（2.08×）**；首 token **1.2 s → 0.14 s** | F364/F372/F374 三批 split JSON |
| 梯 | 自检 **163/0**、8000 **115/0**、8001 par/fairness **0**、scaling **1.542×**、哨兵 **0** | `engine/run_f374.log`、`engine/gate_f374.log` |
| 未闭合 | 首请求仍比后续贵 ~55 ms；**CPU 未测**（"活跃期不忙等"是结构推断）；N 槽并发的 2.96× 仍未落到服务面 | 见 `findings.md` F374「边界」 |

### F375（2026-11-06 03:11）——服务面并发可配：**N=4 → 85.56 tok/s、scaling 2.069×**；缺陷由 F368 的横幅抓出
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 并发数确实生效（形状） | `PAR slots=4`；`STEP_SLOTS: A B C D`；`STEP_COUNTS: ... 60 C 60 D` | `engine/par_slots_f375b4.log` |
| 四路并发的服务面吞吐 | concurrent **244 chunks / errors=0 / 85.56 tok/s**；scaling **2.069x** | `engine/par_slots_f375b4.log`、`engine/par_slots_f375b4_s4.log` |
| 两路默认未被改写（控制） | `PAR slots=2`；122 chunks / errors=0 / 62.75 tok/s / **1.539x** | `engine/par_slots_f375b2.log`、`engine/par_slots_f375b2_s2.log` |
| **use-before-assign 缺陷的发现方式** | 首轮 N=4 报 `PAR slots=2` **且 errors=2 且 chunks 与两路相同（122）** | `engine/par_slots_f375q.log`（缺陷版本的原样记录，故意保留） |
| 修正 | `$nslots` 的决定移到 banner 与 `model_batch_start_n` **之前** | `engine/main.php`（`api_serve_par` 开头） |
| 探针自身缺陷 | awk 取字段 2 打印的是字面量 `STEP`；改为字段 3 | `scripts/par_slots_run.sh` |
| 两路梯 | 自检 **163/0**、`RUN_EXIT=0`；8000 **115/0**；8001 par **0** fairness **0**；哨兵 **0** | `engine/run_f375b.log`、`engine/gate_f375b.log` |
| **验收未覆盖** | ②逐流内容对照未测；③公平判据仍是两路 ⇒ 不声称"N 路公平" | 本节 + `engine/tests/fairness_test.py`（未改） |

### F376（2026-11-06 04:05）——N 路公平与逐流内容补齐：F375 的五条验收全部达成
| 论断 | 数值 | 证据文件 |
|---|---|---|
| N 路公平（streams=4） | 三条长流 **2.28 / 2.30 / 2.30 s（各 101 chunk）**，短流 **0.18 s、TTFT 0.08 s** ⇒ PASS | `engine/par_slots_f376fair2.log` |
| 两路控制不变 | streams=2：长 1.75 s、短 0.15 s ⇒ PASS | 同上 |
| 槽位不足不是"不公平" | `NOSUITE` + 需要的最少槽位数 | `engine/tests/fairness_test.py`（`refused` 分支） |
| 逐流内容（四槽） | 并发 vs 单独：**48 / 62 / 76 / 96 字节，四条 identical=1** | `engine/content_f376g_par*.json`、`engine/content_f376g_ser*.json` |
| 四槽确实在用 | `PAR slots=4`、`STEP_SLOTS: A B C D`、`STEP_COUNTS ... 258 C 264 D` | `engine/par_slots_f376fair.log`、`engine/par_slots_f376b4.log` |
| harness 缺陷（已修） | 裸 wait 等后台服务；无单实例护栏互杀；无数据误报 FAIL differing=4；探针 300 s 超时 | `scripts/par_content_check.sh`、`engine/tests/chunk_gap_probe.py` |

### F377（2026-11-06 10:53）——按请求的投机归因：接受率 87.7%（贪心）/ 72.5%（tree），服务面 **1.78×**（同分钟对照）
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 每请求记录存在且字段齐 | 22 行含 `got=`（mode/k/want/got/drafts/accepted/verifies/drafting/ms） | `engine/spec_req.log` |
| 贪心接受率与耗时 | 9 请求、drafts 1920、accepted 1683（**87.7%**）、**10.47 ms/token**（10.31–10.58） | 同上 |
| 纯解码对照（同分钟） | 2 请求、**18.62 ms/token**（18.08–18.62） | 同上 |
| 服务面收益（按实际产出 token） | **1.78×** | 上两行之比 |
| 仪器自检 | plain 18.6 ms/token 与引擎步进 17–19 ms 独立吻合 | 上 + `engine/run_f377b.log` |
| 观察（不下结论） | `drafting=off` 的 tree 请求 24.6–24.8 ms/token，比纯解码更慢 | 同上 |
| 口径错误（已修） | 初版用 `want` 作分母 ⇒ 假的 "投机慢 8 倍" | `scripts/spec_attrib_run.sh` 注释 |
| 梯 | 自检 **163/0**、8000 **115/0**、8001 par **0** fairness **0**、scaling **1.559x**、哨兵 **0** | `engine/run_f377b.log`、`engine/gate_f377b.log` |
| 未完成 | 独立 harness `HEALTH_TIMEOUT`，balanced 样本未取 | `engine/spec_attrib_f377c.log` |

### F378（2026-11-06 11:03）——收益是接受率的函数：87.7% → 1.78×，50% → 0
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 高接受率组的收益 | 87.7%（1683/1920）⇒ **10.47 vs 18.62 ms/token ＝ 1.78×** | `engine/spec_req.log`（F377 段） |
| 低接受率组无收益 | **50.0%（72/144）、19.06 ms/token**（17.83–20.27） | 同上（f378b 段） |
| 草稿器在无重复提示上不提任何草稿 | `drafts=0`，24 token / 480 ms ≈ 20 ms/token | 同上（f378 段） |
| plain 组未被记录（前缀缓存分支无表） | 12 条请求中 plain 0 条；serve 日志 `cache=0`/`cache=-1` 交替 | `engine/serve_spec_f378b.log` |
| 路径抖动（三种形态，均改为 `C:/` + 重试） | `SENTINEL_WRITE_FAILED`、`engine alive: 0`、`FileNotFoundError` | `engine/spec_attrib_f378.log`、`scripts/spec_attrib_run.sh` |

### F379（2026-11-06 11:17）——配平对照：greedy 1.337×（50% 接受率）；**前缀缓存命中路 60.5 ms/token ≈ 从头解码的 3 倍**
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 配平窗口（同提示同限同分钟） | greedy 50.0%（72/144）、**18.57 ms/token**；plain 缓存命中 **24.82** ⇒ **1.337×** | `engine/spec_req.log`（f379 段）、`engine/spec_attrib_f379.log` |
| 收益随接受率变 | 50% ⇒ 1.34×（本轮）vs 87.7% ⇒ 1.78×（F377 长探测） | 同上 + F377 段 |
| **缓存命中路更贵** | `plain/cache=0` 26 条：**60.49 ms/token**（21.88–68.14）vs `cache=-2` 21.94、greedy 17.83 | 同上（全程报告） |
| 机制 | 命中请求在 PHP 里逐 token `model_session_next`；`model_generate` 是批内一次调用 | `engine/main.php`（前缀缓存分支） |
| plain 行计数残留 | 旧报告据陈旧计数算出 `acc=38.9%`；报告现只对 drafted 组打印接受率 | `scripts/spec_attrib_report.py` |
| 梯 | 自检 **163/0**、8000 **115/0**、8001 par **0** fairness **0**、scaling **1.522×**、哨兵 **0** | `engine/run_f379.log`、`engine/gate_f379.log` |

### F380（2026-11-06 11:20）——撤回 F379 的缓存命中归因：配对实验显示命中更快
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 同提示 miss → hit（短） | TTFT 105.8 → **87.2 ms**；decode 16.94 → **17.09 ms/token** | `engine/cache_hit_f380.log` |
| 同提示 miss → hit（长，约 471 tok） | TTFT **826.7 → 73.5 ms（11.3×）**；decode 22.61 → **22.17** | 同上 |
| 撤回的旧结论 | "命中路 60.5 ms/token ≈ 从头解码 3 倍"（F379）——**组内构成差异，不是机制** | `findings.md` F379 与本节 |
| 错因（方法） | 按 `cache=` 标签分组 ≠ 对照；配对（同提示同进程只改命中）才是 | 本节 |
| 引擎未改 | 只新增探针；梯沿用 F379 绿 | `engine/run_f379.log`、`engine/gate_f379.log` |

### F381（2026-11-06 11:22）——配对设计下的投机收益：1.272× @ 50% 接受率（控制组从头解码）
| 论断 | 数值 | 证据文件 |
|---|---|---|
| 配对窗口（同提示同进程交替，plain 关缓存） | greedy **50.0%（72/144）、18.56 ms/token**；plain **23.70** ⇒ **1.272×** | `engine/spec_req.log`（f381 窗口）、`engine/spec_attrib_f381.log` |
| 收益随接受率（三窗口） | 87.7% ⇒ 1.78×；50% ⇒ 1.337× / **1.272×** | 同上 + F377/F379 段 |
| 仪器清理 | 删掉收集脚本里的内嵌分析块（三次抛 FileNotFoundError） | `scripts/spec_attrib_run.sh` |
| 未改引擎 | 梯沿用 F379 绿 | `engine/run_f379.log`、`engine/gate_f379.log` |
