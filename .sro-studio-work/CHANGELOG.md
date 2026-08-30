# Changelog

## [0.6.0] - 2026-08-29

### Changed

- Replaced the multi-studio customer interface with a focused two-page CASY tool: Player Control and Item Finder.
- Added one shared CharID or exact-name target for silk, level, gold, STR/INT, skill points, position teleport, town return, self teleport, player notice, and disconnect.
- Added automatic self-teleport refresh after direct level, STR, INT, and skill-point changes in `_Char`.
- Added server notices, unique spawning near a player or at coordinates, single-player Item Chest delivery, and online-player Item Chest broadcasts through verified KMTGuard procedures.
- Rebuilt Item Finder with instant prefix search, DDJ thumbnails when indexed, Item ID, CodeName copy, and one-click return to the reward action.
- Removed the sidebar, dashboard workflow, generic studios, raw tables, and unrelated tools from customer navigation.
- Added dedicated responsive layouts for desktop, tablet, and mobile without horizontal workflows or hidden desktop-only forms.

### Verified

- Verified all 14 requested actions, nine required `_Char` fields, 16 installed KMTGuard procedures, item/monster prefix lookup, full Blade rendering, and all 30 Laravel tests without executing a live write command.

### Customer action

- Refresh CASY with `Ctrl+F5`. No Filter, Client DLL, media, or KMTGuard database update is required.

## [0.5.0] - 2026-08-28

### Changed

- Rebuilt the complete CASY chrome with readable typography, calm light surfaces, grouped sidebar navigation, a working tool search, and consistent responsive spacing.
- Replaced multi-card action pages with a single selected-task workspace so operators see only the form they are using.
- Rebuilt Live Control as a three-step action, player, and details flow for the six operator tasks verified in the connected KMTGuard database.
- Added automatic CharID and player-name procedure selection for targeted notices and disconnects while keeping procedure and queue details hidden.
- Refreshed login, profile, dashboard, settings, item, NPC, area, audit, and maintenance presentation and removed raw table links from daily Area operations.

### Verified

- Verified all 26 Laravel tests, 30 automation smoke checks, and the nine installed KMTGuard live procedures without executing any live write command.

### Customer action

- Refresh CASY with `Ctrl+F5` to load the rebuilt frontend assets. No Filter, Client DLL, media, or KMTGuard database package replacement is included.

## [0.4.0] - 2026-08-28

### Added

- Added 30 guarded one-click tools mapped to 51 entries in the supplied 95-task SQL library after duplicate tasks were merged.
- Added working preview adapters for accounts, characters, inventory, silk, monsters, spawns, drops, fortress, item stack, Gacha ratios, job EXP thresholds, and SOX probabilities.

### Changed

- Removed database tables from daily task studios and kept them under Advanced only.
- Removed unfinished data-only modules from normal navigation until their action workflow is complete.
- Simplified task pages to a compact choose, fill, preview, and execute flow.
- Fixed SQL Server integer binding for paginated reads so valid records no longer appear as empty results.

### Customer action

- Refresh CASY and load the latest frontend assets. No Filter, Client DLL, media, or KMTGuard database package replacement is included.

## [0.3.1] - 2026-08-28

### Improved

- Matched the workspace chrome to the CASY reference panel direction with a compact utility header, connection status, execution-mode indicator, pale navigation chrome, and warm action accent.
- Replaced remaining legacy text glyphs with accessible SVG controls across dashboard, item, NPC, live, settings, icon, table, and maintenance views.
- Added local page search with the `Ctrl/Cmd + K` shortcut for faster navigation.
- Fixed non-identity ID allocation when cloning linked item rows and full-world teleport/spawn dependencies.
- Live procedure discovery now includes the reviewed `Teleport_*` procedure family while still enforcing the explicit KMTGuard allowlist.

### Customer action

- Refresh the CASY web application and rebuild frontend assets. No KMTGuard Filter, Client DLL, or SQL package replacement is included.

## [0.3.0] - 2026-08-28

### Added

- Added the CASY modern light workspace with responsive grouped navigation, inline SVG brand assets, keyboard-friendly controls, and dedicated Maintenance Vault access.
- Added a single-owner security foundation with inactive-account enforcement, disabled public registration and password reset in production, secure session defaults, security headers, and HTTPS redirect configuration.
- Added a capability registry and operation journal with risk levels, operation IDs, redacted snapshots, and auditable live mutations.
- Added DDJ scan reconciliation so removed files are removed from the indexed icon library after a completed scan.
- Added Quick and guarded Full Area Clone modes. Full mode remaps verified teleports and Hive/Nest dependencies and stops on unsupported event/trigger relationships.
- Added pagination, composite-key row inspection, typed item fields, and a reviewed write allowlist to Advanced Table Studio.
- Added filtered Audit Log export and a password/backup-proof dry-run flow in Maintenance Vault; no emergency reset executes without a reviewed adapter.

### Improved

- Reworked Items, NPC Shops, Live Control, Areas, Icons, Audit Logs, and generic studios around consistent English-only light surfaces and SVG iconography.
- Live Control now exposes only player level, silk, teleport, targeted notice, and disconnect procedures discovered from KMTGuard; runtime queue details remain hidden.
- Added SQL Server profile defaults from local environment settings and a visible warning when the daily connection still uses the `sa` administrator.

### Customer action

- Refresh the CASY web application and run the Laravel migrations. Configure a dedicated least-privilege SQL login and verify the DDJ icon root. No KMTGuard Filter or Client DLL replacement is included in this web release.

## [0.2.0] - 2026-08-28

### Added

- Added a schema-aware World & Region Studio for creating instance worlds from existing terrain regions.
- Added safe Clone Area workflow with source-world templates, region validation, conflict checks, transaction rollback, optional start-position copying, SQL preview, and audit logging.
- Added live world and terrain mapping discovery for the configured shard database.
- Added first-run SQL profile defaults for the configured SRO databases, with the password kept in the local environment and encrypted when saved.
- Added live world configuration editing and idempotent terrain mapping add/remove actions.
- Added template-based Item Studio creation, preserving linked `_RefObjItem` values while allocating a safe new common ID.
- Added NPC Shop Studio actions for live price updates, removing goods from a tab, creating a tab group/tab mapping, and returning to the exact NPC context after item edits.
- Replaced placeholder module pages with live schema-aware explorers for Characters, Monsters, Drops, Teleports, Quests, Events, Economy, and Logs; each links into the safe Data Studio editor.
- Added schema-aware row creation to Universal Table Studio, so discovered tables can be populated without writing SQL while identity, computed, and binary columns remain protected.
- Added a focused Live Control composer for the installed KMTGuard actions: gold, all silk balances, buffs, player teleports, town returns, notices, and disconnects. Player actions accept either CharID or player name and are executed only through the official KMTGuard stored procedures.

### Improved

- Refreshed the Clone Area workspace into a focused responsive builder with a clear review summary and selected-world management panel.
- Added a dedicated item creation flow, catalog template picker, and icon preview on the item editor.
- Isolated automated tests from the configured production SQLite metadata database.
- Removed the technical runtime/queue dashboard from the Live Control page so operators work from a single clear action form; CASY audit history remains available from Logs & Restore.
- Improved Live Control feedback with connection-aware status, an empty-procedure state, and a clear disabled level action when the connected Filter does not provide level control.

### Customer action

- No SQL update is required. Refresh the CASY web application; the SQL Server connection remains configured in Server Settings.
