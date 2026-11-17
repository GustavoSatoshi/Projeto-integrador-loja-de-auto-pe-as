@echo off
chcp 65001 >nul
cd /d "%~dp0"
title SpeedZone
if not exist "bin\instalar.php" goto semprojeto
set "PHPEXE="
where php >nul 2>nul && for /f "delims=" %%I in ('where php') do if not defined PHPEXE set "PHPEXE=%%I"
if defined PHPEXE goto achou
if exist "C:\xampp\php\php.exe" set "PHPEXE=C:\xampp\php\php.exe"
if defined PHPEXE goto achou
for /d %%D in (C:\laragon\bin\php\*) do if exist "%%D\php.exe" set "PHPEXE=%%D\php.exe"
if defined PHPEXE goto achou
echo PHP nao encontrado. Baixe em https://windows.php.net/download e coloque a pasta no PATH.
goto fim
:achou
for %%I in ("%PHPEXE%") do set "PHPDIR=%%~dpI"
set "FLAGS="
"%PHPEXE%" -r "exit(extension_loaded('mysqli')&&extension_loaded('mbstring')?0:1);" >nul 2>nul && goto flagsok
set FLAGS=-d extension_dir="%PHPDIR%ext" -d extension=mysqli -d extension=mbstring
"%PHPEXE%" %FLAGS% -r "exit(extension_loaded('mysqli')&&extension_loaded('mbstring')?0:1);" >nul 2>nul
if errorlevel 1 goto semext
:flagsok
"%PHPEXE%" %FLAGS% bin\instalar.php
if errorlevel 1 goto fim
echo.
echo Abrindo a loja... Deixe esta janela aberta; feche-a para desligar o servidor.
start "" http://localhost:8000/
"%PHPEXE%" %FLAGS% -S localhost:8000 -t public public/index.php
goto fim

:semprojeto
echo.
echo Nao encontrei a pasta bin neste local:
echo %CD%
echo.
echo O iniciar.bat precisa ficar DENTRO da pasta do projeto, junto das pastas bin, public e src.
echo Extraia o speedzone-backend.zip inteiro e use o iniciar.bat que esta dentro da pasta extraida.
goto fim

:semext
echo.
echo O PHP em %PHPEXE% nao conseguiu carregar mysqli e mbstring.
echo Na pasta do PHP, copie php.ini-development para php.ini, abra o php.ini e tire o ponto e virgula
echo do inicio das linhas: extension_dir = "ext"   extension=mysqli   extension=mbstring
echo Salve e de dois cliques em iniciar.bat de novo.
goto fim

:fim
echo.
pause
