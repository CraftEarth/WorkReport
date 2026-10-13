# WorkReport Timeline

WorkReport Timeline is a private workplace complaint, incident, evidence, and compliance documentation platform built with PHP and SQLite.

The system is designed to give workers a structured place to document workplace concerns while helping authorized administrators review patterns, preserve evidence, identify recurring issues, and surface potential legal or compliance concerns for human review.

## Purpose

Workplace complaints are often scattered across verbal conversations, text messages, emails, handwritten notes, and memory.

WorkReport Timeline creates a consistent record of workplace events by allowing workers to document concerns while the information is still fresh.

The platform focuses on:

- Workplace complaints
- Harassment concerns
- Retaliation concerns
- FMLA and leave-related issues
- Discrimination concerns
- Safety incidents
- Supervisor or management conduct
- Disciplinary actions
- Attendance issues
- Quality disputes
- Work assignment concerns
- Scheduling issues
- Wage or pay concerns
- Accommodation concerns
- Witness statements
- Supporting evidence

The platform is intended to organize facts and surface patterns without automatically determining that misconduct or a legal violation occurred.

## Core Principle

WorkReport Timeline separates information into distinct layers:

```text
Original Worker Statement
        ↓
Structured Facts
        ↓
Detected Patterns
        ↓
Potential Legal / Compliance Match
        ↓
Official Resources
        ↓
Human Review
```

The original worker statement remains the source record.

The system should never replace a worker's original statement with generated content or automatically declare an individual or company guilty of wrongdoing.

## Current Features

### Daily Workplace Reports

Workers can document workplace events using natural language.

Reports currently support:

- Date
- Shift
- Department
- Machine or workstation
- Report title
- Original worker statement
- Draft and finalized status
- SHA-256 fingerprinting of finalized reports

### Automatic Report Analysis

The local analyzer can extract and organize:

- People
- Supervisors
- Coworkers
- Work areas
- Machines
- Locations
- Categories
- Approximate times
- Individual events

Workers can review and correct detected information before it is saved.

### Local Knowledge System

The analyzer can learn organization-specific terminology.

Examples:

```text
Brent → Brent Knight
Josh → Joshua Yates
Ford → Ford Service
E2 → Eagle 2
```

Custom phrases can also be connected to report categories.

### Timeline

Reports and extracted events can be viewed chronologically.

This allows a workplace history to develop over time rather than treating every complaint as an isolated incident.

### Case Analysis

The system can analyze reports across a worker's documentation history and identify recurring information such as:

- Repeated people
- Repeated supervisors
- Repeated machines or work areas
- Recurring complaint categories
- First and most recent occurrence
- Event counts
- Documented patterns

### Final Report Generator

Authorized users can generate structured reports using:

- Date range
- Person
- Category
- Chronological view
- Detailed view

Generated reports maintain links back to the original source reports.

### Evidence Management

Reports can contain supporting evidence including:

- Photos
- Screenshots
- Documents
- Schedules
- Write-ups
- Messages
- Quality records
- Attendance records

Evidence files receive SHA-256 fingerprints and remain connected to the report where they were submitted.

### Report Management

The application supports:

- Draft deletion
- Evidence deletion
- Clearing and rebuilding structured analysis
- Protection of finalized reports

Finalized reports are not silently deleted.

## Users and Workers

The system distinguishes between a **User** and a **Worker**.

### User

A user is a person authorized to log into the application.

### Worker

A worker is the employee whose workplace documentation is being stored.

This separation allows administrators or compliance personnel to have authorized access to multiple worker files without mixing their records.

## Roles

The authentication system is designed around three roles.

### Super Admin

Super Admins may:

- Create administrators
- Create users
- Create workers
- Manage system permissions
- Access authorized workforce reporting
- Manage system configuration

### Admin

Admins may:

- View authorized worker records
- Review reports
- Review evidence
- Analyze workplace patterns
- Generate reports
- Perform compliance review

### User

Standard users are limited to worker files specifically assigned to them.

## Worker Privacy and Isolation

Every worker must have a separate documentation history.

```text
Worker A
    ├── Reports
    ├── Evidence
    ├── Timeline
    └── Analysis

Worker B
    ├── Reports
    ├── Evidence
    ├── Timeline
    └── Analysis
```

Records must not be mixed between workers.

User permissions determine which worker files a logged-in account may access.

## Legal and Compliance Analysis

WorkReport Timeline is being designed to identify reports that may require legal or compliance review.

The platform follows a neutral model:

```text
Documented Fact
        ↓
Objective Pattern
        ↓
Potential Legal Issue
        ↓
Official Legal Source
        ↓
Human Review Required
```

The application should use language such as:

> Possible FMLA retaliation concern requiring review.

It should not state:

