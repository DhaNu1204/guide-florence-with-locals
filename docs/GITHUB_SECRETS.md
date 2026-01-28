# GitHub Secrets Configuration

This document explains how to configure GitHub Secrets for automated deployment.

## Required Secrets

The following secrets must be configured in your GitHub repository settings:

| Secret Name | Description | Example Value |
|-------------|-------------|---------------|
| `SSH_HOST` | Hostinger server IP address | `82.25.82.111` |
| `SSH_PORT` | SSH port number | `65002` |
| `SSH_USERNAME` | SSH username | `u803853690` |
| `SSH_PRIVATE_KEY` | SSH private key (full content) | `-----BEGIN OPENSSH PRIVATE KEY-----...` |

## Optional Secrets

| Secret Name | Description | Example Value |
|-------------|-------------|---------------|
| `SLACK_WEBHOOK_URL` | Slack webhook for notifications | `https://hooks.slack.com/...` |
| `DISCORD_WEBHOOK_URL` | Discord webhook for notifications | `https://discord.com/api/webhooks/...` |

---

## Step-by-Step Setup

### 1. Generate SSH Key Pair

On your local machine, generate a new SSH key pair specifically for GitHub Actions:

```bash
# Generate a new SSH key (no passphrase for automation)
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/github_deploy_key -N ""

# This creates two files:
# ~/.ssh/github_deploy_key      (private key - for GitHub)
# ~/.ssh/github_deploy_key.pub  (public key - for server)
```

### 2. Add Public Key to Server

Add the public key to the Hostinger server's authorized_keys:

```bash
# Copy the public key content
cat ~/.ssh/github_deploy_key.pub

# SSH into the server
ssh -p 65002 u803853690@82.25.82.111

# On the server, add the key to authorized_keys
echo "ssh-ed25519 AAAA... github-actions-deploy" >> ~/.ssh/authorized_keys

# Ensure correct permissions
chmod 700 ~/.ssh
chmod 600 ~/.ssh/authorized_keys
```

### 3. Add Secrets to GitHub

1. Go to your GitHub repository
2. Click **Settings** → **Secrets and variables** → **Actions**
3. Click **New repository secret**
4. Add each secret:

#### SSH_HOST
```
82.25.82.111
```

#### SSH_PORT
```
65002
```

#### SSH_USERNAME
```
u803853690
```

#### SSH_PRIVATE_KEY
Copy the **entire content** of your private key file:
```bash
cat ~/.ssh/github_deploy_key
```

The content should look like:
```
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtz
... (multiple lines)
-----END OPENSSH PRIVATE KEY-----
```

**Important:** Include the entire key including the `-----BEGIN` and `-----END` lines.

---

## Verification

### Test SSH Connection Locally

Before running the workflow, verify the SSH connection works:

```bash
ssh -p 65002 -i ~/.ssh/github_deploy_key u803853690@82.25.82.111 "echo 'SSH connection successful'"
```

### Test GitHub Actions Workflow

1. Push a commit to the `main` branch, or
2. Go to **Actions** → **Deploy to Production** → **Run workflow**

---

## Security Best Practices

### Do's ✅

- Use SSH keys instead of passwords
- Generate a dedicated key for GitHub Actions
- Use Ed25519 keys (more secure than RSA)
- Regularly rotate SSH keys
- Limit key permissions on the server

### Don'ts ❌

- Never commit private keys to the repository
- Never share secrets in issues or pull requests
- Don't use your personal SSH key for automation
- Don't use password authentication

---

## Troubleshooting

### "Permission denied (publickey)"

1. Verify the public key is in `~/.ssh/authorized_keys` on the server
2. Check file permissions:
   ```bash
   chmod 700 ~/.ssh
   chmod 600 ~/.ssh/authorized_keys
   ```
3. Verify the private key in GitHub Secrets is complete

### "Host key verification failed"

The workflow uses `StrictHostKeyChecking=no` for the first connection. If you see this error:

1. Manually connect once to add the host key:
   ```bash
   ssh -p 65002 u803853690@82.25.82.111
   ```
2. Or add the host key to the workflow's known_hosts

### "rsync: command not found"

The workflow uses rsync for frontend deployment. If Hostinger doesn't have rsync:

1. Use scp instead (modify the workflow)
2. Or install rsync via Hostinger's package manager

### Workflow Runs But Files Don't Update

1. Check the workflow logs for errors
2. Verify file permissions on the server
3. Clear any caching (Hostinger may cache files)

---

## File Reference

| File | Purpose |
|------|---------|
| `.github/workflows/deploy.yml` | Main deployment workflow |
| `scripts/deploy.sh` | Manual deployment script |
| `docs/GITHUB_SECRETS.md` | This documentation |

---

## Quick Reference

### Run Deployment Manually

```bash
# Via GitHub CLI
gh workflow run deploy.yml

# Or via web interface
# Go to Actions → Deploy to Production → Run workflow
```

### View Deployment Status

```bash
# Via GitHub CLI
gh run list --workflow=deploy.yml

# View specific run
gh run view <run-id>
```

### Disable Automatic Deployment

To temporarily disable automatic deployment on push:

1. Go to **Settings** → **Environments** → **production**
2. Enable **Required reviewers**
3. Add yourself as a required reviewer

---

## Environment Variables

The workflow uses these environment variables (defined in the workflow file):

| Variable | Value | Description |
|----------|-------|-------------|
| `NODE_VERSION` | `18` | Node.js version for build |
| `PRODUCTION_PATH` | `/home/u803853690/domains/deetech.cc/public_html/withlocals` | Server deployment path |

---

*Last Updated: January 2026*
