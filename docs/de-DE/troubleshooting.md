---
title: Fehlerbehebung
slug: troubleshooting
order: 40
summary: Was jeder blockierte oder fehlgeschlagene Zustand bedeutet — und wie Sie ihn auflösen.
---

## „sevDesk hat 119,00 € gebucht, Commerce hat aber 100,00 € berechnet"

Ihre Einstellung **Positionspreise** passt nicht zu Ihrem sevDesk-Konto. Sevvies sagt das
ausdrücklich, wenn die Differenz genau die Umsatzsteuer ist — genau dafür ist die Meldung da.

Stellen Sie **Positionspreise** unter **Sevvies → Einstellungen → Beleg** von *Netto* auf *Brutto*
um (oder umgekehrt), korrigieren Sie die bereits in sevDesk liegende Rechnung von Hand, lösen Sie die
Verknüpfung an der Bestellung (**Verknüpfung lösen**) und buchen Sie erneut.

## „Diese Bestellung wurde mit 19 % USt. berechnet, sevDesk erlaubt unter Ausfuhren aber nur 0 %"

Commerce hat auf einer Bestellung Umsatzsteuer berechnet, die Sevvies als Ausfuhr eingestuft hat.
Eines von beidem ist falsch, und Sevvies rät nicht, welches.

Meist sind es die Commerce-Steuerregeln: ein Steuersatz greift für ein Zielland, für das er nicht
gelten sollte. Prüfen Sie **Commerce → Steuern → Steuersätze** und die Zone, an die der Satz gebunden
ist. Falls die Bestellung tatsächlich Umsatzsteuer tragen sollte, ist das Land der Rechnungsadresse
falsch.

## „Das Land der USt-IdNr. des Kunden (AT) passt nicht zum Rechnungsland (FR)"

Der Kunde hat eine USt-IdNr. aus einem anderen Land als seiner Rechnungsadresse eingetragen.
Korrigieren Sie eines von beidem an der Bestellung und buchen Sie erneut.

## „USt-IdNr. … ist keine gültige FR-Nummer"

Die Nummer entspricht nicht dem strukturellen Format des Landes. Das ist eine Formatprüfung, keine
VIES-Abfrage — eine formal korrekte, aber nicht registrierte Nummer kommt hier durch.

Hat der Kunde tatsächlich keine USt-IdNr., leeren Sie das Feld. Die Bestellung wird dann als
Endkundenverkauf abgerechnet, was korrekt ist.

## „sevDesk kennt das Land ‚CH' der Rechnungsadresse nicht"

Sevvies hat in sevDesks eigener Länderliste kein passendes Land gefunden. Führen Sie
`php craft sevvies/tools/flush-cache` aus und versuchen Sie es erneut — die Liste wird einen Tag lang
zwischengespeichert, und ein kürzlich ergänztes Land fehlt in der zwischengespeicherten Fassung
womöglich.

## „Es wurde kein sevDesk-Ansprechpartner gefunden"

Jede sevDesk-Rechnung braucht einen Ansprechpartner. Setzen Sie unter **Sevvies → Einstellungen →
Beleg** die **Ansprechpartner-ID**; **Verbindung testen** listet Ihre sevDesk-Benutzer mit IDs auf.

## „sevDesk hat den API-Token abgelehnt"

Das Token ist falsch, abgelaufen oder gehört einem Benutzer, der den API-Zugang verloren hat. Holen
Sie sich ein neues unter **Einstellungen → Benutzer → Ihr Benutzer** in sevDesk.

Falls Sie eine Umgebungsvariable nutzen: prüfen Sie, dass der Name in der `.env` und in der
Einstellung identisch geschrieben ist. Eine nicht existierende Umgebungsvariable liest sich als leer,
nicht als Fehler.

## „sevDesk ist nicht erreichbar"

Ein Netzwerkproblem oder eine sevDesk-Störung. Solche Fälle werden automatisch wiederholt — bis zur
Grenze der Einstellung **Wiederholungen** — mit wachsendem Abstand über die Queue. Es geht nichts
verloren, und es entsteht nichts doppelt.

## Eine Bestellung bleibt auf „Ausstehend" stehen

Der Queue-Job ist nicht gelaufen. Prüfen Sie **Dienstprogramme → Queue Manager** auf einen
fehlgeschlagenen Job und stellen Sie sicher, dass Ihr Queue-Runner tatsächlich läuft — Crafts
standardmäßig durch Web-Requests angestoßene Queue bleibt auf einer Seite ohne Traffic stehen.

Um die Queue für eine Bestellung zu umgehen, öffnen Sie sie unter **Sevvies → Rechnungen** und
klicken auf **Rechnung erstellen**.

## Rechnungen werden erstellt, bleiben aber Entwürfe

Das ist sevDesks Verhalten, kein Fehler: Jede Rechnung wird als Entwurf angelegt und wird erst zum
Buchhaltungsbeleg, wenn sie gesendet wird.

Stellen Sie **Versand** unter **Sevvies → Einstellungen → Nach der Rechnung** auf *Als gesendet
markieren* oder *sevDesk soll sie per E-Mail senden* — oder schließen Sie jede Rechnung in sevDesk
von Hand ab.

## Zahlungen werden nicht gebucht

Prüfen Sie alles davon:

- **Zahlungen buchen** ist eingeschaltet
- Eine **Konto-ID** ist gesetzt — beim Buchen wird keine geraten
- Die Bestellung ist in Commerce tatsächlich bezahlt (`order.isPaid`)
- Die Rechnung ist kein Entwurf mehr. Sevvies markiert sie vor dem Buchen automatisch als gesendet,
  da sevDesk auf einen Entwurf nichts bucht.

## Dieselbe Bestellung wurde zweimal abgerechnet

Das kann nicht sein. Ein eindeutiger Datenbankindex auf der Bestell-ID erzwingt eine Rechnungszeile
je Bestellung — ein Duplikat wird von der Datenbank abgelehnt, nicht von einer Prüfung, die in ein
Rennen geraten könnte.

Wenn Sie wirklich zwei Belege in sevDesk sehen, wurde einer außerhalb von Sevvies erstellt — oder die
Verknüpfung der Bestellung wurde **gelöst** und danach erneut gebucht, was den ersten Beleg
absichtlich nicht anfasst.

## Löschen

Sevvies löscht oder verändert nie einen Buchhaltungsbeleg in sevDesk. **Verknüpfung lösen** entfernt
nur Sevvies' eigenen Eintrag zu einer Bestellung — danach kann sie wie neu gebucht werden.

## Das Protokoll lesen

**Sevvies → Protokoll** hält jede Anfrage mit Endpunkt, Statuscode und Dauer fest, dazu jede
Entscheidung und Auslassung. Filtern Sie auf **Nur Fehler**, wenn etwas nicht stimmt.

Jeder Eintrag bewahrt Anfrage- und Antwortdaten auf, sofern Sie **Anfragedaten protokollieren** nicht
ausgeschaltet haben. Bei einer abgelehnten Rechnung sind die Antwortdaten sevDesks eigene Erklärung —
meist genauer als alles, was Sevvies stellvertretend sagen könnte.
