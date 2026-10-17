# Auto Archive: guida d'uso

Per chi **gestisce il sito**: installare, scegliere la categoria archivio, aggiornare,
disinstallare. Tutto da interfaccia Joomla. English: [USAGE.md](./USAGE.md). Sviluppatore o AI?
[DEVELOPING.md](./DEVELOPING.md).

## Perché esiste

Per chi gestisce un sito lo stato **Archiviato** di Joomla è invisibile, mentre una categoria
"Venduti" o "Eventi passati" si vede, si apre, si riempie. Per questo quasi tutti archiviano
spostando l'articolo in una cartella, e quasi nessuno usa lo stato, e i due gesti restano
separati: chi trascina un articolo in "Venduti" lo lascia pubblicato, chi clicca **Archivia** lo
lascia nella categoria di prima, e in tutti e due i casi l'articolo resta a metà. Auto Archive fa
coincidere i due gesti: trascinare in "Venduti" vuol dire archiviare, cliccare **Archivia** vuol
dire finire in "Venduti", e qualunque gesto faccia chi lavora sul sito, il sito resta coerente.

La cartella conta più dello stato per tre motivi. Tutto quello che nel sito ragiona per
categoria e non per stato (moduli, filtri, contatori, ricerche, feed, sitemap) smette di vedere
un articolo solo quando cambia categoria. Un archivio unico che raccoglie articoli da molte
sottocategorie è più semplice da gestire di dieci filtri separati. E l'unico effetto collaterale
dello spostamento, il cambio di indirizzo, lo ripara il plugin stesso con il **redirect 301**.

Per disegnare la pagina degli archiviati con il builder di YOOtheme Pro non serve questo plugin
ma [Layout archivio per YOOtheme](../yooarchive/USO.md): anche due archivi distinti, con due
layout diversi, si ottengono con due voci di menu *Articoli archiviati* filtrate per categoria,
senza spostare nulla. Auto Archive è per chi vuole gli archiviati in una cartella propria.

## A cosa serve

Joomla ha lo stato **Archiviato** per gli articoli, ma un articolo archiviato resta nella
categoria in cui era. Auto Archive tiene gli articoli archiviati del sito **in una categoria** che
scegli tu:

- clicchi **Archivia** (elenco, barra strumenti, modulo di modifica o API) e l'articolo viene
  **spostato nella categoria archivio**;
- salvi un articolo pubblicato in quella categoria, o lo sposti lì con **Elaborazione in serie**, e
  viene **archiviato** (dal modello articolo di Joomla, quindi Ricerca intelligente, cache,
  cronologia e registro azioni si aggiornano come con un clic a mano);
- facoltativo: **redirect 301** dal vecchio indirizzo dell'articolo al nuovo, quando la categoria
  fa parte dell'URL.

È Joomla puro: funziona con o senza YOOtheme. Spento di serie. (Per disegnare la pagina Articoli
archiviati col builder di YOOtheme Pro vedi [Layout archivio per YOOtheme](../yooarchive/USO.md),
un plugin separato.)

Requisiti: Joomla 5 o 6, PHP 8.1+.

## 1. Installare

1. *Sistema > Installa > Estensioni > Carica il pacchetto* e scegli `plg_system_autoarchive-X.Y.Z.zip`.
2. Il plugin si attiva da solo. Non fa nulla finché non accendi l'automazione e scegli la categoria.

