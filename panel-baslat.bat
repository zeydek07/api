@echo off
REM Yonetim panelini http://localhost:4200 adresinde baslatir.
REM Once API acik olmali (baslat.bat).
cd /d "%~dp0panel"
if not exist node_modules call npm install
call npx ng serve --port 4200 --open
