@echo off
rem mem-probe.bat : build+run the device memory probe with DPC++.
call "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvars64.bat" x64 >nul
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\probe\sycl
icx -fsycl /Qstd=c++17 /EHsc -O2 mem_probe.cpp -o mem_probe.exe
if errorlevel 1 (echo COMPILE_FAILED & exit /b 1)
mem_probe.exe
