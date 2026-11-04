---
description: Auction theme UI development rules — loaded for default/auction and theme_aster/auction views
---

# Auction UI Rules

## Theme Separation
Default and Aster are fully separate (different Bootstrap, different structure).
- Reuse: logic, conditions, flow only
- Never copy: HTML, classes, layout

Default = logic source. Adapt to Aster; never copy verbatim.

## UI Changes
Inject logic/conditional rendering only. Never alter layout, structure, or design.

## Code Standards
- No duplicate logic — use services, helpers, model scopes
- Must not break existing features
- Web and API logic must stay in sync

## Perspectives
Handle Auction Owner and Customer (bidder) as separate concerns.

## Engineering
Choose the best approach freely. Improve logic where possible. Never break existing behavior.
