#!/usr/bin/env python3
"""Upload the local squid meme images into our R2 bucket under 'squid/<file>'.

Reads every file in /squid-src (mounted) and uploads it to the bucket at key
'squid/<basename>', so it becomes reachable at
https://media.viscous.wiki/squid/<basename>. Idempotent-ish: always re-uploads
(the set is tiny), which also lets us update an image by re-running.

Usage (on the wiki host):
  scp -r e:/squid ubuntu@host:/tmp/squid-src         # done from the client
  grep -E '^R2_(ACCOUNT_ID|ACCESS_KEY_ID|SECRET_ACCESS_KEY|BUCKET)=' .env > /tmp/r2.env
  docker run --rm -v /tmp/squid-src:/squid-src -v /tmp/upload-squid.py:/upload-squid.py \
      --env-file /tmp/r2.env python:3-slim \
      sh -c "pip install -q boto3 && python /upload-squid.py"; rm -f /tmp/r2.env
"""
import os, boto3
from botocore.config import Config

SRC = '/squid-src'
KEY_PREFIX = 'squid/'
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

done = fail = 0
for name in sorted(os.listdir(SRC)):
    path = os.path.join(SRC, name)
    if not os.path.isfile(path):
        continue
    ext = os.path.splitext(name)[1].lower()
    ct = CT.get(ext, 'application/octet-stream')
    try:
        with open(path, 'rb') as f:
            data = f.read()
        s3.put_object(
            Bucket=bucket, Key=KEY_PREFIX + name, Body=data, ContentType=ct,
            CacheControl='public, max-age=31536000, immutable',
        )
        done += 1
        print('OK  ', KEY_PREFIX + name, len(data))
    except Exception as e:
        fail += 1
        print('FAIL', name, '->', e)

print(f'DONE uploaded={done} failed={fail}')
