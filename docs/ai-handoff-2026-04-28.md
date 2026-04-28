# AI handoff — MarcinCoach v2

Data: 2026-04-28
Repo: `C:\Users\marcin.kula\Documents\GitHub\MarcinCoach-v2`

Ten plik służy do przeniesienia kontekstu rozmowy do nowej sesji Codex na innym komputerze.

---

## 1. Najważniejsze instrukcje projektu

Z `AGENTS.md`:

- Nie budować frontendu na IQHost. Serwer/hook Git nie ma `npm`.
- Poprawny deploy frontu:
  1. zmiany lokalnie,
  2. `npm run build`,
  3. `.\deploy-front.ps1`,
  4. pliki `dist/*` trafiają przez SCP do `public_html/`.
- Frontend: `https://coach.host89998.iqhs.pl`
- Backend API: `https://api.coach.host89998.iqhs.pl/api`
- Repo struktura:
  - `src/` — React/Vite/TypeScript frontend,
  - `backend-php/` — Laravel backend,
  - `docs/` — dokumentacja.

---

## 2. Co zostało zrobione w tej sesji

### Roadmap

Zmieniony plik:

- `docs/roadmap.md`

Dopisana decyzja UX do onboardingu:

- na ekranie wyboru źródła danych dodać akcję typu "Brakuje Twojej aplikacji? Powiadom nas",
- dodać krótki formularz zgłoszenia brakującej integracji/API:
  - nazwa aplikacji lub urządzenia,
  - link do API/strony integracji,
  - typ danych: treningi/sen/HRV/readiness,
  - opcjonalny kontakt do usera,
- formularz nie może blokować onboardingu,
- fallbackiem pozostaje import TCX/GPX/FIT.

### Scenariusze użytkownika

Źródłem był ZIP:

- `C:\Users\marcin.kula\Downloads\files (1).zip`

Rozpakowano i skorygowano dokumentację do:

- `docs/user-scenarios/`

Pliki w katalogu:

- `README.md`
- `01-onboarding-data-sources.md`
- `02-import-data-quality.md`
- `03-analysis-profile.md`
- `04-planning-feedback-loop.md`
- `05-integrations.md`
- `06-auth-session-production-smoke.md`
- `07-privacy-gdpr.md`
- `08-manual-check-in.md`
- `coverage-matrix.md`
- `gaps-and-next-steps.md`
- `it-consultation-scenarios.md`

Dodany nowy obszar:

- `08-manual-check-in.md`

Opisuje pełny flow dla użytkownika, który:

- nie podłącza Garmin/Strava,
- nie wrzuca TCX/GPX/FIT po treningu,
- korzysta z aplikacji przez ręczny check-in:
  - wykonane,
  - zmienione,
  - nie zrobiłem,
  - czas,
  - dystans opcjonalnie,
  - RPE,
  - ból/kontuzja,
  - notatka.

Decyzja produktowa: manual check-in jest P0/core MVP, nie P2 fallback.

### Dokument dla IT

Dodany plik:

- `docs/user-scenarios/it-consultation-scenarios.md`

To jest scalony dokument do wysłania do konsultacji IT. Zawiera:

- kontekst produktu,
- schematy Mermaid,
- definicje statusów i priorytetów,
- decyzje techniczne dla IT,
- zakres MVP,
- pełną mapę scenariuszy,
- luki P0,
- kolejność prac,
- kontrakty API do weryfikacji,
- pytania dla IT.

---

## 3. Najważniejsze decyzje i stan wiedzy

### Status projektu

Stan na 2026-04-28:

- projekt jest po D0/post-cutover,
- frontend i backend produkcyjnie żyją,
- UX nadal ma istotne luki MVP.

### Kolejność prac

Ustalony kierunek:

1. Najpierw scenariusze użytkownika i macierz pokrycia.
2. Dopiero potem wdrażanie z roadmapy.

Powód: trzeba przewidzieć i przetestować drogi użytkownika, zanim zaczniemy implementować kolejne pakiety.

### Dokumenty historyczne

Nie traktować jako źródła prawdy:

- `docs/archive/`,
- stare `marcincoach_status_po_*`,
- stare `m3_m4_beyond_*`,
- `.cursor/plans/implement_session_idle_timeout_*`.

Aktualne źródła prawdy:

- `docs/status.md`
- `docs/roadmap.md`
- `docs/integrations.md`
- `docs/user-scenarios/`
- rzeczywisty kod w `src/` i `backend-php/`

