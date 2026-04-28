# 07 — Prywatność, RODO i dane zdrowotne

Plik obejmuje: zgody przy pierwszym logowaniu, odłączenie integracji, usunięcie konta i danych, export danych, separacja informacji treningowej od medycznej, dane wrażliwe (ból, kontuzje, HR).

Realny stan 28.04.2026: większość scenariuszy to **missing**. Nie blokuje to zamkniętej bety ani testów wewnętrznych, ale **blokuje publiczny launch dla realnych nieanonimowych użytkowników w UE**.

---

## US-PRIVACY-001 — Zgody przy rejestracji

**Typ:** happy path (compliance)
**Persona:** każdy nowy user
**Status:** missing / unknown
**Priorytet:** P0 (przed launch)

### Stan wejściowy
User w trakcie rejestracji.

### Wymagane zgody
- (a) **Akceptacja regulaminu** (Terms of Service).
- (b) **Akceptacja polityki prywatności** (Privacy Policy).
- (c) Opcjonalna: marketing/newsletter.

### Wymagane informacje
- Jakie dane zbieramy (email, treningi, ból/kontuzje, HR).
- Jak długo przechowujemy.
- Z kim dzielimy (OpenAI dla escalation? — patrz uwagi).
- Prawa użytkownika (export, usunięcie, sprostowanie).

### Kroki użytkownika
1. Wypełnia formularz rejestracji.
2. Przed submit: checkbox "Akceptuję Regulamin i Politykę Prywatności" (linki do dokumentów).
3. Bez akceptacji submit jest disabled.

### Oczekiwane API
- `POST /api/auth/register` z `acceptedTos: true, acceptedPrivacy: true, acceptedTosVersion: '1.0'`.

### Oczekiwane zmiany danych
- Tabela `user_consents` (nowa) lub kolumny w `users`:
  - `tos_accepted_at`, `tos_version`
  - `privacy_accepted_at`, `privacy_version`
  - `marketing_accepted_at` (nullable)

### Kryteria akceptacji (P0)
- Brak akceptacji = brak konta.
- Wersja regulaminu/polityki zapisana (audit przy zmianie).
- Przy zmianie regulaminu/polityki user dostaje prompt do akceptacji nowej wersji przy następnym logowaniu.

### Testy / smoke
- Test backend: register bez zgody → 400.
- Test backend: register z zgodami → user.tos_accepted_at = now.

### Uwagi produktowe
**OpenAI consent:** jeśli MVP używa AI escalation (gpt-5.4-nano/mini) z user data, to wymaga **explicit consent** w polityce lub osobnej zgody. Zgodnie z `ai-integration.md`: "Nie włączać opcji 'Share inputs and outputs with OpenAI'" — to ogranicza, ale nadal dane lecą do OpenAI processora.

**Dokumenty do napisania:**
- Regulamin (Terms of Service).
- Polityka prywatności (RODO compliant).
- Cookie policy (jeśli używamy cookies dla sesji).

---

## US-PRIVACY-002 — Odłączenie integracji (Garmin, Strava)

**Typ:** happy path (compliance)
**Persona:** każdy user z integracją
**Status:** unknown
**Priorytet:** P0 (przed launch)

### Stan wejściowy
User chce odłączyć Garmin lub Stravę.

### Patrz: US-GARMIN-007 (techniczna implementacja).

### Dodatkowe wymogi RODO
- Backend powinien **revoke token** u providera (nie tylko usunąć z bazy).
  - Strava: `POST /oauth/deauthorize` z access_token.
  - Garmin: connector specific.
- Wpis w audit log: kto, kiedy odłączył.

### Kryteria akceptacji (P0)
- Odłączenie działa.
- Token unieważniony u providera.
- User widzi potwierdzenie.
- Workouty zaimportowane wcześniej **pozostają** (chyba że user też je usuwa — US-PRIVACY-004).

---

## US-PRIVACY-003 — Export wszystkich moich danych

