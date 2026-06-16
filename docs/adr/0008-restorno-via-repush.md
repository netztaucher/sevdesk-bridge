# ADR-0008: „Storno des Stornos" via Neu-Übertragung

## Kontext
Nach einem Storno soll die Rechnung wieder aktiviert werden können („Wiederübertragung"). Eine ausgestellte, festgeschriebene Gutschrift ist jedoch **GoBD-immutabel** und nicht löschbar.

## Entscheidung
Re-Push erzeugt eine **neue** Rechnung statt die Gutschrift zurückzunehmen:
```
1. aktuelles RE + GU nach _sevdesk_history archivieren
2. Invoice- + Storno-Meta löschen
3. push(force=true)   → neue Rechnung (RE-neu) mit aktuellem Datum
```
`force` umgeht den „erstattet"-Guard, da der FluentCart-Status nach Refund weiterhin `refunded` sein kann.

## Begründung
- Festgeschriebene Belege lassen sich nicht löschen (`resetToDraft` → „Already enshrined"). Eine Rücknahme der Gutschrift ist damit unmöglich.
- Buchhalterisch korrekt: ein ausgestelltes Storno wird **nie gelöscht**, sondern durch eine neue Rechnung „rückgängig" gemacht → GoBD-Kette *Verkauf → Storno → Neu-Verkauf*.
- Verworfene Alternativen:
  - **B (Gutschrift zurücknehmen):** nur bei nicht-festgeschriebener Gutschrift möglich → bei EÜR-Sofortbuchung nie gegeben.
  - **C (explizite Gegenrechnung):** wie A plus Verknüpfung, ohne echten Mehrwert.

## Konsequenzen
- Es entsteht eine dritte Belegnummer (RE-alt, GU, RE-neu) — korrekt + nachvollziehbar.
- Vollständige Historie pro Bestellung in `_sevdesk_history` (JSON-Array, mehrfach möglich).
- Button-Zustand „storniert" wird klickbar (`↻ Neu übertragen`).
