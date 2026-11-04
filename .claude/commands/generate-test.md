**Generate comprehensive Playwright tests for a given page or feature.**

Arguments: $ARGUMENTS

Instructions:

1. Parse "$ARGUMENTS" to determine:

   * Surface: admin | vendor | themes (required)
   * For themes: theme name is also required — "default" or "aster"
   * Feature: e.g., "auth", "product", "order", "dashboard", "home", "cart" (required)
   * Test name: e.g., "login", "list", "detail" (required)
   * Examples:

     * "admin auth login"
     * "vendor product list"
     * "themes default cart add-to-cart"
     * "themes aster home hero-banner"

2. If "$ARGUMENTS" is empty or incomplete, ask the user for the required parts.

   * For themes surface, always ask which theme (default or aster) if not provided.

3. Determine the target file path (feature-based structure):

   * admin/vendor: `tests-e2e/{surface}/{feature}/{test-name}.spec.ts`
   * themes: `tests-e2e/themes/{theme}/{feature}/{test-name}.spec.ts`
   * Create the feature folder if it does not exist.

4. Read existing test files in that folder for style, patterns, and helper usage.

5. Generate a new `.spec.ts` file with:

   * Proper imports (`@playwright/test`)

   * A `test.describe` block named with the theme (if applicable), feature, and test

   * **Extensive and realistic test coverage (NOT limited to a fixed number):**
     Generate as many relevant `test()` cases as reasonably possible, ensuring coverage of:

     * Happy paths (core successful flows)
     * Negative scenarios (invalid inputs, failures)
     * Edge cases (empty data, unusual inputs, unexpected states)
     * Boundary conditions (min/max limits, constraints)
     * Error handling (API failures, UI error states, fallback behavior)
     * Loading and async states
     * Permission/role-based access (if applicable)
     * State variations (empty, partial, populated data)
     * UI behavior (visibility, disabled states, responsiveness where applicable)
     * Regression-prone and high-risk scenarios based on the feature type

   * **Each test MUST include an actual implementation (not just skeletons):**

     * Use Playwright APIs (`page.goto`, `locator`, `click`, `fill`, `expect`, etc.)
     * Include meaningful assertions (`expect`) for validation
     * Reuse helpers from `utils/helpers` where appropriate
     * Keep tests clean, readable, and maintainable

   * **Selector strategy — strictly follow this priority order:**

     1. `page.getByRole('button', { name: '...' })` — preferred for interactive elements
     2. `page.getByLabel('...')` — preferred for form fields
     3. `page.getByTestId('...')` — when `data-testid` attributes exist
     4. `page.getByText('...')` — for static text content
     5. `page.getByPlaceholder('...')` — for input placeholders
     6. `page.locator('css')` — **last resort only**; avoid nth-child, long CSS chains, or implementation-detail class names

     > These match the selectors the AI healing pipeline (`/fix-playwright-test`) promotes when fixing broken tests. Starting with them prevents `selector_issue` failures.

   * **Timing rules — never use manual waits:**

     * DO NOT use `page.waitForTimeout(n)` — Playwright auto-waits on all locator actions
     * Wait for visibility with assertions: `await expect(locator).toBeVisible()`
     * Wait for navigation: `await page.waitForLoadState('networkidle')` (only when a full reload is expected)
     * For slow operations, add a timeout to the specific assertion: `await expect(locator).toBeVisible({ timeout: 10_000 })`

     > These patterns prevent `timing_issue` failures and keep tests compatible with the AI healing pipeline.

   * Use clear, descriptive test names reflecting real user behavior — one distinct behavior per `test()` block so the triage system can pinpoint failures precisely

6. Avoid redundant or trivial duplicate tests, but prioritize meaningful coverage depth.

7. Ensure the generated tests are runnable and logically consistent (no placeholder-only code).

8. Confirm the file was created and briefly summarize what areas are covered and what could be tested further.

   * Mention if any tests are likely candidates for flakiness (e.g. depend on network timing or dynamic data) so they can be monitored by the `/fix-tests` pipeline.

