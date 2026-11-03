@echo off
rem devshell.bat <command...> : run a command with MSVC x64 env loaded.
rem Set USE_ONEAPI=1 before calling to also load Intel oneAPI (DPC++/SYCL) env.
call "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvars64.bat" x64 >nul
if defined USE_ONEAPI call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
%*
