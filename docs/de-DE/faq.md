---
title: Häufige Fragen
slug: faq
order: 50
summary: Preis, Umsatzsteuer, VIES, doppelte Belege — und was passiert, wenn sevDesk ausfällt.
---

## Was kostet Sevvies?

99 $ pro Craft-Installation, eine Edition, alles enthalten. Entwicklungs- und Testinstallationen sind
kostenlos.

Es gibt bewusst keine kostenlose Variante. Ein Shop, der nur im Inland verkauft, wäre genau der
Kunde, der sie nähme — und sobald er eine Sache an ein österreichisches Unternehmen verkauft, würde
eine abgespeckte Edition das falsch buchen. Ein Plugin, dessen kostenlose Edition einen falschen
Buchhaltungsbeleg erzeugen kann, ist schlechter als gar keine kostenlose Edition.

## Brauche ich einen kostenpflichtigen sevDesk-Tarif?

Sie brauchen einen sevDesk-Tarif mit API-Zugang. Sevvies nutzt die normale sevDesk-API; eine
zusätzliche Integrationsgebühr gibt es nicht.

## Funktioniert es mit sevDesk 1.0 und 2.0?

Mit beiden. sevDesk hat mit dem Buchhaltungssystem 2.0 die Steuertypen durch Steuerregeln ersetzt.
Sevvies fragt Ihr Konto, auf welchem System es läuft, und sendet beide Formen — ein Konto, das
gerade migriert wird, funktioniert also während der Migration weiter.

## Prüft es USt-IdNrn. gegen VIES?

Nein. Es prüft, ob eine USt-IdNr. für ihr Land strukturell gültig ist — richtige Länge, richtiger
Aufbau. Das fängt Tippfehler und hineinkopierten Unsinn ab.

Zu bestätigen, dass eine Nummer tatsächlich *registriert* ist, heißt VIES anzufragen, und VIES ist
häufig langsam und gelegentlich nicht erreichbar. Den Checkout davon abhängig zu machen ist ein
schlechtes Geschäft, und eine VIES-Zeitüberschreitung als „nicht registriert" zu werten, würde einem
Kunden Umsatzsteuer berechnen, der keine zahlen sollte. Ob Sie die Registrierung prüfen, ist eine
Entscheidung mit rechtlichem Gewicht — sie bleibt bei Ihnen.

## Kann dieselbe Bestellung zweimal abgerechnet werden?

Nein. Ein eindeutiger Datenbankindex auf der Bestell-ID erlaubt eine Rechnungszeile je Bestellung,
erzwungen von der Datenbank statt von einer Prüfung, die mit einer Wiederholung oder einem
Statuswechsel in ein Rennen geraten könnte.

## Was passiert, wenn sevDesk ausfällt?

Nichts, was Ihre Kunden betrifft. Jeder Auslöser scheitert nach außen offen — ein sevDesk-Ausfall
kann keine Zahlung verhindern und auch nicht verhindern, dass Commerce sie festhält.

Die Rechnung geht in die Queue und wird wiederholt. Sevvies unterscheidet einen vorübergehenden
Fehler, der eine Wiederholung wert ist, von einem abgelehnten Beleg, der genauso wieder abgelehnt
würde und Ihnen stattdessen gemeldet wird.

## Was, wenn meine Steuersituation ungewöhnlich ist?

Schalten Sie **Steuerregel je Bestellung ermitteln** aus und setzen Sie eine Standardregel. Jede
Rechnung wird dann unter dieser einen Regel erstellt, und Sevvies führt weiterhin den Abgleich und
den Duplikatschutz aus.

## Beherrscht es One Stop Shop?

Ja, sofern Sie für OSS registriert sind. Einschalten und angeben, ob Sie Waren, elektronische
Dienstleistungen oder sonstige Dienstleistungen verkaufen — sevDesk hat für jedes eine eigene Regel.
Das Bestimmungsland reist mit dem Beleg mit, sodass sevDesk den Satz des Bestimmungslands anwendet.

Sevvies entscheidet nicht, ob Sie sich für OSS registrieren *sollten*, und verfolgt auch nicht die
Lieferschwelle. Das ist Sache Ihres Steuerberaters.

## Beherrscht es Erstattungen?

Ja, als Gutschriften — so sieht die Stornierung einer Rechnung in der Buchhaltung tatsächlich aus.
Eine vollständige Erstattung storniert die Rechnung; eine Teilerstattung erhält eine eigene
Gutschrift, die zum selben Steuersatz auf die ursprüngliche Rechnung verweist. Jede
Commerce-Erstattung wird genau einmal übertragen.

## Kann ich Bestellungen von vor der Installation abrechnen?

Ja — `php craft sevvies/sync/pending` trägt jede abgeschlossene Bestellung ohne Rechnung nach.
Vorher einen Testlauf machen.

## Löscht es etwas in sevDesk?

Nein. Sevvies erstellt Belege und löscht oder verändert keinen. **Verknüpfung lösen** entfernt nur
Sevvies' eigenen Eintrag zu einer Bestellung.

## Können Kunden ihre Rechnungen auf der Website sehen?

Ja, über `craft.sevvies` in Twig — die Rechnungsnummer und, bei eingeschalteter PDF-Archivierung,
das archivierte PDF. Die Variable ist schreibgeschützt: ein Frontend-Template kann eine Rechnung
anzeigen, aber nie eine ausstellen.

## Arbeitet es mit Commerces Steuerberechnung zusammen oder ersetzt es sie?

Es arbeitet mit ihr zusammen. Commerce berechnet die Steuer; Sevvies liest, was berechnet wurde, und
bucht es unter der richtigen sevDesk-Regel. Widersprechen sich die beiden — eine Ausfuhr, die 19 %
berechnet hat — hält Sevvies an und sagt es Ihnen, weil das ein Problem der Commerce-Steuerregeln
ist, über das es nicht hinweggehen sollte.

## Steht Sevvies mit sevDesk in Verbindung?

Nein. Es ist eine unabhängige Integration, gebaut gegen die öffentliche API von sevDesk.

## Ist das eine Steuerberatung?

Nein. Sevvies bucht das, was Ihre Commerce-Einrichtung berechnet hat, unter der Regel, die deren
Konfiguration nahelegt. Ob diese Behandlung für Ihr Unternehmen korrekt ist, klären Sie mit Ihrem
Steuerberater.
