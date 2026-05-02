Option Explicit
Dim WshShell, FSO, scriptDir

Set WshShell = CreateObject("WScript.Shell")
Set FSO = CreateObject("Scripting.FileSystemObject")
scriptDir = FSO.GetParentFolderName(WScript.ScriptFullName)

' Kill any existing node processes silently
WshShell.Run "taskkill /F /IM node.exe", 0, True
WScript.Sleep 1000

' Set working directory - avoids path-with-spaces quoting problems
WshShell.CurrentDirectory = scriptDir

' Window style 0 = completely hidden, no taskbar entry (correct for .vbs silent launcher).
' npm run dev works fine hidden - the old 5-min delay was npx registry check, not the window.
' False = fire and forget.
WshShell.Run "cmd /c npm run dev", 0, False

' Poll http://localhost:4321/product - NOT just "/".
' Vite opens its TCP socket before content is compiled, so "/" can return 200 too early.
' Polling "/product" (which loads all content) ensures the site is TRULY ready.
Dim http, ready, attempts
Set http = CreateObject("WinHttp.WinHttpRequest.5.1")
ready = False
attempts = 0

Do While Not ready And attempts < 120
    WScript.Sleep 2000
    attempts = attempts + 1
    On Error Resume Next
    http.Open "GET", "http://localhost:4321/product", False
    http.SetTimeouts 3000, 3000, 3000, 3000
    http.Send
    If Err.Number = 0 And http.Status = 200 Then
        ready = True
    End If
    Err.Clear
    On Error GoTo 0
Loop

' Server + content is confirmed ready - open browser now
WshShell.Run "http://localhost:4321"

Set http = Nothing
Set WshShell = Nothing
Set FSO = Nothing
WScript.Quit