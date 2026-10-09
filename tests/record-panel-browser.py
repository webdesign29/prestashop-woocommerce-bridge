from pathlib import Path
import subprocess,json
from playwright.sync_api import sync_playwright,expect
root=Path(__file__).resolve().parents[1]
html=subprocess.check_output(['php',str(root/'tests/record-panel-admin.php'),'8.2.8','render'],text=True)
result={'ok':True,'state':'changed','direction':'out','source_summary':'Client source','destination_summary':'Client cible','key':'ps:customer:7','hash':'a'*64,'destination':'b'*64,'can_sync':True,'changes':['email'],'peer_host':'woo.example.test','remote_admin_url':'https://woo.example.test/wp-admin/admin-post.php?action=open','customer_note':'Le statut compare le répertoire de contacts Sync.','native_account':{'state':'directory_only','message':'Compte natif désactivé'}}
with sync_playwright() as p:
 browser=p.chromium.launch();page=browser.new_page();calls=[]
 def handler(route):
  if 'record-panel-admin.js' in route.request.url:route.fulfill(body=(root/'includes/record-panel-admin.js').read_text(),content_type='text/javascript; charset=utf-8');return
  if 'controller=' in route.request.url:
   from urllib.parse import parse_qs
   data=parse_qs(route.request.post_data);calls.append(data)
   route.fulfill(json=dict(result) if data['operation']==['compare'] else {'ok':True,'state':'applied','ack':'pending','message':'Confirmation en attente.'});return
  route.fulfill(body=html,content_type='text/html; charset=utf-8')
 page.route('https://ps.example.test/**',handler);page.goto('https://ps.example.test/fixture')
 panel=page.locator('[data-wd-record-panel]');sync=panel.locator('[data-wd-record-sync]')
 expect(sync).to_be_visible();assert len(calls)==1 and calls[0]['operation']==['compare']
 expect(sync).to_have_text('Synchroniser PrestaShop → WooCommerce')
 expect(panel.get_by_role('link')).to_have_attribute('rel','noopener noreferrer')
 page.once('dialog',lambda d:d.dismiss());sync.click();page.wait_for_timeout(100);assert len(calls)==1,'Cancel mutated'
 page.once('dialog',lambda d:d.accept());sync.click();expect(panel.locator('[data-wd-record-status]')).to_contain_text('Confirmation en attente')
 assert len(calls)==2 and calls[-1]['hash']==['a'*64] and calls[-1]['destination']==['b'*64] and calls[-1]['confirm']==['1']
 expect(sync).to_be_hidden()
 panel.locator('[data-wd-record-compare]').click();expect(panel.locator('[data-wd-record-details]')).to_contain_text('Dernière synchronisation');assert len(calls)==3
 result['remote_admin_url']='https://attacker.test/';result['can_sync']=False;result['state']='conflict'
 panel.locator('[data-wd-record-compare]').click();expect(sync).to_be_hidden();expect(panel.get_by_role('link')).to_have_count(0)
 page.wait_for_timeout(500);assert len(calls)==4,'Unexpected polling'
 browser.close()
print('PASS native record panel visible-only comparison, confirmation cancellation, guarded payload, pending warning, safe remote link and no polling')
