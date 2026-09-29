# AGENTS.md

# Laravel Enterprise Development Rules

> This document is the mandatory engineering guideline for AI coding agents and human developers working on this Laravel application.
>
> The primary goals are:
>
> - Maintainability
> - Scalability
> - Security
> - Performance
> - Testability
> - Clear ownership of business logic
> - Minimal architectural complexity
> - Safe evolution of the system
>
> Follow these rules unless the task explicitly requires an exception.

---

# 1. Core Architecture

## 1.1 Architecture Style

This project uses:

**Modular Monolith + Pragmatic Layered Architecture**

Do NOT introduce Microservices, Hexagonal Architecture, Clean Architecture, CQRS, Event Sourcing, or another architectural paradigm unless explicitly requested.

The default request flow is:

```text
HTTP Request
    ↓
Route
    ↓
FormRequest
    ↓
Controller
    ↓
Service / Action
    ↓
Query / Eloquent Model
    ↓
Database
    ↓
Resource / Response
```

For asynchronous work:

```text
Controller / Service
    ↓
Job
    ↓
Queue
    ↓
Worker / Horizon
```

For side effects:

```text
Business Operation
    ↓
Event
    ↓
Listener
```

---

# 2. Architectural Principles

Follow these principles:

1. Prefer simple solutions over abstractions.
2. Prefer Laravel conventions over custom frameworks.
3. Keep controllers thin.
4. Keep business logic out of controllers.
5. Keep complex database queries out of controllers.
6. Do not introduce abstractions without a concrete reason.
7. Do not create a repository for every model by default.
8. Organize code primarily by business module.
9. Keep modules understandable and independently maintainable.
10. Optimize based on evidence, not assumptions.
11. Avoid premature optimization.
12. Avoid premature microservices.
13. Preserve backward compatibility unless breaking changes are explicitly requested.
14. Do not silently change database behavior.
15. Do not expose internal database structures through public APIs.

---

# 3. Directory Structure

Use the following structure as the default:

```text
app/
├── Domain/
│   ├── Employee/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Actions/
│   │   ├── Queries/
│   │   └── DTOs/
│   │
│   ├── Deposit/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Actions/
│   │   ├── Queries/
│   │   └── DTOs/
│   │
│   ├── Credit/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Actions/
│   │   ├── Queries/
│   │   └── DTOs/
│   │
│   ├── Transaction/
│   ├── User/
│   └── Report/
│
├── Http/
│   ├── Controllers/
│   │   ├── API/
│   │   │   └── V1/
│   │   └── Web/
│   │
│   ├── Requests/
│   │   ├── API/
│   │   │   └── V1/
│   │   └── Web/
│   │
│   └── Resources/
│       └── API/
│           └── V1/
│
├── Jobs/
│   ├── Import/
│   ├── Export/
│   └── Report/
│
├── Events/
├── Listeners/
├── Notifications/
├── Policies/
└── Support/
    ├── Exceptions/
    ├── Helpers/
    └── Constants/
```

Do not create directories that are not needed.

If a module does not need `DTOs`, do not create an empty `DTOs` directory.

---

# 4. Module Organization

Business modules are the primary organizational boundary.

Examples:

```text
Employee
Deposit
Credit
Transaction
Payroll
Report
User
Notification
```

A developer working on Credit should primarily work inside:

```text
app/Domain/Credit/
```

Avoid scattering Credit-related business logic across:

```text
app/Services/
app/Repositories/
app/Helpers/
app/Managers/
```

unless the code is genuinely shared infrastructure.

---

# 5. Controller Rules

Controllers must remain thin.

A controller should primarily:

1. Receive the request.
2. Validate through FormRequest.
3. Call a Service or Action.
4. Return a Resource/Response.

Good:

```php
public function store(StoreEmployeeRequest $request)
{
    $employee = $this->createEmployee->execute(
        $request->validated()
    );

    return new EmployeeResource($employee);
}
```

Bad:

```php
public function store(Request $request)
{
    $request->validate([...]);

    DB::transaction(function () {
        // 100+ lines of business logic
    });

    // database queries
    // notifications
    // audit logs
    // calculations
    // external API calls
}
```

If a controller method becomes complicated, move the logic into a Service or Action.

---

# 6. Service Rules

Use Services for business processes involving multiple steps or business rules.

Example:

```text
CreateEmployee
UpdateEmployee
TransferEmployee
ApproveCredit
ReconcileDeposit
```

A Service may:

- coordinate multiple models
- use transactions
- call Actions
- dispatch Jobs
- dispatch Events
- perform business validation
- coordinate external integrations

Example:

```php
final class CreateEmployee
{
    public function execute(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $employee = Employee::create($data);

            EmployeeCreated::dispatch($employee);

            return $employee;
        });
    }
}
```

---

# 7. Action Rules

Use an Action when an operation represents a clear, meaningful business operation.

Examples:

```text
ApproveCredit
RejectCredit
ImportCredit
ExportCredit
ReconcileCredit
GenerateReport
```

Prefer:

```text
Actions/
├── ApproveCredit.php
├── RejectCredit.php
└── ReconcileCredit.php
```

over:

```text
CreditService.php
```

containing hundreds or thousands of lines.

Actions should generally represent one primary operation.

---

# 8. Query Rules

Complex or reusable queries belong in `Queries/`.

Examples:

```text
EmployeeSearchQuery
EmployeeReportQuery
CreditSummaryQuery
DepositReconciliationQuery
```

Example:

```php
final class EmployeeSearchQuery
{
    public function build(string $keyword): Builder
    {
        return Employee::query()
            ->select([
                'id',
                'employee_number',
                'full_name',
                'status',
            ])
            ->whereFullText('full_name', $keyword);
    }
}
```

