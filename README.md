# Simple Meet Timer

Phone-based swim meet timing with a live scoreboard. Plain HTML/JS + one PHP file, no database.

- **Starter** – types a heat label, picks the distance, taps START. Can recall (false start) or save results and set up the next heat.
- **Timer** – picks a lane, taps SPLIT at each wall; the button turns into FINISH on the last lap. Up to 3 timers per lane.
- **Scoreboard** – live clock, lap progress, splits, official times, places, and a history of completed heats.

Official time (per split and final) follows USA Swimming rules: 3 times → middle, 2 → average (truncated to hundredths), 1 → that time.
Phones sync to the server clock, and each tap is time-stamped on the phone at the moment of the tap, so network lag doesn't affect results.
The starter and timer screens show sync accuracy in the header (green ≤ ±0.025s, amber ≤ ±0.075s, red worse); tap it to re-sync.

## Deploy (Apache + PHP on a DigitalOcean droplet)

1. Copy `index.html`, `api.php`, the `vendor/` folder, and the `data/` folder (with its `.htaccess`) to a folder under your web root, e.g. `/var/www/html/meet/`.
2. Make `data/` writable by Apache: `sudo chown www-data:www-data data && sudo chmod 775 data`
3. Make sure Apache honors `data/.htaccess` (`AllowOverride All`), or add
   `<Directory /var/www/html/meet/data> Require all denied </Directory>` to the site config.
4. Open `https://your-domain/meet/`, create a meet, and share the 4-letter code or link.

Use HTTPS if you can: it lets phones keep their screens awake during a heat (Wake Lock API).

## Local testing

```
php -S 0.0.0.0:8000
```

Then open `http://<your-computer-ip>:8000` on phones on the same Wi-Fi.
