# FM-B8D17B — Universal Read & Navigation (navigation stage)

This section gives Food Monster an actual model-selected `navigate_to_gelato_page`
native tool. Its page-ID enum is derived from the current account's existing
Gelato staff navigation. The server checks the destination's page permission
again immediately before providing its navigation receipt. It never accepts
arbitrary URLs or executes PHP, SQL or page mutations.

The complete list of permitted destinations is account dependent. Employee
views cannot reveal restricted screens such as user management or CRM. An
explicit typed chat request can open the verified internal page after chat
history and the action receipt have been saved. Voice requests present an
Open-page action card instead, avoiding a redirect during spoken output.
Navigation cards remain clickable in full Agent Canvas and the popup canvas.

This section intentionally does **not** claim all Gelato data domains have
native read adapters. Previously registered scheduling, menu, timeclock,
training and Vision Lab tools remain the verified record-read paths; other
domains retain permission-scoped specialist handoff contracts. Universal
record query coverage and live site acceptance need their own certification.
