# CASY - SRO Database Studio

CASY is a standalone Laravel web application for visual Silkroad Online administration. It is intentionally independent from KMTGuard and does not load or modify the Filter project.

## Local URL

Laragon serves the application at:

- https://admin.play-casy.online
- http://admin.play-casy.online

The Apache vhost is C:\laragon\etc\apache2\sites-enabled\admin.play-casy.online.conf.

## First run

1. Open the domain and create the first administrator account.
2. Open Server settings.
3. Enter SQL Server host, port, login and encrypted password.
4. Save, then run Test and discover schema.
5. Add the extracted DDJ folder and run Scan DDJ folder.

The local CASY database is SQLite and stores users, encrypted server credentials, icon metadata and audit entries. The game data remains in the configured SQL Server profile. Discovery is read-only.

## Current workflows

- Schema-aware SQL Server connection and database/table capability discovery.
- Schema Map with detected databases, tables, primary keys and foreign-key edges.
- Live _RefObjCommon item catalog search when the profile is connected.
- Dynamic item record editor based on discovered columns.
- Safe item update and identity-aware clone with transaction and audit log.
- Recursive DDJ indexing and PHP/GD previews for DXT1, DXT3, DXT5 and BGRA DDS payloads.
- NPC visual workspace showing the NPC -> groups -> tabs -> item hierarchy.
- Audit log with searchable actions and generated SQL templates.
- Responsive light CASY branding, favicon and standalone web manifest.

NPC, world, characters, monsters, drops, quests and event screens are wired as schema-aware module workspaces and are ready for their table adapters. They do not write placeholder data to a game database.

## Verification

Run:

    php artisan migrate:fresh --seed --force
    php artisan view:cache
    php artisan test
    npm run build
