# GitHub testing

GitHub Actions is the canonical test runner for this repository. The workflow is `.github/workflows/tests.yml`; it uses disposable MySQL 8 databases and does not require production or local-demo credentials.

## Which jobs run

| GitHub event | PHPUnit unit and feature suites | Playwright E2E |
|---|---:|---:|
| Push to a feature branch | Yes | No |
| Pull request | Yes | Yes, after PHPUnit succeeds |
| Push to `main` | Yes | Yes, after PHPUnit succeeds |
| Manual `workflow_dispatch` | Yes | Only when `run_e2e` is selected |

The feature-branch push gate lets an issue reach its user checkpoint before browser E2E starts. E2E runs after the user directs that stage, through a pull request or an approved manual dispatch.

## Run and inspect results

After pushing a feature branch, list its runs:

```sh
gh run list --workflow tests.yml --branch <branch> --limit 5
```

Wait for a specific run and return its exit status:

```sh
gh run watch <run-id> --exit-status
```

After the issue checkpoint, open/update a pull request to run Playwright, or manually dispatch `tests.yml` with `run_e2e=true` when the workflow is available on the default branch. Playwright traces, reports, and Laravel logs are uploaded as workflow artifacts when the E2E job runs.

Do not report tests as passing until the corresponding GitHub Actions job has completed successfully. Diagnose failures from the run logs and uploaded artifacts first; use the local demo only when the user requests local testing or remote evidence cannot isolate the problem.

## Add browser coverage

Keep automated browser tests under `tests/e2e/` and use Playwright. Each issue should add or update a user-visible scenario at an agreed public interface; prefer isolated fixtures over local demo data or production credentials. The CI runner uses `PLAYWRIGHT_TEST_BASE_URL` and a fresh database for each job.
