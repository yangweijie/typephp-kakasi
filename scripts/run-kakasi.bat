@echo off
rem run-kakasi.bat : run the joint-built engine (oneAPI runtime DLLs must be on PATH).
setlocal
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\engine
build\kakasi_engine_sycl.exe
echo ==RUN_EXIT=%ERRORLEVEL%==
