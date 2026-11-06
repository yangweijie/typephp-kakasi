@echo off
rem Run-kakasi-selftest.bat : run the built engine's full self-test with the oneAPI/VC runtime env
rem (setvars.bat hangs while the host WMI layer is stuck, so the env is set explicitly).
rem Usage: run-kakasi-selftest.bat <logfile>
setlocal
call "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvars64.bat" x64 >nul
set "ONEAPI=C:\Program Files (x86)\Intel\oneAPI"
set "TPC=C:\git\php\tpc_v0.9.5_windows_x64"
set "PATH=%ONEAPI%\compiler\latest\bin;%ONEAPI%\tbb\latest\bin;%TPC%;%TPC%\phpx\lib;%TPC%\SDK\lib;%PATH%"
cd /d C:\git\c\typephp-kakasi\engine
rem Serve flags are read at startup: a leftover serve.on turns this self-test into a
rem server that never exits (observed once as a 9 minute run full of accept warnings).
if exist serve.on del serve.on
if exist serve.par.on del serve.par.on
if exist serve.par4.on del serve.par4.on
build\kakasi_engine_sycl.exe > %1 2>&1
echo ==RUN_EXIT=%ERRORLEVEL%== >> %1
