@echo off
rem pull.bat : models pull, with the URL read from pull.url (avoids shell quoting of URLs).
setlocal
call "C:\Program Files (x86)\Intel\oneAPI\setvars.bat" >nul
cd /d C:\git\c\typephp-kakasi\engine
set /p URL=<pull.url
> models.cmd echo pull %URL%
echo on> models.on
build\kakasi_engine_sycl.exe
del models.on
del models.cmd
del pull.url