Do not place complex queries directly inside controllers.

---

# 9. Repository Rules

Repositories are NOT mandatory.

Do NOT create:

```text
EmployeeRepositoryInterface
EmployeeRepository
CreditRepositoryInterface
CreditRepository
```

for every model automatically.

Eloquent is already a powerful abstraction.

Prefer:

```php
Employee::query()
```

when the query is simple.

Use a Repository only when there is a real architectural reason, such as:

- multiple data sources
- external API abstraction
- read/write implementations
- complex persistence strategy
- replacing infrastructure implementations
- a clearly defined boundary requiring abstraction

Do not introduce an interface only for "enterprise appearance".

---

# 10. Model Rules

Models should contain:

- relationships
- casts
- scopes
- accessors/mutators when appropriate
- model-specific behavior
- database-related configuration

Avoid putting large business workflows into models.

Bad:

```php
$employee->approveAndNotifyAndGenerateReport();
```

Prefer:

```php
$approveEmployee->execute($employee);
```

---

# 11. Validation Rules

Use FormRequest classes.

Example:

```text
app/Http/Requests/API/V1/Employee/
├── StoreEmployeeRequest.php
└── UpdateEmployeeRequest.php
```

Avoid large inline validation rules inside controllers.

Good:

```php
public function rules(): array
{
    return [
        'employee_number' => ['required', 'string', 'max:50'],
        'full_name' => ['required', 'string', 'max:255'],
        'department_id' => ['required', 'integer', 'exists:departments,id'],
    ];
}
```

---

# 12. API Rules

All new APIs should be versioned.

Example:

```text
/api/v1/employees
/api/v1/credits
/api/v1/deposits
```

Future breaking changes:

```text
/api/v2/employees
```

Do not silently change the response structure of an existing API.

---

# 13. API Resources

Use Laravel API Resources for public API responses.

Prefer:

```php
return new EmployeeResource($employee);
```

or:

```php
return EmployeeResource::collection($employees);
```

Avoid exposing Eloquent models directly from public APIs.

Do not expose:

- internal database fields
- passwords
- tokens
- internal audit metadata
- internal IDs when not required
- sensitive infrastructure information

---

# 14. Query Builder Rules

When using Spatie Query Builder:

```php
QueryBuilder::for(Employee::class)
```

always explicitly define:

```php
allowedFilters()
allowedSorts()
allowedIncludes()
```

Never expose unrestricted filtering.

Bad:

```php
->allowedFilters('*')
```

Good:

```php
->allowedFilters([
    AllowedFilter::exact('status'),
    AllowedFilter::exact('employee_number'),
    AllowedFilter::partial('full_name'),
])
```

Always evaluate the database impact of every filter and sort.

---

# 15. Database Rules

The database is a critical scalability boundary.

Never assume a query is efficient because it works on development data.

Always consider:

- indexes
- cardinality
- execution plan
- joins
- filtering
- sorting
- pagination
- selected columns
- relationship loading

---

# 16. Large Dataset Rules

This application may contain millions or tens of millions of records.

Avoid:

```php
Model::all();
```

Avoid:

```php
Model::get();
```

when the dataset can be large.

Avoid:

```php
->paginate(100000);
```

Prefer:

```php
->paginate(50);
```

or:

```php
->cursorPaginate(50);
```

or chunking for background processing:

```php
->chunkById(1000, function ($records) {
    //
});
```

For very large exports/imports, use Jobs and queues.

---

# 17. Pagination Rules

For normal UI pagination:

```php
->paginate(50)
```

For very large datasets or infinite scrolling:

```php
->cursorPaginate(50)
```

For background processing:

```php
->chunkById(1000)
```

Never load millions of rows into PHP memory.

---

# 18. Select Only Required Columns

Avoid:

```php
Employee::query()->get();
```

Prefer:

```php
Employee::query()
    ->select([
        'id',
        'employee_number',
        'full_name',
        'status',
    ])
    ->paginate(50);
```

Especially for tables containing large text fields or many columns.

---

# 19. N+1 Prevention

Always evaluate relationships.

Bad:

```php
$employees = Employee::paginate(50);

foreach ($employees as $employee) {
    echo $employee->department->name;
}
```

Prefer:

```php
$employees = Employee::with('department')
    ->paginate(50);
```

However, do not blindly eager-load everything.

Avoid:

```php
->with([
    'department',
    'position',
    'transactions',
    'deposits',
    'credits',
    'logs',
    'notifications',
])
```

unless all relationships are actually required.

---

# 20. Database Indexing

Every new filter, sort, join, or lookup on large tables must be evaluated for indexing.

Typical candidates:

```text
employee_number
status
department_id
created_at
updated_at
foreign keys
business identifiers
```

Do not add indexes blindly.

Consider:

- query frequency
- selectivity
- write overhead
- index size
- composite index ordering

For large tables, use `EXPLAIN` to verify important queries.

---

# 21. Full Text Search

Do not use:

```sql
LIKE '%keyword%'
```

as the default strategy for large datasets.

For large-scale text search, consider:

```text
MySQL FULLTEXT
Laravel Scout
OpenSearch
Elasticsearch
Meilisearch
Typesense
```

Choose based on actual requirements.

Do not introduce a search engine if MySQL FULLTEXT is sufficient.

---

# 22. Transactions

Use database transactions for operations that must be atomic.

Example:

```php
DB::transaction(function () {
    // operation A
    // operation B
    // operation C
});
```

Examples:

```text
Approve credit
Create financial transaction
Transfer balance
Reconcile transaction
Create employee with required related records
```

Do not create transactions around simple read-only queries.

---

# 23. Queue Rules

Long-running or expensive operations must not block HTTP requests.

