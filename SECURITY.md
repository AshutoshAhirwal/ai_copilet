# Security Architecture & Safeguards - Drupal AI Copilot

## 1. Access Control & Permission Gating
- The assistant UI is strictly hidden unless **Developer Mode** is enabled AND the user possesses the `use ai copilot` permission.
- The `use ai copilot` permission is marked `restrict access: true` and is **never** granted to anonymous or authenticated roles by default.

## 2. Human-in-the-Loop Approval & Rollback
- No site mutation (config import, patch application, file modification) is ever executed automatically.
- Every action requires explicit developer approval in the UI.
- All actions record a pre-mutation snapshot (`config_before.json`) and file backup in `private://ai_copilot/snapshots/{audit_id}/`.
- One-click rollback via `SnapshotManagerService::revert()` uninstalls newly installed modules, restores configuration, and restores original files.

## 3. Command Injection Prevention
- All process calls (`git apply --check`, `patch --dry-run`, `php -l`, `phpcs`, `composer`) use `Symfony\Component\Process\Process` passing arguments as a **type-safe indexed array**.
- Shell string concatenation (`system()`, `exec()`, `shell_exec()`) is strictly prohibited.

## 4. Prompt Injection Mitigation
- Untrusted external metadata ingested from drupal.org (module titles, descriptions, READMEs) is wrapped in XML tags (`<untrusted_external_contrib_data>`).
- System prompts enforce strict security rules instructing the model never to interpret text inside untrusted blocks as commands or prompt overrides.

## 5. Data Privacy & Payload Inspection
- Context sanitization defaults to `structure_only`, which strips all node content, field values, user records, passwords, and sensitive keys.
- Developers can click `[View Payload]` in the chat drawer to inspect exact outbound JSON payload data prior to sending requests to external LLM providers.

## 6. Concurrency Protection
- Exclusive locks are managed via Drupal's Lock API (`\Drupal::lock()`) with key `ai_copilot_mutation_lock`.
- Concurrent mutation requests return HTTP 409 Conflict.

## 7. Production Environment Hard-Disabling
- `EnvironmentDetectorService` checks `Settings::get('environment') === 'production'`.
- On production environments, Developer Mode defaults to disabled, and mutations are hard-disabled unless explicit settings overrides and double-confirmation admin modal authentication are provided.
