@echo off
rem Stands in for ffmpeg in local end-to-end tests on Windows, where there is none (scripts/e2e-api.mjs): copies the
rem recording to the output, so the browser's recording, the upload and the bubble are tested for real and only the
rem conversion is not. The real conversion runs on the server (docs/admin-inbox.md section 9). No Node here: the API
rem server's environment doesn't have it on its PATH.
setlocal
:next
if "%~1"=="" goto done
if "%~1"=="-i" set "input=%~2"
set "output=%~1"
shift
goto next
:done
copy /y "%input%" "%output%" >nul
