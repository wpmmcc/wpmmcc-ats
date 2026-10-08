#!/usr/bin/env python3
"""Real owned WP process-crash, competing callback and private Client HTTP fixtures."""
from concurrent.futures import ThreadPoolExecutor
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import uuid

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location("owned_callback", ROOT / "tests/infra/tools/owned-wp-sites.py")
owned = importlib.util.module_from_spec(spec)
spec.loader.exec_module(owned)

BOOTSTRAP = r"""
$root=WP_PLUGIN_DIR.'/wpmmcc-ats/includes/';
require_once $root.'tasks/database/schema-tasks.php';
require_once $root.'tasks/database/schema-translation-results.php';
require_once $root.'tasks/services/class-origin-visit-service.php';
require_once $root.'tasks/tasks.php';
require_once $root.'tasks/tasks-single.php';
require_once $root.'tasks/api/class-client-data-rest-controller.php';
require_once $root.'strings/database/schema-strings.php';
require_once $root.'strings/services/class-string-translation-service.php';
wptsall_ensure_task_table(); wptsall_create_translation_results_table();
"""


def setup(site, family="plugin", lane="i18n"):
    tag = "owned-callback-receipt-" + uuid.uuid4().hex[:20]
    php = BOOTSTRAP + r"""
global $wpdb;
$now=current_time('mysql',true);
$device='owned-callback-receipt-device';
$ok=$wpdb->insert(wptsall_table('site_relations'),array(
 'source_site_id'=>get_current_blog_id(),'source_site_type'=>'wp','source_lang'=>'zh_CN',
 'target_lang'=>'en_US','template'=>$tag,'target_site_type'=>'virtual','target_site_id'=>'v_'.$tag,
 'status'=>'active','created_at'=>$now,'updated_at'=>$now));
if(1!==$ok)throw new RuntimeException('owned relation fixture failed');
$relation=(int)$wpdb->insert_id;
$owner=wptsall_client_claim_owner_hash($device,$relation,'en_US',$family);
$entries=array(); $ids=array();
if('i18n'===$lane){
 require_once $root.'templates/database/schema-templates.php';
 wptsall_create_templates_table(); wptsall_create_template_entries_table();
 $ok=$wpdb->insert(wptsall_table('templates'),array(
  'relation_id'=>$relation,'slug'=>$tag,'source_type'=>$family,'source_identifier'=>$tag,
  'text_domain'=>$tag,'target_language'=>'en_US','status'=>'active','created_at'=>$now,'updated_at'=>$now));
 if(1!==$ok)throw new RuntimeException('owned template fixture failed');
 $template=(int)$wpdb->insert_id;
 for($i=0;$i<2;$i++){
  $ok=$wpdb->insert(wptsall_table('template_entries'),array(
   'template_id'=>$template,'msgid'=>$tag.'-'.$i,'msgstr'=>'','msgctxt'=>'','source'=>'scan',
   'reference'=>'owned.php:1','status'=>'pending','claimed_at'=>$now,'claim_owner_hash'=>$owner,
   'created_at'=>$now,'updated_at'=>$now));
  if(1!==$ok)throw new RuntimeException('owned entry fixture failed');
  $id=(int)$wpdb->insert_id; $ids[]=$id;
  $entries[]=array('entry_id'=>$id,'msgstr'=>'Owned retained translation '.$i);
 }
 $business=$family.'_i18n';
}elseif('content'===$lane){
 $post=(int)wp_insert_post(array('post_title'=>$tag,'post_content'=>'Owned source content','post_type'=>'post','post_status'=>'publish'));
 if($post<=0)throw new RuntimeException('owned content fixture failed');
 $controller=new \WPTSALL\Tasks\API\Client_Data_REST_Controller();
 $claim=new WP_REST_Request('POST','/wptsall/v2/client/content/claim');
 $claim->set_header('Content-Type','application/json');
 $claim->set_header('X-WPTSALL-Device-Id',$device);
 $claim->set_body(wp_json_encode(array('relation_id'=>$relation,'data_type'=>'post','items'=>array(array('object_id'=>$post,'post_type'=>'post')))));
 $claimed=$controller->claim_content($claim);
 if(200!==$claimed->get_status() || 1!==($claimed->get_data()['claimed_count']??0))throw new RuntimeException('owned content claim fixture failed');
 echo wp_json_encode(array('device_id'=>$device,'lane'=>$lane,'ids'=>array($post),
  'payload'=>array('schema_version'=>2,'business_line'=>'post_content','relation_id'=>$relation,
   'client_task_id'=>$tag,'worker_id'=>'owned-callback-worker','source_lang'=>'zh_CN','target_lang'=>'en_US',
   'object_type'=>'post_type','post_type'=>'post','object_id'=>$post,
   'source_revision'=>\WPTSALL\Core\Job_Snapshot::compute_source_revision('post_type',$post),
   'policy_version'=>\WPTSALL\Core\Job_Snapshot::current_policy_version(),
   'translated_fields'=>array('post_title'=>'Owned retained content translation'),'translated_meta'=>(object)array(),
   'media_mappings'=>array(),'execution_time_ms'=>1)));
 return;
}else{
 wptsall_create_strings_table();
 $configured=\WPTSALL\Sites\Services\Relation_Config_Service::save_template_config($relation,array(
  'translate_site_strings'=>true,'translate_menu_strings'=>true,'translate_widget_strings'=>true));
 if(!$configured || is_wp_error($configured))throw new RuntimeException('owned string lane fixture failed');
 $context='site'===$family?'site_title':$family;
 for($i=0;$i<2;$i++){
  $id=\WPTSALL\Strings\Services\String_Translation_Service::register($context,$tag.'-'.$i,'Owned string source '.$i,'zh_CN');
  if(!$id)throw new RuntimeException('owned string fixture failed');
  $ids[]=(int)$id; $entries[]=array('entry_id'=>(int)$id,'msgstr'=>'Owned retained string '.$i);
 }
 $claimed=\WPTSALL\Strings\Services\String_Translation_Service::claim_string_ids($ids,array($context),$owner,'en_US');
 if($claimed!==$ids)throw new RuntimeException('owned string claim fixture failed');
 $business=$family.'_strings';
}
echo wp_json_encode(array('device_id'=>$device,'lane'=>$lane,'ids'=>$ids,
 'payload'=>array('business_line'=>$business,'relation_id'=>$relation,'client_task_id'=>$tag,
  'worker_id'=>'owned-callback-worker','source_lang'=>'zh_CN','target_lang'=>'en_US','entries'=>$entries)));
"""
    preface = "$tag=" + json.dumps(tag) + ";$family=" + json.dumps(family) + ";$lane=" + json.dumps(lane) + ";\n"
    return json.loads(owned.wp(site["name"], preface + php))


