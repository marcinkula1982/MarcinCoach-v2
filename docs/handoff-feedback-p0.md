# Handoff: P0 datowo świadomy feedback potreningowy

Data handoffu: 2026-04-30

## Stan pracy

Zadanie P0 nie jest zakończone. Wdrożenie funkcjonalne zostało zatrzymane przed podpięciem nowego serwisu do produkcyjnego flow aplikacji.

## Co zostało zmienione

- Dodano pierwszy draft `WorkoutPlanFeedbackService.php` jako osobny, deterministyczny serwis plan-vs-execution.
- Wcześniej dodano minimalny guard w `TrainingFeedbackV2Service.php`, który rozpoznaje treningi historyczne starsze niż 3 dni i nie zwraca starego wniosku o kontynuowaniu bieżącego planu.
- Wcześniej dodano feature test dla treningu historycznego w `WorkoutsTest.php`.
- W `docs/execution-plan.md` dopisano EP-038 jako aktywny task P0-MVP.
- W `docs/status.md` dopisano wpis o minimalnym guardzie EP-037.

## Pliki dodane lub zmodyfikowane

- `backend-php/app/Services/WorkoutPlanFeedbackService.php` — nowy, niepodpięty draft serwisu; wymaga korekt przed użyciem.
- `backend-php/app/Services/TrainingFeedbackV2Service.php` — zmodyfikowany wcześniejszym guardem historycznym.
- `backend-php/tests/Feature/Api/WorkoutsTest.php` — dodany test minimalnego guardu historycznego.
- `docs/execution-plan.md` — zaktualizowany plan pracy.
- `docs/status.md` — zaktualizowany status.
- `docs/handoff-feedback-p0.md` — ten handoff.

## Gotowe

- Zweryfikowano, że istnieje migracja `2026_04_21_150000_add_block_fields_to_plan_snapshots_table.php` i dodaje kolumny używane przez `PlanSnapshotService`.
- Nie utworzono modelu `App\Models\PlanSnapshot`.
- Minimalny guard historyczny w istniejącym `TrainingFeedbackV2Service.php` działał w testach przed rozpoczęciem draftu nowego serwisu.
- Nowy `WorkoutPlanFeedbackService.php` jest dodany jako draft, ale nie jest częścią działania aplikacji.

## Niegotowe

- `WorkoutPlanFeedbackService.php` nie został podpięty do `TrainingFeedbackV2Service`.
- Nie rozszerzono jeszcze frontendowego typu `WorkoutFeedback`.
- Nie podpięto zapisu snapshotów planu w `WeeklyPlanController` ani `RollingPlanController`.
- Nie dodano wymaganych testów dla pełnego P0-MVP.
- Draft `WorkoutPlanFeedbackService.php` wymaga korekty przed dalszym użyciem:
  - `historical` i `no_plan` powinny mieć `executionScore = null`, nie `0`.
  - aktywność niebiegowa przy biegowym planie nie powinna być klasyfikowana jako `partial`.
  - trening starszy niż 3 dni nie powinien być automatycznie `historical`, jeśli istnieje snapshot planu obejmujący jego datę.
  - teksty powinny być poprawnym UTF-8 z polskimi znakami.

## Znane ryzyka

- Nowy draft serwisu nie ma testów i może zawierać błędną semantykę statusów.
- `TrainingFeedbackV2Service.php` nadal zawiera część starych, generycznych tekstów poza minimalnym guardem historycznym.
- Aktualny kontrakt API nie zawiera jeszcze nowych pól P0-MVP.
- Dokumenty `docs/execution-plan.md` i `docs/status.md` są już zmienione, mimo że EP-038 nie jest zakończony.
- `docs/execution-plan.md` ma ostrzeżenie Git o przyszłej zmianie LF na CRLF.

## Testy uruchomione

Przed dodaniem draftu `WorkoutPlanFeedbackService.php` były uruchomione:

- `php artisan test tests\Feature\Api\WorkoutsTest.php --filter=workout_feedback` — 2 passed, 37 assertions.
- `php artisan test` — 335 passed, 1794 assertions.
- `npm run build` — OK.
- `git diff --check` — OK, z ostrzeżeniem LF -> CRLF dla `docs/execution-plan.md`.

Po dodaniu draftu `WorkoutPlanFeedbackService.php` nie uruchomiono testów.

## Testy nieuruchomione

- Nie uruchomiono testów po dodaniu `WorkoutPlanFeedbackService.php`.
- Nie uruchomiono testów jednostkowych nowego serwisu, bo nie zostały jeszcze dodane.
- Nie uruchomiono `php artisan test` po ostatniej zmianie.
- Nie uruchomiono `npm run build` po ostatniej zmianie.

## Następny krok po wznowieniu

Pierwszy krok: poprawić wyłącznie semantykę draftu `WorkoutPlanFeedbackService.php` zgodnie z ostatnią instrukcją:

1. zmienić `executionScore` na `?int` i zwracać `null` dla `historical` oraz `no_plan`,
2. zmienić obsługę niebiegowej aktywności przy biegowym planie tak, żeby nie zwracała `partial`,
3. oceniać starszy trening względem snapshotu planu, jeśli snapshot obejmuje jego datę,
4. poprawić teksty na pełne polskie znaki UTF-8,
5. dodać i uruchomić testy tylko dla tych czterech przypadków.

Nie podłączać serwisu do produkcyjnego flow przed przejściem tych testów.
