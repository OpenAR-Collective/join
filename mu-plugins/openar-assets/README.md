# Badge assets

The art and the font that openar-badges.php draws with. Installed on the server
at `wp-content/mu-plugins/openar-assets/` by `server/install-asset.php`; see
that script for the staging steps.

| File | What it is |
|---|---|
| `openar-member-badge-1024.png` | The blank member badge. The member number is drawn over the center hexagon at send time. |
| `openar-mission-supporter-badge-512.png` | The Mission Supporter badge, attached as-is to the listing email. |
| `Barlow-Bold.ttf` | The face the member number is drawn in, matching the badge's own type. |
| `OFL.txt` | Barlow's license, the SIL Open Font License 1.1. |

The badges are pre-rendered from the master SVGs, which live with the
Foundation's brand art rather than here: the badge is a designed object, and
only the number varies per member. If the badge design changes, re-render the
PNGs from the masters and reinstall; nothing in the code carries the design.

Barlow is redistributed here under the SIL OFL. The badge art itself is a
Foundation brand asset and is not open licensed; its use is governed by the
[Trademark Policy](https://openarcollective.org/policies/trademark/).