def callback_php(fixture, crash=None):
    body = fixture["payload"]
    php = BOOTSTRAP + "$body=json_decode(" + json.dumps(json.dumps(body)) + ",true);\n"
    php += "$device=" + json.dumps(fixture["device_id"]) + ";\n"
    if crash:
        php += r"""
if(!function_exists('posix_kill'))throw new RuntimeException('owned process kill unavailable');
$crash=function($point){echo wp_json_encode(array('point'=>$point,'pid'=>getmypid()))."\n";fflush(STDOUT);posix_kill(getmypid(),9);exit(1);};
"""
        if crash == "second_entry_update":
            php += r"""
$hits=0; $table=wptsall_table('template_entries');
add_filter('query',function($sql)use(&$hits,$table,$crash){
 if(0===strpos($sql,'UPDATE `'.$table.'` e INNER JOIN') && ++$hits===2){$crash('second_entry_update');}
 return $sql;
});
"""
        elif crash == "first_update_notification":
            php += "add_action('wptsall_entry_updated',function()use($crash){$crash('first_update_notification');});\n"
        elif crash in ("before_target_effect_boundary", "before_terminal_receipt", "after_terminal_commit"):
            php += "$point=" + json.dumps(crash) + ";\n" + r"""
$table=wptsall_table('translation_results');$terminal_write=false;$terminal_commit=false;
add_filter('query',function($sql)use($table,$point,$crash,&$terminal_write,&$terminal_commit){
 $update=0===strpos($sql,'UPDATE `'.$table.'` SET');
 if('before_target_effect_boundary'===$point && $update && false!==strpos($sql,"'applying'")){$crash($point);}
 if($update && false!==strpos($sql,"'synced'")){
  if('before_terminal_receipt'===$point){$crash($point);}
  $terminal_write=true;
 }elseif($terminal_write && 'COMMIT'===$sql){$terminal_commit=true;}
 elseif($terminal_commit && 0===strpos($sql,'SELECT * FROM `'.$table.'` WHERE id') && false===strpos($sql,'FOR UPDATE')){$crash($point);}
 return $sql;
});
"""
        else:
            raise ValueError("unknown owned crash point")
    php += r"""
$request=new WP_REST_Request('POST','/wptsall/v2/client/translation-callback');
$request->set_header('Content-Type','application/json');
$request->set_header('X-WPTSALL-Device-Id',$device);
$request->set_body(wp_json_encode($body));
$response=(new \WPTSALL\Tasks\API\Client_Data_REST_Controller())->translation_callback($request);
echo wp_json_encode(array('status'=>$response->get_status(),'body'=>$response->get_data()));
"""
    return php


