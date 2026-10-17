# Guida sviluppatore: plg_system_autoarchive (Auto Archive)

Per **sviluppatore o AI** che deve modificare, collaudare o rilasciare il plugin.

> Repo **PUBBLICO**: mai token, password, chiavi, host, percorsi di server o dati di clienti.

## 1. Architettura

```
plugins/plg_system_autoarchive/
  autoarchive.xml                manifest (namespace WebG\Plugin\System\Autoarchive)
  script.php                     PHP >= 8.1, Joomla >= 5.0; alla prima installazione attiva il plugin
                                 e importa le impostazioni di plg_system_yooarchive 1.x
  services/provider.php          service provider Joomla 5/6
  src/Extension/Autoarchive.php  plugin (SubscriberInterface), avvisi agli amministratori
  src/Automation/Automation.php  archiviazione e spostamento
  language/{en-GB,it-IT}/        lingue dentro la cartella del plugin
```

Plugin `system` (e non `content`): gli eventi `onTableBeforeStore` / `onTableAfterStore` e il
cambio di stato via API arrivano anche quando il gruppo `content` non e' caricato. Non dipende da
YOOtheme. Nato dalla divisione di `plg_system_yooarchive` 1.2.1 (stesso comportamento, stessi nomi
dei parametri).

**Migrazione da yooarchive 1.x** (`script.php`, solo alla prima installazione): se esiste la riga
`plg_system_yooarchive` con `target_catid` o `automation` nei parametri, li copia in Auto Archive
(sette chiavi, stessi nomi) e, se l'automazione era accesa e il vecchio plugin e' ancora 1.x
(`manifest_cache` < 2.0.0), spegne `automation` nel vecchio, perche' le due automazioni non
girino insieme. Non sovrascrive un Auto Archive gia' configurato. Il lato opposto sta in
`plg_system_yooarchive` 2.0.0 (`preflight`): aggiornamento rifiutato se l'automazione e' accesa e
Auto Archive non c'e' o e' spento. I redirect gia' salvati dalla 1.2.x (nota
`Archive Layouts for YOOtheme:`) restano sotto il controllo di Auto Archive
(`Automation::LEGACY_REDIRECT_MARKER`).

**Automazione** (`Automation.php`, attiva solo con interruttore acceso e categoria scelta):

- `onTableBeforeStore` ricorda categoria e stato precedenti dell'articolo.
- `onTableAfterStore` mette in coda: archiviazione (pubblicato che arriva nella categoria, o
  sempre con "forza archiviato") oppure spostamento (articolo appena diventato archiviato da
  un salvataggio).
- `onContentChangeState` (elenco, barra, API): stato 2 = sposta subito; stato 1 con "forza" =
  riarchivia.
- La coda si esegue su `onContentAfterSave` e `onContentCleanCache`, cioe' a salvataggio
  concluso (Joomla 6 emette `onContentAfterSave` anche per ogni articolo spostato in serie,
  quindi nel batch l'archiviazione avviene articolo per articolo), mai dentro `store()`.
- Archiviare = `ArticleModel::publish($pks, 2)` del modello amministratore: scattano Smart
  Search, cache, cronologia, log azioni. Spostare = `Table::store()` con nuovo `catid` (asset
  riparentato) + `onFinderAfterSave` per reindicizzare la categoria.
- Un flag statico `$busy` ignora gli eventi generati dal plugin stesso: niente ricorsioni.
- Spostare richiede un utente collegato con `core.edit.state` sull'articolo (e `core.create` sulla
  categoria archivio se l'opzione *Richiedi il permesso di creare* e' accesa); salta gli articoli
  bloccati da un altro utente; mai per ospiti o CLI. A video solo stringhe di lingua generiche,
  dettagli solo in `Log::add()`. Categoria archivio inesistente o nel cestino = automazione ferma
  e avviso agli amministratori.
- Redirect (opzione *Redirect 301 dal vecchio indirizzo*, dalla 1.2.0): l'URL pubblico si
  calcola con `Route::link('site', RouteHelper::getArticleRoute(id, catid, lingua))` prima e
  dopo il cambio di categoria (per lo spostamento fatto dal plugin dentro `move()`, per
  l'articolo pubblicato portato nella categoria da un utente in `onTableBeforeStore`, con
  categoria e alias ancora quelli salvati). Se gli URL differiscono e sono SEF si salva una riga
  in `#__redirect_links` con la tabella `Link` di com_redirect (301, riga esistente con lo
  stesso vecchio URL aggiornata; una riga che parte dal nuovo URL, di nuovo pagina viva, viene
  spenta). La serve il plugin core *System - Redirect* sui 404; se e' spento gli
  amministratori vedono un avviso in Plugin e Reindirizzamenti. 4SEO non crea redirect da solo
  quando cambia l'URL di un articolo (provato con 4SEO 7.2.0). Dalla 1.2.1 il plugin aggiorna o
  spegne solo le righe con il commento che inizia con `Automation::REDIRECT_MARKER` (`Auto Archive:`) o `LEGACY_REDIRECT_MARKER`; una riga
  esistente per lo stesso vecchio URL senza marcatore non viene toccata (solo una riga di log).
- Log: oltre 1 MB `plg_system_autoarchive.php` diventa `plg_system_autoarchive.1.php` (estensione
  .php per tenere la protezione `die()` di Joomla) e si riparte da un file nuovo.

## 2. Ambiente di prova e rilascio

Come per Layout archivio: Docker in `dev/yooarchive/` (vedi [la guida](../yooarchive/DEVELOPING.md#2-ambiente-di-prova-docker)).
Auto Archive non ha bisogno di YOOtheme, ma lo stesso ambiente serve per provare la migrazione dalla
1.x. Script utili: `scripts/automation_on.php <alias> [yooarchive|autoarchive]` (accende
l'automazione), `scripts/api_state.php` (stato o categoria via API), `scripts/mk_published.php`
(articoli pubblicati di prova), `scripts/batch_ui.php` (Batch come dal browser),
`scripts/security_test.php` (permessi), `scripts/set_debug.php` (dettagli degli errori API).

```bash
python build/build_plugin.py plugins/plg_system_autoarchive      # zip in build/out/
```

Rilascio: come gli altri plugin (bump `<version>` in `autoarchive.xml`, `CHANGELOG.md`,
`changelogs/autoarchive.xml`, JED Checker `master` e `develop`, collaudo, `dist/`,
`updates/autoarchive.xml`, riga in `collection.xml`, tag `autoarchive-X.Y.Z` e Release).
Prove da rifare a ogni rilascio: i percorsi di migrazione (automazione accesa/spenta, ordine
giusto e sbagliato) elencati in `report/` dei collaudi.
