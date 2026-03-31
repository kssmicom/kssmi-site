Option Explicit
Dim WshShell, scriptDir, psCmd

Set WshShell = CreateObject("WScript.Shell")
scriptDir = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)

' Kill any existing node processes silently
WshShell.Run "taskkill /F /IM node.exe", 0, True

' Build PowerShell command to start astro completely hidden
psCmd = "Start-Process -FilePath 'node' -ArgumentList 'node_modules/astro/astro.js','dev','--port','4321' -WorkingDirectory '" & scriptDir & "' -WindowStyle Hidden"

' Run PowerShell hidden
WshShell.Run "powershell -NoProfile -WindowStyle Hidden -Command """ & psCmd & """", 0, False

Set WshShell = Nothing
WScript.Quit