def snapshot(site, fixture):
    if fixture["lane"] == "content":
        php = "$fixture=json_decode(" + json.dumps(json.dumps(fixture)) + ",true);\n" + r"""
global $wpdb;
$results=$wpdb->get_results($wpdb->prepare('SELECT id,status,client_task_id,translated_meta FROM %i WHERE client_task_id=%s',wptsall_table('translation_results'),$fixture['payload']['client_task_id']),ARRAY_A);
if($wpdb->last_error)throw new RuntimeException('owned content receipt inventory failed');
$targets=$wpdb->get_col($wpdb->prepare('SELECT p.ID FROM %i p INNER JOIN %i m ON m.post_id=p.ID AND m.meta_key=%s WHERE m.meta_value=%s',
 $wpdb->posts,$wpdb->postmeta,'_wptsall_source_post_id',(string)$fixture['ids'][0]));
if($wpdb->last_error)throw new RuntimeException('owned target inventory failed');
echo wp_json_encode(array('results'=>$results,'targets'=>$targets));
"""
        return json.loads(owned.wp(site["name"], BOOTSTRAP + php))
    php = "$fixture=json_decode(" + json.dumps(json.dumps(fixture)) + ",true);\n" + r"""
global $wpdb;
$states=array();
foreach($fixture['ids'] as $id){
 if('i18n'===$fixture['lane']){
  $row=$wpdb->get_row($wpdb->prepare('SELECT status,msgstr,claimed_at,claim_owner_hash FROM %i WHERE id=%d',wptsall_table('template_entries'),$id),ARRAY_A);
 }else{
  $row=$wpdb->get_row($wpdb->prepare('SELECT status,translations,claimed_at,claim_owner_hash FROM %i WHERE id=%d',wptsall_table('strings'),$id),ARRAY_A);
 }
 if(!is_array($row) || $wpdb->last_error)throw new RuntimeException('owned snapshot unavailable');
 $states[]=$row;
}
$results=$wpdb->get_results($wpdb->prepare('SELECT id,status,translated_fields FROM %i WHERE client_task_id=%s',wptsall_table('translation_results'),$fixture['payload']['client_task_id']),ARRAY_A);
if($wpdb->last_error)throw new RuntimeException('owned receipt inventory unavailable');
echo wp_json_encode(array('entries'=>$states,'results'=>$results));
"""
    return json.loads(owned.wp(site["name"], BOOTSTRAP + php))


