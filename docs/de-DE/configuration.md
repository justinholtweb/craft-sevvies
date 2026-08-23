---
title: Konfiguration
slug: configuration
order: 20
summary: Alle Einstellungen — und die beiden, die darüber entscheiden, ob Ihre Buchhaltung stimmt.
---

## Verbindung

**API-Token** — Ihr 32-stelliges sevDesk-Token. Nutzen Sie eine Umgebungsvariable.

**API-Basis-URL** — nur ändern, wenn Sie Sevvies auf einen Mock-Server zeigen lassen.

**Buchhaltungssystem** — sevDesk 2.0 nutzt Steuerregeln, 1.0 Steuertypen. Sevvies fragt Ihr Konto,
welches es ist, und sendet beides — Sie sollten das hier nicht setzen müssen. Die Einstellung
existiert für den seltenen Fall, dass ein Konto mitten in der Migration widersprüchlich antwortet.

## Positionspreise

**Diese Einstellung lohnt sich am meisten zu verstehen.**

sevDesk entscheidet selbst, ob die empfangenen Positionspreise netto oder brutto sind. Die API kann
Ihnen nicht sagen, was Ihr Konto erwartet. Sevvies sendet ein `showNet`-Kennzeichen, das mitteilt,
was gemeint war — und prüft anschließend die zurückgemeldete Summe.

Hat Ihr Konto die Preise andersherum gelesen, ist die Rechnungssumme Ihre echte Summe mit
aufgeschlagener oder herausgerechneter Umsatzsteuer — 119,00 € statt 100,00 €, oder umgekehrt. Es
gibt keine Fehlermeldung. Der Beleg sieht völlig normal aus.

Sevvies erkennt genau dieses Muster. Wenn die von sevDesk gebuchte Summe die erwartete Summe mit
aufgeschlagener oder herausgerechneter Umsatzsteuer ist, sagt Sevvies das und benennt diese
Einstellung — statt Sie das auf der Umsatzsteuervoranmeldung herausfinden zu lassen.

## Umsatzsteuer

**Besteuerung** — *Regelbesteuerung* oder *Kleinunternehmer (§19 UStG)*.

**Heimatland** — das Land, in dem Sie besteuert werden.

**Steuerregel je Bestellung ermitteln** — standardmäßig an. Eingeschaltet leitet Sevvies die Regel
aus dem Rechnungsland und der USt-IdNr. des Kunden ab. Ausgeschaltet wird jede Rechnung unter der
Standardregel erstellt — die richtige Antwort für einen Shop, der ausschließlich im Inland verkauft.

**Feld für die USt-IdNr.** — der Handle des Felds mit der USt-IdNr. des Kunden. Leer lassen, dann
nutzt Sevvies die *Organization tax ID* der Rechnungsadresse, wo Crafts eigenes Adressfeld sie
ablegt.

**USt-IdNr. für Reverse Charge verlangen** — standardmäßig an. Reverse Charge ohne USt-IdNr. auf dem
Beleg ist kein Reverse Charge.

**One Stop Shop** — einschalten, wenn Sie für OSS registriert sind und EU-Endkunden die
Umsatzsteuer des Bestimmungslands berechnen. Geben Sie an, ob Sie Waren, elektronische
Dienstleistungen oder sonstige Dienstleistungen verkaufen; dafür gelten drei verschiedene
Steuerregeln.

**Standard-Steuerregel** — greift, wenn automatische Regeln aus sind oder nichts anderes passt.

**Steuertext überschreiben** — leer lassen, dann druckt Sevvies den Satz, den die gewählte Regel
verlangt, etwa *Steuerfreie innergemeinschaftliche Lieferung — Reverse Charge, Steuerschuldnerschaft
des Leistungsempfängers*. Nur setzen, wenn Ihr Steuerberater eine andere Formulierung bevorzugt.

## Beleg

**Zahlungsziel** — Tage bis zur Fälligkeit.

**Bezeichnung der Versandposition** — wie die Versandzeile auf dem Beleg heißt. Standard:
*Versandkosten*.

**Rabatte als Rabatte ausweisen** — an: ein Rabatt auf Bestellebene wird zur Rabattzeile im
sevDesk-Beleg. Aus: er wird anteilig auf die Positionen verteilt.

**Artikelnummern anzeigen** — schreibt die SKU der Position in den Positionstext.

**Kopfzeile, Einleitungstext, Fußtext** — Twig, gegen die Bestellung gerendert. Leer lassen für die
sevDesk-Standards.

**sevDesk-Einheiten-ID** — die Unity-ID, in der Positionen gemessen werden. `1` ist *Stück*.

**Ansprechpartner-ID** — der sevDesk-Benutzer auf dem Beleg. Leer lassen für den ersten;
**Verbindung testen** listet sie mit IDs auf.

## Kontakte

**Kontakte anlegen** — für Kunden ohne sevDesk-Kontakt einen anlegen.

**Über E-Mail zuordnen** — einen vorhandenen sevDesk-Kontakt mit passender E-Mail-Adresse
weiterverwenden. Sevvies prüft, ob ein Suchtreffer tatsächlich passt, bevor er verwendet wird — ein
Kunde kann also nie dem Kontakt eines Fremden zugeordnet werden.

**Kundennummern vergeben** — beim Anlegen eines Kontakts die nächste freie Kundennummer von sevDesk
holen.

**Adressen aktuell halten** — die Rechnungsadresse in den sevDesk-Kontakt schreiben, wenn sich die
Adresse eines wiederkehrenden Kunden geändert hat.

## Nach der Rechnung

sevDesk erstellt jede Rechnung als **Entwurf**. Sie zu senden — oder als gesendet zu markieren —
macht sie erst zum Buchhaltungsbeleg.

**Versand**:

- *Als Entwurf belassen* — Sie schließen jede Rechnung in sevDesk von Hand ab
- *Als gesendet markieren, aber nicht mailen* — für Shops, die die Rechnung aus Craft versenden und
  nur wollen, dass sevDesks Buchhaltung dazu passt
- *sevDesk soll sie dem Kunden per E-Mail senden* — sevDesk versendet sie, sie kommt also von Ihrer
  Buchhaltungsadresse mit Ihrem Briefpapier

**Zahlungen buchen** — wenn Commerce eine Bestellung als bezahlt markiert, den Betrag auf die
sevDesk-Rechnung buchen, damit sie geschlossen wird. Erfordert eine **Konto-ID**; **Verbindung
testen** listet Ihre auf.

**Erstattungen** — *Gutschrift erstellen* überträgt Commerce-Erstattungen nach sevDesk. Eine
vollständige Erstattung storniert die Rechnung, eine Teilerstattung erhält eine eigene Gutschrift,
die auf die ursprüngliche Rechnung verweist.

**PDF archivieren** — das PDF von sevDesk als Craft-Asset speichern, damit Ihr Archiv das Abo
überdauert. Wählen Sie ein Volume und optional einen Unterordnerpfad, der als Twig gegen die
Bestellung gerendert wird.

## Aufräumen

**Protokoll aufbewahren** — Tage, die das Verbindungsprotokoll behalten wird. `0` behält alles.

**Anfragedaten protokollieren** — behält die vollständigen Daten jedes Aufrufs. Ausschalten, wenn
Kundenadressen nicht doppelt gespeichert werden sollen.

**Wiederholungen** — wie oft ein fehlgeschlagener Versand wiederholt wird. Nur Zeitüberschreitungen
und sevDesk-Fehler werden wiederholt, eine abgelehnte Rechnung nie — sie würde genauso wieder
abgelehnt.
