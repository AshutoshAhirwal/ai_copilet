# Changelog

All notable changes to this project are documented in this file.

## Unreleased

- **Renamed project from `ai_copilot` to Contribot (machine name: `contribot`).**
  Every module-prefixed file, the PHP namespace, config object name, service
  IDs, route names/paths, permission machine names, database table names,
  and CSS/JS identifiers were renamed to match. See past entries below for
  history recorded under the old name — they are left as originally written.

  **Upgrade note:** this is not a drop-in update for anyone who had
  `ai_copilot` installed — Drupal sees `contribot` as a completely different
  module. Uninstall `ai_copilot` (or manually migrate its config and the
  `ai_copilot_contrib_index` / `ai_copilot_audit_log` database tables),
  install `contribot` fresh, then reconfigure Settings and permissions as
  described in the README.

## 1.0.0-alpha1 — first public release

First release prepared for public, self-hosted BYOK distribution.
Marked `alpha` rather than a stable `1.0.0` because the security fixes below
were only just applied and have not yet had real-world exposure.

### Security fixes

- LLM provider calls no longer silently fall back to a fabricated
  ("demo mode") response when a real API call fails. Demo mode is now used
  *only* when no provider/key is configured at all, and is explicitly
  labeled as such in the chat UI.
- `config_only` mutations are now restricted to an explicit allowlist
  (`node.type.*`, `taxonomy.vocabulary.*`, `user.role.*`) and validated
  against Drupal's typed config schema before saving, instead of trusting a
  client- or LLM-supplied config object name.
- `SnapshotManagerService::createSnapshot()` no longer caps snapshots to the
  first 50 config objects — the full config set is captured so revert can
  restore everything a mutation may have touched.
- `custom_code` mutations are re-validated (`php -l` + `phpcs`) on the exact
  bytes about to be written, immediately before writing, rather than trusting
  validation performed at an earlier preview step.
- The LLM provider is now explicitly selected in settings instead of being
  guessed from API key shape, removing a silent fallback to OpenAI for any
  unrecognized key format.
- The Gemini API key is now sent via the `x-goog-api-key` header instead of
  a `?key=` query parameter, so it can no longer appear in request-URL logs
  or exception messages.
- Model names are now read from AI Copilot settings instead of being
  hardcoded, with per-provider defaults used when left blank.
- `revertMutation()` now acquires the same mutation lock as `handleApply()`,
  so a revert can no longer race a concurrent apply or another revert.

### Other changes

- Removed unused `mcp_tools`, `mcp_server`, and `mcp_client` dependencies
  (declared but never referenced in code) and the unnecessary `drupal:config`
  dependency (core's config API doesn't require that module).
- Added `LICENSE.txt` (GPL-2.0-or-later), `PRIVACY.md`, and GitHub Actions CI
  (phpcs + phpunit across the supported PHP/Drupal core matrix).
- Expanded `SECURITY.md` with an explicit capability → permission →
  safeguard breakdown.

### Upgrade note for existing installations

Because the LLM provider is no longer guessed from key shape, existing
sites that already had a `provider_key_id` configured must open
**AI Copilot Settings** and explicitly select the matching **LLM Provider**
once after upgrading. Until that's set, the assistant will fall back to
(clearly labeled) Demo Mode rather than guessing wrong.
