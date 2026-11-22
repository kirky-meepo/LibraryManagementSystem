<#
  Builds the scratch database lms_test from the numbered SQL files, then runs tests.sql on it.
  The demo database (lms) is never touched.
    powershell -ExecutionPolicy Bypass -File database\run_tests.ps1
#>
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'reset.ps1') -Database lms_test -Tests
exit $LASTEXITCODE
