# KMTGuard Agent Instructions

These instructions apply to every agent working inside the KMTGuard repository.

Follow the most specific instruction available for the current task. A window-specific or feature-specific reference takes precedence over general project instructions.

---

## 1. Project Reference

Before analyzing, debugging, modifying, or adding anything to the project, read:

```text
PROJECT_REFERENCE.md
```

Use it to understand:

* Project architecture and component relationships.
* Important applications, DLLs, services, and entry points.
* Startup and shutdown flows.
* Client hooks and custom UI classes.
* Packets, opcodes, and handlers.
* Database tables, stored procedures, and command queues.
* Feature locations, configuration files, and logs.
* Known issues and sensitive areas.

`PROJECT_REFERENCE.md` is a navigation and context reference only.

Do not treat it as a replacement for the current source code. Before making any change, verify all task-related information directly from the latest source files.

If the reference conflicts with the current source code, the current source code is authoritative.

Do not modify `PROJECT_REFERENCE.md` unless the user explicitly requests a documentation update or the current task requires keeping the reference synchronized.

---

## 2. Player-Facing Text and Localization

Before adding or changing any player-facing text in the Client DLL, Filter, custom packets, notices, events, or language files, read:

```text
docs/player_localization_reference.md
```

Do not introduce hardcoded player-facing wording.

Use the correct text owner:

```text
Client-owned interface text -> Client TextUISystem resources
Filter-generated text       -> External Filter language JSON files
```

This applies to:

* Client windows.
* Buttons and labels.
* Notices.
* Event messages.
* Custom packet text.
* Errors and confirmations.
* Player-facing system messages.

---

## 3. Client UI Design

Before creating or visually changing any Client UI, read:

```text
docs/client_ui_design_reference.md
```

Named UI styles are routed explicitly:

```text
Item Mall  -> docs/client_ui_design_reference.md
Old School -> docs/client_ui_old_school_reference.md
```

### Item Mall Style

The Killer Animation Studio is the canonical Item Mall design reference.

### Old School Style

When the user explicitly requests `Old School`, read the following file completely:

```text
docs/client_ui_old_school_reference.md
```

Use the current Secondary Password window as the canonical Old School implementation.

Do not mix Item Mall and Old School elements in the same window, including:

* Window chrome.
* Background panels.
* Input fields.
* Buttons.
* Close controls.
* Borders.
* Titles.
* Decorative elements.

An existing window-specific design reference always takes precedence over the general UI references.

---

## 4. Drop Logs UI

Before changing the Drop Logs Client UI, read:

```text
docs/droplogs_ui_reference.md
```

When the request is UI polish only, do not modify:

* Packets.
* Opcodes.
* Filter code.
* SQL.
* Stored procedures.
* Existing feature behavior.

Keep the Drop Logs layout resinfo-driven through:

```text
JTClientLibrary/media/clientlibrary/resinfo/ifdroplogswnd.txt
```

Do not move layout ownership into hardcoded C++ unless explicitly required by the task.

---

## 5. Final Build Output

The final delivery path is fixed:

```text
D:\KMTGuard-build
```

Do not create or use an alternative final delivery path unless the user explicitly changes this rule.

Any agent producing a final build must copy the latest outputs into:

```text
D:\KMTGuard-build\Filter
D:\KMTGuard-build\DLL
D:\KMTGuard-build\Media
```

### Filter Output

The latest built Filter output must be copied to:

```text
D:\KMTGuard-build\Filter
```

The main executable must be available as:

```text
D:\KMTGuard-build\Filter\KMTGuard.exe
```

### Client DLL Output

The latest built Client DLL must be copied to:

```text
D:\KMTGuard-build\DLL
```

The main DLL must be available as:

```text
D:\KMTGuard-build\DLL\KMTGuardKit.dll
```

### Generated Media Output

The latest generated Client media and resource files must be copied while preserving their folder structure:

```text
D:\KMTGuard-build\Media\clientlibrary
D:\KMTGuard-build\Media\media
D:\KMTGuard-build\Media\client-resources
```

Only produce a build when the user requests one or when building is explicitly required by the task.

---

## 6. Database Package Layout

Developer/Test and customer Filter packages must use this database update layout:

```text
Filter\database\vMAJOR.MINOR.PATCH
```

Example:

```text
Filter\database\v2.7.12
```

Executable update scripts must be placed directly inside their owning version folder.

Optional verification scripts must be placed inside:

```text
Filter\database\vMAJOR.MINOR.PATCH\validation
```

