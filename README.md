ClueBot NG - Bot
=================

The bot parses the Wikipedia change feed, applies some pre-filtering,
looks up related data & submits it to the core for scoring.

It can be considered the primary business logic & all Wikipedia API interactions are contained within.

## Runtime Configuration

There are 4 hard runtime dependencies;

1. Local MySQL database (for storing reverted vandalism)
2. Tools MySQL database (to lookup additional information)
3. ClueBot NG Core (to score edits)
4. Wikipedia password (to authenticate)

All details are contained within `cluebot-ng.config.php`, which should be considered sensitive.
