@echo off
rem build-async-probe.bat : F688 Phase-A probe - queue sync/event timing on the real devices.
call "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvars64.bat" x64 >nul
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\probe\sycl
where icx
icx -fsycl /Qstd=c++17 /EHsc -O2 async_probe.cpp -o async_probe.exe
echo ==COMPILE_EXIT=%ERRORLEVEL%==
if not errorlevel 1 async_probe.exe
echo ==PROBE_EXIT=%ERRORLEVEL%==
