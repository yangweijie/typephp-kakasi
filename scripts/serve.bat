@echo off
rem serve.bat : run the engine as an OpenAI-compatible API (Phase 3).
rem The sentinel file switches main() from the self-test suite to the request loop.
setlocal
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\engine
echo on> serve.on
echo API on http://127.0.0.1:8000/  (chat page at /, OpenAI /v1/chat/completions, Anthropic /v1/messages)
build\kakasi_engine_sycl.exe
del serve.on
echo ==SERVE_EXIT=%ERRORLEVEL%==
