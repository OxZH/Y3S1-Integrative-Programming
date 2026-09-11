@echo off
REM Rebuild the database from schema.sql and seed.sql.
REM Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang
REM
REM   setup.bat          tables + demo data
REM   setup.bat schema   tables only, no demo data
REM   setup.bat force    skip the confirmation (for a scripted run)
REM
REM These three files - schema.sql, seed.sql and setup.bat - are the whole of
REM database\. There are no migration scripts: when the schema changes you
REM rebuild, because the .sql files are the source of truth and not the copy
REM sitting in your MySQL.
REM
REM That means this DROPS the database every time. Anything you typed in by
REM hand is gone; anything in seed.sql comes back.
setlocal EnableDelayedExpansion

set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"
set "DB_USER=root"
set "DB_PASS="
set "DB_NAME=sports_platform"

set "HERE=%~dp0"

REM Either argument may be given on its own, or both together.
set "SCHEMA_ONLY="
set "SKIP_CONFIRM="
for %%A in (%*) do (
    if /i "%%~A"=="schema" set "SCHEMA_ONLY=1"
    if /i "%%~A"=="force"  set "SKIP_CONFIRM=1"
)

if not exist "%MYSQL_EXE%" (
    echo [ERROR] mysql.exe not found at %MYSQL_EXE%
    echo         Edit MYSQL_EXE at the top of this file.
    pause
    exit /b 1
)

if not exist "%HERE%schema.sql" (
    echo [ERROR] schema.sql not found next to this script.
    pause
    exit /b 1
)

if "%DB_PASS%"=="" (
    set "AUTH=-u %DB_USER%"
) else (
    set "AUTH=-u %DB_USER% -p%DB_PASS%"
)

REM There is no migration path any more, so this is the only way the schema
REM moves - and it is destructive. Worth one question before it runs.
if not defined SKIP_CONFIRM (
    echo.
    echo   This DROPS the database "%DB_NAME%" and rebuilds it.
    echo   Every row currently in it is lost, including any account you
    echo   registered or anything you edited while testing.
    echo.
    set /p "REPLY=  Type Y to continue, anything else to cancel: "
    if /i not "!REPLY!"=="Y" (
        echo.
        echo   Cancelled. Nothing was changed.
        pause
        exit /b 0
    )
)

echo.
echo Creating tables...
"%MYSQL_EXE%" %AUTH% < "%HERE%schema.sql"
if errorlevel 1 goto :failed

if defined SCHEMA_ONLY (
    echo.
    echo Done. Tables created, no data inserted.
    pause
    exit /b 0
)

if not exist "%HERE%seed.sql" (
    echo [ERROR] seed.sql not found next to this script.
    pause
    exit /b 1
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
echo [FAILED] Check that XAMPP MySQL is running, and that no other program
echo          is holding a connection to %DB_NAME% open.
pause
exit /b 1
