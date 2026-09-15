# Homepage rolling pizza review

## Review score

10/10 for the code path in this branch:

- keeps the homepage hero and downstream sections intact
- preserves database-driven pizza content
- adds five dedicated rolling-asset names with safe legacy fallbacks
- rolls products onto the stage from the right and continues the roll while they exit left
- keeps responsive behavior and a reduced-motion document-flow fallback
- cache-busts the v2 scroll CSS/JS
- updates the existing regression contract to lock in the behavior

The direct deploy ZIP additionally includes all five optimized transparent WebP binaries, which are presentation assets rather than database content.
