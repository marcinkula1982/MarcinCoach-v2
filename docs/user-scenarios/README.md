# MarcinCoach v2 — scenariusze użytkownika

Stan dokumentu: 2026-04-28.
Cel: jeden punkt prawdy o tym, co użytkownik realnie może dziś zrobić w aplikacji, czego nie może, i co planujemy.

## Po co ten dokument

Backlog techniczny i roadmapa odpowiadają na pytanie *co zaimplementujemy*. Ten dokument odpowiada na pytanie *co zobaczy użytkownik i co się stanie* w każdym istotnym przypadku — happy path, brak danych, błąd integracji, wygasła sesja, przerwany flow, powrót po przerwie.

Każdy scenariusz ma status, więc dokument jest też mapą rzeczywistości — nie listą życzeń.

## Struktura

| Plik | Zakres |
|---|---|
| [01-onboarding-data-sources.md](01-onboarding-data-sources.md) | Rejestracja, login, wybór źródła danych, pytania uzupełniające, skip, "brakująca aplikacja" |
| [02-import-data-quality.md](02-import-data-quality.md) | Upload TCX/GPX/FIT, multi-upload, ZIP, walidacja sportu, sanity check, duplikaty |
| [03-analysis-profile.md](03-analysis-profile.md) | Analiza zaimportowanych danych, profil użytkownika, propozycja stref, low-data confidence |
| [04-planning-feedback-loop.md](04-planning-feedback-loop.md) | Rolling plan 14 dni, feedback po treningu, korekta planu, treningi spontaniczne, cross-training |
| [05-integrations.md](05-integrations.md) | Garmin, Strava, Polar, Suunto, Coros, race profile, Garmin Event Dashboard |
| [06-auth-session-production-smoke.md](06-auth-session-production-smoke.md) | Sesja, wygasły token, retry, smoke produkcyjny |
| [07-privacy-gdpr.md](07-privacy-gdpr.md) | Zgody integracji, odłączenie, usunięcie danych, export, dane zdrowotne |
| [08-manual-check-in.md](08-manual-check-in.md) | Użytkownik bez integracji i bez plików: wykonanie, pominięcie, RPE, ból, feedback manualny |
| [it-consultation-scenarios.md](it-consultation-scenarios.md) | Scalony dokument konsultacyjny dla działu IT: schematy, decyzje, pełna mapa scenariuszy |
| [coverage-matrix.md](coverage-matrix.md) | Macierz pokrycia: scenariusz × frontend × backend × test × smoke × status |
| [gaps-and-next-steps.md](gaps-and-next-steps.md) | Czego brakuje, co blokuje MVP, rekomendowana kolejność wdrażania |
| [../functional-schema.md](../functional-schema.md) | Odtworzony schemat funkcjonalny portalu: moduły, ekrany, przepływy, API i status MVP |

## Persony

Każdy scenariusz ma persona reference. Poniżej skrót.

### P-NOVICE — Anna, początkująca, brak danych
Wiek 32, biega od 4 miesięcy, bez zegarka, bez plików treningowych. Cel: ukończyć pierwsze 10 km. Łączy się z aplikacją bez integracji, korzysta z odpowiedzi w wizardzie i manualnych check-inów po treningu.

### P-GARMIN — Marek, średniozaawansowany Garmin user
Wiek 42, biega od 5 lat, Garmin Forerunner 265, średnio 30–40 km/tydz. Cel: 10 km w 50:00. Loguje się do Garmin Connect przy onboardingu, ma 8–15 treningów w ostatnich 30 dniach.

### P-MULTI — Kasia, zaawansowana, multi-source
Wiek 36, 6 lat biegania, używa Garmina + Stravy, sporadycznie wgrywa pliki. Cel: maraton sub-3:30. Ma starty w kalendarzu, śledzi tempo progowe i strefy HR.

