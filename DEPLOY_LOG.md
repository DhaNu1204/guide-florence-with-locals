# Deploy Log
| Date/Time | Commit | Branch | Summary | Actions |
|-----------|--------|--------|---------|---------|
| 2026-06-13 20:43 UTC | 0b1a37f | master | CLI-cron guard for bokun_sync + deploy skill, log, docs | FAIL — "Deploy Backend" step: scp got empty SSH_PORT (SSH_HOST/SSH_USERNAME/SSH_PORT secrets unset in repo). Build passed; git push to master OK. Prod still serving prior code (site 200, tours API 401=auth-enforced). |
| 2026-06-13 22:52 | 0b1a37f (deploy.sh) | master | Task 1 live-refresh + bokun_sync CLI guard shipped via deploy.sh | manual deploy |
| 2026-06-15 13:03 UTC | 7dcd037 (deploy.sh) | master | Guide Reports — read-only per-guide monthly tour verification (new guide-tour-report.php endpoint + GuideReports page, PDF/CSV export) | manual deploy. Backup withlocals_backup_20260615_130253. report endpoint 401 (auth-enforced), frontend 200, live bundle index-BsGhMmGE.js. |
| 2026-06-15 13:22 UTC | 6c01e84 (deploy.sh) | master | Guide Reports category breakdown (Combo/Uffizi/Pitti/Accademia/Other) in header chips, Type column, PDF + CSV exports | manual deploy. Backup withlocals_backup_20260615_132157. report endpoint 401 (auth-enforced), frontend 200, live bundle index-CPjo5Fg4.js. |
| 2026-06-15 13:58 UTC | fcb8ab5 (deploy.sh) | master | Guide Reports all-guides overview: per-guide category breakdown columns (Guide \| Combo \| Uffizi \| Pitti \| Accademia \| Other? \| Total), PDF + CSV export updated | manual deploy. Backup withlocals_backup_20260615_135816. report endpoint 401 (auth-enforced), frontend 200, live bundle index-BxHXbhGg.js. |