Move these to Jobs:

```text
Import
Export
Report generation
Large file processing
Bulk notifications
Email batches
Data synchronization
Heavy calculations
```

Example:

```php
GenerateCreditReport::dispatch($filters);
```

---

# 24. Queue Architecture

Use Redis + Laravel Horizon.

Recommended queues:

```text
default
imports
exports
reports
notifications
```

Avoid putting every job into one queue if workloads have significantly different characteristics.

Example:

```php
public $queue = 'imports';
```

---

# 25. Job Rules

Jobs should be:

- idempotent where possible
- retryable
- observable
- reasonably small
- safe to execute more than once

Never assume a Job will execute exactly once.

Handle:

- retries
- duplicate execution
- timeout
- partial failure
- external API failure

---

# 26. Events

Use Events primarily for side effects.

Example:

```text
CreditApproved
    ↓
CreateAuditLog
SendNotification
UpdateStatistics
```

Do not hide critical business logic inside obscure event chains.

Critical business decisions should remain visible in the main Service/Action flow.

---

# 27. Audit Logging

Use:

```text
spatie/laravel-activitylog
```

for important business changes.

Examples:

```text
Employee updated
Credit approved
Credit rejected
Deposit created
Transaction reconciled
User role changed
```

Prefer:

```php
->logOnly([...])
->logOnlyDirty()
```

Do not log every field of every model by default.

The audit table can become extremely large.

For high-volume audit data, consider retention and archival strategies.

---

# 28. Permission

Use:

```text
spatie/laravel-permission
```

for roles and permissions.

Prefer permission checks:

```php
$user->can('credit.approve');
```

or:

```php
->middleware('permission:credit.approve');
```

Use permissions such as:

```text
employee.view
employee.create
employee.update
employee.delete

credit.view
credit.create
credit.update
credit.approve

deposit.view
deposit.create
deposit.update
deposit.approve

report.view
report.export
```

Avoid hardcoding role checks throughout the application:

```php
if ($user->role === 'admin')
```

Prefer capability-based authorization.

---

# 29. Authorization

Use:

- Policies
- Gates
- Permissions

Authorization must happen server-side.

Never trust:

```text
hidden buttons
frontend roles
request parameters
client-side permission flags
```

The backend must enforce authorization.

---

# 30. Authentication

Use Laravel's supported authentication mechanisms.

For API authentication, use:

```text
Laravel Sanctum
```

Do not build custom authentication unless there is a documented requirement.

Never store:

- plaintext passwords
- plaintext API secrets
- plaintext tokens

---

# 31. Security

Never commit:

```text
.env
passwords
API keys
private keys
database credentials
production secrets
```

Use environment variables or a proper secret-management system.

Never log:

```text
password
token
authorization header
API secret
private key
credit card information
```

Validate and authorize every mutation endpoint.

---

# 32. Mass Assignment

Use explicit:

```php
$fillable
```

or carefully controlled:

```php
$guarded
```

Never blindly trust:

```php
Model::create($request->all());
```

Prefer:

```php
Model::create($request->validated());
```

---

# 33. Input Validation

Never trust client input.

Validate:

- type
- format
- range
- existence
- authorization
- business constraints

Technical validation and business validation are separate concerns.

---

# 34. Error Handling

Do not expose internal exceptions to users.

Bad:

```json
{
    "message": "SQLSTATE[HY000] ..."
}
```

Production API responses should contain a safe message.

Detailed information belongs in logs.

Use custom domain exceptions when business errors need to be distinguished.

---

# 35. Logging

Use structured, meaningful logs.

Good:

```php
Log::info('Credit approval started', [
    'credit_id' => $credit->id,
    'user_id' => auth()->id(),
]);
```

Avoid:

```php
Log::info($request->all());
```

especially when the request may contain sensitive data.

---

# 36. Caching

Use Redis for appropriate cache workloads.

Good candidates:

```text
master data
configuration
permissions
expensive aggregations
frequently accessed reference data
```

Do not cache everything.

Every cache must have:

- clear key naming
- reasonable TTL
- invalidation strategy

Example:

```text
employee:{id}
department:{id}
dashboard:credit-summary:{date}
```

---

# 37. Cache Keys

Use consistent names.

Preferred:

```text
employee:123
employee:list:active
department:10
credit:summary:2026-09
```

Avoid ambiguous keys:

```text
data1
cache_employee
temp
```

---

# 38. API Rate Limiting

Public and sensitive endpoints must use appropriate rate limits.

Especially:

```text
login
password reset
OTP
search
export
report generation
bulk operations
external API endpoints
```

Do not allow unrestricted expensive endpoints.

---

# 39. Export Rules

Never generate a large export synchronously.

Bad:

```text
HTTP request
    ↓
Query 20 million records
    ↓
Generate CSV
    ↓
Response
```

Preferred:

```text
HTTP request
    ↓
Create Export Job
    ↓
Queue
    ↓
Generate file
    ↓
Object Storage
    ↓
Notify user
```

---

# 40. Import Rules

Large imports must use queued processing.

Preferred:

```text
Upload
    ↓
Validate file
    ↓
Create Import record
    ↓
Dispatch Job
    ↓
Chunk processing
    ↓
Database
    ↓
Progress tracking
```

Never process millions of rows in one HTTP request.

---

# 41. File Storage

Do not store large generated files permanently on local application storage.

Prefer object storage for:

```text
exports
reports
backups
large uploads
documents
```

Examples:

```text
S3
Cloudflare R2
MinIO
compatible object storage
```

---

# 42. Backup

Use:

```text
spatie/laravel-backup
```

Production backups must not exist only on the production server.

Recommended:

