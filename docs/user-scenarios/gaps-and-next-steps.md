# Gaps and next steps — luki, blokery, kolejność prac

Stan: 28.04.2026.
Cel: na podstawie scenariuszy z plików 01-08 i macierzy pokrycia odpowiedzieć na trzy pytania:
1. Co jest niezbędne żeby MVP można było pokazać realnym użytkownikom?
2. Co możemy odsunąć, ale trzeba mieć ślad w roadmapie?
3. W jakiej kolejności robić, żeby każdy pakiet pracy domykał konkretną luki, a nie szarpał systemu?

---

## Założenia

**Co już mamy (D0 PASS, 28.04.2026 10:00:07 UTC):**
- Frontend i backend działają na produkcji.
- Login i sesja działają.
- Onboarding wizard 2-fazowy działa, ze skipem.
- Upload TCX działa (single + multi).
- Garmin connect + sync 30 dni działa (smoke 26.04).
- Wysyłka workoutu do Garmin działa (smoke 26.04).
- Rolling plan 14 dni renderuje się.
- Backend feedback endpoint działa (deterministyczny).
- Healthcheck działa.

**Co używamy jako źródło prawdy (28.04.2026):**
- `backend-php/docs/`
- `docs/status.md`, `docs/roadmap.md`, `docs/integrations.md`
- `AGENTS.md`, `CLAUDE.md`

**Co ignorujemy:**
- Wszystkie historyczne plany w `docs/archive/`.
- Plany Node z `.cursor/plans/` (np. idle session timeout) — to nie aktualny stan.
- Stare `marcincoach_status_po_*` i `m3_m4_beyond_*` jako źródło prawdy. Można je archiwizować lub przenieść do `docs/archive/`.

**Hosting i deploy:**
- IQHost shared (PHP 8.5, LiteSpeed, CloudLinux/DirectAdmin).
- Frontend: lokalny build + `deploy-front.ps1` (SCP).
- Backend: `git push iqhost main`, post-receive hook.
- `open_basedir` można self-managować przez DirectAdmin → Dodatkowe funkcje → Zarządzanie separacją domen.

---

## Luki krytyczne — P0, blockery MVP

Te rzeczy musimy mieć **przed pierwszym launchem dla niezamkniętej grupy użytkowników**. Bez nich aplikacja nie spina się jako produkt.

### Bucket A — Auth i konto
- **A1: Rejestracja w UI (US-ONBOARD-001).** Backend ma endpoint, UI nie pokazuje przycisku. Bez tego nikt nowy sam się nie zaloguje.
- **A2: Reset hasła (US-AUTH-009).** Brak. Wymaga skonfigurowania mailera (SMTP). Bez tego user, który zapomni hasła, nie ma jak wrócić.
- **A3: Globalny 401 interceptor (US-AUTH-006).** Frontend dziś niespójnie obsługuje wygasłą sesję. Trzeba 1 miejsce w kodzie (axios interceptor lub fetch wrapper) które zawsze czyści sesję i robi redirect.

### Bucket B — UX feedbacku i pętla treningowa
- **B1: UX feedbacku po treningu (US-PLAN-005).** Backend zwraca pełny feedback (praise / deviations / conclusions / planImpact / confidence / metrics), frontend tego nie pokazuje wyraźnie. **To jest core MVP** — bez tego aplikacja jest tylko logiem treningów.
- **B2: Auto-refresh planu po imporcie (US-PLAN-004).** User wgrywa trening i nie wie, że plan się dezaktualizował, bo trzeba kliknąć refresh. Sugestia: po `POST /workouts/upload` frontend automatycznie odświeża `/rolling-plan?days=14`.
- **B3: Pełna pętla E2E (US-PLAN-018).** Smoke produkcyjny: login → upload → feedback → plan jutrzejszy. Powinien być cron raz dziennie z alertem.
- **B4: Manual check-in bez integracji i bez plików (US-MANUAL-002/003/005/006).** User, który nie ma Garmina/Stravy i nie wrzuca TCX, musi móc kliknąć "Wykonane" albo "Nie zrobiłem", podać RPE/ból/notatkę i dostać feedback. **To jest core MVP**, bo inaczej ścieżka "brak danych" kończy się na planie startowym.

