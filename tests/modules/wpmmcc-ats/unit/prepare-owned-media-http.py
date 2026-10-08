#!/usr/bin/env python3
"""Prepare a private client fixture for real HTTP tests on verified owned WP."""
import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[4]
spec=importlib.util.spec_from_file_location("owned_media_http",ROOT/"tests/infra/tools/owned-wp-sites.py")
owned=importlib.util.module_from_spec(spec)
spec.loader.exec_module(owned)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context",type=Path,required=True)
    parser.add_argument("--fixture",type=Path,required=True)
    args=parser.parse_args()
    context=owned.load(args.context)
    if context["plugin"]!="wpmmcc-ats":
        raise ValueError("owned ATS fixture required")
    site=context["sites"][0]
    label=subprocess.check_output(["docker","inspect",site["name"],"--format",
                                   '{{index .Config.Labels "com.wptsall.owned.run"}}'],text=True).strip()
    if label!=context["owner"]:
        raise ValueError("owned fixture identity mismatch")
    for relative in ["includes/tasks/api/class-client-data-rest-controller.php","includes/core/class-transport-middleware.php"]:
        source=ROOT/"wpmmcc-ats/source"/relative
        destination="/var/www/html/wp-content/plugins/wpmmcc-ats/"+relative
        subprocess.run(["docker","cp",str(source),site["name"]+":"+destination],check=True)
        copied=subprocess.check_output(["docker","exec",site["name"],"sha256sum",destination],text=True).split()[0]
        if copied!=hashlib.sha256(source.read_bytes()).hexdigest():
            raise RuntimeError("owned source copy mismatch")
        subprocess.run(["docker","exec",site["name"],"chmod","644",destination],check=True)
    data=json.loads(owned.wp(site["name"],"""
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$upload=wp_upload_bits('owned-http-source.png',null,$png);
if(!empty($upload['error']))throw new RuntimeException('owned source fixture');
$source=wp_insert_attachment(array('post_mime_type'=>'image/png','post_title'=>'Owned HTTP source','post_status'=>'private'),$upload['file']);
$created=\\WPTSALL\\Sites\\Services\\Site_Relation_Service::create_relation(array(
 'template'=>'wordpress-blog','source_site_id'=>get_current_blog_id(),'source_lang'=>'en',
 'target_sites'=>array(array('id'=>'v_owned_http_'.uniqid(),'type'=>'virtual','lang'=>'zh_CN'))));
$relation=(int)($created['relation_ids'][0]??0);
$device='owned-http-'.wp_generate_uuid4();
$issued=wptsall_issue_client_device_token($device,'owned private HTTP tests');
echo wp_json_encode(array('source_id'=>(int)$source,'relation_id'=>$relation,
 'token'=>$issued['token'],'device_id'=>$issued['device_id'],'route_secret'=>wptsall_get_client_route_secret()));
"""))
    if data["source_id"]<=0 or data["relation_id"]<=0:
        raise RuntimeError("owned HTTP identity is incomplete")
    # WP-CLI runs as root, while genuine HTTP imports run as www-data.
    # The uploads tree belongs exclusively to this verified disposable site.
    subprocess.run(["docker","exec",site["name"],"chown","-R","www-data:www-data",
                    "/var/www/html/wp-content/uploads"],check=True)
    data["wp_base"]=site["base_url"]+"/wp-json/wptsall/v2/"+data["route_secret"]+"/client"
    data["format"]="owned-media-http-v1"
    data["owner"]=context["owner"]
    data["container"]=site["name"]
    data["png"]=base64.b64encode(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")).decode()
    fd=os.open(args.fixture,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(fd,"w") as output:
        json.dump(data,output)
        output.flush()
        os.fsync(output.fileno())
    print("Owned HTTP fixture ready, private file only; no credentials printed")


if __name__=="__main__":
    main()
