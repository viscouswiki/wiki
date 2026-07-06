#!/usr/bin/env python3
"""Upload a backup file to a private Hugging Face dataset repo, then prune old ones.

Reads config from the environment:
  HF_TOKEN     write-scoped Hugging Face access token
  HF_REPO      dataset repo id, e.g. "yourname/viscous-wiki-backups"
  BACKUP_KEEP  how many backups to keep under backups/ (default 14)

Usage: hf_upload.py <local_file> <path_in_repo>
"""
import os
import sys
from huggingface_hub import HfApi


def main():
    if len(sys.argv) != 3:
        print("usage: hf_upload.py <local_file> <path_in_repo>", file=sys.stderr)
        sys.exit(2)
    local, path_in_repo = sys.argv[1], sys.argv[2]

    token = os.environ.get("HF_TOKEN")
    repo = os.environ.get("HF_REPO")
    keep = int(os.environ.get("BACKUP_KEEP") or "14")
    if not token or not repo:
        print("HF_TOKEN and HF_REPO must be set", file=sys.stderr)
        sys.exit(2)

    api = HfApi(token=token)
    # Make sure the (private) dataset repo exists.
    api.create_repo(repo_id=repo, repo_type="dataset", private=True, exist_ok=True)

    api.upload_file(
        path_or_fileobj=local,
        path_in_repo=path_in_repo,
        repo_id=repo,
        repo_type="dataset",
        commit_message=f"backup {os.path.basename(path_in_repo)}",
    )
    print(f"uploaded {path_in_repo}")

    # Retention: keep only the newest N backups (names sort chronologically).
    if keep > 0:
        backups = sorted(
            f for f in api.list_repo_files(repo_id=repo, repo_type="dataset")
            if f.startswith("backups/")
        )
        for stale in backups[:-keep] if len(backups) > keep else []:
            api.delete_file(
                path_in_repo=stale, repo_id=repo, repo_type="dataset",
                commit_message=f"prune {os.path.basename(stale)}",
            )
            print(f"pruned {stale}")


if __name__ == "__main__":
    main()
