@echo off
rem build-sock.bat : build the Phase 3 socket/JSON probe (tpc only, no SYCL).
setlocal
call "C:\git\c\typephp-kakasi\scripts\devshell.bat" >nul
cd /d C:\git\c\typephp-kakasi\probe\sock
tpc project.yml --no-progress -O2
echo ==TPC_EXIT=%ERRORLEVEL%==
