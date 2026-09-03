# ViceCode

A VSCode-style multi-panel terminal workspace (TUI). Built on **PHP 8.3 + php-tui/php-tui 0.2.1 + Swoole 6.2.1**, it provides a coordinated six-panel environment in your terminal: Explorer / Editor / Terminal / GIT / Search / AI Chat.

```
┌───────────┬────────────────────────────┬──────────────┐
│ Sidebar   │  Editor                    │ Terminal     │
│ Explorer  │  (highlight/multi-tab/...) │ (cmd/inter)  │
│ GIT       │                            │              │
│ Search    │                            │              │
├───────────┴────────────────────────────┴──────────────┤
│ AI Chat Stream                            │ AI Input   │
├───────────────────────────────────────────┴────────────┤
│ StatusBar                                                  │
└───────────────────────────────────────────────────────────┘
```

The top menu bar opens with **F10** (File / View / Terminal / Help).

## Preview

> Text mockups below (identical layout in a real terminal). Press **F2** in the Terminal panel to switch between "Command Runner" and "Interactive PTY" modes — the latter forwards keystrokes directly to a real shell, so full-screen programs like `vim` / `top` / `ssh` work.

**Command Runner mode (Terminal panel default)**

```
┌─ Command Runner mode (Terminal panel default) ───────────────────────────────┐
┌──────────────┬──────────────────────────────────────┬──────────────────────┐
│ Sidebar      │ Editor                               │ Terminal             │
├──────────────┼──────────────────────────────────────┼──────────────────────┤
│ > Project    │ <?php                                │ $ ls src             │
│   src        │ final class App {                    │ app.php  panel/ ...  │
│   tests      │   public function run() {            │ $ grep -r TODO .     │
│   vendor     │     // edit code                     │ ... 3 hits           │
│              │   }                                  │ $ ▏                  │
│ GIT          │ }                                    │                      │
│  * main      │                                      │                      │
└──────────────┴──────────────────────────────────────┴──────────────────────┘
┌────────────────────────────────────────────────────┬────────────────────────┐
│ AI Chat                                            │ AI Input                │
├────────────────────────────────────────────────────┼────────────────────────┤
│ (model output streams in)                          │ type message, Enter to  │
│ (here)                                             │ send                   │
└────────────────────────────────────────────────────┴────────────────────────┘
└──────────────────────────────────────────────────────────────────────────────┘
│ Focus:Edit  Branch:main  Lang:en  Theme:midnight  Ctrl+Q quit                 │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Interactive PTY mode (Terminal panel, press F2 to capture)**

```
┌─ Interactive PTY mode (Terminal panel, F2 to capture) ────────────────────────┐
┌──────────────┬──────────────────────────────────────┬──────────────────────┐
│ Sidebar      │ Editor                               │ Terminal             │
├──────────────┼──────────────────────────────────────┼──────────────────────┤
│ > Project    │ <?php                                │ $ vim README.md      │
│   src        │ final class App {                    │ ~                VIM │
│   tests      │   // editor still usable             │ ~  reading/editing…   │
│   vendor     │ }                                    │ ~                    │
│              │                                      │ :wq                  │
│ GIT          │                                      │ $ top                │
│  * main      │                                      │ Interactive · F2 exit │
└──────────────┴──────────────────────────────────────┴──────────────────────┘
┌────────────────────────────────────────────────────┬────────────────────────┐
│ AI Chat                                            │ AI Input                │
├────────────────────────────────────────────────────┼────────────────────────┤
│ (interactive shell takes over                      │ AI paused in interactive│
│  all keys forwarded to PTY)                        │ mode                   │
└────────────────────────────────────────────────────┴────────────────────────┘
└──────────────────────────────────────────────────────────────────────────────┘
│ Focus:Terminal  Interactive · Capturing  Ctrl+Q quit                           │
└──────────────────────────────────────────────────────────────────────────────┘
```

## Requirements

- PHP **>= 8.3**
- Extension: **swoole** (recommended, provides the coroutine runtime); falls back to a pure blocking-read mode automatically when not installed
- Dependencies: php-tui/php-tui `^0.2`, scrivo/highlight.php `^9.18` (installed via Composer)

## Installation

```bash
composer install
```

## Running

```bash
php bin/vicecode.php                 # launch (uses the Swoole coroutine runtime by default)
php bin/vicecode.php path/to/file    # launch and open the given file directly (multiple allowed)
TUI_USE_SWOOLE=0 php bin/vicecode.php   # force fallback to the pure php-tui/term blocking-read mode
```

> Must run in a real terminal (pty); size detection and raw mode are unavailable under a pipe/redirection.

## General shortcuts

| Key | Action |
| --- | --- |
| `Tab` | cycle focus between panels |
| `Ctrl+Q` | quit the app (confirm first if there are unsaved changes) |
| `Esc` | exit the current modal (menu/help); quits the app when the terminal input is empty |
| `?` | toggle the shortcut help page |
| `F10` | open/close the top menu bar |
| `Ctrl+T` | switch theme |
| `Shift+←` / `Shift+→` | horizontal scroll (covers editor/terminal/AI/sidebar) |
| mouse wheel / drag | scroll content; drag panel dividers to resize the layout (session-only) |

For the Editor (`Enter` newline, `Ctrl+S` save, `Ctrl+W` close, `Ctrl+Tab` switch tab, etc.), Explorer (single-click to open a file, double-click a directory to expand/collapse, click the leading triangle to expand/collapse), and GIT / Search panel usage, see the in-app `?` help page.

## Terminal panel

The Terminal panel has two modes, toggled with **F2**:

### 1. Command Runner mode (default)

Run commands like a normal command palette:

- Type a command in the input line at the bottom and press `Enter`; output streams in real time.
- `↑` / `↓` browse command history; `Home` / `End` jump to line start/end.
- `Ctrl+C` interrupts the running command; `Ctrl+L` clears the output.
- `PageUp` / `PageDown` review past output (auto-disables "stick to bottom").

### 2. Interactive PTY mode (F2 to enter)

Press **F2** to allocate a **real PTY** and launch an interactive shell (bash); all keystrokes are forwarded directly to the shell — so you can run `vim`, `top`, `less`, `ssh`, TUI programs, or anything that needs a full terminal. A built-in lightweight VT100/ANSI emulator handles cursor positioning, coloring, erasing, scrolling, and the alternate screen.

- **Enter capture**: with the Terminal panel focused, press `F2` → enter capture mode, all keys forwarded to the PTY.
- **Exit capture** (shell keeps running in the background): in capture mode press `Esc` or `F2` again. Focus returns to app navigation; you can then use `PgUp/PgDn` and arrow keys to browse terminal scrollback.
- **Re-enter**: after exiting capture (shell not yet quit), press `F2` again to recapture.
- **Quit shell back to runner**: in capture mode send `Ctrl+D` or `exit`; once the shell ends, it automatically returns to Command Runner mode.
- The terminal title shows the current state (`Interactive` / `Capturing`).

> Window size is synced to the shell via `stty` as the panel resizes; wide characters (CJK) are rendered at 2 columns.

### Session persistence (opt-in)

By default the interactive shell dies when you quit ViceCode (the PTY is an OS process, killed on exit). With session persistence enabled, ViceCode saves a snapshot of the terminal on exit — scrollback + the current main screen as **plain text** plus the startup working directory — and on the next launch automatically spawns a fresh shell and replays the snapshot, so the session appears to continue.

- **Off by default** — opt in by adding `"persistSession": true` to your config (`~/.vicerc`, or the file pointed to by `VICECODE_CONFIG`).
- The snapshot is written to `~/.vicecode_session` (next to the config, or `dirname(VICECODE_CONFIG)/.vicecode_session`) with `0600` permissions, and is consumed (deleted) after a successful restore so it is not replayed twice.
- **Privacy**: scrollback may contain passwords / tokens typed at prompts. The file is `0600`, but consider it sensitive and only enable persistence on trusted machines.

**Limitations (v1, by design):**

- Only the **main screen** is persisted; full-screen programs (`vim` / `top` / `less` / `ssh`) run on the alternate screen and are intentionally excluded — their frozen UI would be garbage after restart.
- **No colors** — plain text only.
- Only the **startup** working directory is restored; a `cd` performed inside the shell is *not* restored.
- The PTY process itself cannot be serialized; what is restored is a brand-new shell with the snapshot text injected above its fresh prompt.

## Configuration & Language

- Config is persisted to `~/.vicerc` (JSON: layout / theme / language, and `persistSession` if you set it) and auto-saved on exit. On save, ViceCode **merges** with the existing file so hand-edited keys (like `persistSession`) are preserved.
- Override the config path with the `VICECODE_CONFIG` env var (used for test isolation to avoid polluting the home directory).
- UI language is switched via `APP_LOCALE`, default `zh_CN`, `en` also available; missing keys fall back to English.

```bash
APP_LOCALE=en php bin/vicecode.php
VICECODE_CONFIG=/tmp/my_vicecode.json php bin/vicecode.php
```

## Tests

`tests/` contains both headless unit tests and real-pty end-to-end acceptance tests (require a real terminal; wrapped in `timeout` as a safety net):

```bash
php tests/interactive_term_unit.php     # Interactive PTY: emulator + pty pipe headless unit test
timeout 90 php tests/pty_interactive.php # Interactive PTY: real pty end-to-end (F2/echo/Ctrl+D)
php tests/session_unit.php              # Session persistence: export/import + App restore (headless)
timeout 120 php tests/pty_session.php   # Session persistence: save on exit -> restore on restart (real pty)
php tests/m6_unit.php                   # terminal/editor/keybinding-drift regression
```

> Never run `bin/vicecode.php` bare for pty acceptance; always go through `tests/pty_*.php` and set `VICECODE_CONFIG` to a temp file to isolate config.
