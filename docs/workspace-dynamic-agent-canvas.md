# Workspace Dynamic Agent Canvas

The Restaurant Command Center keeps the current admin page mounted while the global Gelato Agent opens as an in-place canvas layer. The canvas reuses the existing persistent Agent conversation, routing, permissions, action receipts, voice support, and Restaurant Brain integrations.

The workspace command center reads canonical server data for new resume submissions, recent employee activity, and scheduling coverage. Resume and employee-activity questions are routed through the main Agent Workspace into the workforce skill; schedule-specific questions continue through the canonical Scheduling Agent.

The legacy footer Agent Canvas launcher is removed by the dynamic canvas layer. Sending or submitting from the global Agent bar opens the canvas without navigation or page reload, and closing it restores the underlying page and focus.
