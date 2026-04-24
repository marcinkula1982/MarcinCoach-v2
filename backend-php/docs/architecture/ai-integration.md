# AI Integration — ustalenia i decyzje

## Zasada nadrzędna

AI **nie jest silnikiem planu**. Backend (deterministyczny) robi 80% logiki.
AI uruchamia się tylko jako warstwa eskalacji — gdy backend nie umie bezpiecznie podjąć decyzji.

### Kiedy backend NIE woła AI

- Generowanie standardowego planu tygodniowego
- Obliczanie stref HR, tempa, driftu
- Wykrywanie przeciążenia i flagowanie fatigue
- Adaptacje planu oparte na regułach (reduce_load, recovery_focus itp.)

### Kiedy backend MOŻE wołać AI

- Dane z TCX są sprzeczne lub niekompletne
- Użytkownik zgłasza ból, uraz lub chorobę
- Użytkownik zmienia cel w trakcie cyklu
- Backend nie ma pewności decyzji (conflict między sygnałami)
- Objaśnienie planu w języku naturalnym (PL) dla użytkownika

---

## Modele

| Rola | Model | Endpoint | Koszt (input/output) |
|------|-------|----------|----------------------|
| Objaśnienie planu | `gpt-5.4-nano` | Responses API | $0.20 / $1.25 per 1M |
| Eskalacja (ból, choroba, konflikt) | `gpt-5.4-mini` | Responses API | $0.75 / $4.50 per 1M |

Konfiguracja przez `.env`:
```
AI_PLAN_PROVIDER=openai
AI_PLAN_MODEL=gpt-5.4-nano
AI_ESCALATION_MODEL=gpt-5.4-mini
OPENAI_API_KEY=sk-proj-...
```

### Dlaczego nie gpt-5.4 (pełny)

Pełny `gpt-5.4` ($2.50/$15.00) jest zbędny dla MarcinCoach na etapie MVP.
Przypadki eskalacji są rzadkie — `gpt-5.4-mini` daje wystarczającą jakość rozumowania przy 3× niższym koszcie.

### Dlaczego nie o3-pro

`o3-pro` ($20/$80) — zbyt drogi jako baza portalu. Możliwy jako druga eskalacja
w wyjątkowych przypadkach w przyszłości (np. złożony uraz + zmiana celu + brak danych).

---

## API: Responses API (nie Chat Completions)

Dla całej rodziny `gpt-5.4` OpenAI zaleca **Responses API** (`/v1/responses`).
Chat Completions (`/v1/chat/completions`) pozostaje dla starszych modeli (np. `gpt-4o`).

### Parametry dla gpt-5.4-nano (objaśnienie planu)

```php
Http::withToken($apiKey)
    ->post('https://api.openai.com/v1/responses', [
        'model'            => 'gpt-5.4-nano',
        'max_output_tokens' => 2000,
        'instructions'     => '...system prompt...',
        'input'            => '...dane JSON...',
        'reasoning'        => ['effort' => 'none'],  // szybciej, taniej
        'text'             => ['verbosity' => 'low'], // zwięzły output
    ]);
```

Odpowiedź: `data_get($response->json(), 'output_text')`

### Uwaga: temperature i top_p

Parametry `temperature` i `top_p` działają **tylko** gdy `reasoning.effort = none`.
Przy wyższym effort — używać `text.verbosity` i `reasoning.effort`.

---

## Cache

Cache planu (`AiCacheService`) trzyma odpowiedź przez jeden dzień UTC (wygasa o północy).
Klucz: `ai:plan:{userId}:{YYYY-MM-DD}:days={windowDays}`

Wymaga `CACHE_STORE=file` (lub `database`) w `.env`.
**Uwaga:** Laravel 11 czyta `CACHE_STORE`, nie `CACHE_DRIVER` — zmienna musi mieć właściwą nazwę.

Wyczyszczenie cache na serwerze:
```bash
php artisan cache:clear
```

---

## Prywatność danych

**Nie włączać** opcji "Share inputs and outputs with OpenAI" w dashboardzie OpenAI.
Dane treningowe i zdrowotne użytkowników są danymi osobowymi (RODO) —
użytkownicy nie wyrażali zgody na ich użycie do treningu modeli.
