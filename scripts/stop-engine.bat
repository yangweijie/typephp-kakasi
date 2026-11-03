@echo off
rem stop-engine.bat : clear the serve flags and stop the engine, so a rebuild or self-test
rem starts from a known-quiet state. Use it after an interrupted gate chain left a server
rem holding port 8000/8001.
rem
rem Why PowerShell and not taskkill: on this host taskkill/tasklist go through the WMI layer
rem that hangs under load, and one hung probe once stalled an entire gate run behind it.
setlocal
cd /d C:\git\c\typephp-kakasi\engine
if exist serve.on del serve.on
if exist serve.par.on del serve.par.on
powershell -NoProfile -Command "Get-Process -Name kakasi_engine_sycl -ErrorAction SilentlyContinue | Stop-Process -Force"
echo serve flags cleared; engine stopped if it was running
