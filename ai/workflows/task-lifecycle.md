# Task Lifecycle

## Goal

Standardize task states across all agents.

---

## Mandatory Task Updates

Task files are the operational source of truth.

Agents MUST update the task file:
- before implementation
- after major progress
- before commit
- before push
- before handoff
- before marking task completed

Required sections:
- Progress Log
- Tests
- Review Notes
- updated_at

---

# Lifecycle

```text
TODO
IN_PROGRESS
BLOCKED
TESTING
IN_REVIEW
DONE
MERGED
ARCHIVED
```

---

# Status Definitions

## TODO

Task exists but work has not started.

---

## IN_PROGRESS

An agent is actively working on the task.

The task should normally be locked.

---

## BLOCKED

Work cannot continue.

Examples:
- missing information
- failing dependency
- quota limitation
- unresolved bug

Blockers must be documented.

---

## TESTING

Implementation is complete.

Validation is ongoing.

Examples:
- feature tests
- browser validation
- responsive checks
- Playwright testing

---

## IN_REVIEW

Task is ready for human or agent review.

---

## DONE

Implementation and validation are complete.

Waiting for merge.

---

## MERGED

Changes merged into target branch.

Version automatically bumped to `v0.{TASK_ID}-alpha` by `merge-task.sh`.

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- Version bump is enforced by `merge-task.sh`
- `finalize-task.sh` does NOT update `VERSION`

---

## MERGED

Changes merged into target branch.

---

## ARCHIVED

Task is closed and archived.

---

# Important Rules

Task status must always reflect reality.

Agents must update:
- timestamps
- logs
- handoffs
- lock state