**Typ:** happy path (compliance)
**Persona:** każda
**Status:** missing
**Priorytet:** P0 (przed launch)

### Stan wejściowy
User chce kopię swoich danych.

### Kroki użytkownika
1. Profil → Moje dane → "Eksportuj wszystkie dane".
2. Klika "Generuj export".
3. Backend tworzy ZIP z danymi.
4. User dostaje email z linkiem do pobrania (lub direct download).

### Zawartość exportu
- `profile.json` — dane profilu.
- `workouts.json` — wszystkie workouty.
- `workouts_raw/` — raw TCX/GPX/FIT files.
- `feedback.json` — feedback ze wszystkich treningów.
- `plan_snapshots.json` — historia planów.
- `consents.json` — historia zgód.
- `integration_accounts.json` — info które integracje były podłączone.

### Oczekiwane API
- `POST /api/me/export` — uruchamia async job.
- `GET /api/me/export/{job_id}` — status / download link.

### Kryteria akceptacji (P0)
- Export dostarczony w ciągu 30 dni (RODO: 1 miesiąc).
- Format: JSON + raw files w ZIP.
- User może zażądać exportu maks 2× /miesiąc (rate limit).

### Testy / smoke
- Test backend: export job → ZIP zawiera wszystkie tabele.

### Uwagi produktowe
RODO Art. 20 (Right to Data Portability). **Nieopcjonalne dla użytkowników z UE.**

---

## US-PRIVACY-004 — Usunięcie konta i wszystkich danych

**Typ:** happy path (compliance)
**Persona:** każda
**Status:** missing
**Priorytet:** P0 (przed launch)

### Stan wejściowy
User chce usunąć swoje konto.

### Kroki użytkownika
1. Profil → Moje dane → "Usuń konto".
2. Modal ostrzegawczy: "Ta operacja jest nieodwracalna. Twoje dane zostaną usunięte. [Nie] [Tak, usuń]".
3. Wymaga ponownego wpisania hasła.
4. Submit.

### Oczekiwane zachowanie
- Backend usuwa wszystkie dane usera (cascade):
  - `users` → `user_profiles`, `workouts`, `workout_raw_tcx`, `plan_snapshots`, `training_feedback_v2`, `integration_accounts`, `integration_sync_runs`, `training_signals_v1/v2`, `plan_compliance_v1/v2`, `training_alerts_v1`, `training_weeks`.
- Tokeny revoked.
- Email potwierdzający usunięcie.
- Logout natychmiastowy.

### Oczekiwane API
- `DELETE /api/me` z body `{ password: ... }`.

### Kryteria akceptacji (P0)
- Wszystkie dane usunięte w ciągu 30 dni (RODO).
- Backup retention policy: dane mogą zostać w backupach 7-30 dni, potem usunięte.
- Audit log: timestamp usunięcia (bez personalnie identyfikujących danych).

### Testy / smoke
- Test backend: delete user → wszystkie tabele clear.
- Test backend: backup retention.

### Uwagi produktowe
RODO Art. 17 (Right to Erasure / Right to Be Forgotten). **Nieopcjonalne.**

**Decyzja produktowa:** czy zachować "soft delete" (oznaczyć jako deleted, dane fizycznie zostają) czy "hard delete" (fizyczne usunięcie). Sugestia: hard delete dla głównych tabel + audit log z anonimowym ID.

---

## US-PRIVACY-005 — Granica informacji treningowej i medycznej

**Typ:** error / compliance
**Persona:** P-RETURN, każda osoba zgłaszająca ból
**Status:** missing (jako wytyczna w UI)
**Priorytet:** P0

### Stan wejściowy
User zgłasza ból w profilu lub w post-workout formularzu.

### Wymóg
Aplikacja **nie udziela porad medycznych**. Każdy komunikat dotyczący bólu/kontuzji musi:
- (a) odnotować zgłoszenie,
- (b) złagodzić plan (technicznie OK),
- (c) **eksplicytnie polecić konsultację** z fizjoterapeutą / lekarzem.

