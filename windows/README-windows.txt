BareBits — Windows desktop edition
========================================

Run BareBits as a local point-of-sale on any Windows 10/11 PC or
tablet. No web server, no hosting account, no command line.

Everything runs only on THIS computer (127.0.0.1). Nothing is reachable
from the network or the internet; payments work because the app makes
outbound connections (to mints, lightning addresses, relays) only.


Getting started
---------------
1. Extract this zip somewhere you have write access — your Desktop,
   Documents, or C:\BareBits are all fine.
   Do NOT use C:\Program Files (the app needs to write its database).
2. Double-click BareBits.bat.
   - First run may install the Microsoft Visual C++ runtime (one UAC
     prompt) and Windows may show a SmartScreen warning for an unsigned
     app: choose "More info" -> "Run anyway".
3. Your browser opens the setup screen. Create your admin account and
   store — see the main project README for configuring payout options.

A console window titled "BareBits" stays open while the app runs.
Close that window to stop the app. A second, minimized window
("BareBits background tasks") keeps invoice polling and webhook
delivery ticking; it closes itself shortly after you stop the server.


Day-to-day use
--------------
- Start: double-click BareBits.bat (double-clicking while it is
  already running just reopens the browser).
- Stop: close the BareBits console window.
- The POS lives at http://127.0.0.1:8737/ — bookmark it.


Your data
---------
Everything (stores, invoices, keys, settings) lives in the app\data
folder inside this directory. BACK IT UP regularly — copying the whole
BareBits folder while the app is stopped is a complete backup.


Updating
--------
The built-in auto-updater is disabled in the desktop edition (it is
designed for web hosts). To update:

1. Stop the app (close the server window).
2. Extract the new barebits-windows-*.zip next to the old folder.
3. Copy the old  app\data  folder into the new  app\data.
4. If you customized  app\user_config.php , copy that too.
5. Start the new version; keep the old folder around until you have
   confirmed everything works.


Changing the port
-----------------
Default port is 8737. To use another one, start with an argument:

    BareBits.bat 9000

or set the CASHUPAY_PORT environment variable. Pick one port and stick
with it — the app stores absolute URLs for e-commerce integration.


Troubleshooting
---------------
- "PHP could not start": run vc_redist.x64.exe (bundled in this folder)
  manually, then start again.
- Port already in use: another program owns port 8737 — start with a
  different port (see above).
- Payment errors mentioning TLS/SSL: check the computer's date and time;
  certificate validation fails on machines with a wrong clock.
- PHP errors are logged to  app\data\php-error.log  — include it when
  asking for help.