### API i korekty faktów

Poprawione w scenariuszach:

- profil: `GET/PUT /api/me/profile`, nie `/api/profile`,
- rolling plan: `GET /api/rolling-plan?days=14`, `POST /api/rolling-plan`,
- nie używać już `windowDays` ani `/api/weekly-plan?windowDays=14` jako głównego kontraktu,
- Strava produkcyjnie: `unknown/needs credentials/smoke`,
- Garmin: history sync i send workout smoke 26.04, auto-sync missing, MFA UI partial/missing,
- GPX/FIT: backend parser istnieje, UI nadal partial/missing,
- `WRONG_SPORT`: nie zakładać odrzucania wszystkiego poza running; aktualny kierunek to cross-training jako pełnoprawny sygnał,
- RODO: nie blokuje zamkniętej bety, blokuje publiczny launch realnych nieanonimowych użytkowników w UE,
- `scripts/e2e-cross-stack.mjs`: missing/TODO; lokalnie istnieje tylko `scripts/import-tcx.ps1`.

---

## 4. Aktualna macierz scenariuszy

W `docs/user-scenarios/coverage-matrix.md`:

- razem: 106 scenariuszy,
- P0: 59,
- P1: 40,
- P2: 7.

Statusy:

- implemented: 22,
- partial: 43,
- missing: 30,
- unknown: 11.

P0 only:

- implemented: 19,
- partial: 28,
- missing: 8,
- unknown: 4.

Najważniejsze P0 missing:

- reset hasła,
- zgody przy rejestracji,
- export danych,
- usunięcie konta,
- granica info treningowa/medyczna,
- audit log zgód,
- manual check-in: oznaczenie treningu jako wykonanego bez pliku,
- manual check-in: pominięcie treningu.

---

## 5. Zalecana kolejność następnych prac

Z `gaps-and-next-steps.md`:

1. Pakiet 0 — higiena i monitoring:
   - smoke produkcyjny,
   - healthcheck monitoring,
   - globalny 401 interceptor.

2. Pakiet 1 — auth dla nowych użytkowników:
   - rejestracja UI,
   - reset hasła,
   - zmiana hasła z profilu.

3. Pakiet 2 — pętla treningowa MVP:
   - UX feedbacku po treningu,
   - auto-refresh planu po imporcie,
   - manual check-in bez integracji i bez plików.

4. Pakiet 3 — profil i nawigacja:
   - zakładka Profil,
   - pełny formularz startów/races,
   - powrót do onboardingu z profilu.

5. Pakiet 4 — RODO przed publicznym launchem:
   - zgody,
   - regulamin/polityka prywatności,
   - export,
   - delete account,
   - disclaimer medyczny,
   - audit log.

6. Pakiet 5+:
   - Strava prod,
   - Garmin auto-sync/MFA,
   - GPX/FIT UI,
   - jakość importu,
   - HR/pace zones,
   - integracje przyszłościowe.

---

## 6. Aktualny stan git/worktree

Na moment tworzenia tego handoffu były zmiany:

- `M docs/roadmap.md`
- `?? docs/user-scenarios/`

Ten plik `docs/ai-handoff-2026-04-28.md` też jest nową zmianą.

Nie robiono commita ani stage w tej sesji.

---

## 7. Prompt startowy dla nowej sesji Codex

Wklej to w nowym Codexie na drugim komputerze:

```text
Pracujemy w repo MarcinCoach-v2. Najpierw przeczytaj:
- AGENTS.md
- docs/ai-handoff-2026-04-28.md
- docs/roadmap.md
- docs/user-scenarios/README.md
- docs/user-scenarios/it-consultation-scenarios.md
- docs/user-scenarios/coverage-matrix.md
- docs/user-scenarios/gaps-and-next-steps.md

Nie buduj frontendu na IQHost. Jeśli będzie deploy frontu, build lokalnie przez npm run build i potem .\deploy-front.ps1.

Aktualny cel: kontynuować prace po przygotowaniu scenariuszy użytkownika. Najważniejsza decyzja produktowa: manual check-in bez integracji i bez plików jest P0/core MVP. Użytkownik bez Garmina/Stravy/TCX musi móc domknąć pętlę: plan -> wykonane/nie zrobiłem -> RPE/ból/notatka -> feedback -> kolejny plan.

Najpierw sprawdź git status i nie cofaj istniejących zmian.
```

