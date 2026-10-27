# GitHub testing

GitHub Actions is the canonical test runner for this repository. The workflow is `.github/workflows/tests.yml`; it uses disposable MySQL 8 databases and does not require production or local-demo credentials.

## Which jobs run

| GitHub event | PHPUnit unit and feature suites | Playwright E2E |
|---|---:|---:|
| Push to `main` | Yes | No |
| Push to another branch | Yes | No |
| Pull request | Yes | No |
| Manual `workflow_dispatch` | Yes | Only when `run_e2e` is selected |

Normal work goes directly to `main`; do not create feature branches or pull requests unless the user explicitly asks. PHPUnit runs on every push. Playwright E2E is manual so work can reach the required user checkpoint first; after the user directs that stage, dispatch it with `run_e2e=true`. It starts only if the PHPUnit job succeeds.

A Playwright job marked `skipped` on a push is not a test result and must never be reported as a pass. Run the manual workflow after the user checkpoint when E2E evidence is required.

## Run and inspect results

After pushing to `main`, list its runs:

```sh
gh run list --workflow tests.yml --branch main --limit 5
```

Wait for a specific run and return its exit status:

```sh
gh run watch <run-id> --exit-status
```

After the issue checkpoint and the user's direction, manually dispatch `tests.yml` against `main` with `run_e2e=true`:

```sh
gh workflow run tests.yml --ref main -f run_e2e=true
```

Playwright traces, reports, and Laravel logs are uploaded as workflow artifacts when the E2E job runs.

Do not report tests as passing until the corresponding GitHub Actions job has completed successfully. For a failure, inspect the failed step and uploaded artifacts first. In the Playwright trace, check whether the expected UI action fired and whether its network request reached the application before diagnosing the endpoint or state transition. Fix the cause instead of blindly rerunning the same commit. After a fix, complete the relevant non-E2E checks, report the new result and risks, and return to the issue checkpoint before dispatching another E2E run. Use the local demo only when the user requests local testing or remote artifacts cannot isolate the problem.

## Add browser coverage

Keep automated browser tests under `tests/e2e/` and use Playwright. Each issue should add or update a user-visible scenario at an agreed public interface; prefer isolated fixtures over local demo data or production credentials. The CI runner uses `PLAYWRIGHT_TEST_BASE_URL` and a fresh database for each job.
