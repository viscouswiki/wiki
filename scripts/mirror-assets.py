#!/usr/bin/env python3
"""Mirror deadlock-api assets we reference into our own R2 bucket.

Reads /tmp/mirror_urls.txt (one origin URL per line, all on
assets-bucket.deadlock-api.com) and copies each object to R2 under the key
'deadlock/<original-path>', so it becomes reachable at
https://media.viscous.wiki/deadlock/<original-path>. Idempotent: objects that
already exist in the bucket are skipped, so it can be re-run safely.

Usage (on the wiki host):

  # 1. Generate the exact list of asset URLs the site references:
  docker compose exec -T mediawiki php maintenance/run.php eval <<'PHP'
  $rA=new ReflectionMethod('MediaWiki\\Extension\\DeadlockBuilds\\Hooks','assetMap'); $rA->setAccessible(true);
  $items=$rA->invoke(null,'items'); $heroes=$rA->invoke(null,'heroes');
  $rB=new ReflectionMethod('MediaWiki\\Extension\\DeadlockBuilds\\Hooks','abilityMap'); $rB->setAccessible(true); $abils=$rB->invoke(null);
  $u=[]; foreach($items as $it){if(!empty($it['image']))$u[$it['image']]=1;}
  foreach($heroes as $h){if(!empty($h['image']))$u[$h['image']]=1;}
  foreach($abils as $a){if(!empty($a['image']))$u[$a['image']]=1; if(!empty($a['video']))$u[$a['video']]=1;}
  file_put_contents('/tmp/mirror_urls.txt', implode("\n", array_keys($u))."\n");
  PHP
  docker cp "$(docker compose ps -q mediawiki)":/tmp/mirror_urls.txt /tmp/mirror_urls.txt

  # 2. Run this script with the R2_* env vars set (e.g. via a python container):
  grep -E '^R2_(ACCOUNT_ID|ACCESS_KEY_ID|SECRET_ACCESS_KEY|BUCKET)=' .env > /tmp/r2.env
  docker run --rm -v /tmp:/tmp --env-file /tmp/r2.env python:3-slim \
      sh -c "pip install -q boto3 && python /tmp/mirror-assets.py"; rm -f /tmp/r2.env
"""
import os, sys, urllib.request, boto3
from botocore.config import Config

HOST = 'https://assets-bucket.deadlock-api.com/'
KEY_PREFIX = 'deadlock/'
CT = {
    '.webp': 'image/webp', '.png': 'image/png', '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg', '.gif': 'image/gif', '.svg': 'image/svg+xml',
    '.mp4': 'video/mp4', '.webm': 'video/webm',
}

acct = os.environ['R2_ACCOUNT_ID']
bucket = os.environ['R2_BUCKET']
s3 = boto3.client(
    's3',
    endpoint_url=f'https://{acct}.r2.cloudflarestorage.com',
    aws_access_key_id=os.environ['R2_ACCESS_KEY_ID'],
    aws_secret_access_key=os.environ['R2_SECRET_ACCESS_KEY'],
    region_name='auto',
    config=Config(signature_version='s3v4'),
)

urls = [l.strip() for l in open('/tmp/mirror_urls.txt') if l.strip()]
done = skip = fail = 0
for u in urls:
    if not u.startswith(HOST):
        print('SKIP (other host):', u); fail += 1; continue
    path = u[len(HOST):]
    key = KEY_PREFIX + path
    try:
        s3.head_object(Bucket=bucket, Key=key)
        skip += 1
        continue
    except Exception:
        pass
    ext = os.path.splitext(path)[1].lower()
    ct = CT.get(ext, 'application/octet-stream')
    try:
        req = urllib.request.Request(u, headers={'User-Agent': 'viscous-wiki-mirror'})
        data = urllib.request.urlopen(req, timeout=60).read()
        s3.put_object(
            Bucket=bucket, Key=key, Body=data, ContentType=ct,
            CacheControl='public, max-age=31536000, immutable',
        )
        done += 1
        if done % 50 == 0:
            print(f'... uploaded {done}')
    except Exception as e:
        fail += 1
        print('FAIL', u, '->', e)

print(f'DONE uploaded={done} skipped={skip} failed={fail} total={len(urls)}')