> The company violated the FMLA.

Legal determinations belong to qualified professionals and appropriate agencies.

## Worker Assistance

When a report contains language associated with a potentially serious workplace issue, the worker may be shown relevant educational and assistance resources.

Example:

```text
Worker reports unwanted physical touching
        ↓
Possible harassment issue detected
        ↓
Worker receives:
- Workplace rights information
- Official government resources
- Reporting guidance
- Lawyer referral resources
```

The worker receives this information immediately rather than requiring an administrator to notice the report first.

## Legal / Compliance Escalation

Not every complaint should be treated as a legal concern.

Only reports that match defined legal or compliance rules should enter the escalation system.

Examples may include:

- Possible sexual harassment
- Possible retaliation
- Possible FMLA interference
- Possible FMLA retaliation
- Possible discrimination
- Possible disability accommodation issue
- Possible pregnancy discrimination
- Possible wage violation
- Possible workplace safety issue
- Possible retaliation for protected activity

The system may later recognize recurring legally relevant events over time.

Example:

```text
90-Day Worker Review

Reports: 31
Legally Flagged Reports: 6

Possible FMLA Issues: 3
Possible Retaliation Issues: 2
Possible Harassment Issue: 1

System Status:
Human review recommended
```

## Cross-Worker Pattern Detection

Authorized administrators may eventually identify patterns across multiple independent worker reports.

Example:

```text
Person Mentioned:
Supervisor A

Mentioned By:
4 Workers

Related Reports:
11

Potential Compliance Flags:
3

System Recommendation:
Management or compliance review may be appropriate.

No determination of misconduct has been made.
```

The purpose is to surface facts that may otherwise remain disconnected across multiple employee complaints.

## Administrative Review

The long-term admin workflow is:

```text
Worker Report
    ↓
Structured Analysis
    ↓
Potential Compliance Flag
    ↓
Pattern Detection
    ↓
Admin Review
    ↓
Investigation / Meeting
    ↓
Action or Follow-Up
    ↓
Documented Resolution
```

Admins should be able to review source reports, evidence, timelines, recurring individuals, categories, and legal resources before beginning an internal investigation or employee meeting.

## Know Your Rights

The platform currently begins with Kentucky workplace resources.

The legal-resource architecture is designed to support additional jurisdictions later.

Future structure:

```text
Worker
    ↓
State / Jurisdiction
    ↓
State Employment Resources
    +
Federal Employment Resources
```

Examples:

```text
KY → Kentucky resources + federal law
IN → Indiana resources + federal law
TN → Tennessee resources + federal law
IL → Illinois resources + federal law
```

The long-term goal is support for all U.S. states.

## Organization Support

Future versions are intended to support multiple organizations.

Planned structure:

```text
Organization
    │
    ├── Super Admins
    ├── Administrators
    ├── Users
    └── Workers
         ├── Reports
         ├── Events
         ├── Evidence
         ├── Legal Flags
         └── Analysis
```

This will allow separate companies or organizations to deploy WorkReport Timeline without sharing data.

## Technology

Current stack:

- PHP
- SQLite
- HTML
- CSS
- JavaScript
- Apache / localhost development environment

The application is currently being developed as a local-first system.

## Privacy

Workplace documentation may contain sensitive information.

The project is designed around keeping operational data separate from application source code.

Private data includes:

```text
storage/reports.sqlite
storage/attachments/
storage/backups/
```

These files are excluded from Git through `.gitignore`.

The GitHub repository contains application source code only.

## Security Goals

Current and planned security features include:

- Password hashing using PHP password APIs
- User authentication
- Role-based authorization
- Worker-level access controls
- Worker record isolation
- Evidence access controls
- SHA-256 document and evidence fingerprinting
- Protected storage directories
- Session security
- Admin audit logging
- Disabled account support
- Permission-based worker access

## Development Status

WorkReport Timeline is under active development.

Current development priorities:

1. Complete worker-level record isolation.
2. Expand daily incident data extraction.
3. Add worker-facing legal resource recommendations.
4. Add legal/compliance flagging.
5. Add admin compliance review tools.
6. Add cross-worker pattern detection.
7. Add administrative review workflows.
8. Expand jurisdiction support beyond Kentucky.
9. Add organization-level deployment support.
10. Build secure export and evidence packages.

## Project Philosophy

WorkReport Timeline is built around four principles:

**Document the facts.**

**Preserve the evidence.**

**Identify the pattern.**

**Let humans determine the outcome.**

The platform is not intended to replace attorneys, human resources professionals, investigators, government agencies, or courts.

Its purpose is to make workplace concerns harder to lose, ignore, misunderstand, or separate from the evidence supporting them.

---

**Status:** Active Development  
**Repository:** CraftEarth / WorkReport-Timeline