Vieni da Layout archivio per YOOtheme 1.x? Vedi [Passare da Layout archivio 1.x](#passare-da-layout-archivio-1x).

## 2. Opzioni

*Sistema > Plugin > Sistema - Auto Archive*:

| Opzione | Cosa fa |
|---|---|
| Attiva automazione | Accende tutto il resto. |
| Categoria archivio | Dove finiscono gli articoli archiviati. Senza categoria, o se la categoria viene cancellata o messa nel cestino, l'automazione si ferma e gli amministratori vedono un avviso. Deve essere **pubblicata**: se non lo è, gli articoli spostati non compaiono nel sito pubblico e gli amministratori vedono un avviso giallo. |
| Richiedi il permesso di creare | Acceso di serie: sposta solo se chi archivia può anche creare articoli nella categoria archivio. |
| Sposta gli articoli archiviati | Archivia (da elenco, barra, modifica o API) = sposta nella categoria. |
| Redirect 301 dal vecchio indirizzo | Acceso di serie. Se la categoria fa parte dell'URL dell'articolo, entrare nella categoria archivio cambia l'indirizzo: il vecchio viene reindirizzato (301) al nuovo tramite il componente *Reindirizzamenti* di Joomla, sia per gli spostamenti fatti dal plugin sia per gli articoli che un utente sposta nella categoria. Serve il plugin *Sistema - Reindirizzamento* attivo (se è spento gli amministratori vedono un avviso). Se l'indirizzo non cambia non viene salvato nulla. |
| Archivia gli articoli spostati nella categoria | Un articolo pubblicato salvato o spostato (anche con *Elaborazione in serie*) nella categoria viene archiviato. |
| Forza archiviato | Spento di serie: puoi ripubblicare apposta un articolo che sta già nella categoria. Acceso: viene riarchiviato sempre. |

Dopo ogni azione compare un messaggio blu che dice cosa ha fatto il plugin (anche quanti redirect
ha salvato: li trovi in *Sistema > Reindirizzamenti*).

Lingue: lo spostamento non guarda la lingua. Un articolo in inglese finisce nella categoria
archivio anche se quella categoria è in italiano; su un sito multilingua usa una categoria
archivio con lingua *Tutte*.

I redirect li scrive il plugin, quindi all'utente non serve alcun permesso sul componente
Reindirizzamenti; vengono scritti solo per gli spostamenti ammessi dalle regole qui sotto. Un
redirect già esistente per lo stesso vecchio indirizzo e non creato dal plugin non viene mai
toccato.

Permessi: l'articolo viene spostato solo se chi lo archivia è un utente collegato con il
permesso *Modifica stato* sull'articolo (e, con l'opzione accesa, *Crea* nella categoria
archivio). Gli articoli aperti in modifica da un altro utente non vengono toccati. Ospiti e
riga di comando non spostano nulla.

Se qualcosa non va (permesso mancante, alias doppio nella categoria di arrivo) compare un
messaggio giallo generico; i dettagli (numeri degli articoli, errore del database) finiscono solo
nel log `administrator/logs/plg_system_autoarchive.php` (ruotato oltre 1 MB, tenuto un file
precedente). Nel sito pubblico (modifica articoli dal frontend) i messaggi li vede solo chi può
gestire gli articoli.

Limiti: una sola categoria archivio per sito; non provato con i *Flussi di lavoro* di Joomla attivi
(di serie sono spenti).

## Passare da Layout archivio 1.x

Fino alla versione 1.2 l'automazione era dentro *Layout archivio per YOOtheme*. Nella 2.0 di quel
plugin non c'è più: è questo plugin.

1. Installa Auto Archive. Se Layout archivio 1.x aveva l'automazione configurata, Auto Archive
   **importa le impostazioni** (categoria e opzioni) da solo e **spegne la vecchia automazione**,
   così le due non girano insieme. Un messaggio dice cosa è stato importato. Controlla le opzioni
   una volta.
2. Poi aggiorna Layout archivio per YOOtheme alla 2.0. Se lo fai **prima** di installare Auto
   Archive e l'automazione era accesa, l'aggiornamento viene **rifiutato con un messaggio e senza
   cambiare nulla**: la versione vecchia continua a funzionare.
3. I redirect che la 1.x aveva salvato restano in *Sistema > Reindirizzamenti* e li gestisce
   Auto Archive.

## Note per chi usa 4SEO

4SEO non crea un redirect quando un articolo cambia indirizzo. Se la categoria fa parte degli URL
degli articoli tieni accesa l'opzione *Redirect 301 dal vecchio indirizzo* (con il plugin *Sistema -
Reindirizzamento* di Joomla attivo). Dopo aver archiviato molti articoli insieme rifai l'analisi del
sito in 4SEO (*Pagine > Reset analisi*), così sitemap e canonical usano i nuovi indirizzi. Con URL
degli articoli che contengono l'id (`/<id>-alias`) Joomla trova l'articolo dall'id qualunque sia la
categoria nell'indirizzo: dopo lo spostamento il vecchio URL resta vivo (niente 404) e il redirect
non serve.

## 3. Aggiornare

Arriva da *Sistema > Aggiorna > Estensioni* come gli altri plugin WebG.

## 4. Disinstallare

*Sistema > Gestisci > Estensioni*, cerca "Auto Archive", *Disinstalla*. Non restano tabelle né file
del plugin. Restano i redirect salvati in *Sistema > Reindirizzamenti* (la nota inizia con "Auto
Archive:", da lì li togli se vuoi) e il log in `administrator/logs/`.