### Bucket C — Race profile i nawigacja
- **C1: Pełny formularz race w UI (US-RACE-001).** Backend ma, UI tylko upraszcza (data + dystans + priority A). Brakuje: nazwa, target time, edycja (US-RACE-002), usunięcie (US-RACE-003).
- **C2: Zakładka Profil + nawigacja tabelaryczna.** Bez niej user po skipie nie ma jak wrócić do uzupełnienia (US-ONBOARD-007), zarządzić integracjami (US-INTEGRATION-001), edytować race (US-RACE-002).

### Bucket D — RODO przed publicznym launchem
Te 5 punktów to **prawny blocker** dla otwartego launchu w UE. Nie blokują zamkniętej bety / testów wśród znajomych, ale otwarcie publiczne wymaga ich domknięcia.

- **D1: Zgody przy rejestracji (US-PRIVACY-001).** Checkboxy TOS i Privacy + audit log w `user_consents`.
- **D2: Polityka prywatności i regulamin** (dokumenty). Na razie nie ma żadnego z nich.
- **D3: Export danych (US-PRIVACY-003).** RODO Art. 20.
- **D4: Usunięcie konta (US-PRIVACY-004).** RODO Art. 17.
- **D5: Disclaimer medyczny (US-PRIVACY-005).** Każde miejsce w UI gdzie pojawia się ból/kontuzja musi mieć adnotację "MarcinCoach nie zastępuje porady medycznej". Bez tego ryzyko klasyfikacji jako urządzenie medyczne.

### Bucket E — Smoke i monitoring
- **E1: Automatyczny smoke produkcyjny (US-AUTH-011, US-AUTH-012).** Cron / GitHub Action raz dziennie. Bez tego nie wiemy, że produkcja padła, dopóki user nie zgłosi.
- **E2: Healthcheck monitoring.** Endpoint `/api/health` istnieje (potwierdzony 28.04). Brak monitoringu (UptimeRobot lub własny cron z alertem).

---

## Luki istotne — P1, do zamknięcia po MVP launch

Te rzeczy poprawiają UX i pokrycie, ale nie blokują pierwszego użytkownika.

### Integracje
- **Strava produkcyjny smoke (US-STRAVA-001, US-STRAVA-002).** Wymaga produkcyjnych credentials i smoke z realnym kontem.
- **Strava webhook (US-STRAVA-003).** Stabilna ścieżka auto-sync. Implementacja przed Garmin polling.
- **Garmin auto-sync (US-GARMIN-004).** Decyzja: on-demand button + cron 1-2× dziennie. Polling Garmina ma ryzyko rate limit.
- **Garmin MFA UI (US-GARMIN-002).** Bez pola MFA w UI część użytkowników odbije się na pierwszym kroku.
- **Globalny widok integracji (US-INTEGRATION-001).** Zakładka Ustawienia → Integracje.

### Import i dane
- **Korekta klasyfikacji aktywności (US-IMPORT-006).** User FIT-based (Coros) jest bezsilny gdy parser się pomyli.
- **Sanity check tempa (US-IMPORT-008).** Plik z `Sport="Running"` ale tempem 2:30/km = oczywisty rower przemianowany.
- **Trening dziś vs historyczny (US-IMPORT-011).** Dziś brak rozróżnienia w pipeline. Bez tego feedback po treningu jest mniej trafny.
- **Upload GPX i FIT z UI (US-IMPORT-002).** Backend obsługuje, UI nie eksponuje. Niewielka zmiana, dużo zadowolonych Coros / Strava-export userów.

### Profil i analiza
- **Propozycja stref HR z danych (US-ANALYSIS-004).** Dziś backend ma `hrZones` ale UI nie pokazuje propozycji. Wpływa na precyzję klasyfikacji intensywności.
- **Propozycja stref pace (US-ANALYSIS-005).** Analogicznie do HR.
- **Profile Quality Score widoczny (US-ANALYSIS-008).** Backend liczy, UI nie pokazuje. To sposób na "edukację użytkownika" — pokazuje, że więcej danych = lepszy plan.
- **Trend formy (US-ANALYSIS-007).** Wykresy tygodniowe.

### Plan
- **Powrót po przerwie / kontuzji UX (US-PLAN-015).** Backend rozpoznaje, UX informuje.
- **Pominięty kluczowy trening alert (US-PLAN-010).** Alert siedzi w bazie, user może go nie zobaczyć.
- **Drift detection UX (US-PLAN-017).** Empatyczna komunikacja przy gapie 5+ dni.

