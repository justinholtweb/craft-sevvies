---
title: Verwendung
slug: usage
order: 30
summary: Wie Steuerregeln ermittelt werden, was der Abgleich leistet, und die Arbeit im CP, in Twig und auf der Konsole.
---

## Wie eine Steuerregel ermittelt wird

Dafür gibt es Sevvies. Ein Checkout erzeugt mehrere rechtlich verschiedene Belege, und sevDesk bucht
klaglos den, den man ihm nennt.

Für jede Bestellung wertet Sevvies das Rechnungsland und die USt-IdNr. des Kunden aus:

| Fall | Regel | Was auf dem Beleg steht |
| --- | --- | --- |
| Rechnungsland ist Ihr Heimatland | `taxRule 1` Umsatzsteuerpflichtige Umsätze | Umsatzsteuer zum berechneten Satz |
| Außerhalb der EU | `taxRule 2` Ausfuhren | Steuerfreie Ausfuhrlieferung, ohne Steuer |
| EU-Unternehmen mit gültiger USt-IdNr. | `taxRule 3` Innergemeinschaftliche Lieferungen | Steuerfreie innergemeinschaftliche Lieferung — Reverse Charge |
| EU-Endkunde, OSS an | `taxRule 18/19/20` One Stop Shop | Besteuerung im Bestimmungsland |
| EU-Endkunde, OSS aus | `taxRule 1` | Inländische Umsatzsteuer |
| Kleinunternehmer, jede Bestellung | `taxRule 11` §19 UStG | Gemäß §19 UStG wird keine Umsatzsteuer berechnet |

**Die Begründung wird auf jeder Rechnung festgehalten.** In der Bestellansicht steht dann etwa
*„EU-Geschäftskunde in AT mit USt-IdNr. ATU12345678 — innergemeinschaftliche Lieferung, Reverse
Charge."* Wenn ein Steuerberater fragt, warum eine bestimmte Rechnung ohne Steuer ausgestellt wurde,
steht die Antwort auf der Rechnung.

### Wann Sevvies sich weigert

Manche Bestellungen lassen sich so, wie sie sind, nicht korrekt abrechnen. Sevvies hält an, statt
etwas Falsches zu buchen:

- **Eine USt-IdNr. aus dem falschen Land.** Eine österreichische USt-IdNr. auf einer französischen
  Rechnungsadresse — eines von beidem ist falsch, und Reverse Charge auf dem falschen ist ein
  Problem.
- **Eine fehlerhafte USt-IdNr.**, wo Reverse Charge erwartet wurde. Sevvies prüft den Aufbau der
  Nummer gegen das Format ihres Landes.
- **Ein Steuersatz, den die Regel nicht tragen kann.** sevDesk akzeptiert je Regel nur bestimmte
  Sätze — 0, 7 oder 19 unter Regel 1, ausschließlich 0 bei einer Ausfuhr. Eine Ausfuhr, die 19 %
  berechnet hat, heißt, dass Ihre Commerce-Steuerregeln und das Zielland nicht zusammenpassen.
  Sevvies benennt das, statt sevDesk den Beleg mit einer Fehlermeldung ablehnen zu lassen, die kein
  Feld nennt.
- **Ein Land, das sevDesk auf der Rechnungsadresse nicht kennt.**

Jeder dieser Fälle markiert die Bestellung als **Braucht Aufmerksamkeit** samt Begründung, und es
wird nichts gesendet.

Sevvies prüft das *Format* einer USt-IdNr., nicht ihre Registrierung. Registrierungen bestätigt nur
VIES — das würde Ihren Checkout von fremder Verfügbarkeit abhängig machen und ist eine Entscheidung
mit rechtlichem Gewicht. Die bleibt bei Ihnen.

## Der Abgleich

Jede Rechnung wird zweimal summiert.

**Vor dem Senden** addiert Sevvies die Positionen und Rabatte, die es senden will, und vergleicht das
mit dem, was Commerce tatsächlich berechnet hat. Weichen sie ab, wird nichts gesendet.