### Przykładowy komunikat (dobry)
> "Zgłoszenie bólu odnotowane. Plan został złagodzony — nie planujemy dziś intensywnych sesji. Jeśli ból się utrzymuje, skonsultuj się z fizjoterapeutą lub lekarzem. MarcinCoach nie zastępuje porady medycznej."

### Przykładowy komunikat (zły, do zakazania)
> "Wygląda na zapalenie ścięgna Achillesa. Zalecam ICE + odpoczynek 2 tygodnie."

### Kryteria akceptacji (P0)
- Każde miejsce w UI gdzie pojawia się ból/kontuzja ma disclaimer.
- Plan automatycznie się łagodzi (US-PLAN-016).
- Zero diagnozowania. Zero zalecania konkretnych leków/zabiegów.

### Testy / smoke
- Manual review tekstów UI.
- Code review: brak template'ów typu "Wygląda na...".

### Uwagi produktowe
**Granica prawna.** Bez tego MarcinCoach może być uznany za urządzenie medyczne (regulowane). To by była katastrofa kosztowa i prawna.

---

## US-PRIVACY-006 — Dane zdrowotne (ból, kontuzje, HR) jako wrażliwe

**Typ:** compliance
**Persona:** każda
**Status:** unknown / partial
**Priorytet:** P0

### Wymóg
Dane zdrowotne (`hasCurrentPain`, opis bólu, HR, historia kontuzji) są **danymi szczególnej kategorii** w RODO Art. 9.

### Co to oznacza
- Wymagana **explicit consent** (nie wystarczy ogólna zgoda na regulamin).
- Wymagana wyraźna informacja co z tymi danymi robimy.
- Wymagane szyfrowanie at-rest (jeśli jeszcze nie ma).
- Wymagane szyfrowanie in-transit (HTTPS — już jest).
- Audit access (kto czytał te pola).

### Kroki w UI
- Pole "Ból" w onboardingu / formularzu post-workout ma adnotację:
  > "Te dane pomagają dostosować plan i są traktowane szczególnie. [Czytaj więcej]"
- Link do Privacy Policy section about health data.

### Kryteria akceptacji (P0)
- Backend: kolumny `has_current_pain`, `pain_description`, HR data — szyfrowane at-rest LUB jasne uzasadnienie czemu nie.
- UI: disclaimer obok pól zdrowotnych.
- Privacy policy ma sekcję "Dane zdrowotne".

### Testy / smoke
- Code review: explicit consent przy zbieraniu danych zdrowotnych.

### Uwagi produktowe
**Decyzja architektoniczna:** czy szyfrować at-rest (np. AES dla `pain_description`)? Argumenty za: RODO Art. 9 + 32. Argumenty przeciw: trudniej query/analyze. Sugestia: szyfrować `pain_description` (free text), pozostawić unencrypted `has_current_pain` (boolean).

---

## US-PRIVACY-007 — Logi zgód i historia

**Typ:** compliance
**Persona:** ops/admin
**Status:** missing
**Priorytet:** P0

### Wymóg
Każda zgoda musi mieć:
- timestamp,
- wersję dokumentu (TOS v1.0, Privacy v1.0),
- IP użytkownika (opcjonalnie),
- user agent (opcjonalnie).

### Kiedy logować
- Rejestracja (akceptacja TOS, Privacy).
- Akceptacja nowej wersji (po update dokumentu).
- Włączenie/wyłączenie integracji.
- Włączenie marketingu.

### Tabela `user_consents`
```sql
CREATE TABLE user_consents (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    consent_type VARCHAR(64), -- 'tos', 'privacy', 'marketing', 'integration_garmin'
    consent_version VARCHAR(16),
    accepted_at TIMESTAMP,
    revoked_at TIMESTAMP NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL
);
```

