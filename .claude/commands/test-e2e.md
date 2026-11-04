Run Playwright end-to-end tests.

Arguments: $ARGUMENTS

Instructions:

1. Determine the test scope from "$ARGUMENTS":

   * If empty: run all tests (`npx playwright test`)
   * If a surface name (admin, vendor, themes): run that folder (`npx playwright test tests-e2e/$ARGUMENTS/`)
   * If a specific file path: run that file (`npx playwright test $ARGUMENTS`)

2. Run the appropriate Playwright command with `--reporter=list` for readable output.

3. If tests fail:

   * Show the failure summary (test name, error message, file:line).
   * Identify the root cause of the failure.
   * Fix the issue in the relevant code or test.
   * Re-run the same test(s) to verify the fix.
   * Repeat until tests pass or no further fixes can be confidently applied.
   * Suggest next steps if still failing (check selectors, verify dev server is running, review screenshots in test-results/).

4. If all tests pass:

   * Confirm with a short summary (number of tests passed, duration).
   * If fixes were applied, briefly mention what was fixed.

5. Do NOT open the HTML report automatically — mention that the user can run `npm run test:e2e:report` to view it.