```text
Production Database
        ↓
Backup
        ↓
Object Storage
        ↓
Retention Policy
```

Backups must be tested by restoring them periodically.

A backup that has never been restored is not considered verified.

---

# 43. Database Backup Strategy

At minimum consider:

```text
Daily backup
Weekly backup
Monthly retention
```

Actual retention must match business requirements.

For critical systems, also consider:

```text
point-in-time recovery
database snapshots
cross-region backup
off-site backup
```

---

# 44. Performance Rules

Before optimizing:

1. Identify the bottleneck.
2. Measure.
3. Optimize.
4. Measure again.

Do not introduce:

```text
Octane
Redis
Read replicas
Search engines
partitioning
sharding
```

simply because they are considered "enterprise".

Every infrastructure component must solve a measured problem.

---

# 45. Octane

Laravel Octane may be used when application benchmarks justify it.

Do not assume Octane automatically solves:

- slow SQL
- missing indexes
- inefficient joins
- excessive data transfer
- bad API design

Database optimization usually comes first.

---

# 46. Read Replicas

Read replicas may be introduced when database read load justifies them.

Before adding replicas, optimize:

```text
queries
indexes
pagination
cache
database schema
```

Do not introduce replication merely for architectural complexity.

---

# 47. Database Connections

Be careful with database connection counts.

Total possible connections are affected by:

```text
PHP workers
Queue workers
Horizon workers
Octane workers
Scheduler
CLI processes
Monitoring
```

Always consider database connection capacity when scaling workers horizontally.

---

# 48. Configuration

Never hardcode environment-specific values.

Bad:

```php
'host' => '192.168.1.10'
```

Good:

```php
'host' => env('DB_HOST')
```

Environment-specific configuration belongs in:

```text
.env
.env.example
deployment configuration
secret manager
```

---

# 49. Environment Separation

Maintain clear environments:

```text
local
development
testing
staging
production
```

Never use production credentials locally.

Never test destructive migrations directly on production.

---

# 50. Migration Rules

Every schema change must use a migration.

Never manually modify production schema without documenting it.

Migrations must be:

- deterministic
- reversible when practical
- safe for production
- tested on realistic data volumes

Be careful with large-table migrations.

Operations such as:

```text
adding indexes
changing column types
renaming columns
adding NOT NULL columns
```

may lock large tables.

Consider zero-downtime migration strategies for large production tables.

---

# 51. Seeders

Seeders are appropriate for:

```text
reference data
roles
permissions
development data
system configuration
```

Do not put environment-specific production secrets in seeders.

Role/permission seeders should be idempotent.

Prefer:

```php
firstOrCreate()
```

or:

```php
syncPermissions()
```

when appropriate.

---

# 52. Factories

Use factories for automated tests.

Example:

```php
Employee::factory()->count(100)->create();
```

Do not use real production data in tests unless it has been properly anonymized.

---

# 53. Testing

New business logic should have tests.

Prioritize:

```text
Feature Tests
Integration Tests
Critical Unit Tests
```

Important business operations must be tested.

Examples:

```text
approve credit
reject credit
reconcile transaction
create employee
change user permission
generate report
import data
```

---

# 54. Testing Database Logic

Tests involving database behavior should use realistic relationships and constraints.

Test:

```text
authorization
validation
transactions
duplicate records
race conditions where relevant
large data behavior where relevant
```

---

# 55. Static Analysis

When available, use:

```text
PHPStan
Larastan
Pint
PHPUnit / Pest
```

Code should pass automated checks before merge.

---

# 56. Code Formatting

Follow Laravel/PHP standards.

Prefer:

```text
Laravel Pint
PSR-12-compatible formatting
```

Do not manually create unusual formatting styles.

---

# 57. Naming Conventions

Use clear names.

Classes:

```text
CreateEmployee
ApproveCredit
EmployeeSearchQuery
StoreEmployeeRequest
EmployeeResource
```

Avoid:

```text
EmployeeManager
EmployeeProcessor
EmployeeHandler
EmployeeHelper
DataProcessor
CommonService
```

unless their responsibility is genuinely clear.

---

# 58. Boolean Naming

Prefer:

```text
is_active
is_verified
has_permission
can_approve
```

Avoid ambiguous:

```text
active_flag
status_bool
check
```

unless existing schema requires compatibility.

---

# 59. Constants

Do not scatter magic strings.

Bad:

```php
if ($credit->status === 'approved')
```

when the status is used throughout the application.

Prefer centralized enums/constants where appropriate.

Example:

```php
CreditStatus::APPROVED
```

Use PHP Enums when they improve clarity and are compatible with the existing schema.

---

# 60. Enums

Use enums for stable finite business states.

Examples:

```text
CreditStatus
EmployeeStatus
TransactionStatus
ImportStatus
ExportStatus
```

Do not create enums for values that are genuinely dynamic database data.

---

# 61. DTO Rules

Use DTOs when:

- data crosses application boundaries
- request data becomes complex
- service parameters become large
- external API payloads need typed structures
- strong typing improves maintainability

Do not create DTOs for every trivial method.

---

# 62. External APIs

All external integrations should have a clear boundary.

Prefer:

```text
Domain / Application
        ↓
Integration Service
        ↓
External API
```

Do not call external APIs directly from controllers.

Handle:

```text
timeout
retry
rate limits
authentication
failure
logging
response validation
```

---

# 63. HTTP Client

Use Laravel's HTTP client.

Always configure reasonable:

```text
timeout
connectTimeout
retry
```

Do not allow external services to hang application workers indefinitely.

---

# 64. Transactions + External APIs

Avoid holding a database transaction open while waiting for an external API.

Bad:

```php
DB::transaction(function () {
    // database write
    Http::post(...); // external network request
    // database write
});
```