Do not:

* Place SQL files directly in the `Filter` root.
* Generate a packaged `Filter\database\migrations` folder.
* Assign one SQL source file to multiple releases.
* Package SQL files that are not assigned to a release.

The required package assignment manifest is:

```text
database\package-versions.psd1
```

Every SQL source file must be assigned exactly once to a version that exists in:

```text
CHANGELOG.md
```

Publishing must fail when a SQL file is:

* Unassigned.
* Assigned more than once.
* Missing.
* Assigned to an unknown version.
* Assigned to a version not present in `CHANGELOG.md`.

Keep these checks enforced for both:

```text
Developer/Test
CustomerProductionBase
```

---

## 7. Customer Media Source

The fixed complete media source for licensed customer packages is:

```text
D:\KMTGuard-build\Media-KemtGuard
```

Copy its contents into customer packages exactly as they exist.

It contains the top-level folders:

```text
Client-import
Media
```

Do not replace it with, merge it with, or generate it from:

```text
D:\KMTGuard-build\Media
```

These locations have different purposes:

```text
D:\KMTGuard-build\Media
```

Generated Developer/Test media output.

```text
D:\KMTGuard-build\Media-KemtGuard
```

Fixed complete media source for licensed customer packages.

---

## 8. Publishing Workflow

After source changes, use:

```text
scripts\Publish-KmtGuardDeveloper.ps1
```

This publishing process must refresh both:

* Developer/Test delivery.
* Licensed `CustomerProductionBase`.

Refresh both outputs before generating customer packages.

Do not publish or generate customer packages unless the current task requires it.

---

## 9. Required Customer Changelog

The repository-root file below is required:

```text
CHANGELOG.md
```

No change is complete until it includes a customer-facing changelog entry.

This applies to:

* Bug fixes.
* New features.
* Performance improvements.
* System changes.
* Database changes.
* Security improvements.
* Client changes.
* Operational changes.

Keep the newest version first and preserve all previous versions.

Use semantic versioning:

```text
Patch -> Small fixes and compatible corrections
Minor -> New features or grouped improvements
Major -> Large or incompatible changes
```

Use the actual release date.

Write only clear customer-facing outcomes.

Do not include:

* Source code.
* Internal class names.
* Internal function names.
* Implementation details.
* Repository paths.
* Memory addresses.
* Hook details.
* Protection or licensing internals.
* Information that could expose how security mechanisms work.

Clearly state when an update requires:

* Server restart.
* Filter restart.
* SQL update.
* Client DLL replacement.
* Media update.
* Additional customer action.

Omit empty sections and do not duplicate entries.

---

## 10. General Safety Rules

Before changing anything:

1. Read `PROJECT_REFERENCE.md`.
2. Read the current task-related source files.
3. Read any relevant feature-specific reference.
4. Confirm the actual current implementation.
5. Keep the modification limited to the requested scope.

Do not:

* Replace complete files to solve a small localized issue.
* Change unrelated systems.
* Change packets or opcodes during a UI-only task.
* Change SQL during a Client-only task.
* Change Hook order without verifying dependencies.
* Assume documentation is newer than the source.
* Invent missing classes, procedures, columns, packets, or settings.
* Build, publish, package, or deploy unless the task requires it.

When information cannot be verified, clearly mark it as:

```text
Unknown / Needs Verification
```

---

## 11. Automatic Delegated Development Workflow

This workflow is mandatory for every future coding task in this repository. It supplements all rules above; it does not override feature-specific references, safety rules, localization rules, build-output rules, database packaging rules, or changelog requirements.

### Lead Engineer

The lead engineer must always be:

```text
Model: GPT-5.6 Sol (gpt-5.6-sol)
Reasoning: High
```

Repository-local Codex defaults are defined in:

```text
.codex/config.toml
```

If a coding session is not running with GPT-5.6 Sol at High reasoning, do not use it as the lead. Restart or switch to the required lead configuration before planning or approving implementation.

SOL owns diagnosis, architecture, the implementation plan, review, verification, corrections, and final approval. Delegated implementation is never accepted automatically.

### Phase 1 — SOL Diagnosis and Plan

Before delegation, SOL must:

