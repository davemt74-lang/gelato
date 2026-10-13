# Vision Lab — POS → KDS → Glasses testing

Vision Lab has two launch options:
- **Open Glasses Simulator:** opens the existing \`glasses-simulator.php\` page.
- **POS → KDS → Glasses Test:** opens \`glasses-pos-kds-test.php\`, a three-pane workspace embedding the actual POS, KDS and Web Glasses Simulator. **Focus** expands one pane, and the responsive view switches panels on tablets and phones. **Full page** opens an individual module if its embedded UI needs more space.

## Mock versus Live Gelato

**Mock mode** starts with a deterministic Club Sandwich + Fries ticket and simulated ingredient recognition. It never pulls orders from POS and never writes to KDS or the AR build ledger.

**Live Gelato** consumes canonical station work through the existing glasses simulator API, subject to the user's glasses and location permissions. It automatically refreshes the station work while visible. An incoming POS order is visible only after ordinary KDS routing places its items on the selected device's station.

## Test procedure

1. Open Vision Lab → **POS → KDS → Glasses Test**.
2. Confirm that you are signed in and have \`glasses.view\`; interactive POS and KDS panes separately require \`pos.use\` and \`kds.view\`. Missing permissions are displayed in the respective panes, not bypassed.
3. In the **POS** pane, choose the correct restaurant location and create a **test** ticket.
4. In **KDS**, confirm the order is routed to the intended kitchen station.
5. In **Glasses Simulator**, select **Live Gelato** (the default is Mock), select an existing active glasses device associated with that station, and confirm the ticket appears in Active Orders.
6. Focus the glasses pane, or use its Full page link, to inspect the item, special instructions, build steps, expected ingredients, and validation state. Start a live build only if your testing procedure explicitly allows a state-changing operation.

**Safety:** The test workspace is a presentation tool. It does not create tickets, mutate POS/KDS records, or invoke build actions itself. The embedded real applications keep their own permissions, CSRF requirements and normal write controls. Live simulator actions can write through existing governed APIs when the operator has \`glasses.manage\` and \`kds.update\`.

**Hardware:** This browser view models the glasses HUD. It does not prove the physical AIR3 SDK or camera inference will run on actual hardware.
