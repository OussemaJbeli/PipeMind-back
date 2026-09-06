# PipeMind Backend

The backend is the operational core of **PipeMind**, an intelligent CI/CD failure analysis platform.

It sits between CI/CD platforms, the PipeMind AI layer, the database, and the frontend. Its job is to keep the system connected and reliable: receive what is happening in external CI/CD systems, understand the state of projects and pipelines, orchestrate analysis jobs, store results, and expose everything through a clean API.

The backend should not try to be the AI itself. Its responsibility is to **coordinate the system and provide the context the intelligence layer needs**.

---

## What PipeMind Is

PipeMind observes CI/CD pipelines from platforms such as GitLab, GitHub Actions, and Jenkins.

When a developer pushes code, PipeMind can follow the resulting pipeline, collect its events and relevant logs, detect failures, and send the right context to the AI layer for deeper analysis.

The overall idea is:

```text
Developer
   ↓
Git Push
   ↓
CI/CD Platform
   ↓
PipeMind Backend
   ↓
Pipeline Context + Logs
   ↓
PipeMind AI
   ↓
Analysis / Root Cause / Recommendations
   ↓
PipeMind Backend
   ↓
Database + API
   ↓
PipeMind Frontend
```

The backend is the layer that makes this flow possible.

---

## Main Responsibilities

The backend is expected to handle:

* Authentication and authorization
* Users, teams and projects
* CI/CD integrations
* Webhooks and external events
* Pipeline and job tracking
* Log collection and normalization
* Queue-based processing
* Redis communication
* Database persistence
* AI analysis orchestration
* Failure and analysis history
* Recommendations and remediation workflows
* Notifications
* API consumed by the frontend
* Security around external integrations and automated actions

The backend should remain the **source of truth for application state**.

---

## CI/CD Integrations

PipeMind is designed to work with multiple CI/CD platforms rather than being tied to one provider.

Initial integrations may include:

* GitLab CI/CD
* GitHub Actions
* Jenkins

The integration layer should ideally hide provider-specific differences behind a common internal model.

For example, PipeMind should be able to represent:

```text
Pipeline
Job
Stage
Status
Branch
Commit
Logs
Duration
Failure
Artifact
```

regardless of whether the information came from GitLab, GitHub Actions, or Jenkins.

A provider adapter approach is encouraged so that adding another CI/CD platform does not require rewriting the core application.

---

## Event Flow

A typical pipeline might look like this:

```text
GitLab
   │
   │ webhook
   ▼
PipeMind Backend
   │
   ├── validate event
   ├── identify project
   ├── update pipeline
   ├── store event
   └── dispatch jobs
            │
            ▼
         Redis
            │
            ▼
       Queue Worker
            │
            ▼
     Build analysis context
            │
            ▼
       PipeMind AI
```

The backend should react to events rather than constantly relying on expensive polling wherever the provider offers reliable webhooks or event APIs.

Polling can still be used when necessary.

---

## Queue and Redis

CI/CD monitoring and AI analysis can involve operations that should not block normal API requests.

Laravel queues and Redis are therefore an important part of the architecture.

Examples of background jobs include:

```text
ProcessPipelineEvent
FetchPipelineLogs
NormalizeLogs
AnalyzeFailure
StoreAIAnalysis
GenerateRecommendation
SendNotification
ExecuteApprovedRemediation
```

The exact job structure can evolve as the system grows.

The important principle is that expensive or asynchronous operations should be handled through background processing rather than making the main API wait unnecessarily.

---

## The AI Boundary

PipeMind has a dedicated Python AI service.

The backend communicates with that service when intelligence is required.

A simplified interaction is:

```text
CI/CD
  ↓
Laravel
  ↓
Collect context
  ↓
Python AI
  ↓
Analyze
  ↓
Structured result
  ↓
Laravel
  ↓
Store result
```

The backend should provide the AI layer with useful context rather than blindly forwarding raw logs.

Context can include:

```text
Project information
Pipeline information
Branch
Commit
Changed files
Failed job
Relevant logs
Previous pipeline state
Historical failures
Project configuration
Relevant documentation
```

The AI service is responsible for reasoning and intelligence.

The backend is responsible for **collecting, controlling, storing, and delivering that context**.

---

## Analysis Results

AI results should preferably be represented as structured data rather than only a block of generated text.

A failure analysis may contain:

```text
Failure category
Severity
Confidence
Root cause
Evidence
Similar historical failures
Recommendations
Possible remediation
Risk level
```

For example:

```json
{
  "category": "authentication",
  "severity": "high",
  "confidence": 0.91,
  "root_cause": "Authorization token is not attached to API requests.",
  "evidence": [],
  "recommendations": [],
  "remediation": {
    "available": true,
    "requires_approval": true
  }
}
```

The exact schema should evolve with the AI capabilities.

---

## Remediation and Automation

One of PipeMind's longer-term goals is not only to explain failures but potentially help resolve them.

However, the backend should remain the **control and security boundary** for automated actions.

The AI layer can recommend:

```text
Retry pipeline
Re-run failed job
Create patch
Update configuration
Open issue
Create merge request
```

