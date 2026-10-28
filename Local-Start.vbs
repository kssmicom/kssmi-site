Option Explicit
Dim shell, fso, scriptDir, result, command
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
shell.CurrentDirectory = scriptDir
command = "node " & Chr(34) & scriptDir & "\scripts\local-dev.mjs" & Chr(34) & " start"
On Error Resume Next
result = shell.Run(command, 0, True)
If Err.Number <> 0 Then
    MsgBox "Cannot start Node.js. Check the Node.js installation, then run Local-Start.bat for details.", vbExclamation, "KSSMI local preview"
    WScript.Quit 1
End If
On Error GoTo 0
If result <> 0 Then
    MsgBox "Local preview did not start. Run Local-Start.bat to read the exact error. Logs, when created, are under Kssmi\tep. Other projects were not stopped.", vbExclamation, "KSSMI local preview"
End If
WScript.Quit result
