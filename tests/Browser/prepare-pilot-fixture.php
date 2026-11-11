<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||basename(config('database.connections.sqlite.database'))!=='phase6-ui.sqlite'||config('intelligence.openai_key')!=='ui-mock-key-not-valid'){throw new RuntimeException('Use only the isolated Phase 6 SQLite fixture and invalid test key.');}
Illuminate\Support\Facades\Http::preventStrayRequests();
$organization=App\Models\Organization::where('slug','nandini-jungle')->firstOrFail();$user=App\Models\User::where('email','ui-batches@example.test')->firstOrFail();$brand=Illuminate\Support\Facades\DB::table('monitored_brands')->where('organization_id',$organization->id)->where('slug','nandini')->first();app(App\Services\TenantContext::class)->set($organization->id,$brand->id,$user);
$study=app(App\Services\PilotStudy::class);$batch=$study->prepare($user);
if(($argv[1]??'prepare')==='prepare'){echo 'Isolated 12-item draft prepared without API requests.'.PHP_EOL;exit;}
if($batch->runs()->exists()){throw new RuntimeException('Mock fixture already has pilot results; start from a fresh fixture copy.');}
App\Models\SpendingSetting::first()->update(['monthly_limit_micros'=>5000000]);$organization->forceFill(['monthly_limit_micros'=>5000000])->save();
$preview=$study->preview($batch);$study->enable($batch,$user,2000000,$preview['fingerprint']);
$index=0;Illuminate\Support\Facades\Http::fake(['api.openai.com/*'=>function()use(&$index){$index++;[$name,$url]=$index===2?['Hanging Gardens of Bali','https://hanginggardensofbali.com/']:['Nandini Jungle Resort','https://nandinibali.com/'];$text=$index===3?'Nandini Jungle Resort is mentioned for context only, not recommended.':'1. '.$name.' is recommended.';return Illuminate\Support\Facades\Http::response(['id'=>'resp_browser_pilot_'.$index,'model'=>'gpt-4.1-mini','status'=>'completed','output'=>[['type'=>'web_search_call','status'=>'completed'],['type'=>'message','content'=>[['type'=>'output_text','text'=>$text,'annotations'=>$index===3?[]:[['type'=>'url_citation','url'=>$url,'title'=>$name,'start_index'=>3,'end_index'=>mb_strlen($text)]]]]]],'usage'=>['input_tokens'=>8528,'output_tokens'=>512,'total_tokens'=>9040]]);}]);
$count=app(App\Services\BatchProcessor::class)->process(3);if($count!==3){throw new RuntimeException('Expected three mocked pilot requests.');}
$batch->refresh();$batch->update(['status'=>'paused','status_note'=>'Isolated browser fixture: three mocked results; no live requests.','pilot_enabled_at'=>null,'pilot_approved_by'=>null,'pilot_budget_micros'=>null,'pilot_approval_fingerprint'=>null]);
App\Models\SpendingSetting::first()->update(['monthly_limit_micros'=>0]);$organization->forceFill(['monthly_limit_micros'=>0])->save();
echo 'Three mocked results saved in isolated SQLite; both test allowances restored to $0. No paid requests.'.PHP_EOL;
