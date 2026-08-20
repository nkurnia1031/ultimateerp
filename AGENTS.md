## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

When the user types `/graphify`, use the installed graphify skill or instructions before doing anything else.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- Dirty graphify-out/ files are expected after hooks or incremental updates; dirty graph files are not a reason to skip graphify. Only skip graphify if the task is about stale or incorrect graph output, or the user explicitly says not to use it.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## JavaScript assets

- Treat `Modules/<Module>/Resources/assets/js/` as the only editable source for module JavaScript.
- Do not manually edit the matching runtime copy in `public/modules/<module>/js/`. Inspect it only when diagnosing a deployed/runtime mismatch.
- After changing module JavaScript, publish the changed source at the end of the task with `php scripts/publish-module-js.php <Module> [relative/file.js]`. Omitting the file publishes every JavaScript file for that module.
- Verify the publish command succeeds before finishing the task. The command copies the source without deleting it and verifies that the source and runtime copy are byte-identical.
- JavaScript in `public/js/` may be edited directly only when it is an application-wide script and has no canonical source under a module.
- Both `public/js/`, `public/modules/**/*.js`, and module JavaScript sources are included in Graphify so their relationships with Blade views remain discoverable. Generated dependency bundles remain excluded.

## Local demo smoke test

- URL: `http://localhost/2025/ultimateerp/public/`
- Username: `direktur_cv`
- Password: `cv123456`
- For local UI smoke tests, use `agent-browser` (the in-app browser automation skill) and keep the test within this local URL.
