@echo off
rem par_gate.bat : clean start of the 8001 two-slot server, wait for /health, run par_smoke, stop.
rem Full paths for timeout: a Git Bash PATH ahead of System32 would otherwise pick GNU timeout.
setlocal
cd /d C:\git\c\typephp-kakasi
C:\Windows\System32\taskkill.exe /F /IM kakasi_engine_sycl.exe >nul 2>&1
del engine\serve.par.on >nul 2>&1
del engine\serve_par13.log >nul 2>&1
del engine\par_gate_result.txt >nul 2>&1
start "" /b cmd /c "cd /d C:\git\c\typephp-kakasi\engine && call C:\git\c\typephp-kakasi\scripts\serve-par.bat > C:\git\c\typephp-kakasi\engine\serve_par13.log 2>&1"
set /a i=0
:loop
C:\Windows\System32\timeout.exe /t 5 /nobreak >nul
set /a i+=1
C:\Windows\System32\curl.exe -s -m 3 http://127.0.0.1:8001/health > engine\par_gate_health.txt 2>&1
findstr /C:"status" engine\par_gate_health.txt >nul 2>&1
if not errorlevel 1 goto ready
if %i% lss 60 goto loop
echo TIMEOUT_AFTER_300S > engine\par_gate_result.txt
C:\Windows\System32\taskkill.exe /F /IM kakasi_engine_sycl.exe >nul 2>&1
exit /b 2
:ready
echo HEALTH_OK_AT_%i%_POLLS > engine\par_gate_result.txt
python engine\tests\par_smoke.py 8001 >> engine\par_gate_result.txt 2>&1
echo SMOKE_EXIT=%ERRORLEVEL% >> engine\par_gate_result.txt
C:\Windows\System32\taskkill.exe /F /IM kakasi_engine_sycl.exe >nul 2>&1
exit /b 0