But the backend should decide whether the requested action is:

```text
Allowed automatically
Requires user approval
Not allowed
```

A useful mental model is:

```text
AI suggests
    ↓
Backend validates
    ↓
Policy / permission check
    ↓
Approval if required
    ↓
Backend executes
    ↓
CI/CD platform
```

AI should not receive unrestricted credentials or direct authority over infrastructure.

---

## API

The backend exposes the main API consumed by PipeMind Front.

Possible areas include:

```text
/api/auth
/api/projects
/api/integrations
/api/pipelines
/api/jobs
/api/failures
/api/analyses
/api/recommendations
/api/remediations
/api/notifications
```

The API should expose application state and actions while keeping provider-specific implementation details internal.

The frontend should not need to know whether a pipeline came from GitLab, GitHub Actions, or Jenkins beyond the information relevant to the UI.

---

## Database

Laravel owns the application's database migrations and persistence layer.

The database should contain the operational history of PipeMind, including things such as:

```text
Users
Projects
Integrations
Repositories
Pipelines
Pipeline Jobs
Pipeline Events
Logs
Failures
Analyses
Recommendations
Remediations
Notifications
```

The complete database design and diagrams belong in the **PipeMind-data** repository.

This repository should contain the implementation of that design through Laravel migrations, models, relationships, and application logic.

---

## Backend ↔ Frontend

The frontend communicates with Laravel.

```text
PipeMind Front
       │
       │ REST API
       ▼
PipeMind Back
       │
       ▼
PostgreSQL
```

The frontend should not communicate directly with GitLab, GitHub, Jenkins, Redis, or the Python AI service for normal application operations.

Laravel acts as the controlled gateway.

---

## Backend ↔ AI

The Python service is an independent component.

```text
PipeMind Back
      │
      │ internal API
      ▼
PipeMind AI
```

The communication contract should be explicit and versionable.

The backend should be able to request operations such as:

```text
Analyze failure
Classify failure
Find similar failures
Generate recommendations
Predict/analyze anomalies
```

while the AI repository remains responsible for the implementation of those capabilities.

---

## Suggested Technology

The initial backend is expected to use:

```text
Laravel 12
PHP
PostgreSQL
Redis
Laravel Queues
REST API
Docker
```

Additional technologies may be introduced when they solve a real architectural or scalability problem.

The project should avoid adding infrastructure simply because it is technically interesting.

---

## Design Principles

PipeMind should favor:

**Provider independence**
The core system should not be tightly coupled to a single CI/CD platform.

**Clear boundaries**
Application orchestration belongs here; AI reasoning belongs in PipeMind AI.

**Asynchronous processing**
Heavy operations should use queues whenever appropriate.

**Structured data**
Important system information should be represented as data, not hidden inside generated text.

**Security first**
External credentials, webhooks, automated actions, and remediation must be handled carefully.

**Traceability**
A developer should be able to understand where an analysis came from: pipeline → job → logs → evidence → AI analysis → recommendation.

**Extensibility**
New CI/CD providers, AI providers, analysis techniques, and remediation actions should be possible without redesigning the entire backend.

**Pragmatism**
Use the simplest architecture that solves the current problem. Complexity should be earned.

---

## Repository Boundary

This repository is responsible for the **operational/application side of PipeMind**.

### Belongs here

```text
Laravel application
API
Authentication
Business logic
Database migrations
Models
Queues
Redis integration
CI/CD integrations
Webhooks
AI orchestration
Permissions
Remediation control
```

### Does not primarily belong here

```text
Vue UI
Machine-learning model implementation
AI experimentation
Model training
Large datasets
Academic research
Stage report
System-wide documentation
```

Those responsibilities are handled by the other PipeMind repositories.

---

## Relationship With Other Repositories

```text
PipeMind-front
    │
    │ API
    ▼
PipeMind-back
    │
    ├──────────────► PostgreSQL
    │
    ├──────────────► Redis
    │
    ├──────────────► GitLab / GitHub / Jenkins
    │
    └──────────────► PipeMind-ai
                            │
                            ├── ML
                            ├── RAG
                            ├── LLM
                            └── Intelligence
                            
PipeMind-data
    │
    └── Shared project knowledge,
        schema, datasets, research
        and documentation
```

The repositories are separate, but they form one system.

---

## The Bigger Idea

PipeMind should feel less like another CI dashboard and more like an **intelligent operational layer around CI/CD**.

A pipeline tells a developer:

> "The tests failed."

PipeMind should eventually be able to answer:

> "The tests failed because of X. Here is the evidence. This is similar to a previous failure that was fixed by Y. Your current change appears to be related because of Z. Here is the safest next action, and I can execute it if you approve."

The backend exists to make that intelligence reliable, traceable, secure, and usable in a real development workflow.

The architecture should therefore remain open to better ideas as PipeMind evolves. The documented structure is a foundation, not a restriction.


## run project
- php artisan serve
- php artisan queue:work --queue=ingestion,logs,analysis,metrics,default
- php artisan pipemind:tunnel
