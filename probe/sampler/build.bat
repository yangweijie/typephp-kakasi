@echo off
rem build.bat - compile and run the sampler cost probe (F362). Plain C++ (no SYCL), so the MSVC
rem toolchain is enough; the oneAPI linker fails on libircmt without its full environment.
rem usage: build.bat [draws]
setlocal
call "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvars64.bat" x64 >nul
cd /d %~dp0
cl /nologo /O2 /std:c++17 /EHsc sampler_bench.cpp /Fe:sampler_bench.exe > build.log 2>&1
if errorlevel 1 (echo COMPILE_FAILED & type build.log & exit /b 1)
sampler_bench.exe %1 > sampler_bench.log 2>&1
type sampler_bench.log
