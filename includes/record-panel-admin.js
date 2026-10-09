(function () {
  'use strict';
  function init(panel) {
    if (panel.dataset.initialized) return;
    panel.dataset.initialized = '1';
    var compare = panel.querySelector('[data-wd-record-compare]');
    var sync = panel.querySelector('[data-wd-record-sync]');
    var status = panel.querySelector('[data-wd-record-status]');
    var details = panel.querySelector('[data-wd-record-details]');
    var snapshot = null, busy = false, compared = false, syncWarning = '';
    function text(value) { return typeof value === 'string' ? value : value == null ? '' : JSON.stringify(value); }
    function line(label, value) {
      if (value == null || value === '') return;
      var p = document.createElement('p'), strong = document.createElement('strong');
      strong.textContent = label + ' : '; p.appendChild(strong);
      p.appendChild(document.createTextNode(text(value).slice(0, 3000))); details.appendChild(p);
    }
    function draw(result) {
      details.replaceChildren(); details.hidden = false; snapshot = null; sync.hidden = true;
      var direction = result.direction === 'out' ? 'PrestaShop → WooCommerce' : result.direction === 'in' ? 'WooCommerce → PrestaShop' : '';
      line('Sens autorisé', direction);
      if (typeof result.remote_admin_url === 'string' && result.remote_admin_url) {
        try {
          var remote = new URL(result.remote_admin_url);
          if (remote.protocol === 'https:' && !remote.username && !remote.password && (!remote.port || remote.port === '443') && remote.hostname.toLowerCase() === String(result.peer_host || '').toLowerCase()) {
            var anchor = document.createElement('a'); anchor.href = remote.href; anchor.target = '_blank'; anchor.rel = 'noopener noreferrer';
            anchor.className = 'btn btn-outline-secondary mb-3'; anchor.textContent = 'Ouvrir la fiche dans l’administration WooCommerce ↗'; details.appendChild(anchor);
          }
        } catch (error) { /* Untrusted or malformed partner URL is not rendered. */ }
      }
      if (syncWarning) line('Dernière synchronisation', syncWarning);
      function summary(value) { return value && typeof value === 'object' ? [value.label, value.detail, value.status].filter(Boolean).join(' · ') : value; }
      line('Source', summary(result.source_summary));
      line('Destination', summary(result.destination_summary) || 'Aucune fiche correspondante');
      if (result.state !== 'same') line('Différences', Array.isArray(result.changes) ? result.changes.join(', ') || 'Aucune' : result.changes);
      if (result.variation_changes) {
        line('Déclinaisons', result.variation_changes.summary || (Number(result.variation_changes.count || 0) + ' modification(s) sur ' + Number(result.variation_changes.total || 0)));
        var labels = {added:'À ajouter',changed:'À modifier',removed:'Retrait à vérifier dans l’administration',same:'À jour'};
        (result.variation_changes.rows || []).slice(0, 50).forEach(function (row) { line(row.label || row.key, labels[row.state] || row.state); });
        if (result.variation_changes.truncated) line('Liste partielle', 'Les autres déclinaisons enregistrées sont également incluses.');
      }
      if (result.stock_note) line('Gestion du stock', result.stock_note);
      if (result.customer_note) line('Répertoire et compte client', result.customer_note);
      if (result.native_account) {
        var nativeLabels = {directory_only:'Répertoire Sync uniquement : la synchronisation du compte natif est désactivée ou inapplicable.',not_linked:'Aucun compte natif lié.',linked:'Compte natif lié, sans modification locale détectée.',conflict:'Le compte natif a changé ou son identité ne peut pas être confirmée. La synchronisation est bloquée.'};
        line('Compte natif', nativeLabels[result.native_account.state] || 'Statut à vérifier.');
      }
      if (result.stock_difference) line('Stock', 'Les quantités diffèrent. La synchronisation respecte les mouvements de stock et ne force pas leur remplacement.');
      if (Number(result.pending_count) > 0) line(panel.dataset.kind === 'product' ? 'Activité du produit parent · événements en attente' : 'Événements en attente', result.pending_count);
      if (result.last_event) line(panel.dataset.kind === 'product' ? 'Activité du produit parent · dernier événement' : 'Dernier événement', [result.last_event.created_at,result.last_event.direction === 'out' ? 'PrestaShop → WooCommerce' : 'WooCommerce → PrestaShop',result.last_event.state].filter(Boolean).join(' · '));
      if (result.reason) line('Information', result.reason);
      var states = {same:'Les données enregistrées sont synchronisées.',changed:'Des différences ont été détectées.',conflict:'La copie a été modifiée. Consultez le rapport avant toute synchronisation.',equal:'Les données enregistrées sont synchronisées.', synced:'Synchronisation effectuée.', different:'Des différences ont été détectées.', missing:'Cette fiche est absente de la boutique partenaire.', disconnected:'Aucune boutique partenaire connectée.', unavailable:'La comparaison est indisponible.', unmapped:'Aucune correspondance exploitable.', blocked:'Synchronisation indisponible pour cette fiche.'};
      status.textContent = states[result.state] || 'Comparaison terminée. Consultez les différences ci-dessous.';
      if (result.can_sync === true && direction && typeof result.key === 'string' && typeof result.hash === 'string' && typeof result.destination === 'string') {
        snapshot = result; sync.textContent = 'Synchroniser ' + direction; sync.hidden = false;
      }
    }
    async function request(operation, extra) {
      if (busy) return;
      busy = true; compared = true; compare.disabled = true; sync.disabled = true; panel.setAttribute('aria-busy', 'true');
      status.textContent = operation === 'sync' ? 'Synchronisation de cette fiche…' : 'Comparaison avec WooCommerce…';
      var body = new URLSearchParams({kind:panel.dataset.kind,id:panel.dataset.id,shop:panel.dataset.shop,record_token:panel.dataset.token,operation:operation});
      Object.keys(extra || {}).forEach(function (key) { body.set(key, extra[key]); });
      var abort = new AbortController(), timer = setTimeout(function () { abort.abort(); }, operation === 'sync' ? 90000 : 25000);
      try {
        var response = await fetch(panel.dataset.endpoint, {method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},body:body,signal:abort.signal});
        var result = await response.json();
        if (!response.ok || result.ok !== true) throw new Error('request');
        draw(result);
        if (operation === 'sync') {
          if (['pending','source_changed'].includes(result.ack)) syncWarning = typeof result.message === 'string' ? result.message : 'Confirmation en attente ou source modifiée.';
          snapshot = null; sync.hidden = true;
          status.textContent = (typeof result.message === 'string' ? result.message + ' ' : 'Synchronisation demandée. ') + (['pending','source_changed'].includes(result.ack) ? 'Attention : une confirmation est en attente ou la source a changé. ' : '') + 'Comparez à nouveau pour vérifier le résultat.';
        }
      } catch (error) {
        snapshot = null; sync.hidden = true;
        status.textContent = operation === 'sync' ? 'Le résultat de la synchronisation n’a pas pu être confirmé. Comparez à nouveau avant toute nouvelle action.' : 'Comparaison indisponible. Rechargez la fiche ou réessayez ; vérifiez vos droits et la connexion.';
      } finally {
        clearTimeout(timer); busy = false; compare.disabled = false; sync.disabled = false; panel.removeAttribute('aria-busy');
      }
    }
    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        if (entries.some(function (entry) { return entry.isIntersecting; })) { observer.disconnect(); if (!compared) request('compare'); }
      });
      observer.observe(panel);
    }
    compare.addEventListener('click', function () { snapshot = null; sync.hidden = true; request('compare'); });
    sync.addEventListener('click', function () {
      if (busy || !snapshot) return;
      var current = snapshot;
      var direction = current.direction === 'out' ? 'PrestaShop vers WooCommerce' : 'WooCommerce vers PrestaShop';
      var scope = panel.dataset.kind === 'product' ? 'le produit enregistré et toutes ses déclinaisons, sans forcer les quantités de stock' : 'cette fiche enregistrée';
      if (!window.confirm('Synchroniser ' + scope + ' de ' + direction + ' ?')) return;
      request('sync', {key:current.key,direction:current.direction,hash:current.hash,destination:current.destination,confirm:'1'});
    });
  }
  function run() { document.querySelectorAll('[data-wd-record-panel]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
}());
