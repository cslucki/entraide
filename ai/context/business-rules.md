# Business Rules

## Critical Rules

- Point ledger is append-only
- Financial operations must be atomic
- UUID architecture must never be broken
- Transaction state machine must remain valid
- Policies are mandatory for protected actions

## Transaction State Machine

pending → accepted → buyer_done → completed
        ↘ refused
pending/accepted → cancelled

## Points System

- New user receives welcome bonus
- Point ledger is immutable
- Balance updates must be transactional

## Safety Rules

Never modify:
- tenant integrity
- transaction consistency
- point ledger integrity
without explicit verification.