### P-RETURN — Tomek, powrót po kontuzji
Wiek 45, biega od dekady, 3 miesiące przerwy z powodu kontuzji łydki. Wraca ostrożnie, 2 razy w tygodniu. Cel: półmaraton za 5 miesięcy. Zgłasza ból w onboardingu.

### P-COROS — Piotr, użytkownik Coros bez integracji
Wiek 38, Coros Pace Pro, 4 lata biegania. Brak oficjalnej integracji w MarcinCoach. Eksportuje pliki z aplikacji Coros (FIT/TCX) i wgrywa ręcznie. Reprezentuje też Polar/Suunto użytkowników do czasu integracji.

## Konwencje

### ID scenariuszy
Format: `US-{OBSZAR}-{NUMER}`, gdzie obszar to 3–7 liter.
Przykłady: `US-ONBOARD-001`, `US-GARMIN-014`, `US-IMPORT-022`, `US-MANUAL-001`.

### Statusy
- **implemented** — działa na produkcji 28.04.2026, zostało zweryfikowane lub jest pokryte testami.
- **partial** — działa fragment (np. backend tak, UX nie), reszta widoczna jako luka.
- **missing** — nie istnieje, jest wymagane lub planowane.
- **unknown** — kod istnieje, ale brak smoke/testu/potwierdzenia że działa end-to-end na produkcji.

### Priorytety
- **P0** — blocker MVP. Bez tego MVP się nie zamyka.
- **P1** — istotne dla launch szerszego niż MVP. Da się żyć tymczasowo, ale długo nie.
- **P2** — przyszłość lub edge case. Nie wstrzymuje launch.

### Format scenariusza
Każdy scenariusz zawiera:
1. ID i nazwa
2. Typ: happy path / edge case / error / regression
3. Persona / stan wejściowy
4. Preconditions
5. Kroki użytkownika
6. Oczekiwane zachowanie UI
7. Oczekiwane endpointy / API
8. Oczekiwane zmiany danych
9. Kryteria akceptacji (P0 szczegółowe, P1/P2 ogólne)
10. Testy / smoke
11. Priorytet
12. Status
13. Uwagi produktowe

### Język
Opisy po polsku. ID, kody błędów, endpointy, nazwy pól techniczne — po angielsku.

## Zasady stojące za scenariuszami

1. **Backend deterministyczny jest źródłem prawdy.** AI to warstwa eskalacji, nie silnik planu.
2. **Aplikacja nie udaje pewności gdy nie ma danych.** Mała próbka = niski confidence + ostrożny plan.
3. **Skip nie jest karą.** Użytkownik bez integracji ma działającą aplikację, tylko z mniejszym zakresem.
4. **Cross-training jest pełnoprawnym treningiem.** Nie ignorujemy aktywności innych niż bieg, oznaczamy je i liczymy wpływ.
5. **Każda integracja ma fallback w postaci pliku.** Nawet bez Coros API user importuje FIT/TCX/GPX.
6. **Manual check-in jest pełnoprawnym fallbackiem.** User bez integracji i bez plików nadal domyka pętlę: plan → wykonanie/pominięcie → feedback → kolejny plan.

## Czego ten dokument NIE zawiera

- Dokumentacji technicznej API (jest w `backend-php/docs/`).
- Specyfikacji UI / wireframes.
- Architektury AI (jest w `backend-php/docs/architecture/ai-integration.md`).
- Historycznych planów Node/M-pakietów (są w `docs/archive/`).

## Aktualizacja dokumentu

Po każdym wdrożeniu nowej funkcjonalności:
1. Zaktualizuj status w odpowiednim scenariuszu (missing → partial → implemented).
2. Zaktualizuj wpis w `coverage-matrix.md`.
3. Jeśli pojawia się nowy edge case — dopisz scenariusz, nie modyfikuj istniejącego.

Po każdej zmianie roadmapy:
1. Sprawdź czy `gaps-and-next-steps.md` jest aktualny.
2. Sprawdź czy nie pojawia się nowy obszar, który wymaga osobnego pliku scenariuszy.
