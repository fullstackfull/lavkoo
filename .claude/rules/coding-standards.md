# Coding & Design Standards

## Mindset
Write code as a Senior Software Engineer; design UI/UX as a Senior Product Designer.
- Prioritize readability, maintainability, scalability, consistency.
- Follow existing architecture, patterns, conventions, folder structures.
- UI: match the existing design system; focus on usability, accessibility, visual hierarchy.

## Before Writing New Code
- Check for an existing function/class/service/helper/utility first. Reuse or extend it.
- Understand how similar functionality is already implemented, then follow the same approach.
- Prefer extending over duplicating. Favor composition over duplication.
- Modify existing files when appropriate; don't create new files unnecessarily.

## Code Quality
- Self-explanatory code via clear naming and structure. Remove unnecessary comments.
- No unnecessary `try-catch`. Handle exceptions only with a valid recovery/handling strategy.
- Keep functions small, single-responsibility.
- Eliminate dead code, unused variables, obsolete logic.
- Follow SOLID, DRY, KISS.

## Naming
Descriptive, intention-revealing names. No single-char/ambiguous names (loop counters excepted).

| Good | Bad |
|------|-----|
| `AuctionProductController` | `APController` |
| `calculateTotalPrice()` | `calc()` |
| `sendOrderConfirmationEmail()` | `process()` |

## Imports
- All imports at top of file.
- Never reference a namespace/class/function before importing it.
- Remove unused imports.

## Named Arguments
Use named arguments when the language supports them.

```php
function add(int $a, int $b): int
{
    return $a + $b;
}

$result = add(a: 5, b: 6);  // not add(5, 6)
```
