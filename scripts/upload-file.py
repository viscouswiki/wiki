#!/usr/bin/env python3
"""Upload a single mounted file (/upload-src) to R2 at key $UP_KEY.

  scp scripts/discord-mark.svg wiki:/tmp/discord-mark.svg
  scp scripts/upload-file.py   wiki:/tmp/upload-file.py
  grep -E '^R2_(ACCOUNT_ID|ACCESS_KEY_ID|SECRET_ACCESS_KEY|BUCKET)=' .env > /tmp/r2.env
  docker run --rm -v /tmp/discord-mark.svg:/upload-src -v /tmp/upload-file.py:/u.py \
      -e UP_KEY=branding/discord-mark.svg -e UP_CT=image/svg+xml \
      --env-file /tmp/r2.env python:3-slim sh -c "pip install -q boto3 && python /u.py"
"""
import os, boto3
from botocore.config import Config

acct = os.environ['R2_ACCOUNT_ID']
s3 = boto3.client(
    's3',
    endpoint_url=f'https://{acct}.r2.cloudflarestorage.com',
    aws_access_key_id=os.environ['R2_ACCESS_KEY_ID'],
    aws_secret_access_key=os.environ['R2_SECRET_ACCESS_KEY'],
    region_name='auto',
    config=Config(signature_version='s3v4'),
)
key = os.environ['UP_KEY']
ct = os.environ.get('UP_CT', 'application/octet-stream')
with open('/upload-src', 'rb') as f:
    data = f.read()
s3.put_object(
    Bucket=os.environ['R2_BUCKET'], Key=key, Body=data, ContentType=ct,
    CacheControl='public, max-age=31536000, immutable',
)
print('OK', key, len(data))
