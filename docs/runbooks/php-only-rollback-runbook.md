# PHP-only Rollback Runbook

## Purpose
Fast rollback procedure if PHP-only cutover is unhealthy.

## Decision and Ownership
- Go/No-Go Decision Owner: Marcin Kula — Project Owner
- Rollback Decision Owner: Marcin Kula — Project Owner
- Rollback Execution Owner: Marcin Kula — Project Owner
- App Validation Owner: Marcin Kula — Project Owner
- Communications Owner: Marcin Kula — Project Owner

See also: [docs/operations/cutover-roles-and-owners.md](docs/operations/cutover-roles-and-owners.md)

## Rollback Triggers
- Sustained elevated 5xx for 10 minutes.
- Critical auth/session break (`login/me/profile` unusable).
- Smoke phase fails at `T+5m`, `T+30m`, or `T+2h`.
- Critical API path unavailable (`/api/health`, `/api/workouts/import`).

## Steps
1. Declare rollback in incident channel and record timestamp.
2. Switch API traffic back to Node deployment target.
3. Verify Node health endpoint (`/health`) is 200.
4. Run quick verification:
   - login path on Node
   - workout import/list path on Node
   - integrations entrypoints reachable
5. Freeze further cutover changes until incident review.
6. Publish user-facing update (degraded mode / rollback notice).

## Validation After Rollback
- Error rates return near pre-cutover baseline.
- Core login + workout flow works again.
- No traffic remains on PHP target unexpectedly.

## Data Written During Cutover Window

### Kontekst
Między `T+0` (przełączenie ruchu na PHP) a momentem rollbacku użytkownicy mogli:
- zarejestrować nowe konto w PHP,
- zaimportować workouty do PHP,
- zaktualizować profil w PHP.

Te dane istnieją w bazie PHP i nie zostały przeniesione do Node.

### Decyzja
**Dane zapisane w PHP podczas okna cutoveru NIE są migrowane z powrotem do Node po rollbacku.**

Uzasadnienie:
- Cutover model to "fresh start" — brak migracji danych między Node a PHP w obie strony.
- Migracja danych z okna cutoveru jest technicznie możliwa, ale ryzykowna i niepotrzebna dla fazy beta.
- Workouty można ponownie zaimportować przez Garmin/Strava lub ręczny upload po ponownym cutoverze.

### Co użytkownik powinien wiedzieć
- Komunikacja po rollbacku powinna zawierać: "Twoje treningi zaimportowane w ciągu ostatnich [X godzin] mogą wymagać ponownego importu."
- Konta założone w PHP podczas cutoveru NIE działają w Node — użytkownik musi użyć swojego poprzedniego konta Node lub poczekać na kolejny cutover.

### Co należy utrwalić przed rollbackiem (jeśli czas pozwala)
- Liczba nowych kont założonych w PHP podczas okna cutoveru.
- Liczba workoutów zaimportowanych podczas okna cutoveru.
- Timestamp pierwszego i ostatniego zapisu w tabeli `workouts` i `users` po `T+0`.

## Evidence to Capture
- Trigger that caused rollback.
- Exact rollback decision time.
- Who approved and executed rollback.
- Post-rollback verification results.
- Data written during cutover window (user count, workout count, time range).