Prefer an architecture that minimizes transaction duration.

---

# 65. Concurrency

Assume multiple requests can modify the same record simultaneously.

For financial or critical state transitions consider:

```text
database transactions
row locking
optimistic locking
unique constraints
idempotency keys
```

Do not rely solely on application-level checks.

---

# 66. Financial Data

For financial operations:

- never use floating-point arithmetic for monetary values
- use DECIMAL database columns
- define precision explicitly
- use transactions
- record audit trails
- prevent duplicate processing
- use immutable transaction records where appropriate

Example:

```text
DECIMAL(20,2)
```

instead of:

```text
FLOAT
```

for monetary values.

---

# 67. Idempotency

Operations that may be retried must be safe against duplicate execution.

Especially:

```text
payments
financial transactions
imports
webhooks
external synchronization
queue jobs
```

Use unique business identifiers or idempotency keys where appropriate.

---

# 68. Webhooks

Webhook endpoints must:

1. authenticate/verify signatures
2. validate payload
3. detect duplicates
4. persist required information
5. return quickly
6. process heavy work asynchronously

Preferred:

```text
Webhook
  ↓
Validate
  ↓
Persist
  ↓
Dispatch Job
  ↓
Return 200
```

---

# 69. Notifications

Notifications that involve:

```text
email
SMS
push notification
external messaging
```

should generally be queued.

Do not block critical HTTP requests unnecessarily.

---

# 70. Frontend/API Contract

Backend changes affecting frontend behavior must be treated as API contract changes.

Before changing:

```text
field names
types
nullable state
HTTP status
pagination format
error format
```

check consumers.

---

# 71. Backward Compatibility

Do not remove or rename public fields without checking consumers.

Prefer additive changes:

```text
old_field
new_field
```

during migration periods.

Breaking changes require explicit approval.

---

# 72. API Error Format

Maintain a consistent error structure.

Example:

```json
{
    "message": "Validation failed.",
    "errors": {
        "email": [
            "The email field is required."
        ]
    }
}
```

Do not invent different error formats for every endpoint.

---

# 73. HTTP Status Codes

Use meaningful HTTP status codes.

Examples:

```text
200 OK
201 Created
204 No Content
400 Bad Request
401 Unauthorized
403 Forbidden
404 Not Found
409 Conflict
422 Unprocessable Entity
429 Too Many Requests
500 Internal Server Error
```

Do not return `200` for every failure.

---

# 74. Soft Deletes

Use SoftDeletes only when business requirements require recoverability/history.

Do not automatically add:

```php
use SoftDeletes;
```

to every model.

For financial records, carefully evaluate whether deletion should be possible at all.

---

# 75. Deletion Rules

For critical business records, prefer:

```text
void
cancel
reversal
inactive
archived
```

over physical deletion when historical integrity is required.

Never delete financial records casually.

---

# 76. Data Retention

Large datasets require retention strategies.

Consider:

```text
active data
historical data
archived data
deleted data
audit data
```

Do not allow unlimited growth without a strategy.

---

# 77. Logging Retention

Logs and audit logs can become very large.

Define retention policies.

Do not keep unlimited:

```text
application logs
activity logs
failed jobs
temporary files
exports
```

without a reason.

---

# 78. Failed Jobs

Production must monitor failed jobs.

Use Horizon and/or operational monitoring.

Failed jobs should be:

```text
investigated
retried when safe
resolved
purged according to retention policy
```

Never blindly retry jobs that are not idempotent.

---

# 79. Health Checks

Production should expose appropriate health checks.

At minimum monitor:

```text
application
database
cache
queue
storage
critical external dependencies
```

The application should be able to distinguish between:

```text
healthy
degraded
unavailable
```

where appropriate.

---

# 80. Observability

Production observability should cover:

```text
Application
Database
Queue
Cache
Infrastructure
Errors
Latency
Throughput
```

Recommended Laravel tooling may include:

```text
Laravel Pulse
Laravel Horizon
Laravel Telescope (primarily development/staging)
```

and an external error-monitoring system where appropriate.

---

# 81. Performance Metrics

When investigating performance, measure:

```text
request latency
database query duration
query count
memory usage
CPU usage
queue wait time
queue processing time
cache hit/miss
external API latency
```

Never claim an optimization is successful without measurement.

---

# 82. N+1 Detection

During development/testing, actively detect N+1 queries.

When adding:

```text
relationship
resource
nested API response
```

check query count.

---

# 83. API Performance

API responses should not contain unnecessary data.

Prefer:

```text
small payload
explicit fields
pagination
resource transformation
```

Avoid returning huge nested relationship graphs.

---

# 84. Large API Responses

Never return millions of records from one HTTP response.

Use:

```text
pagination
cursor pagination
export jobs
streaming
```

depending on the use case.

---

# 85. Search Endpoints

Search endpoints must be designed for scalability.

Avoid:

```php
where('name', 'like', "%{$keyword}%")
```

on very large tables unless the database/search strategy explicitly supports it.

Use:

```text
FULLTEXT
indexed prefix search
Scout
dedicated search engine
```

as appropriate.

---

# 86. Security of Search

Search parameters must be:

- validated
- limited in length
- rate limited when expensive
- constrained to allowed fields

Never allow users to submit arbitrary SQL fragments.

---

# 87. Git Rules

Use meaningful commits.

Examples:

```text
feat: add credit approval workflow
fix: prevent duplicate credit approval
perf: optimize employee search query
refactor: extract credit approval action
test: add credit approval feature tests
```

Avoid:

```text
fix
update
changes
asdf
final
final2
```

---

# 88. Pull Request Rules

A PR should explain:

