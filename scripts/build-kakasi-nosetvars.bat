@echo off
rem build-kakasi-nosetvars.bat : same ladder as build-kakasi.bat, but replaces oneAPI setvars.bat
rem (which hangs while the host WMI/process layer is stuck) with the explicit env it would set.
setlocal
call "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvars64.bat" x64 >nul
set "ONEAPI=C:\Program Files (x86)\Intel\oneAPI"
set "TPC=C:\git\php\tpc_v0.9.5_windows_x64"
rem tpc resolves the PHP SDK through PHP_HOME, falling back to "where php" (which may be
rem another PHP install without php8embed.lib), so pin it to the tpc SDK.
set "PHP_HOME=%TPC%"
set "PHPX_HOME=%TPC%\phpx"
set "PATH=%ONEAPI%\compiler\latest\bin;%ONEAPI%\tbb\latest\bin;%ONEAPI%\compiler\latest\lib;%TPC%;%TPC%\phpx\lib;%TPC%\SDK\lib;%PATH%"
set "LIB=%TPC%\SDK\lib;%TPC%\phpx\lib;%ONEAPI%\tbb\latest\lib;%ONEAPI%\compiler\latest\lib;%LIB%"
set "INCLUDE=%ONEAPI%\compiler\latest\include;%INCLUDE%"
cd /d C:\git\c\typephp-kakasi\engine

echo ==ONEAPI_ENV==
where icx
where tpc

echo ==[1/4] SYCL objects via icx==
if not exist build\obj mkdir build\obj
icx -fsycl /Qstd=c++17 /EHsc -O2 /c src\sycl_backend.cc /Fobuild\obj\sycl_backend.obj
if errorlevel 1 (echo SYCL_COMPILE_FAILED & exit /b 1)
icx -fsycl /Qstd=c++17 /EHsc -O2 /c src\ops_sycl.cc /Fobuild\obj\ops_sycl.obj
if errorlevel 1 (echo SYCL_OPS_COMPILE_FAILED & exit /b 1)
icx -fsycl /Qstd=c++17 /EHsc -O2 /c src\model_sycl.cc /Fobuild\obj\model_sycl.obj
if errorlevel 1 (echo SYCL_MODEL_COMPILE_FAILED & exit /b 1)

echo ==[2/4] tpc: PHP + bridge==
tpc project.yml --no-progress -O2
if errorlevel 1 (echo TPC_BUILD_FAILED & exit /b 1)

echo ==[3/4] final link via icx (runs sycl-post-link)==
icx -fsycl /Qstd=c++17 /EHsc @build\gen\cache\link\kakasi_engine.exe.rsp -o build\kakasi_engine_sycl.exe ^
  -link /LIBPATH:"C:\git\php\tpc_v0.9.5_windows_x64\phpx\lib" /LIBPATH:"C:\git\php\tpc_v0.9.5_windows_x64\SDK\lib" /LIBPATH:"%ONEAPI%\compiler\latest\lib" /NODEFAULTLIB:LIBCMT ^
  phpx.lib php8ts.lib php8embed.lib user32.lib gdi32.lib kernel32.lib gmp.lib gmpxx.lib mpfr.lib libmpdec-4.0.1.dll.lib libmpdec++-4.0.1.dll.lib sycl.lib ur_loader.lib tbb12.lib
if errorlevel 1 (echo SYCL_LINK_FAILED & exit /b 1)

rem Usage: build-kakasi-nosetvars.bat [norun]   (norun builds only; used when a stale exe
rem still holds the image or the GPU is busy with another run)
if /I "%1"=="norun" (
  echo ==[4/4] run skipped (norun)==
  exit /b 0
)
echo ==[4/4] run==
for %%F in (build\kakasi_engine_sycl.exe) do echo ==EXE_SIZE_BYTES=%%~zF==
build\kakasi_engine_sycl.exe
echo ==RUN_EXIT=%ERRORLEVEL%==
