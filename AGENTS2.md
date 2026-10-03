# Python AI Agents Guidelines (AGENTS2.md)

> This document is the mandatory engineering guideline for AI coding agents and human developers working on Python projects.
>
> The primary goals are:
> - Correctness
> - Readability (over cleverness)
> - Maintainability
> - Performance
> - Security
> - Reproducible Builds
>
> Follow these rules unless the task explicitly requires an exception.
>
> **Primary References:**
> - [PEP 8 — Style Guide for Python Code](https://peps.python.org/pep-0008/)
> - [PEP 20 — The Zen of Python](https://peps.python.org/pep-0020/)
> - [PEP 257 — Docstring Conventions](https://peps.python.org/pep-0257/)
> - [PEP 484 — Type Hints](https://peps.python.org/pep-0484/)
> - [PEP 517/518 — Build System](https://peps.python.org/pep-0517/)

---

# 1. Default Behavioral Directives

1. **The Zen of Python First (PEP 20):**
   - Beautiful is better than ugly.
   - Explicit is better than implicit.
   - Simple is better than complex.
   - Flat is better than nested.
   - Readability counts.
   - Errors should never pass silently.

2. **YAGNI & Zero Over-Engineering:**
   - Never introduce abstract base classes, metaclasses, or design patterns for theoretical future requirements.
   - Reach for the Python standard library before adding any third-party dependency.

3. **Explicit Over Implicit:**
   - Never use `import *`. Always import exactly what you need.
   - Name your parameters. Avoid `**kwargs` as primary parameters.
   - Never rely on mutable default arguments.

---

# 2. Code Style (PEP 8)

## 2.1 Indentation & Spacing
- Use **4 spaces** per indentation level. Never tabs.
- Maximum line length is **88 characters** (Black default).
- Surround top-level function and class definitions with **two blank lines**.
- No trailing whitespace on any line.

## 2.2 Imports
Imports must be grouped in this exact order, separated by a blank line:
1. Standard library imports
2. Third-party package imports
3. Local application/library specific imports

## 2.3 Naming Conventions
- Module/Package: `snake_case`
- Function/Variable: `snake_case`
- Class: `PascalCase`
- Constant: `UPPER_SNAKE_CASE`
- Private: `_single_leading_underscore`

---

# 3. Type Hints (PEP 484)

All new code **must** include type annotations on function signatures.
- Annotate all function parameters and return types.
- Use `X | Y` union syntax (Python 3.10+) instead of `Optional[X]` or `Union[X, Y]`.
- Use `list[str]`, `dict[str, int]` instead of `typing.List` (Python 3.9+).

---

# 4. Docstrings (PEP 257)

All public modules, classes, and functions **must** have a docstring.
- The summary line must fit on one line, ending with a period.
- Use the `Args:` / `Returns:` / `Raises:` Google-style format consistently.

---

# 5. Error Handling

- Never use a bare `except:` clause. Always name the exception.
- Never silently swallow exceptions with `pass`. At minimum, log them.
- Use `logging.exception()` inside `except` blocks when the full traceback is needed.

---

# 6. Logging

- Use Python's built-in `logging` module. Never use `print()` in production code paths.
- Configure logging once at the entry point using `logging.basicConfig()`.
- Never log secrets, tokens, passwords, or PII.

---

# 7. Threading & Concurrency

- Always protect shared mutable state with `threading.Lock()`.
- Use `daemon=True` for background service threads so they do not prevent clean process exit.
- Never busy-wait. Use blocking I/O or events.

---

# 8. Web / API Rules (WSGI/ASGI)

- Route handlers must be thin. Business logic belongs in a service function or class.
- Always return explicit HTTP status codes for non-200 responses.
- Always validate request JSON before use.

---

# 9. Dependencies & Packaging

- All runtime dependencies **must** be pinned to an exact version in `requirements.txt` (e.g., `requests==2.31.0`).
- Use a project-local virtualenv (e.g., `venv/`). Never install packages into the system Python globally.
- Keep development dependencies (pytest, ruff, black) separated in a `requirements-dev.txt`.

---

# 10. Security

- Never trust user input without validation.
- Never write to arbitrary file paths constructed from user input.
- Never execute shell commands constructed from user input (`shell=True`).
- Never hardcode secrets, tokens, or API keys in source code. Load them via environment variables (`.env`).

---

# 11. Testing & Code Quality

- Use `pytest` for all unit testing.
- Tests must be deterministic. Never make real external network calls in unit tests (use mocks).
- Enforce code quality using **Black** (formatter), **Ruff** (linter), and **Mypy** (type checker).
