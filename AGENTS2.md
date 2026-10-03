# AGENTS2.md



> This document is the mandatory engineering guideline for AI coding agents and human developers working on **Python** components of this project (e.g., `clients/edge-engine`).
>
> The primary goals are:
>
> - Correctness
> - Readability (over cleverness)
> - Maintainability
> - Performance (especially on constrained hardware)
> - Security
> - Reproducible Builds
> - Minimal Footprint
>
> Follow these rules unless the task explicitly requires an exception.
>
> **Primary References:**
> - [PEP 8 — Style Guide for Python Code](https://peps.python.org/pep-0008/)
> - [PEP 20 — The Zen of Python](https://peps.python.org/pep-0020/)
> - [PEP 257 — Docstring Conventions](https://peps.python.org/pep-0257/)
> - [PEP 484 — Type Hints](https://peps.python.org/pep-0484/)
> - [PEP 517/518 — Build System](https://peps.python.org/pep-0517/)
> - [PEP 621 — Project Metadata](https://peps.python.org/pep-0621/)
> - [PEP 3333 — WSGI](https://peps.python.org/pep-3333/)
> - [Python Packaging User Guide](https://packaging.python.org/)

---

# Default Behavioral Directives

1. **The Zen of Python First (PEP 20):**
   - Beautiful is better than ugly.
   - Explicit is better than implicit.
   - Simple is better than complex.
   - Complex is better than complicated.
   - Flat is better than nested.
   - Sparse is better than dense.
   - Readability counts.
   - Errors should never pass silently.
   - In the face of ambiguity, refuse the temptation to guess.
   - There should be one — and preferably only one — obvious way to do it.
   - If the implementation is hard to explain, it is a bad idea.

2. **YAGNI & Zero Over-Engineering:**
   - Never introduce abstract base classes, metaclasses, or design patterns for theoretical future requirements.
   - Reach for the Python standard library before adding any third-party dependency.
   - One module before a package. One function before a class. One class before a framework.

3. **Explicit Over Implicit:**
   - Never use `import *`. Always import exactly what you need.
   - Never use `**kwargs` as the primary parameter for a public function. Name your parameters.
   - Never rely on mutable default arguments (see Rule 10).

4. **Platform & Hardware Awareness:**
   - This Python code runs on **ARM Cortex-A53 (target hardware (e.g., Raspberry Pi))** with 1 GB RAM. Every dependency added has a RAM, disk, and startup-time cost on this real device.
   - Prefer `onnxruntime` over full `torch`/`tensorflow` for inference.
   - Prefer `flask` over `fastapi`/`uvicorn`/`starlette` unless async I/O is a concrete measured requirement.

---

# 1. Code Style (PEP 8)

## 1.1 Indentation & Spacing

- Use **4 spaces** per indentation level. Never tabs (PEP 8).
- Maximum line length is **88 characters** (Black default; stricter than PEP 8's 79 to allow modern monitors).
- Surround top-level function and class definitions with **two blank lines**.
- Surround method definitions inside a class with **one blank line**.
- No trailing whitespace on any line.

## 1.2 Imports (PEP 8, PEP 328)

Imports must be grouped in this exact order, separated by a blank line:

```python
# 1. Standard library
import os
import threading
from pathlib import Path

# 2. Third-party packages
import flask
import onnxruntime as ort

# 3. Local application/library specific
from config.settings import load_env
from core.models.face_recognizer import FaceRecognizer
```

Rules:
- `import x` before `from x import y`.
- Never use wildcard imports: `from module import *` is **always** forbidden.
- Never place imports inside functions unless you have a documented circular-import reason.
- One import per line for `import x` style.

## 1.3 Naming Conventions (PEP 8)

| Symbol | Convention | Example |
|--------|------------|---------|
| Module | `snake_case` | `stream_service.py` |
| Package | `snake_case` | `face_recognizer/` |
| Function | `snake_case` | `get_embedding()` |
| Variable | `snake_case` | `frame_count` |
| Class | `PascalCase` | `FaceRecognizer` |
| Constant | `UPPER_SNAKE_CASE` | `UDP_PORT = 55555` |
| Private | `_single_leading_underscore` | `_latest_status` |
| Protected from name-mangling | `__double_leading` | `__internal` (use sparingly) |
| Type variable | `PascalCase` or single letter | `T`, `KT`, `VT` |

**Never** use `l` (lowercase L), `O` (uppercase O), or `I` (uppercase I) as single-character variable names.

## 1.4 Expressions & Statements

- One statement per line. Never `x = 1; y = 2`.
- Never use `== True` or `== False` or `== None`. Use `is True`, `is None`, or just `if condition:`.
- Never compare a type with `type(x) == int`. Use `isinstance(x, int)`.

---

# 2. Architectural Principles

Follow these principles, which mirror PEP 20:

1. Prefer functions over classes when there is no state to encapsulate.
2. Prefer composition over inheritance.
3. Prefer flat module structures over deep package hierarchies.
4. Keep service modules focused: one primary responsibility per file.
5. Keep business logic out of Flask route handlers (same principle as thin controllers in backend server).
6. Do not introduce abstractions (Abstract Base Classes, Protocol classes) without a concrete, measurable reason.
7. Organize code by domain/feature, not by type (not `models/`, `services/`, `utils/` at the top level).
8. Optimize based on evidence (profiling), not assumptions.
9. Avoid premature threading. Add threads only when I/O blocking is measured to be a problem.
10. Preserve backward compatibility of internal config formats and API contracts.

---

# 3. Directory Structure

Use the following structure for the Edge Engine Python package:

```text
clients/edge-engine/
├── config/
│   ├── __init__.py
│   ├── settings.py        # load_env(), constants, ROOT_DIR
│   └── onnx.py            # ONNX Runtime session options
│
├── core/
│   ├── __init__.py
│   └── models/
│       ├── face_recognizer/
│       │   ├── __init__.py
│       │   ├── recognizer.py
│       │   └── session_utils.py
│       └── liveness_detector/
│           ├── __init__.py
│           ├── detector.py
│           ├── postprocess.py
│           └── session_utils.py
│
├── services/
│   ├── __init__.py
│   ├── stream_service.py     # Flask MJPEG + REST API
│   └── discovery_service.py  # UDP broadcast listener
│
├── models/                   # Binary .onnx model files (not Python)
│   ├── recognizer.onnx
│   ├── detector.onnx
│   └── liveness.onnx
│
├── venv/                     # Local virtualenv (never committed)
├── requirements.txt          # Pinned runtime dependencies
├── requirements-dev.txt      # Dev/test-only dependencies
├── build.py                  # PyInstaller compilation script
├── engine.spec               # PyInstaller spec file
├── updater.sh                # OTA update script
├── engine.py                 # Main entry point
└── .env                      # Local config (never committed)
```

Do not create directories that are not needed. If a module does not need a `utils.py`, do not create one.

---

# 4. Type Hints (PEP 484, PEP 526, PEP 563)

All new code **must** include type annotations on function signatures.

**Good:**
```python
def get_embedding(frame: np.ndarray, threshold: float = 0.5) -> list[float] | None:
    ...
```

**Bad:**
```python
def get_embedding(frame, threshold=0.5):
    ...
```

Rules:
- Annotate all function parameters and return types.
- Use `from __future__ import annotations` at the top of files to enable PEP 563 deferred evaluation when needed for forward references.
- Use `X | Y` union syntax (Python 3.10+) instead of `Optional[X]` or `Union[X, Y]`.
- Use `list[str]` not `List[str]` (Python 3.9+).
- Use `dict[str, int]` not `Dict[str, int]` (Python 3.9+).
- For functions that never return (always raise), annotate as `-> NoReturn`.
- Do not annotate `self` or `cls` in methods.

---

# 5. Docstrings (PEP 257)

All public modules, classes, and functions **must** have a docstring.

**One-liner (for obvious functions):**
```python
def stop(self) -> None:
    """Stop the discovery service and close the socket."""
```

**Multi-line (for non-obvious functions):**
```python
def get_embedding(frame: np.ndarray) -> list[float] | None:
    """
    Extract a 512-d face embedding from a pre-aligned face crop.

    Args:
        frame: BGR image array of shape (112, 112, 3), dtype uint8.

    Returns:
        A list of 512 floats (L2-normalized), or None if inference fails.
    """
```

Rules:
- The summary line must fit on one line, ending with a period.
- Use the `Args:` / `Returns:` / `Raises:` Google-style format consistently.
- Private methods (`_method`) do not require docstrings but benefit from a comment if non-obvious.

---

# 6. Error Handling

Rules derived from PEP 20 ("Errors should never pass silently"):

**Good:**
```python
try:
    session = ort.InferenceSession(model_path)
except (FileNotFoundError, ort.InvalidGraph) as e:
    logging.error("Failed to load ONNX model: %s", e)
    return None
```

**Bad — catches too broadly:**
```python
try:
    session = ort.InferenceSession(model_path)
except Exception:
    pass  # Silent failure is always wrong
```

Rules:
- Never use a bare `except:` clause. Always name the exception.
- Never silently swallow exceptions with `pass`. At minimum, log them.
- Use specific exceptions. Do not catch `Exception` as a universal handler unless you re-raise.
- Use `logging.exception()` (which logs the traceback) inside `except` blocks, not `logging.error()`, when the full traceback is needed for diagnosis.
- Define custom exception classes in a `exceptions.py` module when domain-specific errors need to be distinguished (e.g., `ModelLoadError`, `InferenceError`).

---

# 7. Logging (PEP 282)

Use Python's built-in `logging` module. Never use `print()` in production code paths.

**Good:**
```python
import logging

logging.info("[Discovery] UDP Listener started on port %d", UDP_PORT)
logging.warning("[OTA] Checksum mismatch: expected=%s got=%s", expected, actual)
logging.error("[Recognizer] ONNX session load failed: %s", e)
```

**Bad:**
```python
print(f"Started on port {UDP_PORT}")
```

Rules:
- Configure logging once at the entry point (`engine.py`) using `logging.basicConfig()`.
- Use `%s` style formatting in log calls (lazy evaluation, not f-strings), to avoid string formatting overhead when the log level is disabled.
- Use structured context: `logging.info("Event", extra={"key": value})` when shipping to a log aggregator.
- Never log secrets, tokens, passwords, face embeddings, or personally identifiable information (PII).
- Log levels: `DEBUG` for development tracing, `INFO` for operational events, `WARNING` for recoverable problems, `ERROR` for failures, `CRITICAL` for unrecoverable state.

---

# 8. Threading & Concurrency

Rules for safe multi-threaded Python code (GIL awareness):

- Always protect shared mutable state with `threading.Lock()`.
- Name every thread: `threading.Thread(target=..., name="DiscoveryListener", daemon=True)`.
- Use `daemon=True` for background service threads so they do not prevent clean process exit.
- Never busy-wait. Use `threading.Event.wait(timeout)` or blocking I/O.
- Avoid `threading.Thread` for CPU-bound work — the GIL prevents true parallelism. Use `concurrent.futures.ProcessPoolExecutor` if CPU parallelism is required.
- For ONNX inference that blocks the camera loop, offload to a `ThreadPoolExecutor` and use `Future` results.

**Good:**
```python
self._lock = threading.Lock()

with self._lock:
    self._latest_status = new_status
```

**Bad:**
```python
# Unprotected shared state mutation
self._latest_status = new_status  # from multiple threads
```

---

# 9. Flask API Rules (PEP 3333 / WSGI)

This project uses Flask as the HTTP server for the Edge Engine REST API.

**Good — thin route handler:**
```python
@app.route("/status")
def status() -> Response:
    with _lock:
        return jsonify({"status": "ok", "recognition": _latest_status})
```

**Bad — business logic in route:**
```python
@app.route("/status")
def status():
    # 50 lines of computation, file I/O, subprocess calls...
```

Rules:
- Route handlers must be thin. Business logic belongs in a service function or class.
- Always return explicit HTTP status codes for non-200 responses: `return jsonify(...), 400`.
- Always validate request JSON before use: `if not data or not data.get("key"): return ..., 400`.
- Use `flask.request.json` — never trust the body without validation.
- Never run blocking I/O (file writes, subprocess calls) synchronously inside a route handler if it takes > 100 ms. Use `threading.Thread(daemon=True)` or `subprocess.Popen` to background it.
- The Flask dev server is only for development. In production, run behind `gunicorn` or as a systemd service with `waitress`.

---

# 10. Common Python Pitfalls to Avoid

### 10.1 Mutable Default Arguments (PEP 8)

**Bad:**
```python
def register(person, persons=[]):  # Bug: shared across all calls
    persons.append(person)
    return persons
```

**Good:**
```python
def register(person: str, persons: list[str] | None = None) -> list[str]:
    if persons is None:
        persons = []
    persons.append(person)
    return persons
```

### 10.2 Late Binding in Closures

**Bad:**
```python
funcs = [lambda: i for i in range(5)]  # All return 4
```

**Good:**
```python
funcs = [lambda i=i: i for i in range(5)]  # Each captures its own i
```

### 10.3 String Concatenation in Loops

**Bad:**
```python
result = ""
for item in items:
    result += str(item)  # O(n²) memory allocations
```

**Good:**
```python
result = "".join(str(item) for item in items)
```

### 10.4 Using `is` vs `==`

- Use `is` only for identity checks (singletons: `None`, `True`, `False`).
- Use `==` for value equality.

### 10.5 Checking Empty Containers

**Bad:** `if len(my_list) == 0:`

**Good:** `if not my_list:`

---

# 11. Dependencies & Packaging

## 11.1 Dependency Pinning (requirements.txt)

All runtime dependencies **must** be pinned to an exact version in `requirements.txt`.

**Good:**
```
flask==3.0.3
onnxruntime==1.18.1
numpy==1.26.4
```

**Bad:**
```
flask>=2.0
onnxruntime
numpy~=1.26
```

Why: Unpinned dependencies cause non-reproducible builds across machines and between dev and production (the edge device ARM device).

## 11.2 Dev Dependencies

Keep dev-only tools in `requirements-dev.txt` (never installed on the device):

```
pytest==8.2.2
pytest-cov==5.0.0
ruff==0.5.0
mypy==1.10.0
black==24.4.2
```

## 11.3 Virtual Environments

- Always use a project-local virtualenv at `venv/`.
- Activate with `.\venv\Scripts\activate` (Windows) or `source venv/bin/activate` (Linux/edge device).
- **Never** install packages into the system Python on the edge device. Always use the virtualenv.
- The `venv/` directory is always in `.gitignore`.

## 11.4 Dependency Evaluation Checklist

Before adding any new third-party package, evaluate:
1. **Necessity**: Does the Python standard library already do this?
2. **Maintenance**: Is it actively maintained? When was the last release?
3. **Security**: Does it have known CVEs?
4. **Size**: How many MB does it add to the installed environment?
5. **ARM Compatibility**: Does it have a pre-built wheel for `linux/arm64`? (No wheel = compiling C extensions on edge device, which is very slow or impossible).

---

# 12. Build System & PyInstaller Rules (PEP 517/518)

This project compiles the Edge Engine Python application into a **single self-contained binary** using PyInstaller to protect intellectual property.

## 12.1 Build Flow

```text
Source Code (clients/edge-engine/)
    ↓
build.py (runs PyInstaller with engine.spec)
    ↓
PyInstaller Analysis (dependency graph)
    ↓
Compilation & Bundling
    ↓
dist/your-app-engine  (final distributable binary)
```

## 12.2 PyInstaller Spec File (`engine.spec`) Rules

- Always use `engine.spec` for reproducible builds. Never rely on auto-generated specs from CLI flags alone.
- Include all data files (`.onnx` models, `.env`, config files) explicitly in the `datas` list:

```python
datas=[
    ('models/', 'models'),
    ('config/', 'config'),
]
```

- If a hidden import is not detected automatically, add it to `hiddenimports` explicitly:

```python
hiddenimports=['onnxruntime.capi._pybind_state']
```

- Use `--onefile` for single-binary distribution to devices (simplest OTA).
- Use `--strip` to reduce binary size.
- Use `--clean` on every build to avoid stale artifact contamination.
- Cross-compilation for ARM must be done **on the ARM device** or in a matching Docker container. PyInstaller binaries are not cross-platform.

## 12.3 build.py Rules

- `build.py` must be idempotent. Running it twice produces the same artifact.
- Clean `build/` and `dist/` at the start of every build run.
- After compilation, print the SHA-256 checksum of the output binary. This checksum is used by `updater.sh` for verification:

```python
import hashlib
sha256 = hashlib.sha256(Path("dist/your-app-engine").read_bytes()).hexdigest()
print(f"SHA-256: {sha256}")
```

- Never commit `build/` or `dist/` to git.

## 12.4 OTA Update Contract

When pushing a new binary or model to a device via the OTA system:
- The payload must be a `tar.gz` archive.
- The SHA-256 checksum of the `tar.gz` must be computed before upload and sent in the backend server OTA trigger payload.
- The Edge Engine's `updater.sh` must verify the checksum before replacing any files.
- A backup of the previous binary/models must be kept for automatic rollback.

---

# 13. ONNX Runtime Rules

Rules for efficient inference on constrained hardware:

- Always initialize ONNX sessions once at application startup, not per-request.
- Select execution providers explicitly. Do not rely on automatic provider selection:

```python
providers = ["CPUExecutionProvider"]  # ARM64 CPU only
session = ort.InferenceSession(model_path, providers=providers)
```

- Use `SessionOptions` to control thread count. On a 4-core ARM, limit intra-op threads:

```python
opts = ort.SessionOptions()
opts.intra_op_num_threads = 2  # Leave cores for camera I/O
opts.inter_op_num_threads = 1
```

- Pre-allocate input tensors where possible. Avoid repeated `np.array()` creation in tight loops.
- Profile model inference time with `time.perf_counter()` during development. Target: < 50 ms per face on edge device.
- Store `model_version` as a constant alongside each `.onnx` file. Expose it via heartbeat so the server knows which model version the device is running.

---

# 14. Memory Management Rules

Critical for the target hardware (e.g., Raspberry Pi) (1 GB RAM shared with OS and GPU):

- Cap the face embedding cache at a defined maximum (e.g., 2000 entries ≈ 1 MB for 512-d float32 vectors).
- Use `collections.deque(maxlen=N)` for rolling history buffers (e.g., recognition history) to prevent unbounded RAM growth.
- Never load entire large files into memory at once. Use chunked reads for file I/O.
- Release references to large NumPy arrays explicitly when done if they are in long-lived objects.
- Target total Python process RSS < 150 MB (same constraint as PRD Section 7.2).
- Monitor RSS in the heartbeat: `import resource; resource.getrusage(resource.RUSAGE_SELF).ru_maxrss`.

---

# 15. Security Rules

## 15.1 Input Validation

- Never trust JSON from any HTTP request without validation.
- Validate types, value ranges, and required fields before processing.

**Good:**
```python
data = request.json
if not isinstance(data, dict) or not data.get("token"):
    return jsonify({"error": "Invalid payload"}), 400
```

## 15.2 File System

- Never write to arbitrary paths constructed from user input.
- Always use `pathlib.Path` and `.resolve()` when resolving user-provided paths, and verify the resolved path is within an expected directory.
- Never execute shell commands constructed from user input. Use `subprocess.run(["cmd", arg1, arg2])` with a list, never `subprocess.run(f"cmd {user_input}", shell=True)`.

## 15.3 Secrets

- Never hardcode secrets, tokens, API keys, or device tokens in source code.
- Load all secrets from `.env` via `config/settings.py` using `os.environ.get()`.
- Never log the value of secrets.
- The `.env` file is always in `.gitignore`.

## 15.4 HTTPS

- All communication with the backend server server must use HTTPS.
- When making outbound requests, do not disable SSL verification: never use `verify=False` in `requests.get()`.

## 15.5 OTA Security

- The OTA `download_url` must always be an HTTPS URL. Reject HTTP URLs.
- Always verify SHA-256 checksum before installing any downloaded binary or model file.
- Consider signature verification (HMAC or asymmetric signing) for production deployments.

---

# 16. Testing Rules

## 16.1 Test Framework

Use `pytest`. All tests live in a `tests/` directory mirroring the source structure:

```text
tests/
├── config/
│   └── test_settings.py
├── core/
│   └── models/
│       └── test_recognizer.py
└── services/
    └── test_stream_service.py
```

## 16.2 What to Test

- Every public function must have at minimum one happy-path test.
- Every error branch (`except`, early `return`, input validation) must have a test.
- ONNX model loading and inference must be tested with a minimal test image.
- Flask endpoints must be tested using `app.test_client()`, not by making real HTTP requests.

**Good:**
```python
def test_status_endpoint_returns_ok(client):
    response = client.get("/status")
    assert response.status_code == 200
    assert response.json["status"] == "ok"
```

## 16.3 Test Rules

- Tests must be deterministic. Never test with random data without a fixed seed.
- Never make real network calls in unit tests. Mock with `unittest.mock.patch`.
- Never load real ONNX models in unit tests. Mock the session with `MagicMock`.
- Test coverage target: >= 80% line coverage for `core/` and `services/`.
- Run tests with: `pytest --cov=. --cov-report=term-missing`.

---

# 17. Code Formatting & Linting

## 17.1 Formatter: Black

Use **Black** (PEP 8 compliant, opinionated, non-configurable style):

```bash
black clients/edge-engine/
```

Configuration in `pyproject.toml`:

```toml
[tool.black]
line-length = 88
target-version = ["py311"]
```

Never manually argue over formatting. If Black formats it differently, accept Black's output.

## 17.2 Linter: Ruff

Use **Ruff** for fast linting (replaces flake8, isort, pylint):

```bash
ruff check clients/edge-engine/
```

Minimum enabled rules in `pyproject.toml`:

```toml
[tool.ruff]
line-length = 88
select = ["E", "F", "W", "I", "N", "UP", "S", "B"]
# E/W: pycodestyle, F: Pyflakes, I: isort, N: naming,
# UP: pyupgrade, S: bandit security, B: bugbear
ignore = ["S101"]  # allow assert in tests
```

## 17.3 Type Checker: mypy

Use **mypy** in strict mode for new code:

```bash
mypy clients/edge-engine/ --strict
```

Configuration:

```toml
[tool.mypy]
python_version = "3.11"
strict = true
ignore_missing_imports = true
```

## 17.4 Pre-commit (Recommended)

Wire `black`, `ruff`, and `mypy` as pre-commit hooks to enforce quality before every commit:

```yaml
# .pre-commit-config.yaml
repos:
  - repo: https://github.com/psf/black
    rev: 24.4.2
    hooks: [{id: black}]
  - repo: https://github.com/astral-sh/ruff-pre-commit
    rev: v0.5.0
    hooks: [{id: ruff, args: [--fix]}]
```

---

# 18. Python Version Policy

- Minimum supported Python version: **3.11**.
- Reason: 3.11 provides significant performance improvements over 3.9/3.10, and all dependencies (onnxruntime, flask, numpy) have stable wheels for `python3.11-arm64`.
- Use `python_requires = ">=3.11"` in `pyproject.toml`.
- Do not use any syntax or stdlib features that were removed or deprecated in 3.11+.
- Use `from __future__ import annotations` only when forward references are unavoidable.

---

# 19. Performance Rules for Constrained Hardware

1. **Never block the camera loop.** Inference must run in a background thread, not inline in the frame-capture loop.
2. **Use `time.perf_counter()` for profiling**, not `time.time()`.
3. **Pre-warm ONNX sessions.** Run one dummy inference at startup to JIT-compile the model graph before real use.
4. **Avoid copying NumPy arrays.** Use views (slices) instead of `.copy()` unless mutation is required.
5. **Use `np.float32` not `np.float64` for ONNX inputs.** Most ONNX models expect float32; passing float64 causes an implicit copy.
6. **Use `collections.deque` not `list` for rolling buffers.** `list.pop(0)` is O(n); `deque.popleft()` is O(1).
7. **Profile before optimizing.** Use `cProfile` or `py-spy` to find actual bottlenecks on the target ARM device before making architectural changes.

---

# 20. Git & Commit Rules (Python-Specific)

Never commit:
```
venv/
__pycache__/
*.pyc
*.pyo
*.pyd
dist/
build/
*.egg-info/
.env
*.onnx
```

Commit messages for Python changes follow Conventional Commits:
```
feat(recognizer): add model_version to embedding cache
fix(discovery): handle socket timeout on UDP recvfrom
perf(onnx): pre-warm session with dummy inference at startup
refactor(stream): move business logic out of /status route handler
test(recognizer): add test for cache eviction at 2000 face limit
```

---

# 21. Model Version Compatibility Rules

These rules address the critical **Latent Space Incompatibility** problem:

1. Every `.onnx` model file must have a companion `MODEL_VERSION` constant defined in the loading module.
2. Every face embedding stored (in-memory cache or transmitted to backend server) must be tagged with the `model_version` string that produced it.
3. The Edge Engine must refuse to compare embeddings from different model versions.
4. When a new `.onnx` model is installed via OTA, the in-memory embedding cache **must be invalidated** and re-populated from freshly synced vectors (which backend server re-extracted using the new model).
5. The heartbeat payload must include `model_version` so backend server can detect version mismatches across a fleet of devices.
6. **A model update is always an atomic operation:** model file + new embedding batch. Never install one without the other.

---

# Summary: One-Page Quick Reference

| Topic | Rule |
|-------|------|
| Style | Black (88 chars), PEP 8, Ruff |
| Imports | Stdlib -> Third-party -> Local; no `import *` |
| Types | PEP 484 annotations on all public functions |
| Docstrings | PEP 257, Google-style Args/Returns |
| Errors | Never silent; always name the exception |
| Logging | `logging` module only; no `print()`; no secrets |
| Threading | Lock all shared state; name all threads |
| Flask | Thin routes; validate all JSON input |
| Dependencies | Exact pinning; stdlib first; ARM wheel check |
| Build | PyInstaller via `engine.spec`; SHA-256 checksum |
| OTA | HTTPS only; SHA-256 verify; backup + rollback |
| Testing | pytest; mock network; >=80% coverage on core/ |
| Memory | RSS < 150 MB; deque for rolling buffers |
| Models | Tag every vector with model_version |
| Python | Minimum 3.11 |

---

# 22. Context Managers

Use context managers (`with` blocks) for every resource that needs explicit cleanup.

**Good:**
```python
with open(env_path, "r") as f:
    lines = f.readlines()
```

**Bad:**
```python
f = open(env_path, "r")
lines = f.readlines()
f.close()  # Never executes if an exception is raised above
```

Rules:
- Always use `with` for file handles, sockets, locks, database connections.
- For custom cleanup logic, implement `__enter__` / `__exit__` or use `contextlib.contextmanager`.
- `threading.Lock()` must always be acquired via `with self._lock:`, never via `.acquire()` / `.release()` manually.

---

# 23. Dataclasses (PEP 557)

Use `@dataclass` instead of plain classes when the primary purpose is to hold structured data.

**Good:**
```python
from dataclasses import dataclass, field

@dataclass
class RecognitionResult:
    person_id: int | None
    confidence: float
    model_version: str
    is_live: bool = True
    timestamp: float = field(default_factory=lambda: time.time())
```

**Bad:**
```python
class RecognitionResult:
    def __init__(self, person_id, confidence, model_version, is_live=True):
        self.person_id = person_id
        self.confidence = confidence
        ...
```

Rules:
- Use `@dataclass(frozen=True)` for immutable value objects (e.g., config snapshots).
- Use `@dataclass(slots=True)` (Python 3.10+) for high-frequency objects to reduce memory overhead.
- Do NOT use dataclasses as a replacement for proper domain models with business logic.

---

# 24. Enums (PEP 435)

Use `enum.Enum` for any fixed set of named values. Never use bare string or integer constants for state.

**Good:**
```python
from enum import Enum, auto

class RecognitionStatus(str, Enum):
    RECOGNIZED = "RECOGNIZED"
    UNKNOWN = "UNKNOWN"
    SPOOF = "SPOOF"
    PROCESSING = "PROCESSING"
```

**Bad:**
```python
STATUS_RECOGNIZED = "RECOGNIZED"
STATUS_UNKNOWN = "UNKNOWN"
# scattered across multiple files
```

Rules:
- Use `str, Enum` mixin when the enum value must be JSON-serializable directly.
- Use `enum.auto()` for internal-only enums where the exact value doesn't matter.
- Never compare enum values with raw strings: use `status == RecognitionStatus.RECOGNIZED`, not `status == "RECOGNIZED"`.

---

# 25. pathlib Over os.path (PEP 428)

Use `pathlib.Path` for all filesystem path operations. Never use `os.path.join()`, `os.path.exists()`, or raw string concatenation for paths.

**Good:**
```python
from pathlib import Path

ROOT_DIR = Path(__file__).parent.parent
env_path = ROOT_DIR / ".env"
model_path = ROOT_DIR / "models" / "recognizer.onnx"

if env_path.exists():
    text = env_path.read_text()
```

**Bad:**
```python
import os
env_path = os.path.join(os.path.dirname(__file__), "..", ".env")
if os.path.exists(env_path):
    with open(env_path) as f:
        text = f.read()
```

Rules:
- Use `/` operator for path joining.
- Use `.read_text()`, `.read_bytes()`, `.write_text()`, `.write_bytes()` instead of `open()` for simple I/O.
- Use `.resolve()` to get the absolute, symlink-resolved path before storing or logging it.

---

# 26. f-strings (PEP 498)

Use f-strings for human-readable string formatting. The only exception is in `logging` calls (use `%s` there for lazy evaluation — see Rule 7).

**Good:**
```python
msg = f"[OTA] Downloading {update_type} from {url}"
label = f"{person.name} ({confidence:.1%})"
```

**Bad:**
```python
msg = "[OTA] Downloading " + update_type + " from " + url
msg = "[OTA] Downloading %s from %s" % (update_type, url)
msg = "[OTA] Downloading {} from {}".format(update_type, url)
```

Rules:
- Always prefer f-strings over `%` and `.format()` for non-logging strings.
- Never use f-strings for SQL queries or shell commands — that is an injection vulnerability.
- Use `=` specifier for debugging: `f"{value=}"` outputs `value=42`.

---

# 27. Comprehensions & Generators

Use list/dict/set comprehensions and generators instead of `map()`, `filter()`, and manual loops when the intent is clearer.

**Good:**
```python
# List comprehension
scores = [cosine_sim(embedding, template) for template in templates]

# Generator (no intermediate list built in memory)
best = max(cosine_sim(e, t) for t in templates)

# Dict comprehension
index = {person.id: person.embedding for person in persons}
```

**Bad:**
```python
scores = list(map(lambda t: cosine_sim(embedding, t), templates))
```

Rules:
- Prefer generators over list comprehensions when you only iterate once (saves memory).
- Never write a comprehension longer than one line. If it needs multiple conditions or nested loops that are non-obvious, use a plain `for` loop with a comment.
- Never use a comprehension for its side effects. Use a `for` loop if the goal is mutation, not a new collection.

---

# 28. subprocess Rules

Use `subprocess` only with a list of arguments, never with `shell=True` and string interpolation.

**Good:**
```python
import subprocess

result = subprocess.run(
    ["sha256sum", str(model_path)],
    capture_output=True,
    text=True,
    check=True,
    timeout=30,
)
```

**Bad:**
```python
os.system(f"sha256sum {model_path}")  # Shell injection risk
subprocess.run(f"sha256sum {model_path}", shell=True)  # Never
```

Rules:
- Always pass a `timeout` to `subprocess.run()` and `subprocess.Popen()` for commands that could hang.
- Use `check=True` to automatically raise `CalledProcessError` on non-zero exit codes.
- Use `capture_output=True` instead of `stdout=PIPE, stderr=PIPE`.
- For fire-and-forget background processes (like OTA update triggering), use `subprocess.Popen(..., start_new_session=True)` so the subprocess survives the parent's exit.

---

# 29. Environment & Configuration

All runtime configuration must come from environment variables loaded at startup. No hardcoded values in business logic.

**Good:**
```python
# config/settings.py
import os
from pathlib import Path
from dotenv import load_dotenv

ROOT_DIR = Path(__file__).parent.parent

def load_env() -> dict:
    """Load configuration from .env file."""
    load_dotenv(ROOT_DIR / ".env")
    return {
        "backend_url": os.environ.get("BACKEND_URL", ""),
        "device_token": os.environ.get("DEVICE_TOKEN", ""),
        "model_version": os.environ.get("MODEL_VERSION", "example_model_v1"),
    }
```

**Bad:**
```python
BACKEND_URL = "http://192.168.1.100"  # Hardcoded IP in source code
```

Rules:
- Load `.env` once at startup. Do not call `load_dotenv()` in multiple places.
- Provide sensible defaults only for non-sensitive config (e.g., port numbers). Never provide defaults for secrets.
- Fail fast at startup if a required environment variable is missing. Do not silently use an empty string.

---

# 30. HTTP Client Rules (requests)

When making outbound HTTP requests to the backend server server:

**Good:**
```python
import requests

response = requests.post(
    f"{backend_url}/api/v1/devices/heartbeat",
    json=payload,
    headers={"Authorization": f"Bearer {token}"},
    timeout=10,
    verify=True,  # Always verify SSL
)
response.raise_for_status()
```

**Bad:**
```python
requests.post(url, json=payload, verify=False)  # SSL disabled — never
requests.post(url, json=payload)  # No timeout — can hang forever
```

Rules:
- Always set `timeout` on every HTTP call. Default: 10 seconds for heartbeats, 30 seconds for sync operations.
- Always use `verify=True` (the default). Never disable SSL verification.
- Always call `.raise_for_status()` or check `response.status_code` explicitly.
- Use a `requests.Session` for repeated calls to the same host (connection pooling).
- Retry failed requests with exponential backoff. Never retry immediately in a tight loop.

---

# 31. Retry & Backoff Rules

Any operation that calls a network endpoint, file system, or external resource must have a retry strategy.

**Good:**
```python
import time

RETRY_DELAYS = [5, 30, 120, 600, 900]  # seconds

def send_with_retry(payload: dict) -> bool:
    """Send attendance batch with exponential backoff."""
    for delay in RETRY_DELAYS:
        try:
            _post_batch(payload)
            return True
        except requests.RequestException as e:
            logging.warning("Sync failed, retrying in %ds: %s", delay, e)
            time.sleep(delay)
    logging.error("Sync failed after all retries. Data stays in outbox.")
    return False
```

Rules:
- Use a fixed sequence of delays (5s, 30s, 2m, 10m, max 15m) — same as PRD FR-E10.
- Add jitter to retry delays when multiple devices are involved to avoid thundering herd.
- Operations must be idempotent: retrying them must never create duplicate records. Use UUID-based idempotency keys.
- Never retry indefinitely. After max retries, leave data in the outbox and log an error.

---

# 32. Idempotency Rules

Every write operation that can be retried must be idempotent.

Rules:
- Every attendance record must have a UUID `id` field generated on the Edge Device, not on the server. This ensures that retrying a failed sync never creates duplicates.
- When syncing the outbox, the server must accept repeated POSTs of the same UUID `id` without creating a duplicate record (INSERT OR IGNORE / upsert).
- When updating a `.env` file (e.g., during auto-pair), always read-modify-write atomically. Never append blindly.

---

# 33. Graceful Shutdown & Signal Handling

The Edge Engine must shut down cleanly when the OS sends `SIGTERM` (systemd stop) or `SIGINT` (Ctrl+C).

**Good:**
```python
import signal
import sys

_shutdown_event = threading.Event()

def _handle_signal(signum: int, frame: object) -> None:
    logging.info("Signal %d received, shutting down...", signum)
    _shutdown_event.set()

signal.signal(signal.SIGTERM, _handle_signal)
signal.signal(signal.SIGINT, _handle_signal)

# Main loop
while not _shutdown_event.is_set():
    process_frame()

# Cleanup
discovery_service.stop()
camera.release()
logging.info("Engine shut down cleanly.")
```

Rules:
- Always handle `SIGTERM` and `SIGINT`.
- Set a shared `threading.Event` to signal all threads to stop.
- Give threads a maximum of 5 seconds to finish before forcing exit.
- Release camera handles, close sockets, and flush outbox before exiting.

---

# 34. Camera & OpenCV Rules

Rules for safe, performant OpenCV usage in the camera loop:

- Always call `cap.release()` on shutdown (inside a `finally` block or via signal handler).
- Always check `ret` from `cap.read()` before using `frame`.
- Prefer MJPEG format for USB cameras: lower CPU usage, higher throughput than YUYV.
- Do not do heavy computation (ONNX inference) in the same thread as frame capture. Use a producer-consumer queue pattern.

**Good:**
```python
import cv2
import queue

frame_queue: queue.Queue = queue.Queue(maxsize=2)

def capture_loop(cap: cv2.VideoCapture) -> None:
    while not _shutdown_event.is_set():
        ret, frame = cap.read()
        if not ret:
            logging.warning("Frame capture failed, skipping.")
            continue
        try:
            frame_queue.put_nowait(frame)
        except queue.Full:
            pass  # Drop frame — inference is slower than capture
```

Rules:
- Use `queue.Queue(maxsize=2)` for the frame buffer. A queue of 1-2 frames is sufficient; larger queues just add latency.
- Drop frames silently (`put_nowait` with `except queue.Full: pass`) rather than blocking the capture loop.
- Resize frames before inference: detect on a smaller resolution, recognize on the full crop.

---

# 35. Serialization Rules

Rules for JSON and binary serialization:

**Good — explicit serialization:**
```python
import json

data = {
    "person_id": result.person_id,
    "confidence": round(float(result.confidence), 4),
    "model_version": result.model_version,
    "timestamp": result.timestamp,
}
payload = json.dumps(data)
```

**Bad:**
```python
import pickle
pickle.dumps(result)  # Arbitrary code execution on deserialization
```

Rules:
- Never use `pickle` for data transmitted over the network or stored in files that cross trust boundaries. Pickle is arbitrary code execution.
- Always use JSON for inter-process and network communication.
- When serializing NumPy arrays (e.g., embeddings), convert to Python `list` with `.tolist()` before JSON encoding.
- Round floating-point values to a sensible precision before serialization to avoid floating-point noise in checksums.

---

# 36. Queue & Producer-Consumer Rules

Use `queue.Queue` for safe communication between threads. Never use a shared list with manual locking as a queue.

**Good:**
```python
import queue
import threading

_outbox: queue.Queue = queue.Queue()

def enqueue_attendance(record: dict) -> None:
    _outbox.put(record)

def sync_worker() -> None:
    while not _shutdown_event.is_set():
        try:
            record = _outbox.get(timeout=15)
            _send_to_server(record)
            _outbox.task_done()
        except queue.Empty:
            continue
```

Rules:
- Use `queue.Queue` (thread-safe). Never use `collections.deque` as a queue between threads without a lock.
- Always call `.task_done()` after processing a `.get()` if anything calls `.join()`.
- Use `timeout` on `.get()` so the worker thread can check the shutdown event periodically.
- For the outbox persistence (surviving reboots), SQLite is the durable store. The in-memory `queue.Queue` is only for buffering between threads in the same process.

---

# 37. SQLite Local Database Rules

The Edge Device uses SQLite for local persistence (attendance outbox, face template cache).

Rules:
- Always open the SQLite connection in WAL mode for better concurrent read performance:
  ```python
  conn.execute("PRAGMA journal_mode=WAL")
  conn.execute("PRAGMA synchronous=NORMAL")
  ```
- Always use parameterized queries. Never format SQL with f-strings:
  ```python
  # Good
  conn.execute("INSERT INTO attendance VALUES (?, ?, ?)", (id, person_id, timestamp))
  # Bad — SQL injection
  conn.execute(f"INSERT INTO attendance VALUES ('{id}', '{person_id}', ...)")
  ```
- Always wrap multiple related writes in a `BEGIN TRANSACTION` / `COMMIT` block for atomicity.
- Never store face photos in SQLite. Store only the 512-d embedding vector as a BLOB.
- Keep the SQLite file on the MicroSD with `noatime` mount to reduce unnecessary writes.

---

# 38. Health Check & Heartbeat Rules

The Edge Engine must emit a heartbeat every 60 seconds to the backend server server.

The heartbeat payload must include:

```python
{
    "device_id": str,          # From .env
    "model_version": str,      # Currently loaded ONNX model version
    "engine_version": str,     # Binary version from build
    "uptime_seconds": float,
    "cpu_temp_celsius": float, # From /sys/class/thermal/thermal_zone0/temp
    "ram_used_mb": float,      # From resource.getrusage
    "fps": float,              # Current capture FPS
    "outbox_count": int,       # Unsynced attendance records
    "status": str,             # "running" | "degraded" | "error"
}
```

Rules:
- If the heartbeat fails to send, log a warning but do not crash. Heartbeat failure is not a fatal error.
- If the CPU temperature exceeds 75°C, set `status = "degraded"` and reduce inference frequency.
- If the CPU temperature exceeds 80°C, set `status = "error"` and trigger a buzzer alert via the Arduino serial bridge.

---

# 39. API Versioning on the Edge

The Edge Engine exposes a small REST API. Version all routes from the start.

**Good:**
```python
@app.route("/api/v1/status")
@app.route("/api/v1/video_feed")
@app.route("/api/v1/history")
@app.route("/api/v1/auto-pair", methods=["POST"])
@app.route("/api/v1/auto-update", methods=["POST"])
```

Rules:
- Prefix all routes with `/api/v1/`.
- When a breaking change is needed, add `/api/v2/` routes without removing v1 routes until all clients have migrated.
- Never change the response schema of an existing versioned endpoint without bumping the version.

---

# 40. HTTP Status Code Rules

Return correct HTTP status codes from Flask routes:

| Situation | Code |
|-----------|------|
| Success (GET, read) | 200 OK |
| Success (POST, created) | 201 Created |
| Success (action triggered, no body) | 204 No Content |
| Bad input / validation failed | 400 Bad Request |
| Missing or invalid auth token | 401 Unauthorized |
| Auth valid but insufficient permission | 403 Forbidden |
| Resource not found | 404 Not Found |
| Server error | 500 Internal Server Error |

Never return 200 with `{"success": false}` — that is an antipattern. Return the correct HTTP error code.

---

# 41. Backward Compatibility Rules

- Never remove or rename a key from the heartbeat JSON payload without a deprecation period.
- Never rename a Flask route without keeping the old route as an alias.
- Never change the format of `.env` config keys without updating `config/settings.py` to support both old and new names.
- When a new field is added to the heartbeat, make it optional with a default so older backend server versions don't break.

---

# 42. Boolean Naming

Boolean variables and function names must read as true/false assertions.

**Good:**
```python
is_live: bool
has_face: bool
should_retry: bool

def is_valid_payload(data: dict) -> bool: ...
def has_pending_records() -> bool: ...
```

**Bad:**
```python
live: bool         # Ambiguous: is this a status or a flag?
face_check: bool
retry: bool
```

---

# 43. Constants & Magic Numbers

Every magic number must be a named constant. Never scatter raw numbers throughout the code.

**Good:**
```python
# config/settings.py
UDP_PORT: int = 55555
BUFFER_SIZE: int = 1024
MAX_FACE_CACHE: int = 2000
HEARTBEAT_INTERVAL_SECONDS: int = 60
FRAME_QUEUE_MAXSIZE: int = 2
LIVENESS_VOTE_THRESHOLD: int = 3
LIVENESS_VOTE_WINDOW: int = 5
RECOGNITION_COOLDOWN_SECONDS: int = 60
COSINE_SIMILARITY_THRESHOLD: float = 0.363
```

**Bad:**
```python
if score > 0.363:   # What is 0.363?
    ...
time.sleep(60)      # Why 60?
```

---

# 44. Comments & Code Documentation

Rules for inline comments (PEP 8):

- Write comments to explain **why**, not **what**. The code shows what; the comment explains the non-obvious reasoning.
- Comments must be complete sentences with a capital letter and a period.
- Inline comments must be at least two spaces after the code.

**Good:**
```python
# Drop frame silently — the camera captures faster than the ONNX model infers.
# A maxsize=2 queue gives a 1-frame buffer without accumulating stale frames.
frame_queue: queue.Queue = queue.Queue(maxsize=2)
```

**Bad:**
```python
# create queue
frame_queue: queue.Queue = queue.Queue(maxsize=2)
```

---

# 45. TODO Rules

If a known limitation or deferred task must be noted in code, use a structured `# TODO:` comment:

```python
# TODO(model-ota): invalidate _persons_cache here once model_version mismatch is detected.
# See AGENTS2.md Rule 21 — Model OTA must be atomic with vector refresh.
```

Rules:
- Every `TODO` must reference the person or the rule that owns it.
- Every `TODO` must be tracked in a separate ledger (e.g., run `ponytail-debt` skill) — not left to rot.
- Never commit a `TODO` that blocks a release-critical feature.

---

# 46. Dead Code Rules

- Never leave commented-out code in the codebase. Use git history to recover deleted code.
- Never leave `print()` debug statements in committed code. Use the `logging` module.
- Never leave unused imports. Ruff (`F401`) will catch these.
- Never leave unreachable code after `return`, `raise`, or `sys.exit()`.

---

# 47. Debugging Code Rules

- Remove all `import pdb; pdb.set_trace()` and `breakpoint()` calls before committing.
- Remove all `import icecream; ic()` debug calls before committing.
- Ruff rule `T20` (`print` statements) and `T10` (debugger calls) will catch these automatically if enabled.

---

# 48. External API Rules

When calling the backend server backend API from the Edge Engine:

- Always authenticate with the Bearer token from `.env`.
- Always validate the HTTP response status before trusting the response body.
- Never block the main thread waiting for an API response. All API calls must run in a background thread or worker.
- Handle `requests.Timeout`, `requests.ConnectionError`, and `requests.HTTPError` explicitly — never let them propagate as unhandled exceptions.
- Log every outbound API call at `DEBUG` level with the URL and method (never the full payload if it contains tokens).

---

# 49. Configuration Validation (Fail Fast)

Validate all required configuration values at startup, before the camera loop starts.

**Good:**
```python
def validate_config(cfg: dict) -> None:
    """Raise ValueError early if critical config is missing."""
    required = ["backend_url", "device_token", "model_version"]
    missing = [k for k in required if not cfg.get(k)]
    if missing:
        raise ValueError(f"Missing required config keys: {missing}")
```

Rules:
- Call `validate_config()` in `engine.py` before initializing any service.
- Log a clear human-readable error message for every missing key.
- Exit with code `1` (not `0`) on config validation failure so systemd marks the unit as failed.

---

# 50. Module Entry Points

Every Python package directory must have an `__init__.py`. It should be empty or contain only the public API re-exports:

**Good (`core/models/face_recognizer/__init__.py`):**
```python
from .recognizer import FaceRecognizer

__all__ = ["FaceRecognizer"]
```

The main entry point `engine.py` must be the **only** file with a `if __name__ == "__main__":` guard:

```python
def main() -> None:
    """Engine entry point."""
    ...

if __name__ == "__main__":
    main()
```

---

# 51. Protocol & Interface Design

When two modules need to cooperate without tight coupling, use `typing.Protocol` instead of Abstract Base Classes.

**Good:**
```python
from typing import Protocol
import numpy as np

class Recognizer(Protocol):
    def get_embedding(self, frame: np.ndarray) -> list[float] | None: ...
    def get_model_version(self) -> str: ...
```

Rules:
- Use `Protocol` for structural subtyping (duck typing with type-checker support).
- Never inherit from `Protocol` in the concrete implementation class — that is not required and adds coupling.
- Only define Protocols when there are **two or more** concrete implementations that need to be swapped.

---

# 52. Dependency Injection

Pass dependencies explicitly as constructor parameters, not as module-level globals.

**Good:**
```python
class StreamService:
    def __init__(self, recognizer: FaceRecognizer, config: dict) -> None:
        self._recognizer = recognizer
        self._config = config
```

**Bad:**
```python
from core.models.face_recognizer.recognizer import _global_recognizer  # Hidden dependency
```

Rules:
- Global mutable state is only acceptable for module-level constants and the Flask `app` object.
- Never import a singleton from a sibling module. Pass it as a parameter.
- This makes unit testing trivial: pass a `MagicMock()` instead of the real object.

---

# 53. Testing — Fixtures & Mocking

Rules for clean pytest tests:

**Good:**
```python
import pytest
from unittest.mock import MagicMock, patch

@pytest.fixture
def mock_onnx_session():
    session = MagicMock()
    session.run.return_value = [[0.1] * 512]
    return session

@pytest.fixture
def recognizer(mock_onnx_session):
    r = FaceRecognizer.__new__(FaceRecognizer)
    r._session = mock_onnx_session
    r._model_version = "test_v1"
    return r

def test_get_embedding_returns_normalized_vector(recognizer):
    frame = np.zeros((112, 112, 3), dtype=np.uint8)
    result = recognizer.get_embedding(frame)
    assert result is not None
    assert len(result) == 512
```

Rules:
- Use `@pytest.fixture` for shared test setup. Never repeat setup code across test functions.
- Use `unittest.mock.patch` as a context manager or decorator, not a manual `patcher.start()` / `patcher.stop()` pair.
- Test filenames: `test_<module>.py`. Test function names: `test_<scenario>_<expected_outcome>`.
- Never assert `True` blindly. Assert the specific value: `assert result == expected`, not `assert result`.

---

# 54. Testing Database Logic

When testing SQLite-dependent code:

- Use an **in-memory SQLite database** in tests: `sqlite3.connect(":memory:")`.
- Create the schema in a `pytest.fixture` with `scope="function"` so each test starts with a clean slate.
- Never use the real production `.db` file in tests.

---

# 55. Static Analysis Configuration

Centralize all tool configuration in `pyproject.toml` at the root of `clients/edge-engine/`:

```toml
[tool.black]
line-length = 88
target-version = ["py311"]

[tool.ruff]
line-length = 88
target-version = "py311"
select = ["E", "F", "W", "I", "N", "UP", "S", "B", "T20", "T10"]
ignore = ["S101"]

[tool.mypy]
python_version = "3.11"
strict = true
ignore_missing_imports = true

[tool.pytest.ini_options]
testpaths = ["tests"]
addopts = "--cov=. --cov-report=term-missing -q"
```

---

# 56. Agent Behavior — Before Coding

Before modifying any Python file in this project, the AI agent must:

1. Read and understand the existing file fully before touching it.
2. Identify all callers of any function being modified.
3. Confirm that the change does not break the heartbeat payload contract (Rule 38).
4. Confirm that the change does not break the Flask API contract (Rule 39).
5. Confirm that no new third-party dependency is introduced without passing the checklist in Rule 11.4.

---

# 57. Agent Behavior — File Creation

When creating a new Python file:

1. Start with the module docstring.
2. Add all imports in the correct order (stdlib → third-party → local).
3. Add type annotations to all public functions immediately.
4. Do not leave `pass` placeholders in production code — implement the function or raise `NotImplementedError` with a comment.

---

# 58. Agent Behavior — Refactoring

When refactoring existing Python code:

1. Never change behavior and style in the same commit.
2. Run `black` and `ruff --fix` first to fix style issues without logic changes.
3. Then commit the style-only changes.
4. Then make the logic refactor in a separate commit.
5. Re-run all tests after each commit.

---

# 59. Agent Behavior — Adding a New Flask Route

Checklist for adding any new endpoint to `stream_service.py`:

- [ ] Route is prefixed with `/api/v1/`.
- [ ] Route handler is thin (< 20 lines).
- [ ] All business logic is in a separate function or class.
- [ ] Request JSON is validated before use.
- [ ] Correct HTTP status codes are returned.
- [ ] Any blocking operation (file I/O, subprocess) is backgrounded with `threading.Thread`.
- [ ] New route is documented in `PRD.md` API contract section.

---

# 60. Agent Behavior — Security Review

Before committing any code that handles external input (HTTP requests, UDP packets, file system paths):

- [ ] Input is validated for type and value range.
- [ ] No secrets are logged.
- [ ] No `shell=True` in subprocess calls.
- [ ] No `verify=False` in HTTPS calls.
- [ ] No path traversal risk (user input not directly used to construct file paths).
- [ ] OTA `download_url` is verified to start with `https://`.

---

# 61. Agent Behavior — ONNX Model Changes

When modifying code that loads or uses an ONNX model:

- [ ] `model_version` constant is updated.
- [ ] Heartbeat payload includes the new `model_version`.
- [ ] In-memory embedding cache is invalidated when model version changes.
- [ ] PRD Section 2 "Model version compatibility" rule is preserved.
- [ ] Unit tests mock the ONNX session — no real model file loaded in tests.

---

# 62. Agent Behavior — OTA Changes

When modifying `updater.sh` or the `/api/v1/auto-update` Flask route:

- [ ] SHA-256 checksum verification is preserved.
- [ ] Backup step is preserved for both binary and model update types.
- [ ] Rollback step (health check after restart) is preserved.
- [ ] The `update_type` parameter is validated: only `"binary"` or `"model"` accepted.
- [ ] The `download_url` must start with `https://`.

---

# 63. Completion Report

After completing any task, the agent must report:

1. **Files modified:** List every file changed with a one-line description.
2. **Tests run:** List the test commands run and their results.
3. **Lint results:** Confirm `ruff check` and `mypy` pass with zero errors.
4. **Breaking changes:** Explicitly state `NONE` or describe what was broken.
5. **Next step:** Exactly ONE concrete action the developer must take next.

---

# 64. Definition of Done (Python)

A Python change is "done" when:

- [ ] All existing tests pass: `pytest`
- [ ] No type errors: `mypy clients/edge-engine/ --strict`
- [ ] No lint errors: `ruff check clients/edge-engine/`
- [ ] Code is formatted: `black --check clients/edge-engine/`
- [ ] The heartbeat payload contract is unchanged (or version-bumped with backward compat).
- [ ] The Flask API contract is unchanged (or new versioned route added).
- [ ] No new `import *` or bare `except:` anywhere in the diff.
- [ ] No hardcoded secrets, IPs, or magic numbers in the diff.

---

# 65. Dangerous Commands

Never run these commands without explicit user confirmation:

```bash
# Deletes virtualenv — all packages lost
rm -rf venv/

# Deletes compiled binary
rm -rf dist/ build/

# Clears all face embeddings from local DB
sqlite3 /var/lib/your-service/local.db "DELETE FROM face_templates;"

# Hard resets source to remote (loses local changes)
git reset --hard origin/main

# Uninstalls everything from system Python
pip uninstall -r requirements.txt
```

---

# 66. Production Device Safety

When working with a live, deployed edge device:

- Never directly edit source files on the device. Always deploy via OTA.
- Never run `pip install` on the device outside of the virtualenv.
- Never run `pip install --upgrade` without testing on a dev device first, as it may break pinned dependencies.
- Never manually edit the SQLite database file on a running device without stopping the service first.
- Before stopping the service, confirm no students are actively using the terminal.

---

# 67. ARM Build Safety

When building the PyInstaller binary for the edge device:

- The build must be done **on an ARM64 machine** or in an ARM64 Docker container. x86 binaries do not run on ARM.
- Verify the target architecture before deploying: `file dist/your-app-engine` must show `ELF 64-bit LSB executable, ARM aarch64`.
- After building, run `./dist/your-app-engine --version` on the build machine to verify the binary starts without import errors.
- Calculate and record the SHA-256 checksum immediately after the build: `sha256sum dist/your-app-engine`.

---

# 68. Git Rules (Python-Specific)

- Never commit `.env` files. Use `.env.example` with placeholder values instead.
- Never commit `*.onnx` files. Use a release artifact store or Git LFS.
- Never commit `venv/`, `build/`, `dist/`, `__pycache__/`, `*.pyc`.
- Commit messages must follow Conventional Commits format (see Rule 20).
- Separate a refactoring commit from a feature commit. Never mix the two.

---

# 69. Pull Request Rules (Python)

Every PR that modifies Python code must:

1. Include a description of what changed and why.
2. Include evidence that `pytest`, `ruff`, `mypy`, and `black --check` all pass.
3. Call out any changes to the heartbeat payload, Flask API contract, or model version.
4. Not include unrelated changes (no drive-by formatting of unrelated files).

---

# 70. Golden Rules

If you remember nothing else from this document, remember these:

1. **Read the code before you write.** Never modify a file you haven't fully read.
2. **Explicit is better than implicit.** No magic, no hidden globals, no surprise imports.
3. **Errors must never pass silently.** No bare `except:`, no `pass`, no `verify=False`.
4. **Every vector has a version.** A model update without re-enrollment is a bug.
5. **OTA must be atomic and safe.** Backup before replace; rollback if the service dies.
6. **The camera loop must never block.** Inference in background threads only.
7. **Never trust the network.** Validate every byte from every HTTP request and UDP packet.
8. **Pin your dependencies.** Unpinned = non-reproducible = broken deployment on the device.
9. **The device has 1 GB of RAM.** Every import costs memory. Every loop costs CPU time.
10. **This is a school gate.** A crash means students cannot get in. Reliability is not optional.

---

# 71. Walrus Operator (PEP 572)

The walrus operator `:=` (assignment expression) is available from Python 3.8+. Use it to eliminate repeated computation in conditions.

**Good — avoid calling the same function twice:**
```python
# Without walrus: cap.read() called in condition and then re-used
while (frame_data := cap.read()) and frame_data[0]:
    ret, frame = frame_data
    process(frame)

# In comprehensions to filter and transform in one pass
results = [cleaned for raw in data if (cleaned := sanitize(raw)) is not None]
```

**Bad — using walrus where a plain assignment is clearer:**
```python
# This is harder to read than a simple assignment above a while loop
if (n := len(items)) > 10:
    print(f"Too many items: {n}")
```

Rules:
- Use walrus only when it genuinely removes a repeated function call or simplifies a loop condition.
- Never use walrus to be clever. If a plain assignment + if statement is more readable, use that.
- PEP 572 explicitly discourages walrus in simple `if` conditions where a variable assignment on the preceding line is clearer.

---

# 72. Structural Pattern Matching (PEP 634, Python 3.10+)

Use `match` / `case` for exhaustive dispatch on structured data. It is not just a `switch` statement — it does structural decomposition.

**Good:**
```python
match command:
    case {"action": "update", "type": "binary", "url": url, "sha256": checksum}:
        _handle_binary_update(url, checksum)
    case {"action": "update", "type": "model", "url": url, "sha256": checksum}:
        _handle_model_update(url, checksum)
    case {"action": "pair", "device_id": device_id}:
        _handle_pair(device_id)
    case _:
        logging.warning("Unknown command: %s", command)
```

**Bad — using match as a simple string switch:**
```python
match status:
    case "ok": ...
    case "error": ...
```
(A dict lookup or `if/elif` chain is more Pythonic for simple string dispatch.)

Rules:
- Use `match` for structured data (dicts, dataclasses, tuples) where multiple fields are matched simultaneously.
- Always include a wildcard `case _:` to handle unexpected cases.
- Do not use `match` just to replace `if/elif` chains on simple scalar values.

---

# 73. Exception Groups (PEP 654, Python 3.11+)

Python 3.11 introduced `ExceptionGroup` and `except*` to handle multiple concurrent exceptions (primarily from `asyncio.TaskGroup`).

**Good:**
```python
try:
    async with asyncio.TaskGroup() as tg:
        tg.create_task(inference_task())
        tg.create_task(sync_task())
except* InferenceError as eg:
    for exc in eg.exceptions:
        logging.error("Inference failed: %s", exc)
except* SyncError as eg:
    for exc in eg.exceptions:
        logging.warning("Sync failed: %s", exc)
```

Rules:
- `ExceptionGroup` is only needed for concurrent tasks (e.g., `asyncio.TaskGroup`). Never use it for sequential code.
- In single-threaded Flask / threading code, use normal `except` chains — not `ExceptionGroup`.
- This project targets Python 3.11+, so this syntax is always available, but should rarely be needed given the threading (not asyncio) concurrency model.

---

# 74. TypedDict (PEP 589)

Use `TypedDict` to give a precise type to dictionaries with a fixed set of string keys. This is far more informative than `dict[str, Any]`.

**Good:**
```python
from typing import TypedDict

class HeartbeatPayload(TypedDict):
    device_id: str
    model_version: str
    engine_version: str
    uptime_seconds: float
    cpu_temp_celsius: float
    ram_used_mb: float
    fps: float
    outbox_count: int
    status: str

def build_heartbeat(cfg: dict) -> HeartbeatPayload:
    ...
```

**Bad:**
```python
def build_heartbeat(cfg: dict) -> dict:  # What keys? What types?
    ...
```

Rules:
- Use `TypedDict` for structured dicts that cross function boundaries (e.g., JSON payloads, config dicts).
- Use `total=False` for `TypedDict` when some keys are optional.
- `TypedDict` is a type-checking tool only. It does not validate at runtime. For runtime validation, use a `dataclass` or explicit checks.

---

# 75. NamedTuple (PEP 3144)

Use `typing.NamedTuple` for lightweight, immutable record types where positional access is natural.

**Good:**
```python
from typing import NamedTuple

class DetectionBox(NamedTuple):
    x: int
    y: int
    width: int
    height: int
    confidence: float

box = DetectionBox(x=10, y=20, width=100, height=80, confidence=0.92)
x, y, w, h, _ = box  # Unpacking still works
```

Rules:
- Prefer `NamedTuple` over a plain `tuple` whenever field names aid readability.
- Prefer `@dataclass` over `NamedTuple` when you need mutability, inheritance, or complex default values.
- `NamedTuple` fields are immutable — you cannot assign to them after creation. This is a feature, not a bug.

---

# 76. Literal Types (PEP 586)

Use `Literal` to narrow the type of a string or integer to a specific set of allowed values.

**Good:**
```python
from typing import Literal

UpdateType = Literal["binary", "model"]

def handle_update(update_type: UpdateType, url: str, checksum: str) -> None:
    ...

DeviceStatus = Literal["running", "degraded", "error"]
```

Rules:
- Use `Literal` instead of raw `str` when a parameter only accepts specific values.
- This allows mypy to catch calls like `handle_update("firmware", ...)` at static analysis time.
- When the set of values grows beyond 4–5 entries, switch to an `Enum`.

---

# 77. Final & ClassVar (PEP 591)

Use `Final` to declare constants that must never be reassigned, and `ClassVar` for class-level attributes.

**Good:**
```python
from typing import Final, ClassVar

UDP_PORT: Final = 55555
BUFFER_SIZE: Final[int] = 1024

class FaceRecognizer:
    MODEL_INPUT_SIZE: ClassVar[int] = 112  # Class-level constant

    def __init__(self, model_path: str) -> None:
        self._session: ort.InferenceSession = ...
```

Rules:
- Module-level constants that should never be reassigned must be annotated with `Final`.
- `ClassVar` prevents mypy from treating a class attribute as an instance attribute.
- Never mutate a `Final` variable. mypy will catch this, but at runtime Python does not enforce it.

---

# 78. functools Module Rules

The `functools` standard library module provides powerful tools. Use them instead of writing custom implementations.

**Good:**
```python
import functools

# Cache expensive computations (e.g., loading config from disk)
@functools.lru_cache(maxsize=1)
def load_config() -> dict:
    return _read_env_file()

# Cache with no size limit — for model session options that never change
@functools.cache
def get_session_options() -> ort.SessionOptions:
    opts = ort.SessionOptions()
    opts.intra_op_num_threads = 2
    return opts

# Partial application — pre-fill a function's arguments
from functools import partial
cosine_sim_for_embedding = partial(cosine_similarity, embedding_a=query_vec)
```

Rules:
- Use `@functools.lru_cache` for memoizing deterministic, pure functions.
- Use `@functools.cached_property` for expensive instance properties that are computed once.
- Use `functools.reduce()` sparingly — a `for` loop is usually more readable.
- Never cache a function that has side effects (network calls, file I/O).

---

# 79. itertools Module Rules

Use `itertools` for memory-efficient iteration over large sequences. Never build an intermediate list when a generator suffices.

**Good:**
```python
import itertools

# Chunk a list without building intermediate lists
def chunked(iterable, size):
    it = iter(iterable)
    return iter(lambda: list(itertools.islice(it, size)), [])

# Flatten a list of lists
flat = list(itertools.chain.from_iterable(nested_lists))

# Rotate / cycle for round-robin load distribution
camera_ids = itertools.cycle([0, 1, 2])
```

Rules:
- Use `itertools.islice()` to read the first N records from a generator without loading everything.
- Use `itertools.chain()` to concatenate iterators without building an intermediate list.
- Use `itertools.groupby()` only on **sorted** data — it only groups consecutive identical keys.

---

# 80. collections Module Rules

Use `collections` standard library types instead of reinventing them.

| Use Case | Use This |
|----------|----------|
| Rolling buffer, deque | `collections.deque(maxlen=N)` |
| Counting occurrences | `collections.Counter` |
| Dict with default values | `collections.defaultdict` |
| Ordered dict (3.7+ dicts are ordered by default) | Built-in `dict` |
| Immutable dict-like record | `typing.NamedTuple` |

**Good:**
```python
from collections import Counter, defaultdict

# Count recognition outcomes over the last 100 frames
vote_counter: Counter[str] = Counter()
vote_counter.update(["RECOGNIZED", "RECOGNIZED", "UNKNOWN"])
winner = vote_counter.most_common(1)[0][0]

# Group attendance records by date without KeyError
by_date: defaultdict[str, list] = defaultdict(list)
by_date["2026-10-01"].append(record)
```

---

# 81. `__repr__` and `__str__`

Every non-trivial class must implement `__repr__`. Only implement `__str__` when the human-friendly output differs meaningfully from the developer-debug output.

**Good:**
```python
class FaceEmbedding:
    def __repr__(self) -> str:
        return (
            f"FaceEmbedding("
            f"person_id={self.person_id!r}, "
            f"model_version={self.model_version!r}, "
            f"dim={len(self.vector)})"
        )
```

Rules:
- `__repr__` must produce output that, when `eval()`-ed, ideally recreates the object — or at minimum, is useful for debugging.
- `@dataclass` generates `__repr__` automatically. Prefer dataclass for data-holding classes.
- Never print a face embedding vector in `__repr__` — it is 512 floats and will flood the log.

---

# 82. `__eq__` and `__hash__`

If you override `__eq__`, you **must** also override `__hash__` — otherwise the object becomes unhashable and cannot be put in sets or used as dict keys.

**Good:**
```python
@dataclass(frozen=True)  # frozen=True generates both __eq__ and __hash__
class ModelVersion:
    name: str
    tag: str
```

Rules:
- `@dataclass(frozen=True)` is the safest way to get a correct, consistent `__eq__` + `__hash__`.
- For mutable classes, if you define `__eq__`, set `__hash__ = None` explicitly to make the unhashability visible rather than inherited from a parent.
- Never define custom `__eq__` and forget `__hash__`.

---

# 83. `__slots__` for Memory Optimization (PEP 3107)

Use `__slots__` on classes that are instantiated very frequently (e.g., detection results per frame) to reduce per-instance memory overhead.

**Good:**
```python
class DetectionResult:
    __slots__ = ("box", "confidence", "is_live")

    def __init__(self, box: tuple, confidence: float, is_live: bool) -> None:
        self.box = box
        self.confidence = confidence
        self.is_live = is_live
```

Why: Without `__slots__`, every instance has a `__dict__` (typically 200–300 bytes). With `__slots__`, there is no `__dict__`, saving ~50–100 bytes per object. At 30 FPS processing, this matters.

Rules:
- Use `__slots__` on classes that are created at video frame rate (> 10 instances/second).
- `@dataclass(slots=True)` (Python 3.10+) adds `__slots__` automatically — prefer this over manual `__slots__`.
- `__slots__` cannot be used with multiple inheritance in most cases. If you need inheritance, use `@dataclass(frozen=True)` instead.

---

# 84. Properties vs Direct Attribute Access

Use `@property` to add computation or validation to attribute access — but only when the computation is cheap.

**Good:**
```python
class FaceRecognizer:
    @property
    def model_version(self) -> str:
        """Return the currently loaded model version string."""
        return self._model_version

    @property
    def is_ready(self) -> bool:
        """Return True if the ONNX session is loaded and warmed up."""
        return self._session is not None and self._warmed_up
```

**Bad:**
```python
@property
def embedding_for_all_persons(self) -> list:
    return [p.embedding for p in self._db.query_all()]  # Expensive DB call hidden in property
```

Rules:
- Properties must be cheap (O(1), no I/O). If it requires I/O, make it a method with a clear name: `def fetch_embeddings() -> list`.
- Never hide network calls, file reads, or DB queries behind a property.
- Use `@functools.cached_property` for expensive but idempotent computations that are only needed once.

---

# 85. Class Methods vs Static Methods

Understand the difference and use the correct one:

| Decorator | Receives | Use When |
|-----------|----------|----------|
| `@classmethod` | `cls` (the class) | Factory methods, alternative constructors |
| `@staticmethod` | Nothing extra | Utility functions logically grouped inside the class |

**Good:**
```python
class FaceRecognizer:
    @classmethod
    def from_config(cls, cfg: dict) -> "FaceRecognizer":
        """Alternative constructor from a config dict."""
        return cls(model_path=cfg["model_path"])

    @staticmethod
    def l2_normalize(vector: list[float]) -> list[float]:
        """Normalize a vector to unit length."""
        norm = sum(x ** 2 for x in vector) ** 0.5
        return [x / norm for x in vector]
```

Rules:
- Use `@classmethod` for factory / alternative constructors.
- Use `@staticmethod` for pure utility functions that are closely related to the class but don't need `self` or `cls`.
- If a `@staticmethod` is used from outside the class, it should be a module-level function instead.

---

# 86. Abstract Base Classes (PEP 3119)

Use `abc.ABC` and `@abc.abstractmethod` only when you have **multiple concrete implementations** and need to enforce an interface.

**Good:**
```python
from abc import ABC, abstractmethod
import numpy as np

class BaseDetector(ABC):
    @abstractmethod
    def detect(self, frame: np.ndarray) -> list[tuple]:
        """Return list of (x, y, w, h, confidence) detection boxes."""

class SCRFDDetector(BaseDetector):
    def detect(self, frame: np.ndarray) -> list[tuple]:
        ...

class ExampleDetectorDetector(BaseDetector):
    def detect(self, frame: np.ndarray) -> list[tuple]:
        ...
```

Rules:
- Use `typing.Protocol` (Rule 51) when you want structural subtyping without inheritance.
- Use `abc.ABC` when you want to enforce that subclasses implement specific methods and you control the class hierarchy.
- Never create an ABC with only one concrete subclass. That is speculative abstraction (YAGNI).

---

# 87. `TYPE_CHECKING` Guard (PEP 484)

Use `TYPE_CHECKING` to import types only during static analysis, avoiding circular imports and heavy import overhead at runtime.

**Good:**
```python
from __future__ import annotations
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    import onnxruntime as ort
    from core.models.face_recognizer import FaceRecognizer

def process(recognizer: FaceRecognizer, session: ort.InferenceSession) -> None:
    ...
```

Rules:
- Wrap imports that are only needed for type annotations in `if TYPE_CHECKING:`.
- Always pair this with `from __future__ import annotations` at the top of the file so that the annotation strings are not evaluated at runtime.
- This is particularly important for avoiding circular imports between modules.

---

# 88. `@overload` Decorator (PEP 484)

Use `@overload` when a function has genuinely different return types depending on the argument types.

**Good:**
```python
from typing import overload

@overload
def get_embedding(frame: np.ndarray, as_list: Literal[True]) -> list[float]: ...
@overload
def get_embedding(frame: np.ndarray, as_list: Literal[False]) -> np.ndarray: ...

def get_embedding(frame: np.ndarray, as_list: bool = False) -> list[float] | np.ndarray:
    result = _run_inference(frame)
    return result.tolist() if as_list else result
```

Rules:
- Use `@overload` only for genuinely polymorphic return types. Do not use it just to document multiple parameter combinations.
- The actual implementation body must NOT be decorated with `@overload`.

---

# 89. Async/Await Rules — When NOT to Use It (PEP 492)

`async`/`await` is a concurrency model for I/O-bound work where many coroutines share one thread via cooperative multitasking. It is **not** a performance panacea.

**Do NOT use async/await in this project because:**
- ONNX Runtime's `session.run()` is a blocking C extension call. It does not yield to the event loop. Wrapping it in `async` achieves nothing and makes the code harder to test.
- The camera capture loop (`cap.read()`) is also synchronous and blocking.
- Flask (WSGI) is synchronous by design. Mixing `asyncio` with Flask requires either Quart or a thread executor, adding significant complexity.
- `threading` + `queue.Queue` is the correct concurrency model for this hardware and use case.

**Use async/await ONLY IF:**
- You migrate from Flask to an ASGI framework (Quart, FastAPI) after measuring a concrete I/O bottleneck.
- You use `asyncio.TaskGroup` to run multiple independent I/O tasks (e.g., multiple HTTP calls) concurrently.

---

# 90. Iterable Unpacking (PEP 3132, PEP 448)

Use extended unpacking and splat operators to write clean, expressive code.

**Good:**
```python
# Extended iterable unpacking (PEP 3132)
first, *rest = [1, 2, 3, 4, 5]       # first=1, rest=[2,3,4,5]
*head, last = [1, 2, 3, 4, 5]        # head=[1,2,3,4], last=5
first, *middle, last = range(5)       # first=0, middle=[1,2,3], last=4

# Merging dicts (PEP 448, Python 3.5+)
defaults = {"timeout": 10, "verify": True}
overrides = {"timeout": 30}
merged = {**defaults, **overrides}    # {"timeout": 30, "verify": True}

# Spreading into function calls
args = [url, payload]
kwargs = {"timeout": 10}
requests.post(*args, **kwargs)
```

Rules:
- Prefer `{**defaults, **overrides}` over `dict.update()` when creating a new merged dict.
- Never use `*rest` when you actually need a fixed number of elements — use explicit index unpacking instead.

---

# 91. Secrets & Cryptography Rules

For all cryptographic operations, use Python's standard library. Never implement crypto primitives yourself.

**Good:**
```python
import hashlib
import hmac
import secrets

# Verify SHA-256 checksum of a downloaded file
def verify_checksum(file_path: Path, expected_hex: str) -> bool:
    """Return True if the file matches the expected SHA-256 hex digest."""
    digest = hashlib.sha256(file_path.read_bytes()).hexdigest()
    return hmac.compare_digest(digest, expected_hex)

# Generate a cryptographically secure random token
device_token = secrets.token_hex(32)

# Constant-time comparison to prevent timing attacks
if not hmac.compare_digest(received_sig, expected_sig):
    return jsonify({"error": "Invalid signature"}), 401
```

Rules:
- **Always use `hmac.compare_digest()`** for comparing secrets/tokens/checksums. Never use `==` — it is vulnerable to timing attacks.
- Use `hashlib.sha256()` for checksums, not `md5` or `sha1`.
- Use `secrets.token_hex()` or `secrets.token_urlsafe()` for generating tokens. Never use `random` for security purposes.
- Never implement your own signature or hashing algorithm.

---

# 92. HMAC Signature Verification for OTA

All OTA update payloads from the backend server server must be verified with an HMAC signature.

**Good:**
```python
import hmac
import hashlib

SIGNING_SECRET = os.environ.get("OTA_SIGNING_SECRET", "")

def verify_ota_signature(payload_bytes: bytes, received_sig: str) -> bool:
    """Verify the HMAC-SHA256 signature of an OTA payload."""
    if not SIGNING_SECRET:
        logging.error("OTA_SIGNING_SECRET is not set. Rejecting all OTA requests.")
        return False
    expected = hmac.new(
        SIGNING_SECRET.encode(),
        payload_bytes,
        hashlib.sha256,
    ).hexdigest()
    return hmac.compare_digest(expected, received_sig)
```

Rules:
- The `OTA_SIGNING_SECRET` must be a random 32-byte hex string stored in `.env`.
- The signature must be computed over the **raw JSON bytes**, not the parsed dict.
- Reject all OTA requests that fail signature verification with HTTP 401.

---

# 93. Rate Limiting on Flask

Protect expensive or sensitive Flask endpoints with rate limiting.

**Good — using `Flask-Limiter`:**
```python
from flask_limiter import Limiter
from flask_limiter.util import get_remote_address

limiter = Limiter(app=app, key_func=get_remote_address, default_limits=["200 per day"])

@app.route("/api/v1/auto-update", methods=["POST"])
@limiter.limit("5 per minute")  # OTA updates should not be triggered rapidly
def auto_update():
    ...
```

Rules:
- Rate-limit the `/api/v1/auto-update` endpoint: max 5 requests per minute.
- Rate-limit the `/api/v1/auto-pair` endpoint: max 3 requests per minute.
- The `/api/v1/video_feed` MJPEG stream should be limited to authenticated LAN clients only.
- The heartbeat endpoint on the backend server side (not the Edge) must rate-limit to 1 request per 30 seconds per device.

---

# 94. systemd Service Integration

The Edge Engine runs as a systemd service on the edge device. The service unit file must be managed correctly.

**Correct systemd unit (`/etc/systemd/system/your-service.service`):**
```ini
[Unit]
Description=Smart Absensi Edge Engine
After=network.target
Wants=network.target

[Service]
Type=simple
User=app-user
WorkingDirectory=/var/lib/your-service
ExecStart=/var/lib/your-service/your-app-engine
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
Environment=PYTHONUNBUFFERED=1

[Install]
WantedBy=multi-user.target
```

Rules:
- Use `Restart=always` with `RestartSec=5` so the engine automatically recovers from crashes.
- Use `StandardOutput=journal` so logs are captured by `journald` and can be read with `journalctl -u your-service -f`.
- Never use `Type=forking` for a Python process.
- After OTA update, run `systemctl daemon-reload && systemctl restart your-service` — not just `restart`.

---

# 95. Observability & Metrics

Export basic operational metrics so backend server can monitor device health.

Expose these metrics via the heartbeat (Rule 38) AND optionally via a Prometheus-compatible endpoint if the fleet grows:

```python
# Prometheus-style metrics (optional, using prometheus_client)
from prometheus_client import Gauge, Counter, generate_latest

FACE_RECOGNITION_COUNT = Counter("recognition_total", "Total recognition attempts")
LIVENESS_FAIL_COUNT = Counter("liveness_fail_total", "Total liveness failures")
FPS_GAUGE = Gauge("camera_fps", "Current camera FPS")
RAM_GAUGE = Gauge("ram_used_mb", "RAM used by the engine process")

@app.route("/metrics")
def metrics():
    return generate_latest(), 200, {"Content-Type": "text/plain; charset=utf-8"}
```

Rules:
- Always export `fps`, `ram_used_mb`, `outbox_count`, `model_version`, `cpu_temp_celsius` in the heartbeat.
- Counter metrics (recognition count, liveness failures) must never reset while the process is running. Use `Counter` not `Gauge` for cumulative values.
- Never expose metrics to untrusted networks. The `/metrics` endpoint should only be accessible from the local network.

---

# 96. Structured Logging (JSON)

For production deployments with centralized log aggregation, switch from plain text logging to JSON-structured logging.

**Good — structured log output:**
```python
import logging
import json

class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        log_data = {
            "ts": self.formatTime(record),
            "level": record.levelname,
            "logger": record.name,
            "msg": record.getMessage(),
            "device_id": os.environ.get("DEVICE_ID", "unknown"),
        }
        if record.exc_info:
            log_data["exc"] = self.formatException(record.exc_info)
        return json.dumps(log_data)
```

Rules:
- Use plain text logging during development (`logging.basicConfig(level=logging.DEBUG)`).
- Switch to JSON formatter in production to enable structured log queries (e.g., `journalctl -u your-service | jq '.level == "ERROR"'`).
- Always include `device_id` in every log entry so log aggregators can filter by device.
- Never log `None` values — explicitly check and replace with sensible defaults.

---

# 97. pyproject.toml Metadata (PEP 621)

Use `pyproject.toml` as the single source of truth for project metadata. Do not use `setup.py` or `setup.cfg` for new projects.

```toml
[project]
name = "your-app-engine"
version = "1.3.0"
description = "Smart Absensi Edge Engine for edge device"
requires-python = ">=3.11"
dependencies = [
    "flask==3.0.3",
    "onnxruntime==1.18.1",
    "numpy==1.26.4",
    "requests==2.31.0",
    "python-dotenv==1.0.1",
    "opencv-python-headless==4.9.0.80",
]

[project.scripts]
your-app-engine = "engine:main"

[build-system]
requires = ["setuptools>=68", "wheel"]
build-backend = "setuptools.backends.legacy:build"
```

Rules:
- Use `pyproject.toml` (PEP 621) for all new Python packages.
- Pin all `dependencies` in `pyproject.toml` to exact versions when the package is an application (not a library).
- For a library, use `>=` version constraints to avoid dependency conflicts.
- `version` must follow semantic versioning (MAJOR.MINOR.PATCH — see [semver.org](https://semver.org)).

---

# 98. Semantic Versioning for the Engine

The Edge Engine binary must be versioned with strict Semantic Versioning:

```text
MAJOR.MINOR.PATCH

1.0.0 — First production release
1.1.0 — New feature (backward compatible with backend server API)
1.1.1 — Bug fix
2.0.0 — Breaking change (heartbeat payload incompatible with old backend server)
```

Rules:
- Bump `MAJOR` when the heartbeat or Flask API contract changes in a backward-incompatible way.
- Bump `MINOR` when a new feature is added (new endpoint, new heartbeat field).
- Bump `PATCH` for bug fixes and performance improvements.
- The version must be stored in `pyproject.toml` and read in the engine at startup: `importlib.metadata.version("your-app-engine")`.
- Expose the version in every heartbeat payload (see Rule 38).

---

# 99. Cyclomatic Complexity Limits

Keep functions simple. High cyclomatic complexity makes code hard to test and reason about.

Rules:
- Maximum cyclomatic complexity per function: **10**.
- If a function's cyclomatic complexity exceeds 10, break it into smaller functions.
- Use Ruff's `C90` rule to enforce this automatically:

```toml
[tool.ruff]
select = ["E", "F", "W", "I", "N", "UP", "S", "B", "C90"]

[tool.ruff.mccabe]
max-complexity = 10
```

- Functions with more than 10 branches (if/elif/for/while/try/except) are a design smell. Refactor with early returns, extracted helper functions, or lookup tables.

---

# 100. Function Length Limits

Keep functions short and focused.

Rules:
- Maximum function body length: **40 lines** (excluding blank lines and comments).
- If a function exceeds 40 lines, it likely does more than one thing. Split it.
- The camera main loop, startup initialization, and `updater.sh` wrapper are permitted exceptions — document the reason in a comment.

---

# 101. Module Size Limits

Keep modules focused.

Rules:
- Maximum module size: **400 lines** (excluding blank lines and comments).
- If a module exceeds 400 lines, it likely contains multiple responsibilities. Split it.
- `stream_service.py` may be larger due to the number of Flask routes, but route handlers must still be thin (< 20 lines each).

---

# 102. Naming Anti-Patterns

Avoid these naming anti-patterns that reduce readability:

| Anti-pattern | Example | Better |
|-------------|---------|--------|
| Single letter (non-loop) | `r`, `x`, `d` | `result`, `frame`, `data` |
| Abbreviation | `cfg`, `mgr`, `proc` | `config`, `manager`, `process` |
| Hungarian notation | `strName`, `intCount` | `name`, `count` |
| Redundant type suffix | `name_str`, `items_list` | `name`, `items` |
| Misleading negation | `not_found = False` | `found = True` |
| Generic names | `data`, `info`, `result` | `heartbeat_payload`, `face_box`, `embedding` |

Exception: loop variables in very short loops (`for i in range(3)`) are acceptable.

---

# 103. Code Duplication (DRY)

Every piece of knowledge must have a single, unambiguous, authoritative representation. Do not repeat yourself.

Rules:
- If the same block of code appears in two or more places, extract it into a function.
- If the same configuration value is repeated in two or more places, define it as a constant.
- If the same validation logic appears in two or more routes, extract it into a helper.
- **Exception:** Do not aggressively DRY code that happens to look similar but represents different concepts. Premature abstraction is worse than some repetition.

---

# 104. Test Pyramid

Maintain the correct balance of test types:

```text
         /\
        /  \
       /    \   E2E Tests (1-2 tests, very slow)
      /------\
     /        \  Integration Tests (10-20, moderate speed)
    /----------\
   /            \ Unit Tests (100+, very fast)
  /--------------\
```

Rules:
- **Unit tests** (the base): Pure functions, individual class methods, mocked dependencies. Target: > 80% of all tests.
- **Integration tests** (the middle): Flask `test_client()` calls, SQLite in-memory DB. Target: 10-20 tests covering main flows.
- **End-to-end tests** (the top): Tests on a real edge device with a real camera. Target: 1-2 smoke tests run manually before deployment.
- Never skip unit tests to write only E2E tests. E2E tests are slow, fragile, and do not tell you *where* the failure is.

---

# 105. Property-Based Testing (Hypothesis)

For functions with complex input spaces (e.g., embedding normalization, similarity thresholds, checksum verification), use property-based testing with `hypothesis`:

```python
from hypothesis import given, strategies as st

@given(
    vector=st.lists(st.floats(min_value=-1.0, max_value=1.0, allow_nan=False), min_size=512, max_size=512)
)
def test_l2_normalize_always_returns_unit_vector(vector):
    normalized = FaceRecognizer.l2_normalize(vector)
    norm = sum(x**2 for x in normalized) ** 0.5
    assert abs(norm - 1.0) < 1e-5
```

Rules:
- Use `hypothesis` for functions that compute over numerical data (embeddings, similarity scores).
- Use `hypothesis` for security-relevant functions (checksum verification, URL validation).
- `hypothesis` is a dev dependency only — never installed on the edge device.

---

# 106. Dependency Deprecation Rules

When a dependency becomes deprecated or unmaintained:

1. Identify a replacement with equivalent functionality.
2. Write a migration plan with no breaking changes to the API.
3. Add a deprecation notice in the code as a `# TODO(deprecation):` comment.
4. Update `requirements.txt` with the new package pinned to an exact version.
5. Run `pytest`, `ruff`, `mypy` on the new package.
6. Deploy to a dev edge device first. Verify with `journalctl -u your-service`.
7. Deploy to production via OTA only after 24 hours of clean operation on dev.

---

# 107. Docker Build Rules

When building the Python environment in Docker for ARM cross-compilation:

```dockerfile
# Use the exact ARM64 Python base image
FROM python:3.11-slim-bookworm AS builder

# Install build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    gcc libopencv-dev && rm -rf /var/lib/apt/lists/*

# Copy and install pinned dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy source and build binary
COPY . .
RUN python build.py

FROM scratch AS export
COPY --from=builder /app/dist/your-app-engine /
```

Rules:
- Use `--no-cache-dir` on all `pip install` calls in Docker to reduce image size.
- Use multi-stage builds so the final export contains only the binary, not the entire Python environment.
- Pin the base image to a specific digest: `FROM python:3.11-slim-bookworm@sha256:...` for fully reproducible builds.
- Never use `latest` as a Docker tag. Pin exact versions.

---

# 108. Log Rotation

The Edge Engine runs continuously. Without log rotation, logs will fill the MicroSD card.

Configure `logrotate` on the edge device:

```
# /etc/logrotate.d/your-service
/var/log/your-service/*.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
    postrotate
        systemctl kill -s HUP your-service.service
    endscript
}
```

Rules:
- Log files must rotate daily with a 7-day retention.
- Use `compress` to gzip rotated logs.
- Send `SIGHUP` after rotation (not `SIGTERM`) so the engine reopens the log file without restarting.
- If using `journald` (recommended), log rotation is handled automatically — no `logrotate` config needed.

---

# 109. Environment-Specific Configuration

Maintain separate configuration for each environment:

| File | Purpose | Committed? |
|------|---------|------------|
| `.env.example` | Template with placeholder values | YES |
| `.env` | Actual local dev config | NO (gitignore) |
| `.env.production` | Production device config (deployed via OTA) | NO |

**`.env.example` must document every variable:**
```ini
# Smart Absensi Edge Engine Configuration
# Copy this file to .env and fill in real values.

# backend server backend URL (must be HTTPS in production)
BACKEND_URL=https://your-domain.com

# Device authentication token (generate via backend server admin panel)
DEVICE_TOKEN=

# Model version string (must match the recognizer.onnx model)
MODEL_VERSION=example_model_v1

# UDP port for auto-discovery broadcast listener
UDP_PORT=55555

# HMAC secret for OTA payload verification (min 32 bytes hex)
OTA_SIGNING_SECRET=
```

Rules:
- Every new environment variable added to the code must be documented in `.env.example`.
- Production `.env` values must never appear in git history.
- The `validate_config()` function (Rule 49) must be updated whenever a new required variable is added.

---

# 110. Default Decision Matrix

When you are uncertain which approach to use, apply this decision matrix:

| Question | If Yes → | If No → |
|----------|---------|---------|
| Does stdlib solve this? | Use stdlib | Evaluate PyPI packages |
| Is the class only holding data? | Use `@dataclass` | Use a regular class |
| Does it need to be JSON-serializable? | `str, Enum` or `TypedDict` | Regular `Enum` or `dataclass` |
| Is it called > 10/sec? | Profile first; use `__slots__` | Ignore performance |
| Does the function have side effects? | No `@lru_cache` | Consider `@lru_cache` |
| Does it cross a thread boundary? | Use `queue.Queue` | Use local list |
| Is it a constant that never changes? | `Final` + `UPPER_SNAKE_CASE` | Regular variable |
| Does the error leave state inconsistent? | Wrap in `DB.transaction` or rollback | Plain `try/except` |
| Is this a model update? | Atomic: model + vectors + cache invalidation | Normal file replacement |
| Is it user input? | Validate type + range + existence | Trust it |

---

# 111. Additional PEP Reference Table

The following PEPs are **Finished** (stable, in the language) and directly relevant to this project:

| PEP | Name | Rule in AGENTS2 |
|-----|------|----------------|
| [PEP 8](https://peps.python.org/pep-0008/) | Style Guide | Rule 1 |
| [PEP 20](https://peps.python.org/pep-0020/) | The Zen of Python | Directives |
| [PEP 257](https://peps.python.org/pep-0257/) | Docstring Conventions | Rule 5 |
| [PEP 282](https://peps.python.org/pep-0282/) | Logging System | Rule 7 |
| [PEP 289](https://peps.python.org/pep-0289/) | Generator Expressions | Rule 27 |
| [PEP 308](https://peps.python.org/pep-0308/) | Conditional Expressions | Rule 1.4 |
| [PEP 328](https://peps.python.org/pep-0328/) | Imports: Multi-line, Relative | Rule 1.2 |
| [PEP 343](https://peps.python.org/pep-0343/) | The `with` Statement | Rule 22 |
| [PEP 405](https://peps.python.org/pep-0405/) | Python Virtual Environments | Rule 11.3 |
| [PEP 428](https://peps.python.org/pep-0428/) | pathlib | Rule 25 |
| [PEP 435](https://peps.python.org/pep-0435/) | Enum | Rule 24 |
| [PEP 448](https://peps.python.org/pep-0448/) | Unpacking Generalizations | Rule 90 |
| [PEP 484](https://peps.python.org/pep-0484/) | Type Hints | Rule 4 |
| [PEP 492](https://peps.python.org/pep-0492/) | Coroutines (async/await) | Rule 89 |
| [PEP 498](https://peps.python.org/pep-0498/) | Literal String Interpolation | Rule 26 |
| [PEP 517](https://peps.python.org/pep-0517/) | Build System Interface | Rule 12 |
| [PEP 518](https://peps.python.org/pep-0518/) | pyproject.toml | Rules 12, 55, 97 |
| [PEP 526](https://peps.python.org/pep-0526/) | Variable Annotations | Rule 4 |
| [PEP 544](https://peps.python.org/pep-0544/) | Protocols | Rule 51 |
| [PEP 557](https://peps.python.org/pep-0557/) | Dataclasses | Rule 23 |
| [PEP 563](https://peps.python.org/pep-0563/) | Postponed Evaluation of Annotations | Rules 4, 87 |
| [PEP 572](https://peps.python.org/pep-0572/) | Walrus Operator | Rule 71 |
| [PEP 585](https://peps.python.org/pep-0585/) | Generic Alias (`list[str]`) | Rule 4 |
| [PEP 586](https://peps.python.org/pep-0586/) | Literal Types | Rule 76 |
| [PEP 589](https://peps.python.org/pep-0589/) | TypedDict | Rule 74 |
| [PEP 591](https://peps.python.org/pep-0591/) | Final & ClassVar | Rule 77 |
| [PEP 604](https://peps.python.org/pep-0604/) | `X \| Y` Union Syntax | Rule 4 |
| [PEP 612](https://peps.python.org/pep-0612/) | ParamSpec | Rule 88 (advanced) |
| [PEP 621](https://peps.python.org/pep-0621/) | Project Metadata | Rule 97 |
| [PEP 634](https://peps.python.org/pep-0634/) | Structural Pattern Matching | Rule 72 |
| [PEP 654](https://peps.python.org/pep-0654/) | Exception Groups | Rule 73 |
| [PEP 673](https://peps.python.org/pep-0673/) | `Self` Type | Future use |
| [PEP 695](https://peps.python.org/pep-0695/) | Type Parameter Syntax | Python 3.12+ |
| [PEP 3119](https://peps.python.org/pep-3119/) | Abstract Base Classes | Rule 86 |
| [PEP 3132](https://peps.python.org/pep-3132/) | Extended Iterable Unpacking | Rule 90 |
| [PEP 3333](https://peps.python.org/pep-3333/) | WSGI | Rule 9 |

---

# 112. Video Capture Optimization

When using OpenCV `cv2.VideoCapture` on the edge device, you must optimize it to prevent latency build-up (buffer lag).

**Good:**
```python
cap = cv2.VideoCapture(0)
cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)  # Drop old frames, only keep the newest
cap.set(cv2.CAP_PROP_FRAME_WIDTH, 640)
cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 480)
cap.set(cv2.CAP_PROP_FPS, 30)
```

Rules:
- Always set `CAP_PROP_BUFFERSIZE` to 1. If inference takes longer than the frame interval, the OS buffer will fill up with old frames, causing massive latency.
- Set explicit resolution and FPS. Do not rely on camera defaults.

---

# 113. Image Resizing Rules

When resizing images for neural network input (e.g., to 112x112), use the correct interpolation method to preserve feature quality.

Rules:
- Shrinking an image (downsampling): Use `cv2.INTER_AREA`.
- Enlarging an image (upsampling): Use `cv2.INTER_LINEAR` (fast) or `cv2.INTER_CUBIC` (high quality).
- Never use `cv2.INTER_NEAREST` for face images; it destroys facial features.

---

# 114. Memory-Mapped Files for Large Models

If a model grows too large for RAM, use memory mapping. However, `onnxruntime` handles this automatically in most cases.

Rules:
- Do not manually `mmap` model files unless you are passing pointers directly to a C extension.
- Trust `onnxruntime` to manage model weights in memory efficiently.

---

# 115. Zero-Copy Tensor Conversions

When moving data between OpenCV (NumPy) and ONNX Runtime, avoid unnecessary copying.

**Good:**
```python
# NumPy arrays are passed directly to ONNX Runtime without copying
input_tensor = np.expand_dims(frame, axis=0).astype(np.float32)
session.run(None, {input_name: input_tensor})
```

Rules:
- Ensure the NumPy array is contiguous in memory (`np.ascontiguousarray()`) if you performed slicing or transposition. ONNX Runtime will copy it otherwise.
- Avoid `.copy()` unless you explicitly need to preserve the original array.

---

# 116. Hardware Heat Management (Thermal Throttling)

The target hardware (e.g., Raspberry Pi) will throttle the CPU if it overheats. Continuous 100% CPU usage for face recognition will cause this.

Rules:
- Monitor CPU temperature via `/sys/class/thermal/thermal_zone0/temp`.
- Include the temperature in the heartbeat payload.
- If the temperature exceeds 80°C, the engine should optionally throttle inference (e.g., process every 3rd frame instead of every frame) until it cools down.

---

# 117. Thread Priority & Niceness

Background tasks (like uploading logs or syncing vectors) should not steal CPU time from the camera loop and inference.

Rules:
- The main camera and inference loop runs at default priority.
- Do not attempt to use `os.nice()` to lower priority of background threads in Python, as it affects the whole process.
- Rely on the GIL and `time.sleep()` in background threads to yield execution.

---

# 118. Deadlock Prevention

When using multiple `threading.Lock` objects, you risk deadlocks if they are acquired in different orders.

Rules:
- Always acquire locks in a strict, globally defined order.
- Prefer using a single lock per distinct subsystem (e.g., `_state_lock`, `_db_lock`) and never hold both simultaneously if possible.
- Never make blocking network calls or sleep while holding a lock.

---

# 119. Non-Blocking Sockets

When implementing the UDP auto-discovery listener, prevent it from freezing the thread indefinitely.

**Good:**
```python
sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
sock.settimeout(1.0)  # Check for exit flag every 1 second
while not self._stop_event.is_set():
    try:
        data, addr = sock.recvfrom(1024)
    except socket.timeout:
        continue
```

Rules:
- Always set a timeout on sockets used in long-running threads.
- Check a `threading.Event` (e.g., `_stop_event`) inside the loop to allow clean shutdown.

---

# 120. Bounded Queues

When passing data between threads (e.g., camera thread to upload thread), use bounded queues to prevent Out-Of-Memory (OOM) errors if the consumer is slower than the producer.

**Good:**
```python
import queue
outbox = queue.Queue(maxsize=100)

try:
    outbox.put(record, block=False)
except queue.Full:
    logging.warning("Outbox full, dropping record!")
```

Rules:
- Never use an unbounded `queue.Queue()`. Always specify `maxsize`.
- Decide explicitly whether to block (backpressure) or drop data (`block=False`) when the queue is full.

---

# 121. Retries with Exponential Backoff

When making HTTP requests to the backend server server, handle transient network failures gracefully.

Rules:
- Use a library like `tenacity` or write a simple loop for exponential backoff (e.g., wait 1s, 2s, 4s, 8s).
- Add jitter (randomness) to the backoff to prevent the "thundering herd" problem if all devices disconnect simultaneously.
- Do not retry infinitely. Give up after a set number of attempts and drop the payload or write it to a local dead-letter queue.

---

# 122. Fast-Fail API Design

The Edge Engine's Flask API should reject invalid requests immediately to free up resources.

Rules:
- Validate API keys or tokens as the very first step in the route handler.
- Validate payload schema before attempting to parse or process the data.
- Return `400 Bad Request` or `401 Unauthorized` immediately.

---

# 123. API Payload Versioning

Changes to JSON payloads (like the heartbeat) must be versioned.

Rules:
- Include a `"version": "1.0"` field in the heartbeat payload.
- If you add or remove fields, increment the version.
- The backend server server can use this version to parse the payload correctly.

---

# 124. SQLite Local Database Migrations

If the Edge Engine uses a local SQLite database for caching or offline storage, manage its schema properly.

Rules:
- Use the SQLite `PRAGMA user_version` to track schema versions.
- Run migrations on startup before the main loop begins.
```python
cursor.execute("PRAGMA user_version")
version = cursor.fetchone()[0]
if version == 0:
    cursor.execute("CREATE TABLE ...")
    cursor.execute("PRAGMA user_version = 1")
```

---

# 125. SQLite WAL Mode

For concurrent read/write access to SQLite (e.g., background thread writing records while API thread reads them), use Write-Ahead Logging (WAL).

**Good:**
```python
conn = sqlite3.connect("local.db")
conn.execute("PRAGMA journal_mode=WAL")
```

Rules:
- Always enable WAL mode for local SQLite databases. It significantly improves concurrency and performance.

---

# 126. SQLite Synchronous Mode

Balance data safety with performance on the SD card.

**Good:**
```python
conn.execute("PRAGMA synchronous=NORMAL")
```

Rules:
- `synchronous=NORMAL` is safe in WAL mode and much faster than `FULL` (the default). It reduces wear on the SD card.

---

# 127. JSON Serialization Speed

Python's built-in `json` module is acceptable, but if JSON serialization becomes a bottleneck, consider alternatives.

Rules:
- Default to `import json`.
- If profiling shows JSON encoding is taking > 5ms per frame, evaluate `orjson` (but check ARM compatibility first).

---

# 128. Datetime Formatting (ISO 8601)

All timestamps sent between the Edge Engine and the backend server server must use ISO 8601 format.

**Good:**
```python
import datetime
now = datetime.datetime.now(datetime.timezone.utc).isoformat()
# '2026-10-04T01:15:30.123456+00:00'
```

Rules:
- Always format timestamps in ISO 8601.
- Do not use custom string formats like `%Y-%m-%d %H:%M:%S`.

---

# 129. Timezone Handling (UTC Only)

Never rely on the device's local timezone setting.

Rules:
- All timestamps generated on the Edge Engine must be UTC.
- All timestamps received from the backend server server must be parsed as UTC.
- Let the backend server server or the frontend handle local timezone conversions for display.

---

# 130. File Locks for Multi-Process Safety

If multiple processes (e.g., the Engine and a separate diagnostic script) need to access the same file, use file locks.

Rules:
- Use `fcntl.flock` on Linux to ensure exclusive access to files during writing.
- For SQLite, rely on SQLite's built-in locking.

---

# 131. Atomic File Writes

When writing configuration or cache files, never write directly to the target path. If the power fails during the write, the file will be corrupted.

**Good:**
```python
temp_path = target_path.with_suffix(".tmp")
temp_path.write_text(data)
os.replace(temp_path, target_path)  # Atomic on POSIX
```

Rules:
- Always write to a temporary file first, then `os.replace()` (or `os.rename()`). This guarantees that the target file is never in a partially written state.

---

# 132. Pre-commit Hooks

Ensure code quality locally before pushing.

Rules:
- Use `.pre-commit-config.yaml` to run `black`, `ruff`, and `mypy` automatically on every commit.
- Never use `--no-verify` to bypass pre-commit hooks unless making an emergency hotfix.

---

# 133. Docstring Type Checking

Ensure docstrings match the function signatures.

Rules:
- If a parameter is added or removed, update the docstring immediately.
- Use tools like `darglint` or Ruff's `D` rules to enforce this if desired.

---

# 134. Dependency Vulnerability Scanning

Keep dependencies secure.

Rules:
- Periodically run `pip-audit` to check `requirements.txt` for known CVEs.
- Update vulnerable packages immediately, testing thoroughly on the device before OTA deployment.

---

# 135. Clean Architecture at the Edge

Separate hardware interaction from business logic.

Rules:
- The camera capture logic should be abstracted. The inference engine should accept a generic NumPy array, not require a specific camera object.
- This allows testing the inference engine easily by passing it an image loaded from disk.

---

# 136. Simple Dependency Injection

Pass dependencies explicitly rather than relying on global state.

**Good:**
```python
def main():
    config = load_config()
    db = LocalDB(config["db_path"])
    recognizer = FaceRecognizer(config["model_path"])
    service = StreamService(recognizer, db)
```

Rules:
- Instantiate major components at the top level (`engine.py`) and pass them down.
- Avoid singletons and global variables (except for module-level constants).

---

# 137. Model Metadata Injection

Include metadata directly inside the `.onnx` file if possible.

Rules:
- ONNX allows adding custom metadata strings to the model graph.
- Store the `model_version`, `input_size`, and `thresholds` inside the ONNX file itself. This prevents mismatches between the model file and the configuration.
- Read this metadata on startup: `session.get_modelmeta().custom_metadata_map`.

---

# 138. Post-mortem Debugging

When the engine crashes unexpectedly, save the state to aid debugging.

Rules:
- Wrap the main camera loop in a top-level `try/except Exception`.
- If an exception occurs, write the last processed frame to disk (e.g., `crash_frame.jpg`) and dump local variables to the log before exiting.
- This is critical for debugging edge cases (e.g., corrupted images, unexpected lighting) that only happen in the field.
