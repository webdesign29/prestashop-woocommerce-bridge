<?php
require __DIR__.'/manual-orders.php';
require_once __DIR__.'/../includes/ActionsAdmin.php';
class ActionAdapter extends ManualAdapter {
 public $conf=['mode'=>'audit','conflict_policy'=>'review','display_tax_rate'=>'','tax_rules'=>['20'=>1]],$worker,$suggest=null,$saves=0;
 function __construct($site){parent::__construct($site);$this->worker=['state'=>'idle','at'=>gmdate('c')];}
 function config(){return $this->conf;}
 function saveConfig(array $config):void{$this->conf=$config;$this->saves++;}
 function workerStatus($state=null){return $this->worker;}
 function suggestedTaxRate(){return $this->suggest;}
}
function event($e,$state,$kind,$error,$attempts=8){static $n=0;$n++;$e->sql("INSERT INTO {b}queue (event_id,direction,kind,record_key,payload,state,attempts,next_try,error,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)",[str_pad(dechex($n),32,'0',STR_PAD_LEFT),'in',$kind,'woo:'.$kind.':'.$n,'{}',$state,$attempts,time()+3600,$error,gmdate('Y-m-d H:i:s')]);}
function codes($e){return array_column($e->actionsNeeded(),'code');}
$a=new ActionAdapter('ps');$e=new WD29\Bridge\Engine($a);$a->engine=$e;
check(codes($e)===[],'Healthy store reports actions');
check(WD29\Bridge\ActionsAdmin::render($e,'ps','<input name="wd29_token">')===''&&WD29\Bridge\ActionsAdmin::notice($e,'ps',fn($v)=>'?v='.$v)==='','Empty banner rendered');
$tax='Confirm the tax rate for source display prices first.';
event($e,'failed','product',$tax);event($e,'pending','product',$tax,3);event($e,'conflict','product','Both sites changed this record; review required.',0);
event($e,'pending','product','',0);// fresh pending work is not an action
$actions=$e->actionsNeeded();check(array_column($actions,'code')===['tax_rate_required','conflict_policy_required'],'Expected tax and policy decisions');
check($actions[0]['count']===2&&$actions[0]['suggest']==='20','Tax count or single-mapping suggestion wrong');
$a->suggest='5.5';check($e->actionsNeeded()[0]['suggest']==='5.5','Catalogue rate not preferred');$a->suggest=null;
$html=WD29\Bridge\ActionsAdmin::render($e,'ps','<input type="hidden" name="wd29_token" value="t">');
check(substr_count($html,'name="wd29_token"')===2&&strpos($html,'value="decide_tax_rate"')!==false&&strpos($html,'value="decide_policy_ps"')!==false&&strpos($html,'value="decide_policy_woo"')!==false,'Banner lacks token or one-click decisions');
check(strpos($html,'value="20"')!==false&&strpos($html,'wd_view=settings')!==false&&strpos($html,'wd_view=activity')!==false,'Banner lacks suggestion or direct links');
$notice=WD29\Bridge\ActionsAdmin::notice($e,'woo',fn($v)=>'https://shop.test/wp-admin/admin.php?page=wd29-bridge&wd_view='.$v);
check(strpos($notice,'notice-error')!==false&&strpos($notice,'wd_view=settings#wd-actions')!==false&&strpos($notice,'(+1 autre)')!==false,'Host notice lacks link or count');
check(WD29\Bridge\ActionsAdmin::handle($e,'save',[])===null,'Unrelated action intercepted');
refuses(fn()=>WD29\Bridge\ActionsAdmin::handle($e,'decide_tax_rate',['decision_tax_rate'=>'abc']),'Invalid rate accepted');
refuses(fn()=>WD29\Bridge\ActionsAdmin::handle($e,'decide_tax_rate',['decision_tax_rate'=>'']),'Empty rate accepted');
check($a->saves===0,'Rejected decision saved settings');
$m=WD29\Bridge\ActionsAdmin::handle($e,'decide_tax_rate',['decision_tax_rate'=>'20','decision_tax_basis'=>'gross']);
check($a->conf['display_tax_rate']==='20'&&$a->conf['display_basis']==='gross'&&$a->conf['tax_rules']===['20'=>1],'Tax decision not saved or other settings lost');
check(stripos($m,'2 échange(s) relancé(s)')!==false,'Blocked events not requeued: '.$m);
$rows=$e->sql("SELECT state,attempts,next_try FROM {b}queue WHERE error=?",[$tax]);
foreach($rows as $row)check($row['state']==='pending'&&(int)$row['attempts']===0&&(int)$row['next_try']===0,'Requeued event keeps its backoff');
check(codes($e)===['conflict_policy_required'],'Tax action still shown after requeue');
refuses(fn()=>$e->decide('conflict_policy',['policy'=>'review']),'Review offered as one-click decision');
$m=WD29\Bridge\ActionsAdmin::handle($e,'decide_policy_ps',[]);
check($a->conf['conflict_policy']==='ps'&&$a->conf['display_tax_rate']==='20','Policy not saved or tax setting lost');
check(stripos($m,'conflits de catalogue relancés')!==false&&strpos($m,'appliquez la même priorité sur la boutique partenaire')!==false,'Policy follow-up missing: '.$m);
check($e->sql("SELECT state FROM {b}queue WHERE kind='product' AND error LIKE 'Both%'")[0]['state']==='pending','Conflict not retried');
check(codes($e)===[],'Actions remain after decisions');
// Settings form path: same follow-up without a one-click decision.
event($e,'failed','product',$tax);$before=$a->conf;$a->conf['display_tax_rate']='5.5';
check(strpos($e->settingsChanged($before),'1 échange(s) relancé(s)')!==false&&codes($e)===[],'Settings form does not requeue');
check($e->settingsChanged($a->conf)==='','Unchanged settings produce follow-up');
// Remaining kinds: failed (other error), order conflicts, stale worker, conflicts waiting with a chosen policy.
event($e,'failed','product','Peer timeout');event($e,'conflict','order','Both sites changed this record; review required.',0);event($e,'conflict','product','Both sites changed this record; review required.',0);
$a->worker=['state'=>'idle','at'=>gmdate('c',time()-900)];
check(codes($e)===['catalog_conflicts','order_conflicts','events_failed','worker_stale'],'Remaining actions wrong: '.implode(',',codes($e)));
$a->conf['mode']='disabled';check(codes($e)===[],'Disabled bridge reports actions');
// Partner side: the signed op aligns the policy and retries its own conflicts, without echoing back.
$b2=new ActionAdapter('woo');$p=new WD29\Bridge\Engine($b2);$b2->engine=$p;event($p,'conflict','product','Both sites changed this record; review required.',0);
check($p->receive(['source'=>'ps','op'=>'conflict_policy','policy'=>'ps'])['policy']==='ps'&&$b2->conf['conflict_policy']==='ps','Partner policy not applied');
check($p->sql("SELECT state FROM {b}queue")[0]['state']==='pending','Partner conflicts not retried');
refuses(fn()=>$p->receive(['source'=>'ps','op'=>'conflict_policy','policy'=>'other']),'Invalid partner policy accepted');
echo "PASS: action detection, suggestions, banner and notice links, one-click decisions, requeue on settings change, partner policy alignment\n";
