# Menu Operations + Main Agent Brain

Menu Operations is part of the existing Gelato Restaurant Agent architecture. It is not a separate menu assistant.

The canonical flow is:

`Menu Manager / Food + Drink builders / media changes -> Menu Operations domain -> agent_knowledge_records -> main Agent Workspace / api/agent-brain.php`

The shared menu remains organization-wide. Location-specific operational state layers on top of that canonical menu for item and size 86/sold-out status, reason, timed auto-resume, and channel/day/time availability schedules.

The main Agent Brain can read menu summary, search, availability and recent changes. With `menu.manage`, it can also perform item/size availability changes, inline and bulk pricing, schedules, category/item ordering, bulk moves, channel distribution and lifecycle changes. These actions use the same domain functions and audit/knowledge-refresh path as Menu Manager.

Header and shell conventions for this phase are KDS -> POS -> global Add. The Add launcher uses a white background with a black plus, and the Gelato brand in both admin sidebar shells links to `workspace.php`, the default admin dashboard.

Database migration `20261010_menu_operations_agent_brain.sql` intentionally avoids `ADD COLUMN IF NOT EXISTS`. Upgrade idempotence relies on the existing UpgradeService duplicate-column recovery behavior so it remains compatible with production MySQL versions that reject that syntax.
