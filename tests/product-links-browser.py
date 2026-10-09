"""Browser contract: deferred single request, safe links, explicit retries, no polling."""
from pathlib import Path
import json
import subprocess
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
markup=subprocess.check_output(['php',str(root/'tests/product-links-admin.php'),'9.1.5','render'],text=True)
asset=(root/'includes/product-links-admin.js').read_text()
responses=[{'ok':True,'state':'linked','url':'https://woo.example.test/produit/coffret','peer_host':'woo.example.test'},
 {'ok':True,'state':'unpublished'}, {'ok':False,'message':'PRIVATE_SERVER_SECRET'},
 {'ok':True,'state':'linked','url':'https://other.example.test/wrong','peer_host':'woo.example.test'}]
with sync_playwright() as p:
 browser=p.chromium.launch();page=browser.new_page();requests=[]
 def handle(route):
  req=route.request
  if req.method=='POST':
   requests.append(req.post_data);route.fulfill(status=200,content_type='application/json',body=json.dumps(responses[len(requests)-1]));return
  if 'product-links-admin.js' in req.url:route.fulfill(content_type='text/javascript',body=asset);return
  route.fulfill(content_type='text/html',body='<html><body>'+markup+'</body></html>')
 page.route('https://ps.example.test/**',handle)
 page.goto('https://ps.example.test/admin/product/7');link=page.locator('[data-wd-remote-link]');retry=page.locator('[data-wd-link-retry]')
 link.wait_for(state='visible');assert len(requests)==1 and 'id_product=7' in requests[0] and 'shop=1' in requests[0]
 assert link.get_attribute('href')=='https://woo.example.test/produit/coffret'
 assert link.get_attribute('target')=='_blank' and link.get_attribute('rel')=='noopener noreferrer'
 page.wait_for_timeout(150);assert len(requests)==1
 retry.click();page.wait_for_function("document.querySelector('[data-wd-product-link]').dataset.state==='unpublished'")
 assert not link.is_visible() and link.get_attribute('href') is None
 retry.click();page.wait_for_function("document.querySelector('[data-wd-product-link]').dataset.state==='unavailable'")
 assert 'PRIVATE_SERVER_SECRET' not in page.locator('body').inner_text()
 retry.click();page.wait_for_function("!document.querySelector('[data-wd-product-link]').hasAttribute('aria-busy')")
 assert not link.is_visible() and link.get_attribute('href') is None
 assert len(requests)==4
 markup=markup.replace('data-state="ready"','data-state="unmapped"')
 page.goto('https://ps.example.test/admin/product/7');retry.wait_for(state='visible');page.wait_for_timeout(150)
 assert len(requests)==4 and not link.is_visible()
 browser.close()
print('PASS: deferred AJAX, one automatic request, no polling, safe target link, stale-link removal, unpublished/error/retry and unmapped state')
