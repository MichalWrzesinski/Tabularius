#!/bin/sh
# Repository settings for MichalWrzesinski/Tabularius (project-setup standard). Changes repository settings,
# so only the owner runs it. Idempotent. Requires: gh authenticated as an admin of the repository.
set -eu
REPO=MichalWrzesinski/Tabularius

# Merge commits only; delete merged branches.
gh api -X PATCH "repos/$REPO" \
  -F allow_merge_commit=true -F allow_squash_merge=false -F allow_rebase_merge=false \
  -F delete_branch_on_merge=true >/dev/null

# Vulnerability alerts and automated security fixes (available on private repositories).
gh api -X PUT "repos/$REPO/vulnerability-alerts" >/dev/null
gh api -X PUT "repos/$REPO/automated-security-fixes" >/dev/null || true

# Branch protection on main: PR required, required check `check`, no force pushes or deletion.
# On a private repository this needs GitHub Pro/Team; the command reports the plan limitation if unavailable.
gh api -X PUT "repos/$REPO/branches/main/protection" --input - <<'JSON' || echo "Branch protection not applied (plan limitation?). See docs/technical-profile.md."
{
  "required_status_checks": { "strict": true, "checks": [ { "context": "check" } ] },
  "enforce_admins": false,
  "required_pull_request_reviews": { "required_approving_review_count": 0 },
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false
}
JSON
echo "Done. Verify: gh api repos/$REPO/branches/main/protection"
