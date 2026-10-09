/* One authenticated lookup after rendering, then explicit retries only. */
(function () {
  'use strict';
  function init() {
    document.querySelectorAll('[data-wd-product-link]').forEach(function (panel) {
      if (panel.dataset.initialized) return;
      panel.dataset.initialized = 'true';
      var status = panel.querySelector('[data-wd-link-status]');
      var link = panel.querySelector('[data-wd-remote-link]');
      var retry = panel.querySelector('[data-wd-link-retry]');
      if (!status || !link || !retry) return;
      var pending = false;
      function show(node, visible) { node.hidden = !visible; node.style.display = visible ? '' : 'none'; }
      function message(state) {
        return ({ linked: 'Produit correspondant vérifié sur WooCommerce.',
          unmapped: 'Ce produit n’a pas encore de correspondance Inklura Sync.',
          disconnected: 'Aucune boutique WooCommerce connectée.',
          missing: 'Produit correspondant introuvable sur la boutique partenaire.',
          unpublished: 'Produit distant non publié : aucun lien public disponible.',
          unavailable: 'Vérification indisponible. Réessayez ou rechargez la fiche produit.' })[state] || 'Lien partenaire indisponible.';
      }
      async function lookup() {
        if (pending) return;
        pending = true; retry.disabled = true; show(link, false); link.removeAttribute('href');
        panel.setAttribute('aria-busy', 'true'); status.textContent = 'Vérification du lien vers WooCommerce…';
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 10000);
        try {
          var endpoint = new URL(panel.dataset.endpoint, location.href);
          if (endpoint.origin !== location.origin) throw new Error('Invalid endpoint');
          var body = new URLSearchParams({ id_product: panel.dataset.product, shop: panel.dataset.shop, wd29_product_token: panel.dataset.token });
          var response = await fetch(endpoint.href, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: body, signal: controller.signal });
          if (!response.ok) throw new Error('Unavailable');
          var result = await response.json();
          if (!result.ok) throw new Error('Unavailable');
          if (result.state === 'linked') {
            var url = new URL(result.url);
            if (url.protocol !== 'https:' || url.username || url.password || url.port && url.port !== '443' || url.hostname.toLowerCase() !== String(result.peer_host).toLowerCase()) throw new Error('Invalid URL');
            link.href = url.href; show(link, true);
          }
          panel.dataset.state = result.state;
          status.textContent = message(result.state);
        } catch (_) {
          panel.dataset.state = 'unavailable'; status.textContent = message('unavailable');
        } finally {
          clearTimeout(timeout); pending = false; retry.disabled = false;
          retry.textContent = link.hasAttribute('href') ? 'Actualiser' : 'Réessayer';
          show(retry, true); panel.removeAttribute('aria-busy');
        }
      }
      show(link, false); show(retry, true);
      retry.addEventListener('click', lookup);
      if (panel.dataset.state === 'ready') requestAnimationFrame(function () { setTimeout(lookup, 0); });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