1. Read `PROJECT_REFERENCE.md` and every task-relevant repository instruction or feature reference.
2. Inspect the current source code and repository state directly.
3. Understand the requested behavior, existing architecture, component relationships, and end-to-end code flow.
4. For a bug, reproduce or trace the failure where possible and identify the root cause rather than treating symptoms.
5. Decide the correct solution and produce a detailed, implementation-ready plan.
6. Name the exact files, classes, methods, procedures, resource files, tests, or code paths that must change.
7. State explicitly what must not change, including unrelated components, protocols, opcodes, SQL, UI systems, or behavior.
8. Define edge cases, compatibility concerns, verification commands, and objective acceptance criteria.
9. Inspect the current Git status and preserve any pre-existing user changes. The implementation brief must distinguish pre-existing changes from task-owned changes.

The plan must be self-contained enough for an implementer with no chat history. SOL should not spend tokens on routine implementation when the planned change can safely be delegated.

### Phase 2 — Automatic Implementer Selection

After completing the plan, SOL must classify the implementation once and select exactly one lane:

```text
Simple -> simple lane -> GPT-5.6 Luna (gpt-5.6-luna), High reasoning
Heavy  -> heavy lane  -> GPT-5.6 Terra (gpt-5.6-terra), High reasoning
```

Use the `simple` lane when implementation is clear, bounded, mechanical, repetitive, localized, or low-risk.

Use the `heavy` lane when implementation involves any meaningful multi-file coordination, non-trivial logic, refactoring, SQL or database behavior, concurrency, protocol or packet behavior, security-sensitive behavior, cross-component integration, risky compatibility constraints, or stronger coding judgment.

When uncertain, classify by implementation risk and integration complexity, not by line count. Do not use Terra when Luna is sufficient, and do not use Luna when the task is risky enough to require Terra. Do not delegate the same implementation to multiple models.

The required implementation mechanism is the repository-installed `codex-delegate` skill and relay:

```text
.agents/skills/codex-delegate/scripts/relay.mjs
```

Dispatch with exactly one configured project lane:

```text
node .agents/skills/codex-delegate/scripts/relay.mjs --brief <brief-file> --cd <repo-root> --lane simple
node .agents/skills/codex-delegate/scripts/relay.mjs --brief <brief-file> --cd <repo-root> --lane heavy
```

Use a uniquely named temporary brief outside the repository unless a task explicitly requires retaining it. The brief must include SOL's diagnosis and exact plan, allowed files and code paths, forbidden scope, relevant repository rules, actual build/test commands, edge cases, acceptance criteria, and a structured report contract. It must instruct the implementer not to stage, commit, push, redesign the architecture, expand scope, or substitute a different solution unless the plan is technically impossible. If the plan is impossible, the implementer must stop and report the concrete blocker.

The implementer may inspect files needed to execute the plan, but its role is implementation. It must not redo SOL's architecture decision or broaden the task. All implementation must remain uncommitted for SOL review.

### Phase 3 — SOL Review and Final Approval

After the relay finishes, SOL must treat its report as untrusted until verified. SOL must:

1. Read `result.json`, the implementer's final report, and the touched-file list.
2. Inspect `git status`, `git diff`, `git diff --cached`, and every changed or newly created file. Open untracked files directly because they do not appear in a normal diff.
3. Compare the complete implementation against the original diagnosis, plan, allowed scope, forbidden scope, edge cases, and acceptance criteria.
4. Detect unrelated edits, scope creep, missing work, weakened or bypassed tests, invented APIs, hidden defaults, compatibility regressions, and integration errors.
5. Re-run the relevant repository build, tests, lint, validation, or packaging checks independently. Never rely only on the implementer's claimed gate results.
6. Verify existing behavior outside the requested change remains intact, proportionate to the task's risk.
7. Fix small review findings directly when safe, or send one focused delta brief back to the same implementer session using its `threadId`. Do not start a competing implementation model.
8. Re-review the complete diff and re-run affected gates after every correction.
9. Perform final approval itself and report the selected lane, changed files, verification results, and any remaining known limitations to the user.

SOL is the only final approver. A delegated run completing successfully, producing a plausible report, or passing its own tests is not approval.

### Boundaries and Cost Control

- Use one implementation model per coding task: Luna High for simple work or Terra High for heavy work.
- SOL High performs planning, diagnosis, review, and final approval.
- Do not use generic subagents or another delegation mechanism as a substitute for `codex-delegate` implementation.
- Do not dispatch planning-only, explanation-only, read-only review, or repository-setup tasks unless they contain an actual coding implementation phase.
- Do not commit, push, publish, package, deploy, create a remote, or create a GitHub repository unless the user's task and the rules above explicitly require that action.
- Preserve pre-existing working-tree changes and never erase or overwrite them during dispatch or review.
