# ADR-0003: DOM-injizierter Button + schwebendes Confirm-Popover

## Kontext
Der Push-/Storno-Button soll pro Zeile in der FluentCart-Orders-Tabelle erscheinen. FluentCart ist eine **Vue-SPA (Element-UI)** und bietet **keinen Server- oder JS-Hook für Row-Actions**.

## Entscheidung
1. Button wird per **MutationObserver** in jede Order-Zeile injiziert (in die Zelle der Bestellnummer, direkt nach dem Order-Link).
2. Die Bestätigung (Ja/Nein) ist ein **schwebendes Popover an `document.body`** — nicht inline in der Tabellenzelle.
3. Button-Zustand wird pro Order-ID gecacht (`stateCache`) und bei Re-Render sofort wiederhergestellt.

## Begründung
- Ohne offiziellen Hook ist DOM-Injection der einzige Weg.
- **Problem:** Element-UI re-rendert Zeilen bei Hover → inline ins Zellen-DOM gesetzte Confirm-Buttons wurden weggewischt („Bestätigung verschwindet bei Hover").
- Ein Popover an `document.body` liegt **außerhalb** des Vue-verwalteten Tabellen-DOMs → Hover-/Re-Render-Zyklen berühren es nicht.
- Eine eigene Tabellen-**Spalte** würde dasselbe Re-Render-Problem haben (Vue baut die Spalte neu auf).
- State-Cache verhindert Flackern + unnötige Status-Requests bei Re-Render.

## Konsequenzen
- Abhängig von der DOM-Struktur von FluentCart (Selektor `a[href*="#/orders/"]`); FC-Updates können den Selektor brechen → robuste Fallbacks eingebaut.
- MutationObserver mit `requestAnimationFrame`-Debounce, um Re-Injection-Stürme zu vermeiden.
- Status der sichtbaren Zeilen wird gebündelt über `GET /status?ids=…` geladen.
