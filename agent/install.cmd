@echo off
rem Bhabaghure attendance agent: double-click to install it, or to update it (docs/phase-7-hr-attendance-bonus-wallet.md, 5.2).
rem It asks Windows for administrator rights, then runs install.ps1 from this folder. Arguments are passed on to it:
rem   install.cmd -CommKey 1234        the device's Comm Key, if it has one
rem   install.cmd -Uninstall           remove the agent, its settings and its token
setlocal
rem Work from a folder that is always there: -Uninstall removes the program folder, which may be this one.
cd /d "%SystemRoot%"
if not exist "%~dp0install.ps1" (
    echo install.ps1 isn't next to install.cmd. Unzip the whole download first, then double-click install.cmd in that folder.
    echo.
    pause
    exit /b 1
)
rem fltmc answers only an administrator, whatever the Windows language or services.
fltmc >nul 2>&1 || goto :elevate

rem One block, read whole before it runs: after an uninstall from the program folder this file is gone.
(
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
    echo.
    pause
    exit /b
)

:elevate
rem Already started again as administrator and still not one (fltmc refused): stop here rather than ask Windows again
rem and again in new windows.
if defined BHABAGHURE_ELEVATED (
    echo Windows started this as administrator, but it still doesn't have administrator rights. Sign in to Windows with
    echo an administrator account, then double-click install.cmd again.
    echo.
    pause
    exit /b 1
)
rem Not an administrator yet: Windows (UAC) starts this file again as administrator, with the same arguments, in a new
rem window that does the work. Paths and arguments travel in environment variables, so spaces need no extra quoting; the
rem new window gets BHABAGHURE_ELEVATED, the guard above.
set "BHABAGHURE_INSTALL_CMD=%~f0"
set "BHABAGHURE_INSTALL_ARGS=%*"
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$q = [char]34; Start-Process -FilePath cmd.exe -Verb RunAs -ArgumentList ('/s /c ' + $q + 'set BHABAGHURE_ELEVATED=1&& ' + $q + $env:BHABAGHURE_INSTALL_CMD + $q + ' ' + $env:BHABAGHURE_INSTALL_ARGS + $q)"
if errorlevel 1 (
    echo.
    echo Windows did not give administrator rights, so nothing was installed. Double-click install.cmd again and answer Yes.
    echo.
    pause
)
exit /b