### Auth
- **Zmiana hasła z profilu (US-AUTH-010).** Bez tego user nie może zmienić hasła samodzielnie.
- **Brute force protection.** Po N nieudanych próbach blokada.

---

## Luki przyszłościowe — P2, odsuwamy ale mamy ślad

Te rzeczy nie blokują launch, ale chcemy je mieć w roadmapie żeby nie zniknęły.

- **Polar integracja (US-POLAR-002).** Polar AccessLink API jest oficjalne i stabilne. Po Strava webhook.
- **Suunto integracja (US-SUUNTO-001).** Suunto API Zone wymaga formalności partnerskich.
- **Coros integracja (US-COROS-002).** Brak public API. Można złożyć aplikację partnerską już teraz, sam dev po Polar/Suunto. Tymczasem fallback to FIT/TCX (US-IMPORT-002).
- **Garmin Event Dashboard (US-GARMIN-EVENT-001).** Spike research, niewiadome czy connector wspiera.
- **Upload ZIP (US-IMPORT-015).** Power-user feature dla setek plików. Multi-upload TCX częściowo rozwiązuje problem dla zwykłych userów.
- **Cookie banner (US-PRIVACY-009).** Tylko jeśli dodajemy analytics.
- **"Wyloguj wszędzie".** Multi-session feature.

---

## Rekomendowana kolejność prac

Zasada: każdy "pakiet pracy" zamyka jedną dziurę i nie wymaga rzeczy z późniejszych pakietów. Pakiety są kolejne, nie równoległe (bo zależą od siebie nawzajem).

### Pakiet 0 — Higiena i monitoring (1-2 dni)
**Cel:** Wiemy że produkcja działa.

- E1: Skrypt smoke produkcyjny (login + healthcheck + 5 endpointów) jako GitHub Action raz dziennie.
- E2: UptimeRobot lub odpowiednik na `/api/health` z alertem mailowym.
- A3: Globalny 401 interceptor we frontendzie.

**Test domknięcia:** smoke daje zielone światło codziennie, alert przychodzi gdy ubijesz backend testowo.

### Pakiet 1 — Auth dla nowych użytkowników (3-5 dni)
**Cel:** Nowy user może sam założyć konto i odzyskać dostęp.

- A1: Formularz rejestracji w UI + walidacja.
- A2: Reset hasła (forgot password flow + SMTP konfiguracja).
- A3: Zmiana hasła z profilu (US-AUTH-010, P1 ale tani razem).

**Test domknięcia:** anonim wchodzi, rejestruje się, loguje, zapomina hasła, resetuje, wraca.

### Pakiet 2 — Pętla treningowa MVP (5-8 dni)
**Cel:** Po treningu user widzi sens aplikacji — zarówno po imporcie pliku, jak i bez żadnej integracji.

- B1: UX feedbacku po treningu (5 sekcji: praise / deviations / conclusions / planImpact / confidence + metrics). Backend gotowy.
- B2: Auto-refresh planu po imporcie. Po `POST /workouts/upload` frontend wywołuje `/rolling-plan?days=14`.
- B3: Smoke E2E pełnej pętli włączony do cron z pakietu 0.
- B4: Manual check-in: przy dzisiejszej sesji user może wybrać "Wykonane" / "Nie zrobiłem", wpisać czas, dystans opcjonalnie, RPE, ból/notatkę i dostać feedback bez HR/pace.

**Test domknięcia:** wgrałem TCX → widzę feedback → widzę zaktualizowany plan na jutro; oraz bez pliku klikam "Wykonane" → zapisuję RPE/notatkę → widzę manual feedback → plan nie traci ciągłości.

### Pakiet 3 — Profil i nawigacja (5-7 dni)
**Cel:** User może zarządzać swoim kontem i celami.

- C2: Zakładka Profil + nawigacja tabelaryczna (Dashboard / Treningi / Plan / Profil / Ustawienia).
- C1: Pełny formularz race w Profil → Starty (dodaj, edytuj, usuń).
- US-ONBOARD-007: Powrót do uzupełnienia onboardingu z Profilu.
- Profile Quality Score widoczny (US-ANALYSIS-008, P1 ale tani razem).

**Test domknięcia:** user po skipie wraca przez zakładkę Profil, uzupełnia, dodaje race, widzi że plan się dostosował.

### Pakiet 4 — RODO przed publicznym launchem (5-10 dni, dużo nie-koderskie)
**Cel:** Możemy otworzyć rejestrację publicznie.

