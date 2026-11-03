@echo off
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >/dev/null
cd /d C:\git\c	ypephp-kakasi\engine
del serve.on 2>/dev/null
echo on> serve.par.on
build\kakasi_engine_sycl.exe
echo PAR_EXIT=%ERRORLEVEL%
del serve.par.on 2>/dev/null