```text
What changed?
Why?
How was it tested?
Any migration?
Any performance impact?
Any breaking change?
```

---

# 89. Database Migration Review

Any PR containing migrations must consider:

```text
table size
locking
index creation
rollback
production deployment order
backward compatibility
```

Large production tables require additional caution.

---

# 90. Zero-Downtime Deployment

For critical production systems, prefer backward-compatible deployment sequences.

Example:

```text
1. Add nullable column
2. Deploy application supporting old + new schema
3. Backfill data asynchronously
4. Switch reads/writes
5. Remove old column later
```

Do not combine destructive schema changes with application deployment blindly.

---

# 91. Deployment

Production deployment should generally follow:

```text
Pull code
↓
Install dependencies
↓
Build assets
↓
Run tests
↓
Run safe migrations
↓
Clear/cache configuration
↓
Restart workers
↓
Verify health
```

Never restart workers before considering currently running jobs.

---

# 92. Queue Deployment

After deployment, workers may need restarting:

```bash
php artisan horizon:terminate
```

Use the deployment system's process manager to bring workers back.

Do not manually kill production workers without understanding their current jobs.

---

# 93. Config Cache

Production should use optimized configuration.

Typical commands:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Do not run configuration caching blindly if the application has runtime configuration requirements incompatible with caching.

---

# 94. Production Debugging

Never enable:

```env
APP_DEBUG=true
```

in production.

Production:

```env
APP_ENV=production
APP_DEBUG=false
```

---

# 95. Dependency Rules

Before installing a package:

1. Confirm it solves a real problem.
2. Check Laravel compatibility.
3. Check PHP compatibility.
4. Check maintenance status.
5. Check security history.
6. Check license.
7. Check dependency impact.
8. Check whether Laravel already provides the feature.

Avoid dependency bloat.

---

# 96. Preferred Packages

Recommended packages for this architecture may include:

```text
spatie/laravel-permission
spatie/laravel-activitylog
spatie/laravel-backup
spatie/laravel-query-builder
```

Additional packages should be evaluated individually.

Do not install packages simply because they are popular.

---

# 97. Package Installation Rule

Before running:

```bash
composer require package/name
```

check:

```bash
php artisan --version
php -v
composer show laravel/framework
```

Ensure package compatibility with the project.

Never downgrade Laravel or PHP automatically to satisfy a package dependency.

---

# 98. Laravel Conventions

Prefer Laravel-native features before custom implementations.

Use:

```text
FormRequest
Policy
Gate
Resource
Job
Event
Listener
Notification
Mail
Cache
Queue
Scheduler
HTTP Client
Filesystem
Validation
Eloquent
```

before introducing custom equivalents.

---

# 99. Avoid Custom Frameworks

Do not create custom implementations of:

```text
Request system
ORM
Router
Event bus
Queue system
Validation framework
Authentication framework
Permission framework
```

unless explicitly required.

---

# 100. Comments

Write comments to explain:

```text
why
```

not:

```text
what
```

Bad:

```php
// Get employee
$employee = Employee::find($id);
```

Good:

```php
// Lock the employee record because approval can be triggered
// concurrently by multiple workers.
```

---

# 101. TODO Rules

Do not add vague TODOs.

Bad:

```php
// TODO fix this
```

Good:

```php
// TODO: Replace temporary CSV parser with streaming parser
// after import volume exceeds 5 million rows.
```

---

# 102. Dead Code

Do not leave:

```text
unused imports
unused methods
unused classes
commented-out code
temporary debugging
dd()
dump()
ray()
var_dump()
```

in production code.

---

# 103. Debugging Code

Never commit:

```php
dd();
dump();
var_dump();
die();
exit();
```

or debugging credentials/logging.

---

# 104. Exception Handling

Do not use:

```php
try {
    //
} catch (\Exception $e) {
    //
}
```

without handling the error meaningfully.

Do not silently swallow exceptions.

Bad:

```php
catch (Throwable $e) {
    return null;
}
```

unless failure is explicitly acceptable.

---

# 105. Retry Rules

Retries are appropriate for transient failures:

```text
network timeout
temporary database connection issue
rate limit
temporary external service failure
```

Retries are not a solution for:

```text
validation errors
authorization errors
invalid business state
duplicate records
programming bugs
```

---

# 106. Race Conditions

When two processes can modify the same business state, evaluate race conditions.

Examples:

```text
approve
withdraw
transfer
reconcile
import
increment balance
```

Use appropriate:

```text
transactions
locks
unique constraints
idempotency
```

---

# 107. Critical Financial Operations

For financial operations, prefer this flow:

```text
Validate
↓
Authorize
↓
Begin Transaction
↓
Lock required records
↓
Validate current state
↓
Perform mutation
↓
Create immutable transaction/audit record
↓
Commit
↓
Dispatch side effects
```

Do not send external notifications while holding the transaction open.

---

# 108. Business State Machines

When a model has complex state transitions, explicitly define allowed transitions.

Example:

```text
draft
  ↓
submitted
  ↓
approved
  ↓
completed
```

Invalid transitions must be rejected.

Do not rely on arbitrary string assignment.

---

# 109. API Idempotency

For operations such as:

```text
POST /payments
POST /transactions
POST /credits/{id}/approve
```

consider idempotency where duplicate requests are possible.

A repeated request should not accidentally execute a financial operation twice.

---

# 110. Large Tables

For tables expected to exceed millions of rows:

Before adding features, evaluate:

```text
index
query pattern
data retention
partitioning
archival
read/write load
pagination strategy
search strategy
```

Do not introduce partitioning or sharding without measured need.

---

# 111. Partitioning

Partitioning may be considered for very large tables where access patterns support it.

Potential candidates:

```text
transactions
activity logs
audit logs
historical records
```

