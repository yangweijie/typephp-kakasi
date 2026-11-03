# Phase 0 探针结论 — tpc + SYCL 单二进制联合构建

日期：2026-11-01　·　环境：Windows 11 26200 / x64　·　结论：**GO — 进入 Phase 1**（附 3 项前置条件）

## 1. 探针要回答的问题与答案

| 问题 | 答案 | 证据 |
|------|------|------|
| `tpc` 与 SYCL 工具链能否联合构建出**单一原生二进制**？ | **能** | `probe/engine/build/kakasi_probe_sycl.exe`（126 KB，含内嵌设备镜像），由 `tpc` 生成的 obj + `icx -fsycl` 的 obj 一起链成 |
| PHP 调 C++/SYCL 是原生链接还是 FFI？ | **原生链接，无封送层** | 空操作原生调用 **0.65 ns/op**（纯 PHP 等价调用 0.11 ns/op）；FFI/dlopen 边界量级为数百 ns~µs |
| 单二进制里能否在**≥2 个后端**跑通 matmul 且对参考在容差内？ | **能** | 逐设备 64² matmul：Arc B390(Level-Zero)、Arc B390(OpenCL Graphics)、Core Ultra X7 CPU 均 `maxerr=3.8e-6`；热态 **4/4 设备 PASS** |
| TypePHP 构建阶梯是否可用？ | **可用** | `--dry` → 编译 → 链接 → 运行 四阶全绿（`probe/echo`：`main.exe` 输出 `tpc-rung2-ok`，exit=0） |

## 2. 可复现的联合构建配方（4 步）

```
1) icx -fsycl /Qstd=c++17 /EHsc -O2 /c src/sycl_matmul.cc /Fobuild/obj/sycl_matmul.obj
2) tpc project.yml -O2                 # 编译 PHP + cl 桥接，产出 obj 与链接清单 .rsp
3) icx -fsycl @build/gen/cache/link/<name>.exe.rsp -o <name>_sycl.exe -link <libs...>
4) <name>_sycl.exe                      # 需 oneAPI 运行时 DLL 在 PATH
```
脚本：`scripts/build-engine.bat`（1~3）、`scripts/run-engine.bat`（4）、`scripts/devshell.bat`（仅 MSVC 环境）。

**接口契约**（TypePHP 原生互操作，两条路径同时使用）：
- 路径①：`src/kakasi.stub.php` 声明签名 + `src/kakasi.cc` 以 `php_` 前缀实现，参数/返回值必须是 `php::Int/Float/Str/Array/Var`，**不能返回 void**。
- 路径②：`project.yml` 的 `objects`（预编译 `.obj`）+ `link-paths` + `link-libs` + `cxx-flags`/`ld-flags`。
- **分层纪律**：SYCL 侧（`sycl_matmul.cc`）只暴露普通 C++ ABI（`namespace kakasi`）、**绝不 include `phpx.h`**；`kakasi.cc` 是唯一同时认识 `php::` 与 SYCL 的文件。这样 cl 与 icx 各自只处理自己能编译的东西。

## 3. 关键约束（Phase 1 必须按此设计，别踩）

1. **`tpc` 不能驱动 DPC++**：`--compiler` 白名单按子串识别（`clang`/`gcc`/`cl`），`icx` 直接 `Unsupported compiler`。→ 联合构建只能是"外部工具链产 obj，tpc 出其余 obj，**最终链接交给 SYCL 驱动**"。
2. **链接必须由 SYCL 驱动完成**：用 `link.exe` 直链 SYCL obj 时，`icx` 的链接期 `sycl-post-link` 不会执行，设备镜像未内嵌 → 内核启动先 `0xC0000409`(fastfail)，包 try/catch 后变为挂死。**这是 tpc+SYCL 路线的真正工程接缝**（原 RK-01 的替代风险）。
3. **`project.yml` 细节**：MSVC 下 `link-libs` 必须写 `sycl.lib`（裸 `sycl` 会被 link.exe 当成 `sycl.obj` → `LNK1181`）；`objects` 指向的文件必须**先于 tpc 存在**；clang-cl 的 `/Fo` 必须与路径粘连（`/Fo x.obj` 会被当输入文件，**exit 0 但不产出**）。
4. **bat 脚本必须纯 ASCII**：中文控制台 codepage 936 下，`rem` 行里的 UTF-8 全角破折号会让 cmd 静默解析错位、vcvars 不生效。
5. **运行期依赖**：单二进制仍需 `phpx.dll`/`php8ts.dll`/`libmpdec++.dll` + `sycl.dll`/`ur_loader.dll`/`tbb12.dll` 等。"一次编译"≠"零依赖分发"，Phase 1 需定 redist/静态化策略。
6. **本机 SYCL 工具链已够用，AdaptiveCpp 未安装**：`acpp`/`syclcc` 不存在，`C:\git\c\AdaptiveCpp-develop` 仅源码。探针改用 Intel oneAPI DPC++ 2026.1.1（`icx`，MSVC ABI，与 `cl` 同 14.44.35207）。AMD/Apple 覆盖仍需 AdaptiveCpp 或各厂商后端。

## 4. 遗留问题（进 Phase 1 的前置条件）

- **P0 冷启动一致性**：同一进程内**首次**访问第 2~4 个设备时，host 侧结果缓冲区保持全 0（`maxerr` 恰等于参考值上界 14.95），第二轮则 4/4 PASS。怀疑与"每次调用新建/销毁 `sycl::queue` + USM 分配"的用法有关。→ Phase 1 后端抽象必须：①每设备持有**常驻 context/queue**；②设备首次启用跑一次 **smoke 校验**，校验不过则降级/剔除该设备（不能信"提交成功=算对了"）。
- **P1 CPU 后端数值**：standalone 256² 探针里 OpenCL CPU 两次 `maxerr=56.56`（同代码 GPU 正确），需要单独定位（疑与 naive 内核 + 该 runtime 的 JIT 有关）。
- **P2 内核性能**：naive 全局读 256² matmul 在 Arc B390 上 34.7~79.9 ms，**不可用作引擎内核**；Phase 1 需 tiled/shared-memory 版本并重设基线。

## 5. 本轮实测数字

| 指标 | 值 |
|------|-----|
| 单二进制体积 | 113 KB（link.exe 直链，运行期内核失败）/ **126 KB**（icx 最终链接，可用） |
| 原生调用开销 | 0.65~0.70 ns/op（2,000,000 次）；纯 PHP 等价 0.11 ns/op |
| 冷启动到打印首行 | 亚秒级（`php8ts` 运行时 + SYCL 平台枚举） |
| 64² matmul 逐设备 | 4 设备热态全 PASS，`maxerr=3.8147e-06` |
| 256² matmul（standalone） | GPU 34.73 ms / 79.96 ms；CPU 1134~1184 ms（且数值不符） |
