# OPNG — Development Instructions

## 1. General principles

- Treat this repository as an existing production-oriented application. Prioritize correctness, security, data integrity, maintainability and backward compatibility.
- Prefer simple, explicit and well-established solutions over unnecessary abstractions, dependencies or architectural changes.
- Understand the existing architecture and conventions before modifying code. Do not introduce a new pattern when an established project pattern already solves the problem.
- Keep changes focused on the requested task or approved development phase.
- Do not implement optional features, unrelated refactoring or speculative improvements without explicit approval.
- Preserve existing functionality unless a change is explicitly required.

## 2. Before making changes

- Inspect the relevant code, documentation, tests and Git status before starting.
- Read the applicable audit report and development plan when the task refers to an audit finding or development phase.
- Identify dependencies, affected components, potential regressions and verification requirements.
- For non-trivial changes, present a concise implementation plan before editing:
  1. Files and components affected.
  2. Proposed approach.
  3. Tests and verification.
  4. Relevant risks or decisions requiring approval.
- Ask before making architectural, security-sensitive, database-schema or backward-incompatible decisions that are not already specified.
- Do not ask for approval for routine implementation details that follow established project conventions.

## 3. Git and change safety

- Never discard, overwrite, reset, clean or stash existing user changes without explicit permission.
- Inspect `git status` and the current branch before any operation that could affect the working tree.
- Do not switch branches, rebase, reset, amend or rewrite history without checking for potential conflicts with existing work and obtaining approval when necessary.
- Do not modify unrelated files or include unrelated changes in the task.
- Never create a commit, tag or release unless explicitly requested.
- Never push changes unless explicitly requested.
- Do not force-push.
- Before proposing a commit, inspect the complete diff and verify that it contains only the intended changes.
- Use clear, descriptive commit messages in English, following conventional prefixes when appropriate (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`).

## 4. Implementation and code quality

- Follow the existing language, formatting, naming and architectural conventions.
- Keep code readable and maintainable. Avoid premature optimization and unnecessary complexity.
- Validate input at appropriate trust boundaries.
- Treat database operations, authentication, authorization, session handling and user-supplied content as security-sensitive.
- Preserve transaction boundaries and data integrity.
- Consider concurrency, race conditions, error handling and failure recovery where relevant.
- Avoid exposing credentials, tokens, personal data, internal paths or sensitive information in logs, responses or documentation.
- Do not add dependencies without explaining why they are necessary and considering their maintenance and security implications.
- Do not silently change database behavior, migrations, configuration defaults or public interfaces.

## 5. Testing and verification

- Inspect the available test suite and project verification commands before choosing how to test a change.
- Reproduce the reported problem before fixing it whenever practical.
- Add or update regression tests for bugs and behavior changes.
- Run the most relevant focused tests first, followed by broader tests when appropriate.
- Run available static analysis, linting and syntax checks when relevant.
- For database changes, verify schema compatibility, migration behavior, rollback implications and data integrity.
- For security changes, verify both the intended protection and relevant negative cases.
- For performance changes, compare behavior using representative data. Do not assume that a theoretically simpler query or implementation is necessarily faster.
- Never claim that a test, check or manual verification passed unless it was actually executed and its result inspected.
- Clearly distinguish between verified results, unverified assumptions and checks that could not be performed.
- If a test cannot be executed because of missing services, configuration or environment limitations, explain the limitation and provide the exact command or manual procedure needed.

## 6. Documentation and audit reports

- Update relevant documentation whenever implementation changes affect architecture, configuration, installation, operation, security, database behavior or user-facing functionality.
- Keep documentation consistent with the actual implementation. Do not document planned behavior as if it already existed.
- When working from an audit report, preserve its finding IDs and update their status only after verification.
- Distinguish clearly between:
  - Resolved: implemented and verified.
  - Partially resolved: some mitigation exists, but the underlying issue or limitation remains.
  - Pending: not implemented or not verified.
  - Deferred: deliberately postponed, with a reason.
- Record relevant implementation decisions and their rationale so future work does not accidentally reverse them.
- Keep the development report updated with files changed, tests performed, remaining risks and manual verification requirements.
- Do not remove historical findings or decisions merely to make the report appear complete.

## 7. Phased development workflow

When working through an audit or development plan:

1. Work only on the currently approved phase and its agreed scope.
2. Review the phase objectives, associated findings, dependencies and acceptance criteria.
3. Present the implementation plan when the work is non-trivial.
4. Implement incrementally and verify each logical change.
5. Review the complete diff for regressions, security implications and unrelated modifications.
6. Update tests, documentation and the development report.
7. Summarize completed work, verification results, unresolved issues and required manual checks.
8. Stop and wait for user review before starting the next phase.

Do not automatically advance to another phase, even when the current phase appears complete.

## 8. Manual validation and completion

- Clearly identify anything that requires validation by a human, especially user-interface behavior, authentication flows, email delivery, deployment, backups and real database operations.
- Provide reproducible steps for manual validation.
- Do not mark a phase or finding as fully complete while required checks remain outstanding.
- Do not create commits or releases as part of phase completion unless explicitly authorized.

## 9. Communication

- Communicate with the user in Spanish (Spain).
- Keep explanations concise, technical and precise.
- Use English for source code identifiers, comments, commit messages and technical documentation unless the existing project convention requires otherwise.
- Explain important trade-offs and risks instead of silently choosing between materially different approaches.
- Challenge assumptions when evidence suggests a proposed solution is incorrect or unnecessarily complex.
- Do not invent implementation details, test results, project conventions or completed work.