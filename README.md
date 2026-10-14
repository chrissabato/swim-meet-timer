# Simple Meet Timer

Phone-based swim meet timing with a live scoreboard. Plain HTML/JS + one PHP file, no database.

- **Starter** – no typing: taps the stroke, distance, and (for Boys/Girls or Men/Women meets) gender, then START. Event and heat numbers fill in automatically and have +/− buttons. During a heat, **Next heat** saves results and sets up the next heat of the same event; **New event** saves and moves to the next event number. Can also recall (false start).
- **Timer** – picks a lane, taps SPLIT at each wall; the button turns into FINISH on the last lap. Up to 3 timers per lane.
- **Scoreboard** – full-screen 16:9 display for a TV: live clock, lap, latest split, official times and places. After a heat is saved it keeps the final results up (with the next heat shown at the bottom) until the next START. Press F, double-click, or use the button that appears on mouse move to go full screen.
- **Results** – the current heat plus every completed heat, with places, times, and all splits.

Official time (per split and final) follows USA Swimming rules: 3 times → middle, 2 → average (truncated to hundredths), 1 → that time.
Since these are hand times, everything is displayed truncated to tenths, and places go by those tenths (equal times tie).
Phones sync to the server clock, and each tap is time-stamped on the phone at the moment of the tap, so network lag doesn't affect results.
The starter and timer screens show sync accuracy in the header (green ≤ ±0.025s, amber ≤ ±0.075s, red worse); tap it to re-sync.

## Deploy (Apache + PHP on a DigitalOcean droplet)

1. Copy `index.html` (landing page), `app.html` (the app), `api.php`, the `img/` and `vendor/` folders, and the `data/` folder (with its `.htaccess`) to a folder under your web root, e.g. `/var/www/html/meet/`.
2. Make `data/` writable by Apache: `sudo chown www-data:www-data data && sudo chmod 775 data`
3. Make sure Apache honors `data/.htaccess` (`AllowOverride All`), or add
   `<Directory /var/www/html/meet/data> Require all denied </Directory>` to the site config.
4. Open `https://your-domain/meet/` (landing page) and tap **Launch the timer**, or go straight to `https://your-domain/meet/app.html`. Create a meet, and share the 4-letter code or link.
5. Optional admin page (list and delete all meets): copy `config.sample.php` to `config.php` on the server and set
   `ADMIN_PASSWORD`. Then open `https://your-domain/meet/app.html#/admin` (also linked at the bottom of the home page).
   `config.php` is kept out of git; without it the admin page stays off.

Use HTTPS if you can: it lets phones keep their screens awake during a heat (Wake Lock API).

## Local testing

```
php -S 0.0.0.0:8000
```

Then open `http://<your-computer-ip>:8000/app.html` on phones on the same Wi-Fi.
