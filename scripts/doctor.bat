@echo off
rem doctor.bat : device / toolchain / model report (FR-04 acceptance).
rem The sentinel file switches main() into doctor mode for one run.
setlocal
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\engine
echo on> doctor.on
build\kakasi_engine_sycl.exe
del doctor.on
echo ==DOCTOR_EXIT=%ERRORLEVEL%==
