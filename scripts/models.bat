@echo off
rem models.bat : local model management (FR-10).  usage: models.bat import <path|dir> | pull <url> | list | rm <name> | cache [clear]
setlocal
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\engine
> models.cmd echo %*
echo on> models.on
build\kakasi_engine_sycl.exe
del models.on
del models.cmd
