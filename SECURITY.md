# Security Architecture & Safeguards - Contribot

This module executes shell commands (`git apply --check`, `php -l`, `phpcs`,
`composer`) and can write to `composer.json` and the filesystem. This page
exists specifically so reviewers and cautious adopters can see exactly what
it can do, what gates each capability, and how a mutation is undone — read
this before installing on anything beyond a local/staging environment.

## Capability → Permission → Safeguard

| Capability | Gated by | Safeguard |
| --- | --- | --- |
| View the chat panel / ask questions | `use contribot` permission **and** Developer Mode enabled | Read-only; makes no site changes by itself. |
| Apply a `config_only` change (content type, vocabulary, taxonomy, role) | `use contribot` + Security Preset ≥ *Config-Only* | Config object name is derived server-side from an explicit allowlist (`node.type.*`, `taxonomy.vocabulary.*`, `user.role.*`) — never taken from client- or LLM-supplied strings — and validated against Drupal's config schema (`config.typed`) before saving. Rejected with no write if either check fails. |
| Apply a `contrib_patch` (install a module + composer patch) | `use contribot` + Security Preset = *Full Mutation* | Patch is dry-run validated (`git apply --check`) against fetched upstream source before being registered in `composer.json` and queued. |
| Apply `custom_code` (writes a generated `.module` file) | `use contribot` + Security Preset = *Full Mutation* | The exact code bytes about to be written are re-validated (`php -l` + `phpcs --standard=Drupal,DrupalPractice`) immediately before the write — not just at an earlier preview step the client could bypass. Rejected with no write if validation fails. |
| Revert any of the above | `use contribot` | Restores the full pre-mutation config snapshot and any backed-up files; uninstalls newly-installed modules; deletes newly-created content types/vocabularies/roles. Runs under the same mutation lock as apply, so a revert can't race a concurrent apply. |
| Change Contribot settings (security preset, provider, keys) | `administer contribot` permission | Separate permission from `use contribot` — a user who can chat cannot necessarily change what the assistant is allowed to do. |

Both permissions are marked `restrict access: true` and are **never** granted
to anonymous or authenticated roles by default.

## 1. Access Control & Permission Gating
- The assistant UI is strictly hidden unless **Developer Mode** is enabled AND the user possesses the `use contribot` permission.
- Settings (security preset, provider selection, API key reference) require the separate `administer contribot` permission.

## 2. Human-in-the-Loop Approval & Rollback
- No site mutation (config import, patch application, file modification) is ever executed automatically.
- Every action requires explicit developer approval in the UI.
- All actions record a pre-mutation snapshot (`config_before.json`, capturing **every** config object on the site, not a partial slice) and file backup in `private://contribot/snapshots/{audit_id}/`.
- One-click rollback via `SnapshotManagerService::revertMutation()` uninstalls newly installed modules, restores configuration, and restores original files. Both apply and revert acquire the same exclusive mutation lock, so they cannot race each other.

## 3. Config Mutation Allowlist & Schema Validation
- `config_only` mutations are restricted to an explicit allowlist of config object prefixes (`node.type.*`, `taxonomy.vocabulary.*`, `user.role.*`). The target config name is always derived by the server from the parsed YAML's recognized shape (e.g. presence of a `vid` key ⇒ taxonomy vocabulary) — it is never taken from a `name` field supplied by the client or the LLM's output.
- Before saving, the parsed configuration is validated against Drupal's own typed config schema (`\Drupal::service('config.typed')`, via `SchemaCheckTrait`). Anything that doesn't validate is rejected with no write performed.

## 4. Command Injection Prevention
- All process calls (`git apply --check`, `patch --dry-run`, `php -l`, `phpcs`, `composer`) use `Symfony\Component\Process\Process` passing arguments as a **type-safe indexed array**.
- Shell string concatenation (`system()`, `exec()`, `shell_exec()`) is strictly prohibited.

## 5. Prompt Injection Mitigation
- Untrusted external metadata ingested from drupal.org (module titles, descriptions, READMEs) is wrapped in XML tags (`<untrusted_external_contrib_data>`).
- System prompts enforce strict security rules instructing the model never to interpret text inside untrusted blocks as commands or prompt overrides.

## 6. Data Privacy & Payload Inspection
- Context sanitization defaults to `structure_only`, which strips all node content, field values, user records, passwords, and sensitive keys.
- Developers can click `[View Payload]` in the chat drawer to inspect exact outbound JSON payload data prior to sending requests to external LLM providers.
- See [PRIVACY.md](PRIVACY.md) for a full description of exactly what is and isn't sent to the configured LLM provider.

## 7. Concurrency Protection
- Exclusive locks are managed via Drupal's Lock API (`\Drupal::lock()`) with key `contribot_mutation_lock`.
- Concurrent mutation requests (including reverts) return HTTP 409 Conflict.

## 8. Production Environment Hard-Disabling
- `EnvironmentDetectorService` checks `Settings::get('environment') === 'production'`.
- On production environments, Developer Mode defaults to disabled, and mutations are hard-disabled unless explicit settings overrides are provided.

## 9. LLM Provider Failure Behavior
- A failed call to the configured LLM provider (network error, rate limit, invalid key) is surfaced to the user as a clear error. It is **never** silently replaced with a fabricated response.
- The only case where a template response is shown is when *no* provider/key is configured at all ("Demo Mode"), and that is explicitly labeled as such in the UI — it is never presented as a real model response.

## Reporting a vulnerability

If you believe you've found a security issue in this module, please report it
through drupal.org's [security issue queue process](https://www.drupal.org/drupal-security-team/general-information/how-report-security-vulnerability)
rather than a public issue, so it can be fixed before public disclosure.
