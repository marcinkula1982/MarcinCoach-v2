# Cutover Roles and Owners

## Purpose
Single decision map for PHP-only cutover and rollback.

## Owner Assignment (fill before cutover)
- Go/No-Go Decision Owner: `[TO BE ASSIGNED: Go/No-Go Decision Owner]` (Marcin Kula — Project Owner)
- Rollback Decision Owner: `[TO BE ASSIGNED: Rollback Decision Owner]` (Marcin Kula — Project Owner)
- Rollback Execution Owner: `[TO BE ASSIGNED: Rollback Execution Owner]` (Marcin Kula — Project Owner)
- App Validation Owner: `[TO BE ASSIGNED: App Validation Owner]` (Marcin Kula — Project Owner)
- Communications Owner: `[TO BE ASSIGNED: Communications Owner]` (Marcin Kula — Project Owner)

## Required Roles
- **Incident Commander (IC)**: coordinates timeline, keeps single source of truth.
- **Go/No-Go Decision Owner**: gives final go/no-go decision before traffic switch.
- **Rollback Decision Owner**: makes rollback decision when triggers are met.
- **Rollback Execution Owner**: executes traffic switch and rollback actions.
- **App Validation Owner**: runs smoke checks and confirms pass/fail.
- **Communications Owner**: sends user and stakeholder updates.

## Decision Rules
- Go-live requires all Go/No-Go checks in checklist to PASS.
- Any rollback trigger requires immediate escalation to Rollback Decision Owner.
- If trigger persists and no immediate fix is available, default action is rollback.

## Sign-off Points
- **Pre-switch sign-off**: IC + Go/No-Go Decision Owner + Rollback Execution Owner.
- **T+30m sign-off**: App Validation Owner + IC.
- **T+24h sign-off**: Rollback Decision Owner authorizes Node decommission phase.

## Logging Requirements
- Record owner names for all required roles before cutover starts.
- Record every major decision with UTC timestamp and rationale.