### Kryteria akceptacji (P0)
- Każda zgoda ma audit trail.
- User może w UI zobaczyć "co zaakceptowałem i kiedy".
- Revoke tworzy nowy wpis (nie usuwa starego).

### Uwagi produktowe
**Bez tego nie ma jak udowodnić** że user wyraził zgodę. Audytor RODO będzie tego pytał.

---

## US-PRIVACY-008 — Sprostowanie / edycja danych

**Typ:** compliance
**Persona:** każda
**Status:** partial (profile edit istnieje, missing wpływ na audit)
**Priorytet:** P1

### Wymóg
RODO Art. 16 — prawo do sprostowania.

### Kroki użytkownika
1. Edytuje email, dane profilu (US-ONBOARD-007).
2. Zmiana zapisana.

### Kryteria akceptacji (P1)
- User może edytować dane przez UI.
- Email change wymaga weryfikacji (link na nowy email).

### Testy / smoke
- Test e2e: zmiana email z weryfikacją.

---

## US-PRIVACY-009 — Cookies i tracking

**Typ:** compliance
**Persona:** każda
**Status:** unknown
**Priorytet:** P1

### Wymóg
Jeśli używamy cookies (np. dla sesji) — **cookie banner**.
Jeśli używamy analytics (Google Analytics, Plausible) — **explicit consent**.

### Aktualnie
- Sesja jest w `localStorage` (sprawdzić, ale tak wynika z notatek).
- Brak GA/Plausible (do potwierdzenia).

### Jeśli analytics jest
- Cookie banner na pierwszej wizycie.
- Banner: "Akceptuję wszystkie / Tylko niezbędne / Konfiguruj".
- Zgoda persisted (cookie lub localStorage).

### Kryteria akceptacji (P1)
- Bez analytics: brak cookie bannera (oszczędność czasu i UX).
- Z analytics: pełny banner zgodny z RODO + e-Privacy.

### Testy / smoke
- Audit cookies w produkcji.

---

## US-PRIVACY-010 — Komunikacja po breachu

**Typ:** error / incident response
**Persona:** ops/admin
**Status:** missing
**Priorytet:** P1

### Wymóg
RODO Art. 33-34 — w przypadku breachu (wycieku danych) zgłoszenie do UODO w 72h + komunikacja do użytkowników.

### Plan reakcji
- Procedura wykrywania incydentów.
- Lista kontaktów: ops, prawnik, UODO.
- Template emaila do użytkowników.
- Audit jakie dane wyciekły, kogo dotyczy.

### Kryteria akceptacji (P1)
- Plan istnieje i jest udokumentowany.
- Test scenariusza incydentu (tabletop exercise) raz w roku.

### Uwagi produktowe
**Nie bezpośrednio scenariusz user-facing**, ale wymagany dla produkcji.

---

## Podsumowanie RODO compliance — checklist przed launchem

| Wymóg | Status | Scenariusz |
|---|---|---|
| Zgody przy rejestracji | missing | US-PRIVACY-001 |
| Polityka prywatności (dokument) | missing | docs |
| Regulamin (dokument) | missing | docs |
| Export danych | missing | US-PRIVACY-003 |
| Usunięcie konta | missing | US-PRIVACY-004 |
| Odłączenie integracji | unknown | US-PRIVACY-002 |
| Disclaimer medyczny | missing (UI) | US-PRIVACY-005 |
| Dane zdrowotne — encryption | unknown | US-PRIVACY-006 |
| Audit log zgód | missing | US-PRIVACY-007 |
| Edycja danych | partial | US-PRIVACY-008 |
| Cookie banner (jeśli potrzebny) | unknown | US-PRIVACY-009 |
| Plan incident response | missing | US-PRIVACY-010 |

**Tych 12 punktów to faktyczny blocker dla launchu z realnymi użytkownikami z UE.** Można zostawić jako wewnętrzne testy / closed beta, ale otwarcie publiczne wymaga zamknięcia większości tych braków.
