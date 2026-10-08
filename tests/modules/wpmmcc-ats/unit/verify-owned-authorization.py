#!/usr/bin/env python3
"""Private, real WordPress role/nonce/discovery/write-authorization regressions."""

import argparse
import importlib.util
import json
from pathlib import Path
import subprocess
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "owned_wp_authorization", ROOT / "tests/infra/tools/owned-wp-sites.py"
)
owned_wp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(owned_wp)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    context = owned_wp.load(args.context)
    if context["plugin"] != "wpmmcc-ats":
        raise ValueError("requires owned ATS sites")
    for site in context["sites"]:
        label = subprocess.check_output(
            ["docker", "inspect", site["name"], "--format",
             '{{index .Config.Labels "com.wptsall.owned.run"}}'],
            text=True,
        ).strip()
        if label != context["owner"]:
            raise ValueError("owned identity mismatch")
    site = context["sites"][0]
    rows = []

    def check(name, passed):
        rows.append({"name": name, "passed": bool(passed)})
        print(("PASS " if passed else "FAIL ") + name)

    fixture = json.loads(owned_wp.wp(site["name"], """
global $wpdb;
$translator=get_user_by('login','ownedtranslator');
if(!$translator) {
 $id=wp_insert_user(array('user_login'=>'ownedtranslator',
  'user_pass'=>wp_generate_password(40),'role'=>'wptsall_translator'));
 if(is_wp_error($id)) throw new RuntimeException('owned translator fixture');
 $translator=get_user_by('id',$id);
}
$admin=get_user_by('login','ownedadmin');
$auth=array();
foreach(array('admin'=>$admin,'translator'=>$translator) as $role=>$user) {
 $expiry=time()+3600;
 $token=WP_Session_Tokens::get_instance($user->ID)->create($expiry);
 $logged=wp_generate_auth_cookie($user->ID,$expiry,'logged_in',$token);
 $_COOKIE[LOGGED_IN_COOKIE]=$logged;
 wp_set_current_user($user->ID);
 $auth[$role]=array('cookie'=>AUTH_COOKIE.'='.wp_generate_auth_cookie($user->ID,$expiry,'auth',$token)
  .'; '.LOGGED_IN_COOKIE.'='.$logged, 'nonce'=>wp_create_nonce('wp_rest'));
}
echo wp_json_encode(array('auth'=>$auth,'prefix'=>$wpdb->prefix,
 'posts'=>$wpdb->posts,'users'=>$wpdb->users,'options'=>$wpdb->options,
 'usermeta'=>$wpdb->usermeta));
"""))
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def request(role, method, route, payload=None, nonce="valid"):
        headers = {"Cookie": fixture["auth"][role]["cookie"]}
        if nonce == "valid":
            headers["X-WP-Nonce"] = fixture["auth"][role]["nonce"]
        elif nonce == "wrong":
            headers["X-WP-Nonce"] = "owned-invalid-nonce"
        body = None
        if payload is not None:
            body = json.dumps(payload).encode()
            headers["Content-Type"] = "application/json"
        url = site["base_url"] + "/?rest_route=" + urllib.parse.quote(
            "/wptsall/v2/" + route.split("?", 1)[0], safe="/"
        )
        if "?" in route:
            url += "&" + route.split("?", 1)[1]
        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            with opener.open(req, timeout=15) as response:
                return response.status, json.load(response)
        except urllib.error.HTTPError as error:
            return error.code, json.loads(error.read())

    status, data = request("translator", "GET", "discovery/tables")
    check("translator cannot discover database schema", status == 403)
    status, data = request("translator", "GET", "discovery/values?" + urllib.parse.urlencode(
        {"table": fixture["users"], "column": "user_pass"}))
    check("translator cannot read password hashes", status == 403)
    status, data = request("admin", "GET", "discovery/tables")
    check("settings admin can discover content tables", status == 200 and fixture["posts"] in data)
    check("sensitive and internal tables absent", status == 200 and all(
        name not in data for name in
        (fixture["users"], fixture["usermeta"], fixture["options"],
         fixture["prefix"] + "wptsall_translation_results")))
    for table, column in ((fixture["users"], "user_pass"),
                          (fixture["options"], "option_value"),
                          (fixture["usermeta"], "meta_value")):
        status, data = request("admin", "GET", "discovery/values?" + urllib.parse.urlencode(
            {"table": table, "column": column}))
        check("settings does not grant sensitive values: " + column,
              status == 403 or (status == 200 and data == []))
    status, data = request("admin", "POST", "discovery/test-config",
                           {"config": {"table": fixture["options"], "field": "option_value",
                                       "associated_id_map": "option_id"}, "sample_id": 1})
    check("sensitive sample lookup rejected", status == 403)
    for nonce in ("missing", "wrong"):
        status, data = request("admin", "POST", "discovery/test-config",
                               {"config": {"table": fixture["posts"], "field": "post_title",
                                           "associated_id_map": "ID"}, "sample_id": 1},
                               nonce=nonce)
        check("cookie REST rejects " + nonce + " nonce", status in (401, 403))
    for route, payload in (
        ("plugins/scan", {}),
        ("plugin-mappings/consent", {"plugins": ["owned-example"]}),
        ("models/import", {"models": []}),
        ("custom-models", {"name": "owned-unapproved", "plugin_slug": "owned-example"}),
        ("tasks/monitor/start", {"relation_id": 1}),
    ):
        status, data = request("translator", "POST", route, payload)
        check("translator cannot manage " + route, status == 403)
    # Read-only production information remains useful to a translator.
    status, data = request("translator", "GET", "plugins?status=all&format=simple")
    check("translator retains read-only plugin catalog", status == 200)

    native = json.loads(owned_wp.wp(site["name"], """
global $wpdb;
use WPTSALL\\Models\\Services\\Field_Discovery_Service as D;
use WPTSALL\\Tasks\\Sync\\Sync_Executor as S;
use WPTSALL\\Sites\\Services\\Manual_Content_Service as M;
$id=wp_insert_post(array('post_title'=>'Owned source','post_content'=>'Source',
 'post_excerpt'=>'Source excerpt','post_status'=>'draft'),true);
if(is_wp_error($id)) throw new RuntimeException('owned source fixture');
$table=$wpdb->prefix.'owned_content';
$wpdb->query("CREATE TABLE IF NOT EXISTS `$table` (id BIGINT PRIMARY KEY, caption TEXT)");
$wpdb->replace($table,array('id'=>1,'caption'=>'Owned custom content'));
$register=function($tables) use($table) { $tables[$table]=array('id','caption'); return $tables; };
add_filter('wptsall_discovery_content_tables',$register);
$sample=D::test_field_config(array('table'=>$table,'field'=>'caption','associated_id_map'=>'id'),1);
$custom_allowed=$sample==='Owned custom content';
remove_filter('wptsall_discovery_content_tables',$register);
$custom_denied=D::get_distinct_values($table,'caption')===array();
$alias_denied=D::get_table_columns($wpdb->posts.'!')===array();
$source=array('post'=>array('ID'=>$id,'post_title'=>'Owned source','post_content'=>'Source',
 'post_status'=>'draft','post_author'=>1,'post_type'=>'post'),
 'meta'=>array('hero_caption'=>'Original caption','unknown_blob'=>'Original blob',
  '_elementor_data'=>'[{"id":"n1","widgetType":"heading","settings":{"title":"Original"}}]'));
$formats=array('post_title'=>'plain_text','hero_caption'=>'plain_text',
 '_elementor_data'=>'json_structured','post_status'=>'plain_text',
 '_wptsall_relation_id'=>'plain_text');
$merge=new ReflectionMethod(S::class,'merge_translation_result');
$merge->setAccessible(true);
$out=$merge->invoke(null,$source,
 array('post_title'=>'Translated title','post_status'=>'publish','post_author'=>999,'ID'=>999),
 array('hero_caption'=>'Translated caption','unknown_blob'=>'{"html":"<script>owned()</script>"}',
 '_wptsall_relation_id'=>'999',
 '_elementor_data'=>'[{"id":"n1","widgetType":"heading","settings":{"title":"Translated"}}]'),
 'post_type',$formats);
$bad=$merge->invoke(null,$source,array(),array('_elementor_data'=>'[broken'), 'post_type',$formats);
$term=$merge->invoke(null,array('term'=>array('name'=>'Source term','slug'=>'source','parent'=>0)),
 array('name'=>'Translated term','parent'=>999,'taxonomy'=>'owned-hijack'),
 array(),'taxonomy',array('name'=>'plain_text','parent'=>'plain_text'));
$option=$merge->invoke(null,array('option'=>array('option_name'=>'owned_labels',
 'option_value'=>array('label'=>'Source label','secret'=>'Untouched'))),
 array('label'=>'Translated label','secret'=>'Changed'),array(),'option',array('label'=>'plain_text'));
$translator=get_user_by('login','ownedtranslator');
wp_set_current_user($translator->ID);
$wpdb->insert($wpdb->prefix.'wptsall_site_relations',array(
 'source_site_id'=>get_current_blog_id(),'source_site_type'=>'wp','source_lang'=>'en_US',
 'target_site_id'=>'v_owned_authorization_'.uniqid(),'target_site_type'=>'virtual',
 'target_lang'=>'zh_CN','template'=>'owned-authorization','status'=>'active',
 'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
$relation=(int)$wpdb->insert_id;
if(!$relation) throw new RuntimeException('owned relation fixture creation failed');
$manual=M::save_translation($id,$relation,array('post_title'=>'Manual title',
 'owned_unknown_json'=>'{"html":"<script>owned()</script>"}'));
$edit=M::update_all_fields($id,$relation,array('post_status'=>'publish',
 '_wptsall_relation_id'=>999));
$shape=M::sanitize_editor_payload_value('owned_unknown_json','{"html":"<script>owned()</script>"}');
$formats_method=new ReflectionMethod(S::class,'get_field_content_formats_for_sync');
$formats_method->setAccessible(true);
$default_term=$formats_method->invoke(null,$relation,'category','taxonomy');
$quoted=wp_json_encode(array(array('id'=>'n1','widgetType'=>'heading',
 'settings'=>array('title'=>'Translated "quoted" and \\\\ path'))));
update_post_meta($id,'_elementor_data',wp_slash($source['meta']['_elementor_data']));
$manual_json=M::save_translation($id,$relation,array('post_title'=>'Legal manual title','_elementor_data'=>$quoted));
$manual_native=!is_wp_error($manual_json)
 && get_post_meta($manual_json['target_id'],'_elementor_data',true)===$quoted;
$now=current_time('mysql');
$wpdb->insert($wpdb->prefix.'wptsall_models',array(
 'plugin_slug'=>'owned-policy-'.uniqid(),'plugin_name'=>'Owned policy',
 'status'=>'active','is_system'=>1,'created_at'=>$now,'updated_at'=>$now));
$model=(int)$wpdb->insert_id;
if(!$model) throw new RuntimeException('owned model fixture creation failed');
WPTSALL\\Sites\\Services\\Relation_Model_Service::add_model_to_relation($relation,$model);
$attached=WPTSALL\\Sites\\Services\\Relation_Model_Service::get_models_by_relation($relation);
if(!in_array($model,array_map('intval',array_column($attached,'id')),true)) {
 throw new RuntimeException('owned model association fixture creation failed');
}
$caps=array(
 'post_title'=>array('type'=>'translate','enabled'=>true,'content_format'=>'plain_text'),
 'post_content'=>array('type'=>'translate','enabled'=>false,'content_format'=>'rich_html'),
 'owned_sync'=>array('type'=>'sync','enabled'=>true,'content_format'=>'plain_text'),
 'owned_skip'=>array('type'=>'skip','enabled'=>true,'content_format'=>'plain_text'));
$wpdb->insert($wpdb->prefix.'wptsall_translation_rules',array(
 'model_id'=>$model,'url_pattern'=>'/','url_type'=>'single','data_type'=>'post',
 'object_name'=>'post','is_active'=>1,'field_capabilities'=>wp_json_encode($caps),
 'created_at'=>$now,'updated_at'=>$now));
$rule=(int)$wpdb->insert_id;
if(!$rule) throw new RuntimeException('owned rule fixture creation failed');
$old_flags=get_option('wptsall_field_translations',array());
update_option('wptsall_field_translations',array('post'=>array(
 'owned_overlay'=>array('translatable'=>true),
 'post_content'=>array('translatable'=>true),
 'owned_skip'=>array('translatable'=>true))));
try {
 $resolved=$formats_method->invoke(null,$relation,'post','post_type',$id);
 $adapter_selected=isset($resolved['_elementor_data']) && $resolved['_elementor_data']==='json_structured';
 $overlay_selected=isset($resolved['owned_overlay']);
 $disabled_excluded=!isset($resolved['post_content']) && !isset($resolved['owned_sync']) && !isset($resolved['owned_skip']);
 $rule_update=WPTSALL\\Models\\Services\\Translation_Rule_Service::update_rule($rule,array('is_active'=>0));
 if(is_wp_error($rule_update) || false===$rule_update) throw new RuntimeException('owned rule disable failed');
 $disabled_all=$formats_method->invoke(null,$relation,'post','post_type',$id)===array();
 $fse_disabled=$formats_method->invoke(null,$relation,'wp_global_styles','post_type',$id)===array();
 $manual_disabled=M::save_translation($id,$relation,array('post_title'=>'Disabled rule must reject'));
} finally { update_option('wptsall_field_translations',$old_flags); }
$styles=wp_json_encode(array('settings'=>array('owned_label'=>'Text <strong>quoted</strong>'),
 'styles'=>array('color'=>array('background'=>'#123456'))));
$styles_out=$merge->invoke(null,array('post'=>array('post_content'=>$styles)),
 array('post_content'=>$styles),array(),'post_type',array('post_content'=>'json_structured'));
echo wp_json_encode(array(
 'registered custom content allowed'=>$custom_allowed,
 'unregistered custom values denied'=>$custom_denied,
 'identifier normalization does not alias a table'=>$alias_denied,
 'selected text and custom meta apply'=>$out['post_title']==='Translated title'
   && $out['meta']['hero_caption']==='Translated caption',
 'machine identity and status stay source-owned'=>$out['ID']===$id
   && $out['post_author']===1 && $out['post_status']==='draft',
 'unknown and internal metadata do not apply'=>$out['meta']['unknown_blob']==='Original blob'
   && !isset($out['meta']['_wptsall_relation_id']),
 'registered JSON retains native structure'=>json_decode($out['meta']['_elementor_data'],true)[0]['settings']['title']==='Translated',
 'broken JSON preserves original'=>$bad['meta']['_elementor_data']===$source['meta']['_elementor_data'],
 'term hierarchy cannot come from translation'=>$term['name']==='Translated term'
   && $term['parent']===0 && !isset($term['taxonomy']),
 'option only applies authorized leaves'=>$option['option_value']['label']==='Translated label'
   && $option['option_value']['secret']==='Untouched',
 'manual cannot add unregistered metadata'=>is_wp_error($manual)
   && $manual->get_error_code()==='rest_field_forbidden',
 'translation capability is not native full-edit capability'=>is_wp_error($edit)
   && $edit->get_error_code()==='rest_forbidden' && get_post($id)->post_status==='draft',
 'unknown JSON shape cannot enable structured writer'=>is_wp_error($shape),
 'taxonomy default uses taxonomy rather than post columns'=>isset($default_term['name'])
  && isset($default_term['description']) && !isset($default_term['post_title']),
 'manual registered JSON survives native metadata unslashing'=>$manual_native,
 'callback uses registered source adapter format'=>$adapter_selected,
 'callback honors explicit discovery overlay with attached model'=>$overlay_selected,
 'disabled sync and skip fields stay excluded'=>$disabled_excluded,
 'disabled matching rule cannot regain default or adapter fields'=>$disabled_all,
 'disabled attached model cannot regain synthetic FSE fields'=>$fse_disabled,
 'manual cannot revive a disabled rule'=>is_wp_error($manual_disabled),
 'global styles structured column is not HTML-sanitized'=>$styles_out['post_content']===$styles
));
"""))
    for name, passed in native.items():
        check(name, passed)
    report = {"kind": "owned-wp-authorization", "passed": sum(r["passed"] for r in rows),
              "failed": sum(not r["passed"] for r in rows), "checks": rows}
    args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(f"RESULT {report['passed']} passed, {report['failed']} failed")
    return 1 if report["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
