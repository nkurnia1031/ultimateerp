# Engineering workflow

Use this sequence for new features, issue work, and substantial UX changes in this repository. The skill router is the source of truth for choosing among the available Matt Pocock flows; this document records the repository-specific gates.

## Start with the router

1. Read the full `ask-matt/SKILL.md` and follow its routing guidance.
2. Read each selected skill's full `SKILL.md` before using it. Choose only skills that fit the task.
3. Check the repository setup before starting an engineering flow. This repository already uses GitHub Issues and a single-context domain layout. Read `GLOSSARY.md` and relevant `docs/adr/` entries when present. Reuse the existing setup; run `/setup-matt-pocock-skills` only if its configuration is missing or needs to change.

## Decide and record the work

1. For work in this repository, begin the main feature flow with `/grill-with-docs`. Resolve the open decision frontier in rounds and wait for the user's answers before acting on unresolved product decisions. Use `/prototype` with `/handoff` when a decision needs a runnable UI or behavior to evaluate.
2. Read the relevant domain glossary, ADRs, and the full body and comments of any existing GitHub issue. Compare the latest request with that issue's acceptance criteria; an umbrella issue does not automatically capture later requirements.
3. For multi-session work, use `/to-spec`, then `/to-tickets`. Present the proposed spec seams and ticket breakdown, including blockers, and wait for the user's approval before publishing. Link new tickets to the existing parent issue. For a single-session task, use the route selected by `/ask-matt` and keep the implementation tied to a clear issue or agreed request.
4. If implementation started before this workflow was followed, map the existing changes back to the current request and issue criteria before continuing. Identify unrecorded behavior or conflicts with the spec, resolve product decisions with the user, and update the issue/spec plan before the next implementation or verification stage.

## Implement and pause at the checkpoint

Use the implementation and testing skills selected by `/ask-matt`. Work directly on `main`; do not create a feature branch or pull request unless the user explicitly asks. Finish implementation and relevant static checks, then commit and push to `main` so GitHub Actions runs PHPUnit. Pause before starting Playwright E2E or code review, report the changes and risks, and wait for the user's feedback as required by the checkpoint in `AGENTS.md`. After the user directs E2E, manually dispatch GitHub Actions with `run_e2e=true` and wait for its result. Run tests locally only when the user requests local testing. Record issue progress against its acceptance criteria; close an issue only when its criteria are complete.

After the work is complete, use `/retro` when the session exposed a process or environment improvement worth preserving.