Partitioning is not a default solution.

It must be evaluated against:

```text
query patterns
foreign keys
maintenance
backup
migration
ORM behavior
```

---

# 112. Sharding

Do not introduce database sharding unless explicitly approved.

Sharding significantly increases complexity.

Preferred progression:

```text
Optimize query
↓
Indexes
↓
Pagination
↓
Cache
↓
Redis
↓
Read replica
↓
Partitioning
↓
Sharding
```

Only progress when justified by measured requirements.

---

# 113. Module Dependencies

Modules should have clear dependencies.

Prefer:

```text
Credit → Employee
Credit → User
```

rather than uncontrolled circular dependencies.

Avoid:

```text
Employee → Credit → Deposit → Employee → Credit
```

when possible.

---

# 114. Shared Code

Only place genuinely reusable code in:

```text
app/Support/
```

Examples:

```text
Date utilities
common exceptions
shared value objects
shared infrastructure
```

Do not use `Support` as a dumping ground.

---

# 115. Helpers

Do not create generic helper functions for everything.

Avoid:

```text
helpers.php
```

containing hundreds of unrelated functions.

Prefer:

```text
Services
Actions
Value Objects
Support classes
Laravel utilities
```

---

# 116. Constants

Avoid global constants when a class/enum provides better ownership.

Prefer:

```php
CreditStatus::APPROVED
```

over:

```php
define('APPROVED', 'approved');
```

---

# 117. Documentation

When implementing a complex architectural decision, document:

```text
Problem
Decision
Reason
Alternatives considered
Trade-offs
```

Do not document obvious Laravel code unnecessarily.

---

# 118. Agent Behavior

AI coding agents MUST:

1. Inspect the existing code before changing it.
2. Follow existing conventions when they do not conflict with these rules.
3. Avoid unnecessary refactoring.
4. Avoid unrelated changes.
5. Preserve existing behavior unless explicitly asked to change it.
6. Check existing models, migrations, routes, services, and tests before creating duplicates.
7. Search for existing implementations before adding new utilities.
8. Reuse existing abstractions when appropriate.
9. Explain architectural changes that introduce new complexity.
10. Never silently introduce a new architectural pattern.

---

# 119. Agent — Before Coding

Before making changes, determine:

```text
What module owns this functionality?
What layer should contain the logic?
Does an existing implementation already exist?
Does the database already support this?
Is this an API contract?
Does this affect authorization?
Does this affect large datasets?
Does this require a queue?
Does this require a transaction?
Does this require audit logging?
```

---

# 120. Agent — File Creation

Before creating a new class, search for:

```text
existing Service
existing Action
existing Query
existing Model
existing Policy
existing Resource
existing Request
existing helper
```

Do not create duplicates.

---

# 121. Agent — Refactoring

Do not refactor unrelated code while implementing a feature.

Bad:

```text
Feature request:
Add employee export

Agent:
- rewrite employee module
- rename models
- change repository architecture
- migrate authentication
- format 200 files
```

Prefer:

```text
Implement only what is necessary.
```

---

# 122. Agent — Database Changes

Before changing schema:

1. Inspect existing migration.
2. Inspect model.
3. Inspect indexes.
4. Search for references.
5. Consider production table size.
6. Consider backward compatibility.
7. Consider rollback.

---

# 123. Agent — Large Data

If a requested operation potentially touches:

```text
> 10,000 records
```

evaluate whether it should use:

```text
chunking
cursor
queue
batch processing
streaming
```

Do not assume normal Eloquent collection processing is safe.

---

# 124. Agent — 1M+ Records

If a table may contain:

```text
1M+
```

records, explicitly consider:

```text
indexes
query plan
pagination
memory usage
network payload
locking
transaction duration
```

---

# 125. Agent — 10M+ Records

For:

```text
10M+
```

records, never implement bulk operations without considering:

```text
database load
index impact
locking
queue strategy
batch size
retry behavior
monitoring
rollback/recovery
```

---

# 126. Agent — Query Review

For every new complex query ask:

```text
Is the filter indexed?
Is the sort indexed?
Can this cause a full table scan?
Can this produce N+1?
How many rows can this return?
Can this be paginated?
Should this run asynchronously?
```

---

# 127. Agent — Security Review

For every new endpoint check:

```text
Authentication
Authorization
Validation
Mass assignment
Rate limiting
Sensitive fields
Audit requirements
Error exposure
```

---

# 128. Agent — API Review

For every API endpoint check:

```text
HTTP method
route naming
API version
request validation
authorization
resource response
pagination
error format
rate limit
backward compatibility
```

---

# 129. Agent — Production Safety

AI agents must NEVER:

- delete production data without explicit approval
- drop production tables
- truncate production tables
- disable authentication
- expose credentials
- modify production infrastructure blindly
- run destructive commands without confirmation
- remove security middleware
- disable authorization checks

---

# 130. Dangerous Commands

Treat these as high risk:

```bash
php artisan migrate:fresh
php artisan db:wipe
php artisan migrate:reset
php artisan migrate:rollback
```

Especially when connected to production.

Never execute destructive database commands without explicit confirmation.

---

# 131. Production Database

Never assume the configured database is disposable.

Before destructive operations verify:

```text
environment
database host
database name
```

If uncertain, stop and ask for confirmation.

---

# 132. Environment Variables

Never print or expose:

```text
APP_KEY
DB_PASSWORD
AWS_SECRET_ACCESS_KEY
API_SECRET
private keys
tokens
```

---

# 133. Git Safety

Do not use destructive Git commands without explicit approval:

```bash
git reset --hard
git clean -fd
git push --force
```

Never discard user changes unintentionally.

---

# 134. Existing Changes