- D2: Polityka prywatności i regulamin (napisane lub kupione). Trzeba prawnika lub legal-template, nie copy-paste.
- D1: Zgody przy rejestracji (UI + tabela `user_consents`, audit timestamp + wersja).
- D5: Disclaimer medyczny w każdym miejscu UI gdzie pojawia się ból/kontuzja.
- D3: Export danych (async job → ZIP z JSON + raw files).
- D4: Usunięcie konta (cascade delete + audit).
- US-PRIVACY-002: Sprawdzenie revoke tokenów u providera (Strava `/oauth/deauthorize`, Garmin connector).

**Test domknięcia:** mogę otworzyć landing dla wszystkich, użytkownik z UE ma kontrolę nad swoimi danymi.

**Uwaga:** Ten pakiet można realizować równolegle do 1-3 jeśli launch publiczny jest za ~2-3 miesiące. Jeśli launch szybciej — robić wcześniej.

### Pakiet 5 — Strava produkcyjny i Garmin auto-sync (5-7 dni)
**Cel:** Druga główna integracja działa, Garmin sam się synchronizuje.

- US-STRAVA-001/002: Produkcyjne credentials Strava + smoke z realnym kontem.
- US-STRAVA-005: Refresh token weryfikacja.
- US-STRAVA-003: Webhook subscription dla nowych aktywności.
- US-GARMIN-004: On-demand "Sprawdź nowe treningi" button + cron 1-2× dziennie.
- US-GARMIN-002: MFA UI dla Garmin.
- US-INTEGRATION-001: Zakładka Integracje.

**Test domknięcia:** user łączy Stravę przez OAuth, robi trening, w ciągu 60s widzi go w MarcinCoach. Garmin user z MFA też przechodzi.

### Pakiet 6 — Jakość importu i danych (5-7 dni)
**Cel:** System rozumie co user wgrywa.

- US-IMPORT-002: Upload GPX i FIT z UI.
- US-IMPORT-006: Korekta klasyfikacji aktywności w UI.
- US-IMPORT-008: Sanity check tempa (odrzuć / re-klasyfikuj).
- US-IMPORT-011: Trening dziś vs historyczny w pipeline.
- Aktualizacja `03-workout-domain.md` żeby odzwierciedlała rzeczywistość (cross-training jest pełnoprawny).

**Test domknięcia:** user FIT-based (Coros) z błędną klasyfikacją koryguje przez UI, plan się dostosowuje.

### Pakiet 7 — Strefy HR/pace i analiza (5-7 dni)
**Cel:** Aplikacja używa realnych danych usera, nie tabel wiekowych.

- US-ANALYSIS-004: Propozycja stref HR z danych.
- US-ANALYSIS-005: Propozycja stref pace.
- US-ANALYSIS-007: Trend formy / progres (wykresy tygodniowe).
- US-PLAN-015: UX powrotu po przerwie.
- US-PLAN-010: UX missed key workout.
- US-PLAN-017: UX drift detection.

**Test domknięcia:** user z 15+ treningami widzi wyliczone strefy, akceptuje, kolejne treningi są klasyfikowane wg jego stref.

### Pakiet 8 — Mobilność i polerka (3-5 dni)
**Cel:** Aplikacja jest komfortowa na telefonie.

- US-ONBOARD-008: Audit mobile, naprawić blokery.
- Empty states na każdym komponencie dashboardu (regresja).
- Globalny error boundary + Sentry / error logging.
- US-IMPORT-016: Pełna obsługa błędów technicznych.

### Pakiet 9 — Integracje przyszłościowe (kiedy będzie czas)
- US-POLAR-002: Polar AccessLink integration.
- US-SUUNTO-001: Suunto API Zone.
- US-COROS-002: Aplikacja partnerska Coros (równolegle z innymi pakietami, formalność).
- US-IMPORT-015: Upload ZIP.
- US-GARMIN-EVENT-001: Spike Garmin Event Dashboard.

---

## Co odsuwamy świadomie

Te decyzje są jawne, żeby nie wracać do nich co miesiąc:

