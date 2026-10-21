# Literature & Architectural References

A curated collection of engineering blog posts, architectural case studies, foundational papers, and technical videos that inform the core architecture of **`absensi-cam`**—spanning high-density execution on resource-constrained single-board computers, hybrid Rust/Python recognition pipelines, embedded SQLite in WAL mode (`attendance.db-wal`), encrypted facial embeddings, and offline-first transactional outbox synchronization.

---

## Core Technical Videos

* **[I Asked Expert Programmers to Max Out a $12 Server](https://youtu.be/QGWKCaglSBI)**
  * **Core Concept**: Benchmarking concurrency on a single-board/low-spec box (1 vCPU / 2 GB RAM) across Python, Go, and Rust.
  * **Application to `absensi-cam`**: Demonstrates how stripping framework overhead and adopting Rust with an in-process SQLite database, group commits on a dedicated thread, and `PRAGMA synchronous = NORMAL` scales to 64,000 concurrent requests with <100 MB RAM and 50% CPU—the exact operational target for our edge terminal daemon (`smart-absensi-sync`).

* **[The most trusted code on Earth is being rewritten in Rust](https://youtu.be/Sntj4HmuykI)**
  * **Core Concept**: The engineering rationale behind Turso's Limbo project—re-implementing SQLite entirely in safe Rust.
  * **Application to `absensi-cam`**: Explores deterministic simulation testing, memory safety in native drivers, asynchronous I/O via `io_uring`, and built-in vector similarity search inside embedded databases.

* **[SQLite and its weird new fork “libSQL”](https://youtu.be/PGpL5hYpY1o)**
  * **Core Concept**: How libSQL extends SQLite with asynchronous replication, web API extensions, and edge-first primitives.
  * **Application to `absensi-cam`**: Provides a clear conceptual model of elevating SQLite from an ephemeral desktop store to an enterprise-grade, distributed edge ledger.

---

## Engineering Blog Posts & Case Studies

### 1. Rust Engine Architecture & Edge SQLite

* **[Introducing Limbo: A complete rewrite of SQLite in Rust](https://turso.tech/blog/introducing-limbo-a-complete-rewrite-of-sqlite-in-rust)** — *Pekka Enberg & Glauber Costa (Turso Blog)*
  * **Why it matters**: The primary technical document behind modern Rust-based embedded database engineering. Covers how zero-copy page cache access, deterministic fault injection for power-loss recovery, and native vector math (`vector_distance_cos`) eliminate external database dependencies on embedded devices.

* **[Overclocking a $12 Server: Written Benchmark Breakdown](https://skip.watch/en/QGWKCaglSBI)** — *Technical Analysis of Arjay McCandless's Benchmark*
  * **Why it matters**: Step-by-step breakdown illustrating the progression from standard Python (~2,150 req/s) to stripped Python (~22,000 req/s) and native Rust + SQLite (~64,000 req/s). Details the hardware wins of page-cache memory mapping (`PRAGMA mmap_size`), WAL checkpoint batching, and eliminating IPC overhead.

### 2. SQLite Internals & Production WAL Tuning (`attendance.db-wal`)

* **[I'm All-In on Server-Side SQLite](https://fly.io/blog/all-in-on-sqlite-litestream/)** — *Ben Johnson (Fly.io Blog)*
  * **Why it matters**: The manifesto for modern in-process databases. Explains how eliminating the network round-trip transforms database operations into microsecond-scale CPU instructions, removing the need for caching layers in localized applications.

* **[SQLite Internals: Pages & B-trees](https://fly.io/blog/sqlite-internals-btree/)** — *Ben Johnson (Fly.io Blog)*
  * **Why it matters**: A deep dive into SQLite's physical layout—4KB OS-aligned disk pages, B+Tree node splitting, and variable-length integer (varint) serialization. Vital for understanding how `attendance.db` handles encrypted BLOB biometric embeddings and high-write append-only audit journals without disk fragmentation.

* **[SQLite in Production for as Long as You Can Get Away With It](https://blog.alcazarsec.com/tech/posts/sqlite-in-production)** — *AlcazarSec Engineering*
  * **Why it matters**: Operational guide to production SQLite hygiene: keeping write transactions strictly bounded, ensuring long-running reads never stall WAL checkpoints, and correctly handling the `-db`, `-wal`, and `-shm` triple during file rotations and backups.

* **[SQLite for Production: When and How to Use It Beyond Prototyping](https://daily.dev/blog/sqlite-production-guide-when-how-to-use-beyond-prototyping/)** — *daily.dev*
  * **Why it matters**: Concrete `PRAGMA` recipes for high-concurrency embedded systems (`PRAGMA journal_mode = WAL;`, `PRAGMA synchronous = NORMAL;`, `PRAGMA busy_timeout = 5000;`, `PRAGMA cache_size = -64000;`) and strategies for preventing `SQLITE_BUSY` errors through serialized worker queues.

### 3. Offline-First Edge Telemetry & Transactional Outbox Pattern

* **[Lessons from Using the Outbox Pattern at Scale](https://zapier.com/blog/lessons-from-using-outbox-pattern-at-scale/)** — *Zapier Engineering Blog*
  * **Why it matters**: Real-world architectural retrospective on using SQLite in WAL mode as a high-throughput transactional outbox buffer. Documents how local write atomicity decouples user-facing latency from downstream network fluctuations, mirroring `absensi-cam`'s offline queue and batch sync mechanism.

* **[Local-First Software: You Own Your Data, in Spite of the Cloud](https://www.inkandswitch.com/essay/local-first/)** *(also available as [PDF](https://martin.kleppmann.com/papers/local-first.pdf))* — *Martin Kleppmann, Adam Wiggins, et al. (Ink & Switch)*
  * **Why it matters**: The foundational computer science paper establishing the local-first paradigm. Articulates why the local device must remain the definitive authority for immediate state changes ("no spinners; the network is optional"), treating centralized cloud servers as asynchronous sync targets.

* **[Pattern: Transactional Outbox](https://microservices.io/patterns/data/transactional-outbox.html)** — *Chris Richardson (microservices.io)*
  * **Why it matters**: Standard reference architecture for recording business transactions (biometric recognition event) and relay intent (`attendance_logs` row marked `synced = 0`) in an atomic database transaction, polled and transmitted by an independent sync worker.

### 4. Vector Search & Biometric Embeddings Inside SQLite

* **[Building a New Vector Search SQLite Extension (`sqlite-vec`)](https://alexgarcia.xyz/blog/2024/building-new-vector-search-sqlite/index.html)** — *Alex Garcia*
  * **Why it matters**: Explains why heavyweight C++ vector databases (Faiss, Annoy) often fail on ARM SBCs due to memory footprint and dependency weight, and how compact, chunked shadow tables allow fast cosine/L2 distance search with minimal memory consumption.

* **[Hybrid Full-Text Search and Vector Search with SQLite](https://alexgarcia.xyz/blog/2024/sqlite-vec-hybrid-search/index.html)** — *Alex Garcia*
  * **Why it matters**: Architectural techniques for combining structured relational filtering (student ID, classroom, active enrollment status) with high-dimensional vector similarity in a unified embedded query pipeline.