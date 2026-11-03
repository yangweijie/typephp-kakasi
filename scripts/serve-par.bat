@echo off
rem serve-par.bat : cooperative two-slot streaming server on 127.0.0.1:8001 (streaming text only).
setlocal
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" > setvars.log 2>&1
cd /d C:\git\c\typephp-kakasi\engine
echo on> serve.par.on
build\kakasi_engine_sycl.exe
del serve.par.on
echo ==SERVE_PAR_EXIT=%ERRORLEVEL%==
