@echo off
rem build-sycl.bat : compile the standalone SYCL matmul probe with Intel DPC++.
call "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvars64.bat" x64 >nul
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\probe\sycl
where icx
icx -fsycl /Qstd=c++17 /EHsc -O2 sycl_probe.cpp -o sycl_probe.exe
echo ==COMPILE_EXIT=%ERRORLEVEL%==
if not errorlevel 1 sycl_probe.exe
echo ==RUN_EXIT=%ERRORLEVEL%==