def killed_callback(site, fixture, point):
    command = ["docker", "exec", "-i", site["name"], "wp", "--allow-root", "--path=/var/www/html",
               "eval", "eval('?>'.stream_get_contents(STDIN));"]
    result = subprocess.run(command, input="<?php\n" + callback_php(fixture, point), text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
    markers = []
    for line in result.stdout.splitlines():
        if line.startswith("{"):
            try:
                markers.append(json.loads(line))
            except ValueError:
                pass
    assert result.returncode == 137, "owned PHP callback must actually die by SIGKILL"
    assert len(markers) == 1 and markers[0]["point"] == point
    return {"exit": result.returncode, "point": point, "owned_php_pid": markers[0]["pid"]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--site", type=int, choices=(0, 1), default=1)
    parser.add_argument("--http-fixture", type=Path)
    args = parser.parse_args()
    context = owned.load(args.context)
    assert context["plugin"] == "wpmmcc-ats"
    site = context["sites"][args.site]
    info = json.loads(subprocess.check_output(["docker", "inspect", site["name"]], text=True))[0]
    settings = dict(value.split("=", 1) for value in info["Config"]["Env"] if "=" in value)
    assert info["Config"]["Labels"][owned.LABEL] == context["owner"]
    assert settings["WORDPRESS_TABLE_PREFIX"] == site["prefix"]
    checks = []

    def check(name, condition, detail=None):
        checks.append({"name": name, "passed": bool(condition), "detail": detail})
        print(("PASS " if condition else "FAIL ") + name, flush=True)

    for point in ("second_entry_update", "first_update_notification"):
        fixture = setup(site)
        crash = killed_callback(site, fixture, point)
        before = snapshot(site, fixture)
        if point == "second_entry_update":
            untouched = all(row["status"] == "pending" and row["msgstr"] == ""
                            and row["claimed_at"] and row["claim_owner_hash"] for row in before["entries"])
            check("SIGKILL mid-batch rolls back entries claims and receipt",
                  untouched and len(before["results"]) == 0, crash)
        else:
            check("first update notification occurs only after complete receipt commits",
                  all(row["status"] == "translated" for row in before["entries"])
                  and len(before["results"]) == 1 and before["results"][0]["status"] == "synced", crash)
        resumed = json.loads(owned.wp(site["name"], callback_php(fixture)))
        check("fresh process resumes the original saved callback at " + point,
              resumed["status"] == 200 and resumed["body"].get("entries_updated") == 2
              and resumed["body"].get("result_status") == "synced")
        replay = json.loads(owned.wp(site["name"], callback_php(fixture)))
        after = snapshot(site, fixture)
        check("lost response replay retains one original receipt at " + point,
              replay["status"] == 200 and replay["body"].get("idempotent") is True
              and replay["body"].get("result_id") == resumed["body"].get("result_id")
              and len(after["results"]) == 1 and all(row["status"] == "translated" for row in after["entries"]))

    fixture = setup(site)
    with ThreadPoolExecutor(max_workers=8) as pool:
        replies = list(pool.map(lambda _: json.loads(owned.wp(site["name"], callback_php(fixture))), range(8)))
    state = snapshot(site, fixture)
    check("eight competing original callbacks adopt one complete durable receipt",
          all(reply["status"] == 200 and reply["body"].get("entries_updated") == 2 for reply in replies)
          and len({reply["body"].get("result_id") for reply in replies}) == 1
          and len(state["results"]) == 1)

    fixture = setup(site)
    other = json.loads(json.dumps(fixture))
    other["payload"]["entries"][0]["msgstr"] = "Different competing body"
    with ThreadPoolExecutor(max_workers=2) as pool:
        replies = list(pool.map(lambda body: json.loads(owned.wp(site["name"], callback_php(body))), (fixture, other)))
    state = snapshot(site, fixture)
    check("competing different bodies cannot overwrite the winning callback",
          sorted(reply["status"] for reply in replies) == [200, 409] and len(state["results"]) == 1,
          {"replies": [{"status": reply["status"], "error": reply["body"].get("error"),
                        "result_id": reply["body"].get("result_id")} for reply in replies],
           "result_count": len(state["results"])})

    for point in ("before_target_effect_boundary", "before_terminal_receipt", "after_terminal_commit"):
        fixture = setup(site, "post", "content")
        crash = killed_callback(site, fixture, point)
        before = snapshot(site, fixture)
        assert len(before["results"]) == 1, "owned original content receipt must remain"
        original_id = int(before["results"][0]["id"])
        owned.wp(site["name"], "$id=" + str(original_id) + ";" + r"""
global $wpdb;
if(1!==$wpdb->update(wptsall_table('translation_results'),array('created_at'=>gmdate('Y-m-d H:i:s',time()-3700)),array('id'=>$id)))
 throw new RuntimeException('owned receipt age fixture failed');
""")
        resumed = json.loads(owned.wp(site["name"], callback_php(fixture)))
        after = snapshot(site, fixture)
        if point == "before_target_effect_boundary":
            check("prepared original body resumes after an actual pre-effect process crash",
                  before["results"][0]["status"] == "pending" and before["targets"] == []
                  and resumed["status"] == 200 and resumed["body"].get("result_status") == "synced"
                  and len(after["targets"]) == 1, crash)
        elif point == "before_terminal_receipt":
            check("actual post-write crash keeps original unknown effects despite age",
                  before["results"][0]["status"] == "applying" and len(before["targets"]) == 1
                  and resumed["status"] == 409 and len(after["results"]) == 1
                  and int(after["results"][0]["id"]) == original_id and after["targets"] == before["targets"], crash)
        else:
            check("actual terminal-commit crash replays same applied content receipt",
                  before["results"][0]["status"] == "synced" and len(before["targets"]) == 1
                  and resumed["status"] == 200 and resumed["body"].get("idempotent") is True
                  and resumed["body"].get("result_id") == original_id
                  and after["targets"] == before["targets"], crash)

    if args.http_fixture:
        cases = [setup(site, family) for family in ("theme", "plugin", "config")]
        cases += [setup(site, family, "strings") for family in ("site", "menu", "widget")]
        content_cases = [setup(site, "post", "content"), setup(site, "post", "content")]
        content_cases[1]["payload"]["media_mappings"] = [{
            "source_id": 1, "translated_ref": "https://example.invalid/owned-callback-retained.bin",
        }]
        auth = json.loads(owned.wp(site["name"], r"""
$issued=wptsall_issue_client_device_token('owned-callback-receipt-device','owned callback HTTP gate');
echo wp_json_encode(array('token'=>$issued['token'],'device_id'=>$issued['device_id'],'secret'=>wptsall_get_client_route_secret()));
"""))
        private = {
            "format": "owned-callback-http-v1", "owner": context["owner"], "container": site["name"],
            "wp_base": site["base_url"] + "/wp-json/wptsall/v2/" + auth["secret"] + "/client",
            "token": auth["token"], "device_id": auth["device_id"], "cases": cases,
            "content_cases": content_cases,
        }
        fd = os.open(args.http_fixture, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w") as output:
            json.dump(private, output)
            output.flush()
            os.fsync(output.fileno())
        check("six batch lanes and complete or parked-media content HTTP fixtures prepared privately", True)

    record = {
        "owner": context["owner"], "container": site["name"], "prefix": site["prefix"],
        "checks": checks, "passed": sum(check["passed"] for check in checks),
        "failed": sum(not check["passed"] for check in checks),
        "script_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
    }
    with args.report.open("x") as output:
        json.dump(record, output, indent=2)
        output.write("\n")
    return 0 if record["failed"] == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
