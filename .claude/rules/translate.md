---
paths:
  - "resources/views/**/*.blade.php"
  - "resources/js/**/*.vue"
---

# Blade Translation Rule

In `.blade.php` files, replace all hardcoded text strings with `translate('text_here')`.
Do NOT modify dynamic variables or strings already wrapped in `translate()`, `__()`, or `trans()`.
