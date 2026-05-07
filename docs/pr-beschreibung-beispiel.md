# Perfekte PR-Beschreibung – Beispiel

## Aufgabe (Ticket-Verständnis)

**Ticket:** `TPG-1234` – Beim Import von Pegasus-Bestellungen wird bei Kunden ohne Rechnungsadresse ein Fehler geworfen.

Ich habe verstanden, dass: Pegasus-Kunden optional keine Rechnungsadresse haben können. Shopware erwartet aber zwingend eine. Ziel ist es, einen sauberen Fallback zu implementieren, ohne bestehende Importe zu brechen.

---

## Bug-Analyse

**Ursache:** In `OrderMapper::mapCustomerAddress()` wird direkt auf `$pegasusOrder->getBillingAddress()` zugegriffen, ohne vorher zu prüfen ob `null`.

**Tritt auf wenn:** Pegasus-Bestellung wurde ohne Rechnungsadresse angelegt (z. B. Barzahler an der Kasse).

**Reproduktion:**
1. Pegasus-Bestellung `#PEG-9981` in Testumgebung importieren (Datensatz existiert in der DB)
2. Command ausführen: `bin/console pegasus:order:import --id=9981`
3. → Exception: `Call to a member function getStreet() on null`

---

## Umsetzung

- `OrderMapper::mapCustomerAddress()` prüft nun ob Billing-Adresse vorhanden
- Falls nicht: Lieferadresse wird als Fallback verwendet
- Falls auch keine Lieferadresse: Exception mit klarer Fehlermeldung statt PHP-Fehler
- Keine Änderung an der bestehenden Mapping-Logik für normale Bestellungen

**Warum so:** Lieferadresse ist in Pegasus immer Pflicht, daher sicherer Fallback. Eine leere Dummy-Adresse wäre schlechter, weil sie stille Datenfehler erzeugt.

---

## Notwendige Schritte

```bash
# Kein Migration nötig
# Testdatensatz ist bereits in der DB vorhanden
bin/console pegasus:order:import --id=9981
```

---

## Betroffene Klassen

| Klasse | Änderung |
|---|---|
| `OrderMapper` | Null-Check + Fallback-Logik |
| `OrderMapperTest` | 2 neue Test-Cases |

---

## Tests

- Unit-Test: `OrderMapperTest::testMapsDeliveryAddressAsFallback()`
- Unit-Test: `OrderMapperTest::testThrowsOnMissingBothAddresses()`
- Manuell: Import mit `#PEG-9981` durchgeführt → Bestellung korrekt angelegt

---

## Test-Anleitung für Reviewer

1. Branch auschecken, `composer install`
2. `bin/console pegasus:order:import --id=9981` ausführen
3. **Erwartetes Ergebnis:** Bestellung wird importiert, Lieferadresse als Billing gesetzt
4. **Edge Case:** `--id=9982` testen (beide Adressen fehlen) → saubere Exception erwartet, kein PHP-Fehler
