#!/usr/bin/env python3
"""
deploy.py — beautyhauss ERP SFTP deploy to Hostinger
Usage: python deploy.py [file1.php file2.php ...]
       python deploy.py          (deploys all tracked changed files)
"""
import sys, os, paramiko
from pathlib import Path

HOST     = '46.202.182.50'
PORT     = 65002
USER     = 'u253288084'
REMOTE   = '/home/u253288084/domains/erptiendatopmx.com/public_html/bh-erp'
LOCAL    = Path(__file__).parent

# Read password from .env.deploy (never committed)
env_file = LOCAL / '.env.deploy'
if not env_file.exists():
    print("ERROR: .env.deploy not found. Create it with: SSH_PASS=yourpassword")
    sys.exit(1)

password = None
for line in env_file.read_text().splitlines():
    if line.startswith('SSH_PASS='):
        password = line.split('=', 1)[1].strip()
if not password:
    print("ERROR: SSH_PASS not found in .env.deploy")
    sys.exit(1)

# Files to deploy: CLI args or git diff against last commit
if len(sys.argv) > 1:
    files = sys.argv[1:]
else:
    import subprocess
    result = subprocess.run(
        ['git', 'diff', '--name-only', 'HEAD~1', 'HEAD'],
        capture_output=True, text=True, cwd=LOCAL
    )
    files = [f.strip() for f in result.stdout.splitlines() if f.strip()]
    if not files:
        print("No changed files detected. Pass files explicitly.")
        sys.exit(0)

print(f"Deploying {len(files)} file(s) to {REMOTE}")

transport = paramiko.Transport((HOST, PORT))
transport.connect(username=USER, password=password)
sftp = paramiko.SFTPClient.from_transport(transport)

for f in files:
    local_path  = LOCAL / f
    remote_path = f"{REMOTE}/{f}"
    if not local_path.exists():
        print(f"  SKIP (not found locally): {f}")
        continue
    # Ensure remote directory exists
    remote_dir = remote_path.rsplit('/', 1)[0]
    try:
        sftp.makedirs(remote_dir)
    except Exception:
        pass
    sftp.put(str(local_path), remote_path)
    print(f"  OK: {f}")

sftp.close()
transport.close()
print("DEPLOY COMPLETE")
