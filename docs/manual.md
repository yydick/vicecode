# ViceCode User Manual

A VSCode-style, multi-panel terminal workspace (TUI). This manual walks through everything from launching to each panel, the AI assistant, configuration and plugins.

- For a feature overview → [README.md](../README.md)
- For a quick key reference → press `?` in the app (it shares its source with the key tables below)
- To write a plugin → [docs/plugins.md](plugins.md)
- For the root cause of every fixed bug → [docs/BUGFIXES.md](BUGFIXES.md)

---

## Contents

1. [Requirements & installation](#1-requirements--installation)
2. [Launching](#2-launching)
3. [UI & layout](#3-ui--layout)
4. [Global keys](#4-global-keys)
5. [Editor](#5-editor)
6. [Terminal panel](#6-terminal-panel)
7. [Explorer](#7-explorer)
8. [GIT panel](#8-git-panel)
9. [Search panel](#9-search-panel)
10. [AI assistant](#10-ai-assistant)
11. [Configuration](#11-configuration)
12. [Plugin system](#12-plugin-system)
13. [Troubleshooting](#13-troubleshooting)
14. [Docs & tests index](#14-docs--tests-index)

---

## 1. Requirements & installation

- **PHP >= 8.3**
- Extension: **swoole** (recommended — provides the coroutine foundation); without it the app falls back to a plain blocking-read mode
- Dependencies: `php-tui/php-tui ^0.2`, `scrivo/highlight.php ^9.18`

```bash
composer install
```

> **It must run in a real terminal (pty).** Under a pipe / redirect, raw mode and size detection are unavailable; the app exits with a clear error at startup instead of leaving you with a broken terminal.

---

## 2. Launching

```bash
php bin/vicecode.php                      # launch (Swoole coroutine base by default)
php bin/vicecode.php path/to/file         # launch and open files directly (several allowed)
php bin/vicecode.php .                    # open a directory as the project root
TUI_USE_SWOOLE=0 php bin/vicecode.php     # force the plain php-tui/term blocking-read path
APP_LOCALE=en php bin/vicecode.php        # English UI (zh_CN is the default)
VICECODE_CONFIG=/tmp/vc.json php bin/vicecode.php   # use a different config file (handy for isolation)
```

---

## 3. UI & layout

```
┌───────────┬────────────────────────────┬──────────────┐
│ Sidebar   │  Editor                    │ Terminal     │
│ Explorer  │  (highlight/multi-tab/...)  │ (runner/pty) │
│ GIT       │                            │              │
│ Search    │                            │              │
├───────────┴────────────────────────────┴──────────────┤
│ AI stream                                  │ AI input   │
├───────────────────────────────────────────┴────────────┤
│ StatusBar                                                  │
└───────────────────────────────────────────────────────────┘
```

- **Six focusable panels**: Sidebar / Editor / Terminal / AI stream / AI input (the status bar is not focusable).
- **Four sidebar tabs**: Explorer / GIT / Search / 🧩 Extensions. Click the tab row to switch.
- **Top menu bar**: `F10` (File / View / Terminal / Help / AI / Plugins).
- **Command palette**: `F1` (or *View → Command palette*), fuzzy-filter every command and press `Enter` to run.
- **Dividers**: drag with the mouse to resize the layout (session-only, not persisted).
- **Status bar**: segments are dropped as width shrinks (low-value ones first). Typical segments: transient message, `file=` (with the unsaved `*` and `N cursors`), `mode=`, `provider=`, `strategy=`, `branch=`, `focus=`, `cwd=`, and the quit hint.
- **Clickable status bar**: clicking the `lang=` / `Theme=` segment pops up the matching list above the status bar (`↑`/`↓` to move, `Enter` or a click to apply, `Esc` or a click elsewhere to close; the active value is marked `●`). The *View → Language / Theme* menu items open the same lists, and `Ctrl+T` still cycles the theme. Segments without a list (e.g. `file=`) do nothing when clicked.
  - Under tight widths, segments are trimmed by priority, and a **trimmed segment cannot be clicked either** (hit-testing shares the same source as trimming). The theme segment ranks below the language one, so on very narrow terminals (English UI, roughly <120 columns) it may disappear first — use the menu entry in that case.

---

## 4. Global keys

| Key | Action |
| --- | --- |
| `Tab` | cycle focus; **in inputs / the editor: indent 4 spaces, or accept a completion when candidates show** |
| `Shift+Tab` | cycle focus backwards; **in inputs / the editor: outdent, or select the previous candidate** |
| `Alt+↑` / `Alt+↓` | add an editing cursor on the line above / below (edit several lines at once) |
| `Alt+click` | add an editing cursor at the click (no selection is started) |
| `Esc` | leave the current modal (menu / help / candidates / multi-cursor); quits the app when the terminal input is empty |
| `Ctrl+Q` | quit the app (confirm first if there are unsaved changes) |
| `q` | quit when not in an input |
| `?` | toggle the shortcut help page |
| `F10` | open/close the top menu bar |
| `F1` | open/close the command palette |
| `Ctrl+T` | switch theme |
| wheel | scroll the focused panel |
| `Shift+wheel` | horizontal scroll (covers editor / terminal / AI / sidebar) |
| click | switch focus / select an item |

> Horizontal scrolling is also available from the keyboard: `Shift+←` / `Shift+→`.

---

## 5. Editor

### Open & save

- Click a file in the Explorer, or press `Enter` on it, to open it; `Ctrl+Tab` switches between open files.
- `Ctrl+S` saves, `Ctrl+W` closes the current file. An unsaved file gets a `*` after its name in the status bar.
- **Files larger than 5 MB open read-only** with a notice at the top (so a whole-file read can't blow up memory).
- Saving `~/.vicerc` or `~/.vicecode.providers.php` from within the editor takes effect **immediately**.

### Editing keys

| Key | Action |
| --- | --- |
| `↑ ↓ ← →` | move the cursor (wraps across lines at line boundaries) |
| `Home` / `End` | line start / line end |
| `PageUp` / `PageDown` | page up / down |
| `Enter` | new line |
| `Backspace` / `Delete` | delete the previous / next character |
| `Tab` / `Shift+Tab` | indent 4 spaces / outdent |
| `Ctrl+V` / `Shift+Ins` | paste |

### Auto-pairing

On by default; the pair set is configurable (see [§11 Configuration](#11-configuration)):

```json
{ "editor": { "autoPairs": ["()", "[]", "{}", "\"\"", "''"] } }
```

Behaviour:

- **Type a left symbol → the right one is inserted**, with the cursor between them.
- **Skip an existing closer**: when the character to the right is already the same closer, pressing it just moves over it (typing `()` and then `)` does not produce `())`).
- **Backspace deletes an empty pair at once**: with the cursor between an empty pair, one backspace removes both; a non-empty pair deletes a single character.
- **Wrap a selection**: select some text, then type a left symbol to wrap it (multi-line selections included).
- **Quotes right after a word don't pair**: typing a quote immediately after a letter / digit / underscore does not auto-pair (`don't` / `it's` stay intact).
- **`<>` is deliberately not in the default set**: in PHP `<` is an operator, and auto-inserting `>` in `if ($a < $b)` only gets in the way. Add `"<>"` to `autoPairs` if you want it.
- **An explicit `[]` disables it** (distinct from "not set" — otherwise you could never turn it off); dirty entries (non-string / length ≠ 2 / duplicates) are dropped one by one so a single bad value can't break the whole feature.

### Multi-cursor (edit several lines at once)

- **Add a cursor**: `Alt+↑` / `Alt+↓` adds one on the line above / below (column-aligned), or `Alt+click` adds one where you click.
- **At most one cursor per line**: an `Alt+click` on a line that already has a cursor adds nothing.
- **One keystroke, all cursors**: typing, `Backspace`, `Delete`, `Enter` and `Tab` / `Shift+Tab` indentation apply to **every** cursor at once.
- **Cancel**: `Esc` collapses back to a single cursor; in that state `Esc` does **not** quit (press it again for the global quit flow).
- **Status bar**: the `file=` segment shows `N cursors`.
- **Not participating** (main cursor only): **auto-pairing** and **paste**. Auto-pairing because "should this pair here?" can differ per position, so one keystroke producing different results would be unpredictable.
- `Alt+click` **only adds a cursor and starts no selection** (drag without `Alt` is the normal drag-copy).

### Selection & copy

- Drag inside the editor content to select; releasing copies to the system clipboard (OSC52 when the terminal supports it, otherwise an in-app clipboard fallback).
- The same drag-select works on terminal output.

### AI quick action

- `Ctrl+E`: send the current file (or the selection) to the AI for an explanation, starting a fresh conversation and sending immediately.

---

## 6. Terminal panel

The terminal panel has two modes, toggled with `F2`.

### 6.1 Command Runner mode (default)

Run commands like a normal command palette:

- Type a command in the input line at the bottom and press `Enter`; output streams in real time.
- `↑` / `↓` browse command history; `Home` / `End` jump to line start/end.
- `Ctrl+C` interrupts the running command; `Ctrl+L` clears the output.
- `PageUp` / `PageDown` review past output (auto-disables "stick to bottom").

### 6.2 Interactive PTY mode

Press `F2` to allocate a **real PTY** and launch an interactive shell (bash); all keystrokes are forwarded directly to the shell — so you can run `vim`, `top`, `less`, `ssh`, or anything that needs a full terminal. A built-in lightweight VT100/ANSI emulator handles cursor positioning, coloring, erasing, scrolling and the alternate screen.

- **Enter capture**: press `F2` while the terminal panel is focused.
- **Leave capture** (the shell keeps running): press `Esc` or `F2` again while capturing. Afterwards focus returns to app navigation and you can browse the scrollback with `PageUp` / `PageDown` and the arrow keys.
- **Re-enter**: press `F2` again while the shell is still alive.
- **End the shell back to the runner**: send `Ctrl+D` or `exit` while capturing; when the shell ends the panel returns to Command Runner mode automatically.
- The terminal title shows the current state (`interactive` / `capturing`).

> The window size is synced to the shell via `stty` as the panel resizes; wide characters (CJK) are rendered as 2 columns.

### 6.3 Session persistence (off by default)

By default the interactive shell dies with the process on exit (the PTY is an OS process and is reaped). With persistence on, exit writes a terminal snapshot — the scroll history + the current **primary-screen plain text** + the startup working directory — to disk, and the next launch starts a fresh shell with the snapshot fed back in, so the session looks "continued".

- **Enable**: add `"persistSession": true` to the config file.
- The snapshot goes to `~/.vicecode_session` (or `dirname(VICECODE_CONFIG)/.vicecode_session`) with mode `0600`; once restored it is consumed (deleted) and never restored twice.
- **Privacy note**: the scroll history may contain passwords / tokens typed at a prompt. The file is `0600`, but it is still sensitive — enable this only on a trusted machine.

**Limitations (v1, by design):**

- Only the **primary screen** is persisted; full-screen programs (`vim` / `top` / `less` / `ssh`) run on the alternate screen and are deliberately excluded — their frozen UI would be garbage after a restart.
- **No colors**, plain text only.
- Only the **startup** working directory is restored; directories you `cd`'d into are not.
- The PTY process itself can't be serialized; what you get back is a new shell with the snapshot text fed in, its new prompt appended after it.

---

## 7. Explorer

The first sidebar tab, showing the project tree.

| Action | Effect |
| --- | --- |
| `↑` / `↓` | move the selection |
| `→` | collapsed directory → expand; expanded → select its first child; no-op on a file |
| `←` | expanded directory → collapse; otherwise select the parent |
| `Enter` | expand/collapse a directory, or open a file |
| click a file | open it |
| click the leading triangle | expand / collapse |
| double-click a directory | expand / collapse |

> The tree is **lazily expanded**: only the currently expanded level is scanned, so a huge directory never gets read into memory all at once.

---

## 8. GIT panel

The second sidebar tab. The list has two sub-views — Changes / Log — toggled from the title row (the number of changes is on the left; the `+ -` / `⟳` icons on the right are clickable).

| Key / action | Effect |
| --- | --- |
| type directly | type the commit message (it goes to the input box at the bottom of the panel) |
| `Backspace` | delete the last character of the commit message |
| `Enter` | commit (an empty message is refused with a hint) |
| `↑` / `↓` | move the selection in the changes / log list |
| `+` or `=` | stage the selected file |
| `-` | unstage the selected file |
| `Esc` | close an open dropdown |
| click an icon | stage / unstage / discard changes / view diff |
| click the branch `⎇ … ▾` | open the branch dropdown: `↑` / `↓` to choose, `Enter` to switch, `Esc` to cancel |
| click `Commit ▾` | open the commit-action dropdown |

The four actions in the `Commit ▾` dropdown:

- **Commit** — commit staged changes only
- **Commit all** — `git add -A` then commit
- **Commit and push** — commit, then push
- **Commit and sync** — commit, then sync (pull + push)

> GIT commands run through a native blocking `exec` (`git status` is fast enough); search, by contrast, never blocks — see the next section.

---

## 9. Search panel

The third sidebar tab; it searches **file contents** (not file names).

- Type a keyword → `Enter` starts the search (you can keep typing to change the query at any time).
- Under the hood it is `grep -rnI -F`: fixed-string match, recursive, binary files skipped.
- **Excluded by default**: `.git`, `vendor`, `node_modules`, `dist`, `build`, `.idea`, `.vscode`, `.svn`, `.cache`, `__pycache__`, `target`.
- **Hard cap of 2000 results**; beyond that it truncates and tells you to narrow the search.
- Results are grouped by file: `↑` / `↓` move the selection, `Enter` expands/collapses a group or opens the matched file at that line.
- The UI stays responsive during a search (it runs in a subprocess and never blocks the event loop); pressing `Enter` again cancels the previous search and starts over.

---

## 10. AI assistant

The right-hand AI panel is wired into the workspace: **OpenAI / DeepSeek-compatible endpoints** plus **native Claude (Anthropic Messages API)**. Configuration lives in `config/providers.php` (or the user-level `~/.vicecode.providers.php`), keys come from environment variables, and output streams through a `curl` subprocess so the UI never blocks.

### 10.1 Two protocols

Each provider sets `protocol`:

- `'openai'` (**the default** when omitted) — any `/chat/completions` endpoint: OpenAI, DeepSeek, OpenRouter, Kimi, Qwen, GLM, Ollama, vLLM, self-hosted gateways, …
- `'anthropic'` — native Claude: `POST /v1/messages`, `x-api-key` + `anthropic-version` headers, `system` as a top-level parameter, required `max_tokens` (configurable per provider, 4096 by default).

**Tool calling (the Agent loop) works fully under both protocols** (`tool_use` / `tool_result` round-trips). The internal message shape (OpenAI semantics) is unchanged — protocol translation happens once at the provider boundary, so archive / rendering / copy need no changes.

A built-in `anthropic` entry ships with models `claude-opus-4-8` / `claude-sonnet-4-6` / `claude-haiku-4-5-20251001` and key `ANTHROPIC_API_KEY`.

### 10.2 Model capabilities

Declare capabilities per model in the provider config: `tools` / `reasoning` / `vision` / `audio` (custom names are accepted too). A provider-level default applies to models without their own list, and **models without any declaration default to `tools`** (existing configs need no migration).

Today **only `tools` changes behavior**: only models declaring it get the tool definitions in the request — so a pure reasoning model (`deepseek-reasoner` is pre-declared as `['reasoning']`) is never rejected for carrying tools.

The active model's capabilities show up in the AI panel's empty-state hint; when `tools` is absent the status bar's AI segment gains a "no tools" marker.

### 10.3 User-level model config (no repo edits)

The menu *AI → Edit model config…* opens `~/.vicecode.providers.php` in the **built-in editor** (creating a commented template if it doesn't exist). The format matches `config/providers.php` and is merged **per id, field by field; new ids are appended** (`models` is replaced wholesale). So "just point `openai` at my gateway" means writing one id plus the fields you want to change. `Ctrl+S` hot-reloads it and keeps the current provider / model; if the file has a syntax error the previous working config stays in effect with a status-bar hint.

### 10.4 Model strategies (switchable tiers)

An `@strategies` section in the same file maps "what am I doing now" to a concrete model (e.g. `plan` → a reasoning model, `grind` → a cheap one).

- Cycle them with `Ctrl+R` in the AI panel, or pick one from the menu / command palette; the status bar always shows the current tier.
- A strategy may declare `requires` capability demands (e.g. `['tools']`) — **switching to a model that lacks them is refused with a hint**, so "downgrade to a cheap model" can never **silently** disable the Agent tools.
- A wrong provider / model is likewise refused (no silent fallback to another model).
- Switching provider / model by hand automatically leaves the strategy, so the status bar never shows a wrong tier.

### 10.5 Routing by task type

A strategy may declare `kinds` (e.g. `['unittest', 'refactor']`) and is then picked automatically for matching requests. Task types come from exactly two sources, neither guessed:

- **Quick actions** (explain / comment / refactor / unittest);
- an **input prefix** such as `/plan rework this module` (the prefix is never sent to the model; an unknown type refuses to send and lists the known ones; `//` escapes a literal slash).

**Manual wins**: picking a tier by hand (`Ctrl+R`, the menu, or switching provider) *pins* it and pauses auto-routing; the `Ctrl+R` cycle includes an "auto" stop to hand control back. The status bar spells out who is in charge (`strategy=plan·auto` vs `strategy=plan`), and a typed task type is reported as ignored instead of silently doing nothing.

### 10.6 Code context: `@path`

Mention files with `@path/in/project` in the input — each reference is expanded into a syntax-highlighted fenced block before sending.

- Paths are validated against the project root: `../`, absolute paths, root-escaping symlinks and binaries are rejected.
- Per-file cap `ai.attachMaxBytes` (default 64 KB), truncated with a note.
- The menu *AI → Attach editor selection / Attach current file* injects context too.

### 10.7 Tab completion & indentation

- Type `@` and a **candidate list of project paths** pops up (directories carry a trailing slash, prefix-filtered).
- `Tab` accepts, `Shift+Tab` selects the previous one, `Esc` dismisses the candidates (and nothing else).
- **With no candidates**, `Tab` indents by 4 spaces in the AI input and the editor, and `Shift+Tab` outdents.
- **Single-line inputs** (search / commit) and the other panels keep `Tab` for focus cycling (reverse with `Shift+Tab`) — indenting a single line makes no sense.
- Plugins can serve candidates too via `completions()` (same key, same popup) — that is the landing point for AI autocomplete.

### 10.8 Read-only tools (Agent loop)

The model may call `list_files` / `read_file` inside the project. Calls run **locally and synchronously**, and the loop continues until the model answers or `ai.maxSteps` (default 8) is reached.

- In the message stream you see the call line (`→ name(args)`) and a one-line result summary (`⚙ name(path) ✓`) — file contents are **never** dumped into the chat.
- Clicking a tool line to copy gives you the summary.
- Set `ai.toolAutoRun: false` to approve each call with `y` / `n` (`Esc` cancels).

### 10.9 Context compaction

When the estimated token count exceeds `ai.compactThreshold` (default 24000), the oldest history is summarized automatically (keeping the most recent `ai.compactKeepRecent`, default 6) before the real request.

- Compaction never splits a tool_call/result pair; on failure it falls back to "no compaction" and nothing is lost.
- Trigger it manually via *AI → Compact conversation now*.

### 10.10 Persistence & rendering

- **Persistence** (on by default): every finished exchange is saved to `~/.vicecode_ai` (`0600`) and restored on the next launch, along with provider / model; `Ctrl+L` clears the conversation and the archive together. Set `ai.persist: false` to disable.
- **Markdown rendering**: finished messages render headings, lists, quotes, inline code/links/bold/italic and fenced code blocks (highlighted via scrivo); streaming messages show as plain text first and switch once finished. **Single newlines are kept as separate lines**.
- Click a finished message to copy its whole content.

### 10.11 AI panel keys

| Key | Action |
| --- | --- |
| printable chars | type a message |
| `@` | reference a project file (expanded before sending) |
| `Tab` / `Shift+Tab` | accept completion / previous candidate; indents 4 spaces when there is none |
| `Enter` | send |
| `Ctrl+P` | switch provider |
| `Ctrl+N` | switch model |
| `Ctrl+R` | switch model strategy |
| `Ctrl+L` | clear the conversation |
| `Ctrl+V` / `Shift+Ins` | paste |
| `↑` / `↓` | recall a previous question |
| `PageUp` / `PageDown` | scroll the message stream |
| `Esc` | stop generating |

### 10.12 Quick actions

The menu *AI → Explain this code / Add comments / Suggest refactoring / Write unit tests* (also in the `F1` command palette); `Ctrl+E` in the editor sends the current file (or selection) for an explanation, starting a fresh conversation and sending immediately.

---

## 11. Configuration

### 11.1 `~/.vicerc`

Config is persisted to `~/.vicerc` (JSON) and auto-saved on exit. On save, ViceCode **merges** with the existing file so hand-edited keys (like `persistSession`) are preserved.

```json
{
  "persistSession": false,
  "ai": {
    "persist": true,
    "toolAutoRun": true,
    "maxSteps": 8,
    "compactThreshold": 24000,
    "compactKeepRecent": 6,
    "attachMaxBytes": 65536
  },
  "editor": {
    "autoPairs": ["()", "[]", "{}", "\"\"", "''"]
  }
}
```

| Key | Default | Meaning |
| --- | --- | --- |
| `persistSession` | `false` | interactive-terminal session persistence (see §6.3) |
| `ai.persist` | `true` | persist the conversation to `~/.vicecode_ai` |
| `ai.toolAutoRun` | `true` | run tool calls automatically; `false` asks each time |
| `ai.maxSteps` | `8` | max Agent-loop rounds |
| `ai.compactThreshold` | `24000` | estimated tokens that trigger compaction |
| `ai.compactKeepRecent` | `6` | recent messages kept when compacting |
| `ai.attachMaxBytes` | `65536` | per-`@file` attach cap in bytes |
| `editor.autoPairs` | `["()","[]","{}","\"\"","''"]` | auto-pair set; an explicit `[]` disables it (see §5) |

Layout / theme / language live in the same file.

### 11.2 Model config

- Built in: `config/providers.php`
- User-level override: `~/.vicecode.providers.php` (open it via *AI → Edit model config…*; merged per id, hot-reloaded on save)
- See [§10.1](#101-two-protocols) – [§10.5](#105-routing-by-task-type).

### 11.3 Plugin config

`~/.vicecode.plugins.json` (separate from `~/.vicerc`) — see [§12](#12-plugin-system).

### 11.4 Environment variables

| Variable | Effect |
| --- | --- |
| `VICECODE_CONFIG` | override the config file path (isolation, avoids touching your home dir) |
| `APP_LOCALE` | UI language, `zh_CN` by default, `en` available; missing keys fall back to English |
| `TUI_USE_SWOOLE` | `0` forces the plain blocking-read mode |

Each provider's API key is read from the environment variable its config declares (e.g. `OPENAI_API_KEY` / `DEEPSEEK_API_KEY` / `ANTHROPIC_API_KEY`).

---

## 12. Plugin system

Plugins load **dynamically at runtime**: on startup the app scans `plugins/<id>/plugin.json`, reads the entry file and `require`s / instantiates the plugin class (not via composer autoload). A plugin that fails to load is skipped without affecting startup.

**Built-in example: the `clock` plugin** (`plugins/clock/`) — shows the current time on the right of the status bar and refreshes every second.

### Managing plugins

- The sidebar 🧩 **Extensions** tab: `↑` / `↓` to select, `Space` to toggle enable/disable (hot, immediately), `Enter` to open the config file in the built-in editor.
- Disabled plugins stay in the list (marked `[disabled]`), otherwise disabling one would make it unreachable and impossible to re-enable.
- The menu *File → Installed plugins* opens the config too.

### Writing a plugin

1. Create `plugins/<id>/plugin.json`:

   ```json
   { "id": "myplugin", "entry": "MyPlugin.php", "class": "MyPlugin" }
   ```

2. Implement `App\Plugin\PluginInterface`. The core capabilities (all optional, probed with `method_exists`):
   - `id()`: a stable unique id;
   - `statusSegments(App $app)`: inject custom segments into the bottom status bar;
   - `tickInterval()`: return a number of seconds to request periodic refreshes;
   - `panels()`: provide custom panels (tabbed inside a popup);
   - `completions(context, text, cursor, prefix)`: serve completion candidates for the inputs (shares the key and popup with the built-in `@file` completion).

3. Dropping the entry file in place is enough — **no rebuild**.

Plugin files are plain PHP and are not bound by the AOT coding rules (those only apply to core sources compiled into the binary — see [docs/AOT_INCOMPATIBILITY_REPORT.md](AOT_INCOMPATIBILITY_REPORT.md)).

The full plugin development guide (writing / loading mechanism / field values / tests) is in [docs/plugins.md](plugins.md).

---

## 13. Troubleshooting

### The terminal is left garbled (mouse junk / no echo / `-bash: 35: command not found`)

The usual cause is an **abnormal process exit** where cleanup never ran, leaving the terminal in raw mode + alternate screen + mouse reporting on.

Cleanup now covers these exit paths:

| How it died | Terminal restored | Log |
| --- | --- | --- |
| Normal exit / a catchable exception | yes | none (nothing went wrong) |
| PHP fatal error (including memory exhaustion) | yes | yes, into `.vicecode_fatal.log` |
| Killed by a signal (`SIGTERM` / `SIGHUP` / `SIGINT`) | yes | yes, naming the signal |
| **`SIGKILL` / segfault** | **no** — the process never gets to run any code | indirectly (see below) |

The log lives in the **config directory**:

```bash
cat ~/.vicecode_fatal.log       # only non-empty after an abnormal exit
```

The same directory also holds an "this session is alive" marker, `.vicecode_alive`: written at startup and deleted **only on a clean exit**. So if the previous run was killed by an `SIGKILL` or a segfault — something that **cannot be intercepted** — the next launch appends a WARN line to the log (with the previous run's start time and pid) and prints a notice on the terminal before the UI starts. That is the only way to detect those deaths. Running two ViceCode instances at once does not produce a false warning (the marker records a pid, and a still-running process is not treated as stale).

**If the terminal is still broken**, rescue it manually:

```bash
printf '\e[?1000l\e[?1006l\e[?1015l\e[?1002l\e[?1003l\e[?1049l\e[?25h'; stty sane
```

(`reset` works too, but it clears the screen.)

### It exits with an error right at startup

- Make sure you are in a **real terminal**, not a pipe / redirect.
- Run `php -m` to check for swoole; without it the app still runs (falling back to blocking mode) but warns.

### Huge files / huge directories

- The editor opens files > 5 MB read-only (with a notice).
- The tree is lazily expanded; disk reads happen only when a level is expanded.
- Search excludes `vendor` etc. by default and caps results at 2000.

### After switching language, the UI shows a key literal

That means a locale key is missing. `php tests/i18n_parity.php` points straight at the mismatch between the two packs.

---

## 14. Docs & tests index

| Document | Contents |
| --- | --- |
| [README.md](../README.md) / [README.zh.md](../README.zh.md) | feature overview, UI preview, quick start |
| **docs/manual.md** / [docs/manual.zh.md](manual.zh.md) | this manual (full usage) |
| [docs/plugins.md](plugins.md) | plugin development (interface / loading / tests) |
| [docs/BUGFIXES.md](BUGFIXES.md) | every fixed bug: symptom / root cause / regression test |
| [docs/AOT_INCOMPATIBILITY_REPORT.md](AOT_INCOMPATIBILITY_REPORT.md) | coding constraints for the product build (TypePHP/AOT) |
| [CHANGELOG.md](../CHANGELOG.md) | release notes |

Run the tests (**verdict by each test's exit code**; filename filtering and per-test timeout supported):

```bash
tools/run_tests.sh          # everything
tools/run_tests.sh pty      # only files whose name contains pty
composer test               # same as above
```

> Don't grep the output for keywords to decide pass/fail — test names contain "Uncaught"/"Fatal", and not every test prints a PASS marker. Always go by the exit code.

`tests/` holds both headless unit tests and real-pty end-to-end acceptance. Real-pty scripts must never run `bin/vicecode.php` directly — always go through `tests/pty_*.php` and point `VICECODE_CONFIG` at a temp file to isolate config.