Before modifying files:

```bash
git status
```

Understand existing uncommitted changes.

Do not overwrite unrelated user work.

---

# 135. Testing Before Completion

Before declaring a task complete:

1. Run relevant tests.
2. Run static analysis when available.
3. Run formatter when appropriate.
4. Check migrations.
5. Check changed files.
6. Check API behavior.
7. Check authorization.
8. Check performance-sensitive queries.

---

# 136. Completion Report

When finishing a task, report:

```text
Changed:
- ...

Added:
- ...

Database:
- ...

Tests:
- ...

Potential risks:
- ...

Notes:
- ...
```

Keep the report concise.

---

# 137. Definition of Done

A feature is considered complete when:

```text
[ ] Business logic implemented
[ ] Validation implemented
[ ] Authorization implemented
[ ] API response implemented
[ ] Database changes implemented if needed
[ ] Audit logging implemented if required
[ ] Queue implemented if required
[ ] Tests implemented
[ ] Existing tests still pass
[ ] No debug code remains
[ ] No secrets exposed
[ ] Performance considered
[ ] Documentation updated when necessary
```

---

# 138. Golden Rules

The following rules have the highest priority:

## Rule 1

**Do not make the architecture more complicated than the business problem.**

## Rule 2

**Controllers should be thin.**

## Rule 3

**Business logic belongs in Services/Actions.**

## Rule 4

**Complex database logic belongs in Queries.**

## Rule 5

**Do not create repositories without a real reason.**

## Rule 6

**Do not load large datasets into memory.**

## Rule 7

**Never trust client-side authorization.**

## Rule 8

**Critical mutations require authorization, validation, and appropriate transactions.**

## Rule 9

**Large operations belong in queues.**

## Rule 10

**Measure before optimizing.**

## Rule 11

**Do not introduce infrastructure complexity without a measurable requirement.**

## Rule 12

**Do not break existing APIs without explicit approval.**

## Rule 13

**Never expose secrets or sensitive information.**

## Rule 14

**Never perform destructive production operations without explicit approval.**

## Rule 15

**Prefer Laravel-native solutions and established packages over custom frameworks.**

---

# 139. Default Decision Matrix

When deciding where code belongs:

| Problem | Location |
|---|---|
| HTTP routing | `routes/` |
| Request validation | `Http/Requests/` |
| HTTP response | `Http/Resources/` |
| HTTP orchestration | `Http/Controllers/` |
| Business workflow | `Domain/*/Services/` |
| Single business operation | `Domain/*/Actions/` |
| Complex query | `Domain/*/Queries/` |
| Database entity | `Domain/*/Models/` |
| Data transfer structure | `Domain/*/DTOs/` |
| Async processing | `Jobs/` |
| Side effect trigger | `Events/` |
| Side effect handler | `Listeners/` |
| Authorization | `Policies/` |
| Notifications | `Notifications/` |
| Shared infrastructure | `Support/` |
| Roles/permissions | Spatie Permission |
| Audit trail | Spatie Activitylog |
| Backup | Spatie Backup |
| API filtering | Spatie Query Builder |
| Queue monitoring | Horizon |
| Application monitoring | Pulse |
| Authentication | Sanctum |

---

# 140. Default Architecture Example

For a Credit module:

```text
app/
└── Domain/
    └── Credit/
        ├── Models/
        │   └── Credit.php
        │
        ├── Services/
        │   └── CreditService.php
        │
        ├── Actions/
        │   ├── CreateCredit.php
        │   ├── ApproveCredit.php
        │   ├── RejectCredit.php
        │   ├── ReconcileCredit.php
        │   └── ExportCredit.php
        │
        ├── Queries/
        │   ├── CreditListQuery.php
        │   ├── CreditSearchQuery.php
        │   └── CreditReportQuery.php
        │
        └── DTOs/
            └── CreditData.php
```

HTTP:

```text
app/
└── Http/
    ├── Controllers/
    │   └── API/
    │       └── V1/
    │           └── CreditController.php
    │
    ├── Requests/
    │   └── API/
    │       └── V1/
    │           └── Credit/
    │               ├── StoreCreditRequest.php
    │               └── UpdateCreditRequest.php
    │
    └── Resources/
        └── API/
            └── V1/
                └── CreditResource.php
```

Queue:

```text
app/
└── Jobs/
    ├── Import/
    │   └── ImportCredit.php
    ├── Export/
    │   └── ExportCredit.php
    └── Report/
        └── GenerateCreditReport.php
```

---

# 141. Example Request Flow

For:

```http
POST /api/v1/credits/100/approve
```

Use:

```text
Route
 ↓
Authorization
 ↓
FormRequest
 ↓
CreditController
 ↓
ApproveCredit Action
 ↓
DB Transaction
 ↓
Credit Model
 ↓
Commit
 ↓
CreditApproved Event
 ↓
Listeners
 ↓
Audit / Notification / Statistics
 ↓
CreditResource
 ↓
HTTP Response
```

Do not implement the entire flow inside the controller.

---

# 142. Final Architectural Rule

When in doubt, prefer this:

```text
Simple problem
    ↓
Laravel-native solution

Business workflow
    ↓
Service / Action

Complex query
    ↓
Query class

Large operation
    ↓
Job + Queue

Side effect
    ↓
Event + Listener

Authorization
    ↓
Policy / Permission

Audit
    ↓
Activity Log

Large data
    ↓
Index + Pagination + Chunk/Cursor

Performance problem
    ↓
Measure → Optimize → Measure

Architectural complexity
    ↓
Add only when justified
```

The objective is not to build the most sophisticated architecture.

The objective is to build a system that remains:

**fast, secure, understandable, testable, maintainable, and scalable.**