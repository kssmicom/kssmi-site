Option Explicit
Dim WshShell, FSO, scriptDir

Set WshShell = CreateObject("WScript.Shell")
Set FSO = CreateObject("Scripting.FileSystemObject")
scriptDir = FSO.GetParentFolderName(WScript.ScriptFullName)

' Kill any existing node processes silently
WshShell.Run "taskkill /F /IM node.exe", 0, True

' Set working directory FIRST - avoids all path-with-spaces quoting problems
WshShell.CurrentDirectory = scriptDir

' Now run astro dev - no "cd /d" needed since CurrentDirectory is already set
' Window style 0 = completely hidden (no cmd window, no taskbar entry at all)
' False = don't wait for it to finish (fire and forget)
WshShell.Run "cmd /c npx astro dev --port 4321 --open", 0, False

Set WshShell = Nothing
Set FSO = Nothing
WScript.Quit