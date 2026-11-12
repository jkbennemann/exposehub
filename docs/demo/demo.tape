# The README demo. Run it with ./record.sh, which starts what it needs:
# a tunnel server, a local service on port 3000 and a few visitors.

Output demo.gif

Set Shell bash
Set FontFamily "JetBrains Mono"
Set FontSize 17
Set Width 1180
Set Height 370
Set Padding 30
Set TypingSpeed 45ms
Set Theme { "name": "exposehub", "background": "#15173d", "foreground": "#ebedf2", "cursor": "#ffc531", "selection": "#2f3260", "black": "#0d0f2b", "red": "#e5484d", "green": "#14b8b0", "yellow": "#ffc531", "blue": "#9197ae", "magenta": "#bec3d3", "cyan": "#14b8b0", "white": "#ebedf2", "brightBlack": "#696e8a", "brightRed": "#e5484d", "brightGreen": "#14b8b0", "brightYellow": "#ffc531", "brightBlue": "#bec3d3", "brightMagenta": "#d9dce6", "brightCyan": "#14b8b0", "brightWhite": "#f5f6f9" }

Env EXPOSEHUB_SERVER "http://localhost:8080"
Env EXPOSEHUB_TOKEN "demo-token"

Hide
Type `/demo/start.sh; PS1='\[\e[38;2;255;197;49m\]$\[\e[0m\] '; clear` Enter
Sleep 1s
Show

Sleep 600ms
Type "exposehub http 3000 --subdomain shop" Sleep 400ms Enter
Sleep 7500ms
