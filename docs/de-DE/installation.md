---
title: Installation
slug: installation
order: 10
summary: Voraussetzungen, Installation und der sichere Weg zur ersten Rechnung in sevDesk.
---

## Voraussetzungen

- Craft CMS 5.3 oder neuer
- Craft Commerce 5.0 oder neuer
- PHP 8.2 oder neuer
- Ein sevDesk-Konto mit API-Zugang

## Installation

Im Projektverzeichnis:

```sh
composer require justinholtweb/craft-sevvies
php craft plugin/install sevvies
```

Oder **Sevvies** im Plugin Store suchen und dort installieren.

Sevvies kostet 99 $ pro Craft-Installation. Entwicklungs- und Testinstallationen sind kostenlos.

## Ihr API-Token

sevDesk vergibt pro Administrator ein API-Token — eine hexadezimale Zeichenfolge mit 32 Zeichen. In
sevDesk unter **Einstellungen → Benutzer → Ihr Benutzer** finden Sie das API-Token.

Legen Sie es in der `.env` ab, statt es in die Datenbank zu schreiben:

```
SEVDESK_TOKEN="a1b2c3d4e5f6..."
```

Tragen Sie dann unter **Sevvies → Einstellungen** als API-Token `$SEVDESK_TOKEN` ein.

## Verbindung testen

Klicken Sie auf der Einstellungsseite auf **Verbindung testen**. Bei gültigem Token meldet Sevvies,
auf welchem Buchhaltungssystem Ihr Konto läuft, und listet Ihre Konten und sevDesk-Benutzer auf —
deren IDs brauchen Sie für zwei weitere Einstellungen, das ist also der schnellste Weg dorthin.

Auf der Kommandozeile:

```sh
php craft sevvies/tools/check
```

## Die wichtigsten Einstellungen

Drei Einstellungen entscheiden darüber, ob Ihre Belege korrekt sind. Alles andere hat Zeit.

**Besteuerung** — *Regelbesteuerung* oder *Kleinunternehmer (§19 UStG)*. Kleinunternehmer berechnen
keine Umsatzsteuer; jede Rechnung wird unter dieser einen Regel erstellt.

**Heimatland** — der zweibuchstabige Code des Landes, in dem Sie besteuert werden. `DE`, sofern Sie
es nicht besser wissen.

**Positionspreise** — ob Ihr sevDesk-Konto die von Sevvies gesendeten Preise als netto oder brutto
liest. Diese Einstellung lohnt sich zu verstehen: warum, und was Sevvies tut, wenn sie falsch steht,
steht unter [Konfiguration](../configuration).

## Erst ein Testlauf

Schalten Sie **Testlauf** ein, bevor Sie sonst irgendetwas einschalten.

Im Testlauf erzeugt Sevvies jede Rechnung genau so, wie es sie auch wirklich erzeugen würde, und
schreibt die vollständigen Daten ins Protokoll — gesendet wird nichts. So prüfen Sie Ihre
Steuereinstellungen an Ihren eigenen Bestellungen, bevor ein einziger Beleg in Ihre Buchhaltung
gelangt.

Öffnen Sie eine abgeschlossene Bestellung, nutzen Sie den Bereich **Sevvies** und klicken Sie auf
**Vorschau und buchen**. Sie sehen die Positionen, die Summen, die gewählte Steuerregel und deren
Begründung.

Wenn mehrere echte Bestellungen richtig aussehen, schalten Sie den Testlauf wieder aus.

## Zeitpunkt der Rechnungsstellung

Unter **Sevvies → Einstellungen → Wann Rechnung stellen**:

- **Die Bestellung ist bezahlt** — Standard, und für die meisten Shops die richtige Antwort
- **Die Bestellung ist abgeschlossen** — Rechnung beim Checkout, vor dem Zahlungseingang
- **Die Bestellung erreicht einen Status** — Rechnung, sobald Sie eine Bestellung z. B. auf
  *Versendet* setzen
- **Nie** — Sie buchen von Hand über die Bestellansicht

Lassen Sie **Im Hintergrund senden** eingeschaltet. Der Checkout wartet nie auf sevDesk, und ein
sevDesk-Ausfall kann nie eine Zahlung verhindern.
