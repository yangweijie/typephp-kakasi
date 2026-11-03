@echo off
rem build-kakasi.bat : joint build of engine/ (TypePHP orchestration + SYCL backend) into one binary.
rem usage: build-kakasi.bat [norun]
setlocal
call "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvars64.bat" x64 >nul
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\engine

echo ==[1/4] SYCL objects via icx==
if not exist build\obj mkdir build\obj
icx -fsycl /Qstd=c++17 /EHsc -O2 /c src\sycl_backend.cc /Fobuild\obj\sycl_backend.obj
if errorlevel 1 (echo SYCL_COMPILE_FAILED & exit /b 1)
icx -fsycl /Qstd=c++17 /EHsc -O2 /c src\ops_sycl.cc /Fobuild\obj\ops_sycl.obj
if errorlevel 1 (echo SYCL_OPS_COMPILE_FAILED & exit /b 1)
icx -fsycl /Qstd=c++17 /EHsc -O2 /c src\model_sycl.cc /Fobuild\obj\model_sycl.obj
if errorlevel 1 (echo SYCL_MODEL_COMPILE_FAILED & exit /b 1)
if not exist build\obj\model_sycl.obj (echo SYCL_OBJ_MISSING & exit /b 1)

echo ==[2/4] tpc: PHP + bridge==
tpc project.yml --no-progress -O2
if errorlevel 1 (echo TPC_BUILD_FAILED & exit /b 1)

echo ==[3/4] final link via icx (runs sycl-post-link)==
icx -fsycl /Qstd=c++17 /EHsc @build\gen\cache\link\kakasi_engine.exe.rsp -o build\kakasi_engine_sycl.exe ^
  -link /LIBPATH:"C:\git\php\tpc_v0.9.5_windows_x64\phpx\lib" /LIBPATH:"C:\git\php\tpc_v0.9.5_windows_x64\SDK\lib" /LIBPATH:"C:\Program Files (x86)\Intel\oneAPI\compiler\latest\lib" /NODEFAULTLIB:LIBCMT ^
  phpx.lib php8ts.lib php8embed.lib user32.lib gdi32.lib kernel32.lib gmp.lib gmpxx.lib mpfr.lib libmpdec-4.0.1.dll.lib libmpdec++-4.0.1.dll.lib sycl.lib ur_loader.lib tbb12.lib
if errorlevel 1 (echo SYCL_LINK_FAILED & exit /b 1)

echo ==[4/4] run==
if "%~1"=="norun" (echo SKIPPED_RUN & exit /b 0)
for %%F in (build\kakasi_engine_sycl.exe) do echo ==EXE_SIZE_BYTES=%%~zF==
build\kakasi_engine_sycl.exe
echo ==RUN_EXIT=%ERRORLEVEL%==
