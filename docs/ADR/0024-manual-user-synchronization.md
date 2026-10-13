# ADR 0024: Manual User Synchronization from the User Form

## Status
Accepted

## Context
The plugin applies SAML claim mappings to GLPI users during the Just-In-Time (JIT) creation and, when `sync_on_login` is enabled, on every subsequent login (see ADR 0008). With the introduction of the optional **Regex Transform** on claim mappings, administrators can now derive fields such as `firstname` and `realname` from another claim (for example the email address).

However, this creates two operational gaps:

1. Users created **before** the mapping (or before the Regex Transform) was configured keep empty or stale fields, and there is no way to refresh them without the user logging in again.
2. The only manual synchronization available today lives inside the IdP configuration form (`ConfigForm`), which requires the administrator to know and type the target login and a sample value.

GLPI core provides a "Synchronization" tab on the User form, but the plugin guidelines forbid modifying or manipulating GLPI core classes and features ("core as-is" / "separation of duties").

## Decision
1. Introduce a plugin-side tab on the GLPI `User` form (`GlpiPlugin\Samlsso\UserSync`) registered through the standard `Plugin::registerClass(..., ['addtabon' => ['User']])` mechanism. GLPI core is not touched.
2. The tab resolves the applicable Identity Provider for the user by matching the user's email domain against a configured IdP (`Config::getConfigIdByEmailDomain()`), falling back to the single active configuration (`Config::getIsOnlyOneConfig()`).
3. It previews the fields that would be derived from the user's primary email through the configured Regex Transforms, and applies them on demand.
4. The synchronization is executed through a dedicated CSRF-protected route (`front/usersync`) that verifies the `user` UPDATE right and the target user's `canUpdate()` permission before writing.
5. The stateless transformation logic is exposed as `ClaimMapEntity::transform()` so it can be reused and unit-tested without a GLPI runtime.

## Consequences
- **Positive**:
  - Administrators can refresh an existing user's mapped fields directly from the user form, without a new login.
  - Reuses the existing claim mapping configuration and the Regex Transform; no duplicated logic.
  - Fully plugin-side: GLPI core remains untouched and the extension uses the documented `addtabon` API.
  - The derived preview gives the administrator a clear before/after view before applying.
- **Negative**:
  - Only transform-based fields (fields whose mapping defines a `regex`) can be derived from the available source (the email). Fields that depend on other claims require a real SAML response and therefore still rely on login/JIT.
  - Adds a plugin tab to the User form, which is visible to administrators with access to the user.
