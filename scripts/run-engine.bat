@echo off
rem run-engine.bat : run the driver-linked Phase 0 single binary (needs oneAPI runtime DLLs on PATH).
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\probe\engine
build\kakasi_probe_sycl.exe
echo ==RUN_EXIT=%ERRORLEVEL%==
