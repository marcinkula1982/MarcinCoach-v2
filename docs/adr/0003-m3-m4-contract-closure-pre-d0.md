# ADR 0003: Domkniecie kontraktu M3/M4 przed D0

## Status
Accepted

## Context

Przed D0 endpointy publiczne M3/M4 wymagaja jednoznacznego kontraktu.
W aktualnym stanie repo pojawily sie pola addytywne bez formalnej decyzji:

- `blockContext` w `/api/weekly-plan`,
- `blockContext` w `/api/training-context`,
- `adaptationType`, `confidence`, `decisionBasis` w `adjustments[]`.

Dodatkowo `signals.adaptation` pozostaje widoczne przez `/api/training-context`,
podczas gdy `/api/training-signals` ukrywa to pole.

Brak decyzji powoduje ryzyko utrwalania przypadkowego kontraktu.

## Decision

Przed D0 przyjmujemy nastepujace zasady kontraktowe:

### Publicznie dozwolone
- `blockContext` w `/api/weekly-plan`
- `blockContext` w `/api/training-context`

### Publicznie zakazane
- `adaptationType` w `adjustments[]`
- `confidence` w `adjustments[]`
- `decisionBasis` w `adjustments[]`
- `signals.adaptation` w `/api/training-context`

Kontrakt ma byc egzekwowany przez `ContractFreezeTest`.

## Consequences

### Pozytywne
- Kontrakt API jest jawny i testowalny przed D0.
- `training-context` i `training-signals` sa spojne w zakresie ukrywania `adaptation`.
- Pola audytowe/debugowe nie rozszerzaja publicznego API bez potrzeby.

### Negatywne / Ryzyka
- Klienci, ktorzy zaczeli korzystac z `adaptationType/confidence/decisionBasis`,
  beda musieli przejsc na stabilny kontrakt publiczny.
- Dla kazdej przyszlej zmiany addytywnej potrzebna jest aktualizacja ADR + testow freeze.

Data: 2026-04-21
Decydent: Marcin Kula - Project Owner
