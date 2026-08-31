@echo off
REM Rebuild the database from schema.sql and seed.sql. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang
REM
REM   setup.bat          tables + demo data
REM   setup.bat schema   tables only
REM
REM This DROPS the database and rebuilds it. The .sql files are the source of
REM truth, not the copy on your machine.
setlocal

set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"
set "DB_USER=root"
set "DB_PASS="

set "HERE=%~dp0"

if not exist "%MYSQL_EXE%" (
    echo [ERROR] mysql.exe not found at %MYSQL_EXE%
    echo         Edit MYSQL_EXE at the top of this file.
    pause
    exit /b 1
)

if "%DB_PASS%"=="" (
    set "AUTH=-u %DB_USER%"
) else (
    set "AUTH=-u %DB_USER% -p%DB_PASS%"
)

echo Creating tables...
"%MYSQL_EXE%" %AUTH% < "%HERE%schema.sql"
if errorlevel 1 goto :failed

if /i "%~1"=="schema" (
    echo Done. Tables created, no data inserted.
    pause
    exit /b 0
)

echo Inserting demo data...
"%MYSQL_EXE%" %AUTH% < "%HERE%seed.sql"
if errorlevel 1 goto :failed

echo.
echo Done. Demo accounts all use the password Password123!
pause
exit /b 0

:failed
echo.
echo [FAILED] Check that XAMPP MySQL is running.
pause
exit /b 1
