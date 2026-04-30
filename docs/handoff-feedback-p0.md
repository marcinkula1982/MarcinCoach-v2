# Handoff: P0 datowo świadomy feedback potreningowy

Data handoffu: 2026-04-30
Ostatnia aktualizacja: 2026-04-30 (po sesjach wiring + E2E)

## Stan pracy

Backend P0 jest technicznie domknięty. Brakuje UI smoke i przypadku luki między treningami w UI.

## Co zostało zrobione po pierwszym handoffie

**Sesja 1 — snapshot wiring:**
- `PlanSnapshotService::saveFromPlan()` — nowa metoda: mapuje sessions z `day` → `dateIso`, waliduje window i sessions, zapisuje best-effort.
- `WeeklyPlanController` — wstrzyknięto `PlanSnapshotService`, best-effort save po każdym `generatePlan()`.
- `RollingPlanController` — wstrzyknięto `PlanSnapshotService`, metoda `saveRollingSnapshot()`, snapshotuje oba tygodnie (current + next).
- `PlanSnapshotIntegrationTest.php` — nowy plik, 13 testów: istnienie snapshotu, source weekly/rolling, wymagane pola sessions, window columns, brak zapisu bez sessions/window, endpoint 200 mimo wyjątku serwisu.

**Sesja 2 — E2E feedback→snapshot:**
- `WorkoutsTest.php` — 2 nowe testy E2E (linie 2666 i 2761):
  - `test_feedback_uses_snapshot_written_by_weekly_plan_endpoint` — pełny łańcuch GET plan → snapshot → workout na dacie sesji → `planMatchStatus ∈ {matched, partial, unplanned, missed_related}`, `executionScore` numeryczny, `planVsExecution.planned` non-null.
  - `test_feedback_gives_no_plan_when_workout_predates_all_snapshots` — workout 90 dni przed snapshotem → `planMatchStatus ∈ {no_plan, historical}`, `planVsExecution.planned` null.

## Pliki dodane lub zmodyfikowane (łącznie wszystkie sesje)

- `backend-php/app/Services/PlanSnapshotService.php` — dodana `saveFromPlan()`.
- `backend-php/app/Http/Controllers/Api/WeeklyPlanController.php` — wstrzyknięcie + best-effort snapshot.
- `backend-php/app/Http/Controllers/Api/RollingPlanController.php` — wstrzyknięcie + `saveRollingSnapshot()`.
- `backend-php/app/Services/WorkoutPlanFeedbackService.php` — draft serwisu (podpięty do `TrainingFeedbackV2Service`).
- `backend-php/app/Services/TrainingFeedbackV2Service.php` — guard historyczny + delegacja do `WorkoutPlanFeedbackService`.
- `backend-php/tests/Feature/Api/PlanSnapshotIntegrationTest.php` — nowy, 13 testów.
- `backend-php/tests/Feature/Api/WorkoutsTest.php` — dodane: guard historyczny (EP-037) + 2 testy E2E (EP-038).
- `docs/execution-plan.md` — zaktualizowany EP-038.
- `docs/status.md` — zaktualizowany o EP-037, EP-038 snapshot wiring, EP-038 E2E.
- `docs/handoff-feedback-p0.md` — ten handoff.

## Gotowe

- Migracja `2026_04_21_150000_add_block_fields_to_plan_snapshots_table.php` dodaje kolumny używane przez `PlanSnapshotService`.
- Nie utworzono modelu `App\Models\PlanSnapshot`.
- `PlanSnapshotService` podpięty do obu kontrolerów planów.
- `WorkoutPlanFeedbackService` podpięty do `TrainingFeedbackV2Service`.
- 13 testów integracyjnych snapshotu.
- 2 testy E2E potwierdzające łańcuch snapshot → feedback.
- Kontrakt API endpointów bez zmian.

## Pozostaje do zrobienia (EP-038 nadal w NOW)

- UI smoke: import/login → plan → trening → feedback (widok `planVsExecution` w dashboardzie).
- Przypadek luki między treningami w UI (brak sesji między ostatnimi treningami).
- Uruchomienie testów lokalnie i potwierdzenie wyniku: `php artisan test tests\Feature\Api\PlanSnapshotIntegrationTest.php` + `php artisan test tests\Feature\Api\WorkoutsTest.php --filter=feedback` + `php artisan test`.
- Deploy na IQHost i produkcyjny smoke.

## Testy do uruchomienia po wznowieniu

```
cd backend-php
php artisan test tests\Feature\Api\WorkoutsTest.php --filter=feedback
php artisan test tests\Feature\Api\PlanSnapshotIntegrationTest.php
php artisan test
cd ..
npm run build
```
