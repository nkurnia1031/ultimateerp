# Codex session and cache cleanup

Use the manual helper only when the user explicitly asks to reset local Codex sessions or cache. It stops active Codex processes, then clears conversation/session state and regenerated caches while preserving authentication and settings.

## Run the helper

Run this from a separate PowerShell window outside Codex. The helper will close all detected Codex CLI, desktop, and code-mode processes, so the current Codex session will end. It previews the resolved target and asks for the exact confirmation `RESET CODEX` before changing anything.

From the repository root:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\clear-codex-session-cache.ps1 -Preview
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\clear-codex-session-cache.ps1
```

`-Preview` lists the target directories, session databases, and active Codex processes without stopping or deleting anything. The normal run requires typing `RESET CODEX`.

The helper resolves `%CODEX_HOME%`, or `%USERPROFILE%\.codex` when that variable is unset. It validates that `config.toml` and at least one `auth*.json` file exist before proceeding. It clears the `cache`, `sessions`, temporary, and session-lock directories; removes conversation history/index files and Codex session/state databases; and checks that authentication and `config.toml` remain present.

It preserves `auth*.json`, `config.toml`, `AGENTS.md`, rules, installed skills and plugins, attachments, generated images, and sandbox data. Plugin package cache under `plugins\cache` is preserved because it contains installed plugin content. Codex may rebuild cleared caches on its next start. Starting a new conversation is still required for a fresh model context.

Do not remove the entire Codex home directory. Do not run the helper against a production server or another user's Codex home.