1. **Wiek/waga/wzrost/płeć w profilu.** Backend można te informacje wyliczyć z danych treningowych (max HR, tempo). UI nie jest blockerem MVP. Dodajemy w pakiecie 7 lub później.
2. **Sen i stres.** Wymaga integracji z Garmin / Apple Health / Whoop, lub manual input. P2.
3. **HR spoczynkowe / max manual.** Jak wyżej — można wyliczyć z danych. P2.
4. **Strona blogowa / treści edukacyjne.** Aplikacja jest narzędziem, nie portalem.
5. **Pełna gamifikacja (streaks, badges, rankingi).** P2 lub nigdy. MarcinCoach to coach, nie Strava.
6. **App mobilna natywna.** Web mobile responsive wystarczy do MVP.
7. **Multi-język.** Polski-first. EN po PL stabilizacji.

---

## Otwarte pytania, które trzeba rozstrzygnąć

Te rzeczy nie blokują żadnego konkretnego scenariusza, ale ich brak jest ryzykiem:

1. **Storage raw GPX i FIT.** Dziś tylko TCX ma raw storage (`workout_raw_tcx`). Czy warto dodać raw storage dla GPX/FIT? Argumenty za: re-parse przy update parsera. Argumenty przeciw: storage cost, więcej kolumn.

2. **Spec `03-workout-domain.md` sekcja Wrong Sport.** Niespójna z implementacją (spec mówi "odrzuć non-running", implementacja zapisuje cross-training). Trzeba zaktualizować spec.

3. **Czy szyfrować at-rest dane zdrowotne (`pain_description`)?** RODO Art. 32. Sugestia: tak dla free text fields, nie dla booleanów.

4. **Co z OpenAI consent (jeśli AI escalation jest aktywne)?** `ai-integration.md` mówi nie udostępniać data dla treningu OpenAI, ale data nadal idzie do processora. Wymaga jasnej informacji w Privacy Policy.

5. **Modele AI (gpt-5.4-nano/mini) w `ai-integration.md`.** Zweryfikować że nazwy modeli są aktualne — przy każdym dotknięciu integracji AI sprawdzać czy modele istnieją w API OpenAI (`docs.openai.com`).

6. **Monitoring cost na produkcji.** AI + integracje + storage. Brak alertów cost-related może zaskoczyć.

7. **Backup retention policy.** RODO Art. 17 wymaga "removed within reasonable time" — co to oznacza w praktyce dla nas? 7 dni / 30 dni / 90 dni?

---

## Notka o starych dokumentach

W repo i okolicach są dokumenty, które **nie są źródłem prawdy** dla stanu na 28.04.2026:

- `marcincoach_status_po_*.md` — historyczne snapshoty.
- `m3_m4_beyond_*.plan.md` — plany M-pakietów Node, nieaktualne.
- `.cursor/plans/implement_session_idle_timeout_*.md` — stare plany Node.
- Inne pliki w `docs/archive/`.

**Zasada:** te dokumenty nie są ignorowane jako historia, ale **nie wpływają na bieżące decyzje produktowe**. Jeśli ktoś znajdzie konflikt między starym dokumentem a tymi scenariuszami — wygrywają scenariusze.

Sugestia administracyjna: przenieść ostatnie pozostałości starych dokumentów do `docs/archive/` z jasnym README "snapshoty historyczne, nie używać jako źródło prawdy".

---

## Kiedy zamykamy MVP?

MVP można uznać za zamknięty, gdy:

1. **Pakiety 0-3 są gotowe** (auth, monitoring, pętla treningowa, profil + nawigacja).
2. **Smoke E2E zielony przez 7 kolejnych dni** (US-PLAN-018).
3. **Co najmniej 5 realnych użytkowników (closed beta) ukończyło pełną pętlę: rejestracja → onboarding → import albo manual check-in → feedback → kolejny plan**.
4. **Brak P0 missing w `coverage-matrix.md`**.

MVP **publiczny** wymaga dodatkowo:
5. Pakiet 4 (RODO) zamknięty.
6. Strona regulaminu i polityki prywatności online.
7. Plan incident response na wypadek breachu.

---

## Cykl aktualizacji tego dokumentu

Raz w miesiącu (lub po każdym zamkniętym pakiecie):
1. Zaktualizować statusy w plikach 01-07.
2. Zaktualizować `coverage-matrix.md`.
3. Przejrzeć ten plik — co już nieaktualne, co nowe.
4. Sprawdzić sekcję "Otwarte pytania" — czy któreś już zostało rozstrzygnięte.

Po każdym zamknięciu pakietu 0-9 dopisać tutaj datę zamknięcia w sekcji "Postęp" (nie ma jej jeszcze, dodać przy pierwszym zamknięciu).