**Nach dem Senden** vergleicht Sevvies sevDesks eigene `sumGross` mit derselben Zahl. Weicht *die*
ab, wird die Bestellung als **Braucht Aufmerksamkeit** markiert, die sevDesk-ID bleibt erhalten,
damit Sie den Beleg finden, und die Begründung steht in der Zeile.

Eine Rechnung über den falschen Betrag ist schlimmer als eine fehlende — das ist also keine Warnung,
die man wegklicken kann.

## Arbeiten im Control Panel

**Sevvies → Rechnungen** listet jede Bestellung, die Sevvies angefasst hat, filterbar nach Zustand:
Erstellt, Gesendet, Gebucht, Braucht Aufmerksamkeit, Fehlgeschlagen, Testlauf, Ausstehend.

Eine geöffnete Zeile zeigt die sevDesk-Rechnung, die Steuerregel samt Begründung, jede Position mit
Netto, Steuer und Brutto, beide Summen nebeneinander und die JSON-Daten, die gesendet würden. Von
dort können Sie die Rechnung erstellen, als gesendet markieren, per E-Mail senden, die Zahlung
buchen, das PDF herunterladen oder die Verknüpfung lösen.

Jede Commerce-Bestellansicht trägt außerdem einen Bereich **sevDesk** — ob gebucht, über welchen
Betrag, und ein Link direkt zur Detailansicht.

**Sevvies → Protokoll** enthält jede Anfrage, Entscheidung und Auslassung samt Anfrage- und
Antwortdaten.

## Templates

`craft.sevvies` ist schreibgeschützt. Ein Template kann eine Rechnung anzeigen, aber keine
ausstellen.

```twig
{% if craft.sevvies.isInvoiced(order) %}
    <p>Rechnung {{ craft.sevvies.invoiceNumber(order) }}</p>
{% endif %}

{% set pdf = craft.sevvies.pdf(order) %}
{% if pdf %}
    <a href="{{ pdf.url }}">Rechnung herunterladen</a>
{% endif %}
```

Einem Geschäftskunden vor dem Kauf zeigen, wie sein Warenkorb steuerlich behandelt wird:

```twig
{% set vat = craft.sevvies.taxRule(cart) %}
{% if vat.zeroRated %}
    <p class="notice">{{ vat.text }}</p>
{% endif %}
```

`taxRule()` liefert `rule`, `label`, `reason`, `text` und `zeroRated`.

## Konsole

```sh
php craft sevvies/tools/check              # Token prüfen, Konten und Benutzer auflisten
php craft sevvies/sync/preview <orderId>   # Daten und Steuerbegründung ausgeben
php craft sevvies/sync/order <orderId>     # eine Bestellung buchen
php craft sevvies/sync/pending --limit=50  # alle noch nicht abgerechneten Bestellungen nachtragen
php craft sevvies/tools/prune-log
php craft sevvies/tools/flush-cache        # zwischengespeicherte sevDesk-IDs verwerfen
```

`sync/preview` erzeugt die Daten über denselben Codepfad wie ein echter Versand — was es ausgibt,
ist also genau das, was sevDesk erhalten würde:

```
VAT rule: Innergemeinschaftliche Lieferungen (taxRule 3)
Reason:   EU-Geschäftskunde in AT mit USt-IdNr. ATU12345678 — innergemeinschaftliche Lieferung, Reverse Charge.
Commerce: 238.00 EUR
sevDesk:  238.00 EUR
```

`sync/pending --dry-run` erzeugt und protokolliert jede ausstehende Bestellung, ohne etwas zu senden
— der sichere Weg, einen Nachtrag vorher zu prüfen.

## Bestehende Bestellungen nachtragen

1. **Testlauf** einschalten.
2. `php craft sevvies/sync/pending --limit=25`
3. **Sevvies → Protokoll** lesen und alles beheben, was als *Braucht Aufmerksamkeit* markiert ist.
4. Testlauf ausschalten und den Befehl ohne `--dry-run` erneut ausführen.

Bereits abgerechnete Bestellungen werden übersprungen, der Befehl kann also gefahrlos mehrfach
laufen.
